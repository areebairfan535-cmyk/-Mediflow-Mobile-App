<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * Insurance providers and patient policies (§8).
 *
 * Providers may be platform-wide (organization_id NULL) or a clinic's own —
 * a national insurer is worth defining once, a local corporate scheme is not.
 * Policies are always tenant-scoped, because they belong to a patient record.
 */
final class InsuranceRepository extends Repository
{
    protected string $table        = 'insurance_providers';
    protected bool   $tenantScoped = false;   // providers can be shared

    protected array $fillable = [
        'organization_id', 'country_id', 'name', 'code', 'contact_email',
        'contact_phone', 'portal_url', 'claim_format', 'avg_settle_days',
        'is_active', 'created_at', 'updated_at',
    ];

    /**
     * Providers this organization may use: its own, plus the shared ones.
     *
     * @return list<array<string,mixed>>
     */
    public function providersFor(int $organizationId, bool $activeOnly = true): array
    {
        $where = ['(p.organization_id = :org OR p.organization_id IS NULL)'];
        if ($activeOnly) {
            $where[] = 'p.is_active = 1';
        }

        return Database::select(
            'SELECT p.*, c.code AS country_code,
                    (SELECT COUNT(*) FROM insurance_policies ip
                      WHERE ip.insurance_provider_id = p.id
                        AND ip.organization_id = :org) AS policy_count
               FROM insurance_providers p
               LEFT JOIN countries c ON c.id = p.country_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY (p.organization_id IS NULL), p.name',
            ['org' => $organizationId],
        );
    }

    /** A provider is usable here if it is ours or shared — same guard as roles. */
    public function providerUsableIn(int $providerId, int $organizationId): bool
    {
        return Database::selectOne(
            'SELECT id FROM insurance_providers
              WHERE id = :id AND (organization_id = :org OR organization_id IS NULL)',
            ['id' => $providerId, 'org' => $organizationId],
        ) !== null;
    }

    // ---------------- policies ----------------

    /** @return list<array<string,mixed>> */
    public function policiesFor(int $organizationId, int $patientId): array
    {
        return Database::select(
            'SELECT ip.*, p.name AS provider_name, p.code AS provider_code,
                    p.avg_settle_days,
                    (ip.coverage_amount - ip.coverage_used) AS coverage_remaining
               FROM insurance_policies ip
               JOIN insurance_providers p ON p.id = ip.insurance_provider_id
              WHERE ip.organization_id = :org AND ip.patient_id = :pid
              ORDER BY ip.is_primary DESC, ip.created_at DESC',
            ['org' => $organizationId, 'pid' => $patientId],
        );
    }

    public function findPolicy(int $organizationId, int $policyId): ?array
    {
        return Database::selectOne(
            'SELECT ip.*, p.name AS provider_name, p.claim_format, p.avg_settle_days,
                    (ip.coverage_amount - ip.coverage_used) AS coverage_remaining
               FROM insurance_policies ip
               JOIN insurance_providers p ON p.id = ip.insurance_provider_id
              WHERE ip.organization_id = :org AND ip.id = :id',
            ['org' => $organizationId, 'id' => $policyId],
        );
    }

    /**
     * The policy to bill for a patient on a given date: primary first, and
     * only one that is actually in force.
     */
    public function activePolicyFor(int $organizationId, int $patientId, ?string $onDate = null): ?array
    {
        $date = $onDate ?? gmdate('Y-m-d');

        return Database::selectOne(
            'SELECT ip.*, p.name AS provider_name, p.claim_format, p.avg_settle_days,
                    (ip.coverage_amount - ip.coverage_used) AS coverage_remaining
               FROM insurance_policies ip
               JOIN insurance_providers p ON p.id = ip.insurance_provider_id
              WHERE ip.organization_id = :org
                AND ip.patient_id      = :pid
                AND ip.status          = \'active\'
                AND (ip.valid_from IS NULL OR ip.valid_from <= :d)
                AND (ip.valid_to   IS NULL OR ip.valid_to   >= :d)
              ORDER BY ip.is_primary DESC, ip.valid_to IS NULL DESC, ip.valid_to DESC
              LIMIT 1',
            ['org' => $organizationId, 'pid' => $patientId, 'd' => $date],
        );
    }

