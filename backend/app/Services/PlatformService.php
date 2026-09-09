<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Repositories\CountryRepository;
use App\Repositories\PlanRepository;
use App\Repositories\PlatformRepository;

/**
 * The super admin panel's business rules (§21, §23).
 *
 * Platform staff work across every tenant, so nothing here is organization
 * scoped — the tenant question is "which clinic am I looking at", answered by
 * an id in the path rather than by TenantMiddleware.
 *
 * This exists because §18 wants the layering honest: PlatformController used
 * to hold twenty-four raw SQL statements plus the rules around them. The SQL
 * moved down to repositories, the rules moved here, and the controller kept
 * what a controller is for — validate, call, respond, audit.
 *
 * Dependencies come in through the constructor so a test can hand this class
 * doubles instead of a live database (§12's dependency injection).
 */
final class PlatformService extends Service
{
    private PlatformRepository $platform;
    private PlanRepository     $plans;
    private CountryRepository  $countries;

    public function __construct(
        ?int $organizationId = null,
        ?int $actorId = null,
        ?PlatformRepository $platform = null,
        ?PlanRepository $plans = null,
        ?CountryRepository $countries = null,
    ) {
        parent::__construct($organizationId, $actorId);

        $this->platform  = $platform  ?? new PlatformRepository();
        $this->plans     = $plans     ?? new PlanRepository();
        $this->countries = $countries ?? new CountryRepository();
    }

    // ---------------------------------------------------------------
    // Dashboard
    // ---------------------------------------------------------------

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        return [
            'counts'          => $this->platform->counts(),
            'money'           => array_map('money', $this->platform->money()),
            'failed_payments' => $this->platform->failedPaymentCount(),
            'subscriptions'   => $this->platform->subscriptionBreakdown(),
        ];
    }

    /**
     * @param array{status?:string,search?:string} $filters
     * @return array{data: list<array<string,mixed>>, total: int}
     */
    public function organizations(array $filters, int $page, int $perPage): array
    {
        return $this->platform->organizations($filters, $page, $perPage);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{data: list<array<string,mixed>>, total: int}
     */
    public function auditLogs(array $filters, int $page, int $perPage): array
    {
        return $this->platform->auditLogs($filters, $page, $perPage);
    }

    // ---------------------------------------------------------------
    // Plans (§21, §22)
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function plans(): array
    {
        $plans = $this->plans->allWithSubscriberCounts();

        foreach ($plans as $i => $plan) {
            // The column is JSON text; the panel wants a list.
            $plans[$i]['features'] = is_string($plan['features'] ?? null)
                ? (json_decode($plan['features'], true) ?: [])
                : ($plan['features'] ?? []);
            $plans[$i]['organizations'] = (int) $plan['organizations'];
        }

        return $plans;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createPlan(array $data): array
    {
        if ($this->plans->slugExists((string) $data['slug'])) {
            throw new ConflictException('A plan with this slug already exists.');
        }

        return $this->plans->create($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{before: array<string,mixed>, after: array<string,mixed>}
     */
    public function updatePlan(int $id, array $data): array
    {
        $before = $this->plans->find($id);
        if ($before === null) {
            throw new NotFoundException('Plan not found');
        }

        // The slug is the identity clients key off; renaming it would silently
        // repoint anything that stored it. Name and prices are editable.
        unset($data['slug']);

        return ['before' => $before, 'after' => $this->plans->update($id, $data)];
    }

    // ---------------------------------------------------------------
    // Countries / markets (§23)
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function countries(): array
    {
        $countries = $this->countries->allWithOrganizationCounts();

        foreach ($countries as $i => $country) {
            $countries[$i]['organizations'] = (int) $country['organizations'];
        }

        return $countries;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createCountry(array $data): array
    {
        if ($this->countries->codeExists((string) $data['code'])) {
            throw new ConflictException('That country is already configured.');
        }

        return $this->countries->create($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{before: array<string,mixed>, after: array<string,mixed>}
     */
    public function updateCountry(int $id, array $data): array
    {
        $before = $this->countries->find($id);
        if ($before === null) {
            throw new NotFoundException('Country not found');
        }

        unset($data['code']);   // the code is the identity, same as a plan slug

        return ['before' => $before, 'after' => $this->countries->update($id, $data)];
    }
}
