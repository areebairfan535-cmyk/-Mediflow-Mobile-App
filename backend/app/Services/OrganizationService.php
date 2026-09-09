<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Repositories\CountryRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleRepository;

/**
 * Clinic onboarding (§22).
 *
 * This used to be a transaction written out inside OrganizationController.
 * §18 puts business logic in a service and data access in a repository, and
 * onboarding is the clearest case of both: three writes that must succeed or
 * fail together, with rules about what each one means.
 *
 * Nothing here is tenant scoped — an organization is the tenant, so at the
 * moment this runs there is not one yet.
 */
final class OrganizationService extends Service
{
    private OrganizationRepository $organizations;
    private CountryRepository      $countries;
    private RoleRepository         $roles;

    public function __construct(
        ?int $organizationId = null,
        ?int $actorId = null,
        ?OrganizationRepository $organizations = null,
        ?CountryRepository $countries = null,
        ?RoleRepository $roles = null,
    ) {
        parent::__construct($organizationId, $actorId);

        $this->organizations = $organizations ?? new OrganizationRepository();
        $this->countries     = $countries     ?? new CountryRepository();
        $this->roles         = $roles         ?? new RoleRepository();
    }

    /**
     * Create the clinic and make the caller its owner, atomically.
     *
     * Half-created tenants are worse than none — a clinic with no owner is
     * unreachable, and one with no subscription would have to be read as
     * either "free" or "unlimited", and one of those is a leak. So all three
     * writes share a transaction.
     *
     * @param array<string,mixed> $data validated input
     * @return array<string,mixed> the new organization row
     */
    public function onboard(array $data, int $userId): array
    {
        $country = $this->countries->findActiveByCode(
            strtoupper((string) $data['country_code']),
        );

        if ($country === null) {
            throw new NotFoundException(
                'Country ' . $data['country_code'] . ' is not configured on this platform'
            );
        }

        $slug = $this->uniqueSlug((string) $data['name']);

        return $this->transaction(
            function () use ($data, $slug, $country, $userId): array {
                $organization = $this->organizations->create([
                    'name'       => trim((string) $data['name']),
                    'slug'       => $slug,
                    'country_id' => (int) $country['id'],
                    'email'      => $data['email']   ?? null,
                    'phone'      => $data['phone']   ?? null,
                    'address'    => $data['address'] ?? null,
                    'city'       => $data['city']    ?? null,
                    'status'     => 'active',
                ]);

                $ownerRole = $this->roles->findSystemRole('org_owner');
                if ($ownerRole === null) {
                    throw new \RuntimeException(
                        'System role org_owner is missing — run database/seed.php'
                    );
                }

                (new RbacService())->addMember(
                    (int) $organization['id'],
                    $userId,
                    (int) $ownerRole['id'],
                    'Owner',
                );

                // §22 onboarding: choose plan → organization created. Inside
                // the same transaction, for the reason in the docblock above.
                SubscriptionService::startFor(
                    (int) $organization['id'],
                    (string) ($country['currency_code'] ?? 'USD'),
                    isset($data['plan']) ? (string) $data['plan'] : null,
                );

                return $organization;
            },
        );
    }

    /** The clinic's effective settings, its own overrides over the market's. */
    public function settings(int $organizationId): ?array
    {
        return $this->organizations->settings($organizationId);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{before: array<string,mixed>, after: array<string,mixed>}
     */
    public function updateSettings(int $organizationId, array $data): array
    {
        $before = $this->organizations->find($organizationId) ?? [];

        return [
            'before' => $before,
            'after'  => $this->organizations->update($organizationId, $data),
        ];
    }

    /**
     * A URL-safe name that no other clinic holds.
     *
     * Two clinics called "City Dental" is ordinary; two rows with the same
     * slug is not, since the slug is what appears in links.
     */
    private function uniqueSlug(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? 'clinic';
        $base = trim($base, '-') ?: 'clinic';
        $base = substr($base, 0, 100);

        $slug = $base;
        for ($i = 2; $this->organizations->slugExists($slug); $i++) {
            $slug = $base . '-' . $i;
            if ($i > 200) {
                throw new ConflictException('Could not generate a unique slug for this name');
            }
        }

        return $slug;
    }
}
