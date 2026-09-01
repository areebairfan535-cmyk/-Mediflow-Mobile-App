<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * Organizations are the tenant roots themselves, so this repository is not
 * tenant-scoped — the scoping question is "which organization", answered by
 * TenantMiddleware before any tenant-scoped repository is used.
 */
final class OrganizationRepository extends Repository
{
    protected string $table        = 'organizations';
    protected bool   $tenantScoped = false;

    protected array $fillable = [
        'name', 'slug', 'country_id', 'email', 'phone', 'address', 'city',
        'logo_path', 'currency_code', 'timezone', 'tax_rate', 'invoice_prefix',
        'next_invoice_no', 'status', 'created_at', 'updated_at',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->firstWhere(['slug' => $slug]);
    }

    public function slugExists(string $slug): bool
    {
        return $this->exists(['slug' => $slug]);
    }

    /**
     * Effective settings for an organization: its own overrides where set,
     * otherwise the country defaults (§23 — no hard-coded country behaviour).
     *
     * @return array<string,mixed>|null
     */
    public function settings(int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT o.id,
                    o.name,
                    o.slug,
                    c.code                                  AS country_code,
                    c.name                                  AS country_name,
                    COALESCE(o.currency_code,  c.currency_code)    AS currency_code,
                    c.currency_symbol,
                    COALESCE(o.timezone,       c.timezone)         AS timezone,
                    COALESCE(o.tax_rate,       c.default_tax_rate) AS tax_rate,
                    COALESCE(o.invoice_prefix, c.invoice_prefix)   AS invoice_prefix,
                    c.date_format,
                    o.next_invoice_no,
                    o.status
               FROM organizations o
               JOIN countries c ON c.id = o.country_id
              WHERE o.id = :id',
            ['id' => $organizationId],
        );
    }

    /**
     * Atomically reserve the next document number for a tenant.
     *
     * The UPDATE ... then SELECT pair runs inside the caller's transaction and
     * the UPDATE takes a row lock, so two concurrent invoices can never be
     * handed the same sequence number.
     */
    public function nextDocumentNumber(int $organizationId, string $prefix): string
    {
        return Database::transaction(function () use ($organizationId, $prefix): string {
            Database::statement(
                'UPDATE organizations
                    SET next_invoice_no = next_invoice_no + 1
                  WHERE id = :id',
                ['id' => $organizationId],
            );
            $row = Database::selectOne(
                'SELECT next_invoice_no FROM organizations WHERE id = :id',
                ['id' => $organizationId],
            );
            // We incremented first, so the number we own is one below.
            $sequence = (int) ($row['next_invoice_no'] ?? 2) - 1;

            return sprintf('%s-%06d', $prefix, $sequence);
        });
    }
}
