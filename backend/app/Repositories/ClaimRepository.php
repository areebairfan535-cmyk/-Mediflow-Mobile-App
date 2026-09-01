<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * Claims and claim lines (§8).
 *
 * Lines are written through the parent: a claim is a header plus the charges
 * it is claiming for, submitted together.
 */
final class ClaimRepository extends Repository
{
    protected string $table = 'claims';

    protected array $fillable = [
        'patient_id', 'invoice_id', 'encounter_id', 'insurance_policy_id',
        'claim_no', 'external_claim_no', 'status', 'currency_code',
        'claimed_amount', 'approved_amount', 'paid_amount', 'patient_responsibility',
        'rejection_code', 'rejection_reason', 'resubmission_of', 'submission_count',
        'ai_risk_score', 'ai_missing_items',
        'submitted_at', 'decided_at', 'paid_at',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    ];

    public function nextClaimNo(): string
    {
        // SUBSTRING is 1-indexed and 'CLM-' is four characters, so the digits
        // start at position 5. Starting at 4 keeps the hyphen, and
        // CAST('-000001' AS UNSIGNED) wraps to a nonsense value rather than
        // failing — which produced a duplicate-key error on the second claim.
        $row = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(claim_no, 5) AS UNSIGNED)), 0) AS n
               FROM claims
              WHERE organization_id = :org AND claim_no REGEXP \'^CLM-[0-9]+$\'',
            ['org' => $this->scopeBinding()],
        );
        return sprintf('CLM-%06d', ((int) ($row['n'] ?? 0)) + 1);
    }

    /** @return list<array<string,mixed>> */
    public function items(int $claimId): array
    {
        return Database::select(
            'SELECT * FROM claim_items
              WHERE organization_id = :org AND claim_id = :cid
              ORDER BY id',
            ['org' => $this->scopeBinding(), 'cid' => $claimId],
        );
    }

    /** @param list<array<string,mixed>> $items */
    public function replaceItems(int $claimId, array $items): void
    {
        $org = $this->scopeBinding();

        Database::transaction(function () use ($org, $claimId, $items): void {
            Database::statement(
                'DELETE FROM claim_items WHERE organization_id = :org AND claim_id = :cid',
                ['org' => $org, 'cid' => $claimId],
            );

            foreach ($items as $item) {
                Database::statement(
                    'INSERT INTO claim_items
                        (organization_id, claim_id, invoice_item_id, billing_code,
                         diagnosis_code, description, quantity, claimed_amount,
                         approved_amount, status, created_at, updated_at)
                     VALUES (:org, :cid, :iid, :code, :dx, :desc, :qty, :claimed,
                             0, \'claimed\', :now, :now)',
                    [
                        'org'     => $org,
                        'cid'     => $claimId,
                        'iid'     => $item['invoice_item_id'] ?? null,
                        'code'    => $item['billing_code']    ?? null,
                        // §8: a charge is justified by a diagnosis. Insurers
                        // reject lines that arrive without one.
                        'dx'      => $item['diagnosis_code']  ?? null,
                        'desc'    => $item['description'],
                        'qty'     => $item['quantity'] ?? 1,
                        'claimed' => $item['claimed_amount'],
                        'now'     => now(),
                    ],
                );
            }
        });
    }

    public function findDetailed(int $id): ?array
    {
        $org = $this->scopeBinding();

        $claim = Database::selectOne(
            'SELECT c.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name, p.mrn,
                    i.invoice_no, i.grand_total AS invoice_total, i.status AS invoice_status,
                    ip.policy_number, ip.member_id, ip.copay_percent, ip.coverage_amount,
                    ip.coverage_used,
                    prov.name AS provider_name, prov.avg_settle_days,
                    e.encounter_no
               FROM claims c
               JOIN patients p            ON p.id = c.patient_id
               JOIN invoices i            ON i.id = c.invoice_id
               JOIN insurance_policies ip ON ip.id = c.insurance_policy_id
               JOIN insurance_providers prov ON prov.id = ip.insurance_provider_id
               LEFT JOIN encounters e     ON e.id = c.encounter_id
              WHERE c.organization_id = :org AND c.id = :id',
            ['org' => $org, 'id' => $id],
        );

        if ($claim === null) {
            return null;
        }

        $claim['items'] = $this->items($id);

        // Resubmission chain, so the history of a disputed claim is visible.
        $claim['resubmissions'] = Database::select(
            'SELECT id, claim_no, status, claimed_amount, approved_amount,
                    rejection_reason, created_at
               FROM claims
              WHERE organization_id = :org AND resubmission_of = :id
              ORDER BY created_at',
            ['org' => $org, 'id' => $id],
        );

        return $claim;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where    = ['c.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        if (!empty($filters['status'])) {
            $where[]            = 'c.status = :status';
            $bindings['status'] = $filters['status'];
        }
        if (!empty($filters['patient_id'])) {
            $where[]         = 'c.patient_id = :pid';
            $bindings['pid'] = (int) $filters['patient_id'];
        }
        if (!empty($filters['provider_id'])) {
            $where[]          = 'prov.id = :prov';
            $bindings['prov'] = (int) $filters['provider_id'];
        }
        if (!empty($filters['open'])) {
            $where[] = "c.status IN ('draft','submitted','processing','resubmission')";
        }
        if (!empty($filters['search'])) {
            $where[]      = "(c.claim_no LIKE :q OR c.external_claim_no LIKE :q
                              OR i.invoice_no LIKE :q
                              OR CONCAT(p.first_name, ' ', p.last_name) LIKE :q)";
            $bindings['q'] = '%' . $filters['search'] . '%';
        }

        $from = ' FROM claims c
                  JOIN patients p ON p.id = c.patient_id
                  JOIN invoices i ON i.id = c.invoice_id
                  JOIN insurance_policies ip ON ip.id = c.insurance_policy_id
                  JOIN insurance_providers prov ON prov.id = ip.insurance_provider_id';

        $clause  = ' WHERE ' . implode(' AND ', $where);
        $perPage = max(1, min(100, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;

        $total = (int) (Database::selectOne(
            'SELECT COUNT(*) AS c' . $from . $clause,
            $bindings,
        )['c'] ?? 0);

        $rows = Database::select(
            'SELECT c.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name, p.mrn,
                    i.invoice_no, prov.name AS provider_name,
                    ip.policy_number,
                    DATEDIFF(CURDATE(), DATE(c.submitted_at)) AS days_pending'
            . $from . $clause
            . ' ORDER BY c.created_at DESC
                LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings,
        );

        return [
            'data' => $rows,
            'meta' => [
                'page' => max(1, $page), 'per_page' => $perPage,
                'total' => $total, 'last_page' => (int) max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function forInvoice(int $invoiceId): ?array
    {
        // The live claim for an invoice — a rejected one that has been
        // resubmitted should not block a new attempt.
        return Database::selectOne(
            'SELECT * FROM claims
              WHERE organization_id = :org AND invoice_id = :iid
                AND status NOT IN (\'rejected\')
              ORDER BY id DESC LIMIT 1',
            ['org' => $this->scopeBinding(), 'iid' => $invoiceId],
        );
    }

    /**
     * §8 asks for rejection analytics: which reasons cost the clinic most.
     *
     * @return list<array<string,mixed>>
     */
    public function rejectionAnalysis(): array
    {
        return Database::select(
            'SELECT COALESCE(c.rejection_code, \'(none given)\') AS code,
                    c.rejection_reason,
                    prov.name AS provider_name,
                    COUNT(*)                        AS claims,
                    COALESCE(SUM(c.claimed_amount), 0) AS amount
               FROM claims c
               JOIN insurance_policies ip    ON ip.id = c.insurance_policy_id
               JOIN insurance_providers prov ON prov.id = ip.insurance_provider_id
              WHERE c.organization_id = :org
                AND c.status IN (\'rejected\', \'partially_approved\')
              GROUP BY code, c.rejection_reason, prov.name
              ORDER BY amount DESC
              LIMIT 30',
            ['org' => $this->scopeBinding()],
        );
    }

    /** @return array<string,mixed> Pipeline totals by status. */
    public function pipeline(): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS claims,
                    COALESCE(SUM(claimed_amount), 0)  AS claimed,
                    COALESCE(SUM(approved_amount), 0) AS approved,
                    COALESCE(SUM(paid_amount), 0)     AS paid
               FROM claims
              WHERE organization_id = :org
              GROUP BY status',
            ['org' => $this->scopeBinding()],
        );

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = $row;
        }
        return $byStatus;
    }
}
