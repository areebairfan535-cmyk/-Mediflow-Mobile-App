<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * Service catalogue and its effective-dated prices (§6, §23).
 *
 * `services` says what the clinic offers; `service_prices` says what it costs
 * — per country, per currency, over a date range. They are separate because
 * the same "Consultation" costs PKR 2,000 in Faisalabad and USD 90 in Texas,
 * and because changing a price must not silently rewrite past invoices.
 */
final class ServiceRepository extends Repository
{
    protected string $table = 'services';

    protected array $fillable = [
        'code', 'name', 'description', 'department', 'category',
        'is_taxable', 'is_active', 'created_at', 'updated_at',
    ];

    /**
     * Catalogue with each service's currently effective price attached.
     *
     * @return list<array<string,mixed>>
     */
    public function catalogue(?string $search, ?string $category, ?int $countryId, bool $activeOnly = true): array
    {
        $where    = ['s.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding(), 'today' => gmdate('Y-m-d')];

        if ($activeOnly) {
            $where[] = 's.is_active = 1';
        }
        if ($search !== null && trim($search) !== '') {
            $where[]       = '(s.name LIKE :q OR s.code LIKE :q OR s.department LIKE :q)';
            $bindings['q'] = '%' . trim($search) . '%';
        }
        if ($category !== null && $category !== '') {
            $where[]           = 's.category = :cat';
            $bindings['cat']   = $category;
        }

        // A country-specific price beats the country-agnostic fallback, and a
        // later effective_from beats an earlier one. ROW_NUMBER over that
        // ordering picks exactly one price per service — clearer, and correct,
        // where a GROUP BY MAX() join would need a second pass to break ties.
        $countryClause = 'sp.country_id IS NULL';
        if ($countryId !== null) {
            $countryClause       = '(sp.country_id = :country OR sp.country_id IS NULL)';
            $bindings['country'] = $countryId;
        }

        return Database::select(
            'SELECT s.*,
                    p.id       AS price_id,
                    p.price,
                    p.currency_code,
                    p.tax_rate AS price_tax_rate,
                    p.max_discount_pct,
                    p.effective_from
               FROM services s
               LEFT JOIN (
                   SELECT ranked.* FROM (
                       SELECT sp.*,
                              ROW_NUMBER() OVER (
                                  PARTITION BY sp.service_id
                                  ORDER BY (sp.country_id IS NULL),
                                           sp.effective_from DESC,
                                           sp.id DESC
                              ) AS rn
                         FROM service_prices sp
                        WHERE sp.organization_id = :org
                          AND sp.effective_from <= :today
                          AND (sp.effective_to IS NULL OR sp.effective_to >= :today)
                          AND ' . $countryClause . '
                   ) ranked
                    WHERE ranked.rn = 1
               ) p ON p.service_id = s.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY s.department, s.name',
            $bindings,
        );
    }

    /**
     * The price to charge for a service today.
     *
     * Returns null when the service has no price configured — the caller must
     * then refuse rather than invent a number.
     *
     * @return array<string,mixed>|null
     */
    public function effectivePrice(int $serviceId, ?int $countryId, ?string $onDate = null): ?array
    {
        $date     = $onDate ?? gmdate('Y-m-d');
        $bindings = ['org' => $this->scopeBinding(), 'sid' => $serviceId, 'd' => $date];

        $countryClause = 'country_id IS NULL';
        if ($countryId !== null) {
            $countryClause       = '(country_id = :country OR country_id IS NULL)';
            $bindings['country'] = $countryId;
        }

        return Database::selectOne(
            'SELECT * FROM service_prices
              WHERE organization_id = :org
                AND service_id      = :sid
                AND effective_from <= :d
                AND (effective_to IS NULL OR effective_to >= :d)
                AND ' . $countryClause . '
              ORDER BY (country_id IS NULL), effective_from DESC
              LIMIT 1',
            $bindings,
        );
    }

    public function findByCode(string $code): ?array
    {
        return $this->firstWhere(['code' => $code]);
    }

    /** @return list<array<string,mixed>> */
    public function prices(int $serviceId): array
    {
        return Database::select(
            'SELECT sp.*, c.code AS country_code, c.name AS country_name
               FROM service_prices sp
               LEFT JOIN countries c ON c.id = sp.country_id
              WHERE sp.organization_id = :org AND sp.service_id = :sid
              ORDER BY sp.effective_from DESC',
            ['org' => $this->scopeBinding(), 'sid' => $serviceId],
        );
    }

    /**
     * Add a price. Any currently open price for the same service+country is
     * closed the day before, so two prices can never both be effective — the
     * source of "which price applies?" ambiguity.
     *
     * @param array<string,mixed> $data
     */
    public function addPrice(int $serviceId, array $data): array
    {
        $org  = $this->scopeBinding();
        $from = (string) ($data['effective_from'] ?? gmdate('Y-m-d'));

        return Database::transaction(function () use ($org, $serviceId, $data, $from): array {
            // The outgoing price ran until the day before this one starts —
            // except when it started today too. Closing that one "yesterday"
            // would leave a row whose end is before its own beginning, which
            // is not a period at all; it is closed on its own start date
            // instead, which reads as "superseded the day it was set".
            $closeOn = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');

            $countryId = $data['country_id'] ?? null;
            Database::statement(
                'UPDATE service_prices
                    SET effective_to = GREATEST(:close, effective_from), updated_at = :now
                  WHERE organization_id = :org
                    AND service_id      = :sid
                    AND effective_to IS NULL
                    AND effective_from <= :from
                    AND ' . ($countryId === null ? 'country_id IS NULL' : 'country_id = :country'),
                array_filter([
                    'close' => $closeOn, 'now' => now(), 'org' => $org,
                    'sid' => $serviceId, 'from' => $from,
                    'country' => $countryId,
                ], static fn($v) => $v !== null),
            );

            Database::statement(
                'INSERT INTO service_prices
                    (organization_id, service_id, country_id, currency_code, price,
                     tax_rate, max_discount_pct, effective_from, effective_to,
                     created_at, updated_at)
                 VALUES (:org, :sid, :country, :cur, :price, :tax, :disc, :from, :to, :now, :now)',
                [
                    'org'     => $org,
                    'sid'     => $serviceId,
                    'country' => $countryId,
                    'cur'     => $data['currency_code'],
                    'price'   => $data['price'],
                    'tax'     => $data['tax_rate'] ?? null,
                    'disc'    => $data['max_discount_pct'] ?? 0,
                    'from'    => $from,
                    'to'      => $data['effective_to'] ?? null,
                    'now'     => now(),
                ],
            );

            return Database::selectOne(
                'SELECT * FROM service_prices WHERE id = :id',
                ['id' => Database::lastInsertId()],
            ) ?? [];
        });
    }

    /** @return list<string> */
    public function departments(): array
    {
        $rows = Database::select(
            'SELECT DISTINCT department FROM services
              WHERE organization_id = :org AND department IS NOT NULL
              ORDER BY department',
            ['org' => $this->scopeBinding()],
        );
        return array_column($rows, 'department');
    }
}
