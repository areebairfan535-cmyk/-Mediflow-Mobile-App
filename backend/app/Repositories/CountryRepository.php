<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;
use App\Models\Country;

/**
 * Markets (§23).
 *
 * §23 forbids hard-coded country behaviour, so the country row IS the
 * configuration: currency, symbol, timezone, date format, default tax rate and
 * invoice prefix all come from here, and a clinic's own settings fall back to
 * it wherever they are NULL.
 *
 * Platform-level data, so not tenant scoped.
 */
final class CountryRepository extends Repository
{
    protected string $model = Country::class;

    public function findByCode(string $code): ?array
    {
        return $this->firstWhere(['code' => $code]);
    }

    /** An active market only — what a signing-up clinic may actually choose. */
    public function findActiveByCode(string $code): ?array
    {
        return $this->firstWhere(['code' => $code, 'is_active' => 1]);
    }

    public function codeExists(string $code): bool
    {
        return $this->exists(['code' => $code]);
    }

    /**
     * Every market with the number of clinics in it — the blast radius of
     * editing a tax rate, shown next to the tax rate.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithOrganizationCounts(): array
    {
        return $this->query(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM organizations o WHERE o.country_id = c.id) AS organizations
               FROM countries c
              ORDER BY c.name',
        );
    }

    /**
     * What the sign-up form may show.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        return $this->query(
            'SELECT code, name, currency_code, currency_symbol, timezone
               FROM countries
              WHERE is_active = 1
              ORDER BY name',
        );
    }
}