    /**
     * The most recent policy on file regardless of state.
     *
     * Used by the eligibility quote so an expired or suspended policy can be
     * reported as such. activePolicyFor() returning null would make the answer
     * "no insurance on file", which sends a receptionist looking for a card
     * the patient already handed over.
     */
    public function latestPolicyFor(int $organizationId, int $patientId): ?array
    {
        return Database::selectOne(
            'SELECT ip.*, p.name AS provider_name, p.claim_format, p.avg_settle_days,
                    (ip.coverage_amount - ip.coverage_used) AS coverage_remaining
               FROM insurance_policies ip
               JOIN insurance_providers p ON p.id = ip.insurance_provider_id
              WHERE ip.organization_id = :org AND ip.patient_id = :pid
              ORDER BY ip.is_primary DESC, ip.valid_to IS NULL DESC, ip.valid_to DESC, ip.id DESC
              LIMIT 1',
            ['org' => $organizationId, 'pid' => $patientId],
        );
    }

    /** @param array<string,mixed> $data */
    public function createPolicy(int $organizationId, array $data): array
    {
        $columns = [
            'patient_id', 'insurance_provider_id', 'policy_number', 'member_id',
            'group_number', 'policy_holder_name', 'relation_to_patient',
            'coverage_type', 'coverage_amount', 'copay_percent', 'deductible',
            'valid_from', 'valid_to', 'is_primary',
        ];

        $row = array_only($data, $columns) + [
            'organization_id' => $organizationId,
            'coverage_used'   => 0,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ];

        return Database::transaction(function () use ($row, $organizationId, $data): array {
            // Exactly one primary policy per patient — two "primary" insurers
            // is the ambiguity that produces claims sent to the wrong one.
            if (!empty($data['is_primary'])) {
                Database::statement(
                    'UPDATE insurance_policies SET is_primary = 0, updated_at = :now
                      WHERE organization_id = :org AND patient_id = :pid',
                    ['now' => now(), 'org' => $organizationId, 'pid' => (int) $data['patient_id']],
                );
            }

            $names        = array_keys($row);
            $placeholders = array_map(static fn(string $c): string => ':' . $c, $names);

            Database::statement(
                'INSERT INTO insurance_policies ('
                . implode(', ', array_map(static fn($c) => "`$c`", $names)) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')',
                $row,
            );

            return $this->findPolicy($organizationId, Database::lastInsertId()) ?? [];
        });
    }

    /** @param array<string,mixed> $data */
    public function updatePolicy(int $organizationId, int $policyId, array $data): array
    {
        $allowed = array_only($data, [
            'policy_number', 'member_id', 'group_number', 'policy_holder_name',
            'relation_to_patient', 'coverage_type', 'coverage_amount',
            'copay_percent', 'deductible', 'valid_from', 'valid_to', 'status',
        ]);

        if ($allowed !== []) {
            $sets = [];
            foreach (array_keys($allowed) as $column) {
                $sets[] = "`$column` = :$column";
            }
            Database::statement(
                'UPDATE insurance_policies SET ' . implode(', ', $sets) . ', updated_at = :__now
                  WHERE organization_id = :__org AND id = :__id',
                $allowed + ['__now' => now(), '__org' => $organizationId, '__id' => $policyId],
            );
        }

        return $this->findPolicy($organizationId, $policyId) ?? [];
    }

    /**
     * Move the coverage-used counter. Positive consumes, negative releases
     * (a rejected claim gives the ceiling back).
     *
     * GREATEST(...,0) stops a double release from driving the counter
     * negative, which would silently hand the patient extra cover.
     */
    public function adjustCoverageUsed(int $organizationId, int $policyId, string $delta): void
    {
        Database::statement(
            'UPDATE insurance_policies
                SET coverage_used = GREATEST(coverage_used + :delta, 0),
                    updated_at    = :now
              WHERE organization_id = :org AND id = :id',
            ['delta' => $delta, 'now' => now(), 'org' => $organizationId, 'id' => $policyId],
        );
    }
}
