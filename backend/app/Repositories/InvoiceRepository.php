<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * Invoices and their line items (§6).
 *
 * Items are written through the parent: an invoice is only meaningful as a
 * header plus its lines, and the header totals are derived from them.
 */
final class InvoiceRepository extends Repository
{
    protected string $table = 'invoices';

    protected array $fillable = [
        'patient_id', 'encounter_id', 'invoice_no', 'status', 'currency_code',
        'subtotal', 'discount_total', 'tax_total', 'grand_total', 'paid_total',
        'patient_payable', 'insurance_payable', 'issue_date', 'due_date',
        'notes', 'pdf_path', 'issued_by', 'cancelled_reason',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    ];

    /** balance_due is a generated column — never write to it. */
    protected function filterFillable(array $data): array
    {
        unset($data['balance_due']);
        return parent::filterFillable($data);
    }

    /** @return list<array<string,mixed>> */
    public function items(int $invoiceId): array
    {
        return Database::select(
            'SELECT * FROM invoice_items
              WHERE organization_id = :org AND invoice_id = :iid
              ORDER BY sort_order, id',
            ['org' => $this->scopeBinding(), 'iid' => $invoiceId],
        );
    }

    /**
     * Replace all line items. Called only for draft invoices — the service
     * layer enforces that, because rewriting an issued invoice's lines would
     * change a document the patient already holds.
     *
     * @param list<array<string,mixed>> $items
     */
    public function replaceItems(int $invoiceId, array $items): void
    {
        $org = $this->scopeBinding();

        Database::transaction(function () use ($org, $invoiceId, $items): void {
            Database::statement(
                'DELETE FROM invoice_items WHERE organization_id = :org AND invoice_id = :iid',
                ['org' => $org, 'iid' => $invoiceId],
            );

            foreach (array_values($items) as $i => $item) {
                Database::statement(
                    'INSERT INTO invoice_items
                        (organization_id, invoice_id, service_id, service_code, description,
                         quantity, unit_price, discount_amount, tax_rate, tax_amount,
                         line_total, procedure_id, lab_order_id, is_ai_suggested,
                         sort_order, created_at)
                     VALUES (:org, :iid, :sid, :code, :desc, :qty, :price, :disc, :rate,
                             :tax, :total, :proc, :lab, :ai, :sort, :now)',
                    [
                        'org'   => $org,
                        'iid'   => $invoiceId,
                        'sid'   => $item['service_id']   ?? null,
                        // Snapshots: an issued invoice must not change because
                        // the catalogue was edited afterwards.
                        'code'  => $item['service_code'] ?? null,
                        'desc'  => $item['description'],
                        'qty'   => $item['quantity'],
                        'price' => $item['unit_price'],
                        'disc'  => $item['discount_amount'],
                        'rate'  => $item['tax_rate'],
                        'tax'   => $item['tax_amount'],
                        'total' => $item['line_total'],
                        'proc'  => $item['procedure_id'] ?? null,
                        'lab'   => $item['lab_order_id'] ?? null,
                        'ai'    => !empty($item['is_ai_suggested']) ? 1 : 0,
                        'sort'  => $i,
                        'now'   => now(),
                    ],
                );
            }
        });
    }

    /** Invoice with items, patient, encounter and payments — one round trip. */
    public function findDetailed(int $id): ?array
    {
        $org = $this->scopeBinding();

        $invoice = Database::selectOne(
            'SELECT i.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    p.mrn, p.phone AS patient_phone, p.address AS patient_address,
                    e.encounter_no,
                    u.name AS issued_by_name
               FROM invoices i
               JOIN patients p ON p.id = i.patient_id
               LEFT JOIN encounters e ON e.id = i.encounter_id
               LEFT JOIN users u ON u.id = i.issued_by
              WHERE i.organization_id = :org AND i.id = :id',
            ['org' => $org, 'id' => $id],
        );

        if ($invoice === null) {
            return null;
        }

        $invoice['items'] = $this->items($id);

        $invoice['payments'] = Database::select(
            'SELECT pay.*, u.name AS received_by_name
               FROM payments pay
               LEFT JOIN users u ON u.id = pay.received_by
              WHERE pay.organization_id = :org AND pay.invoice_id = :iid
              ORDER BY pay.created_at',
            ['org' => $org, 'iid' => $id],
        );

        $invoice['refunds'] = Database::select(
            'SELECT * FROM refunds
              WHERE organization_id = :org AND invoice_id = :iid
              ORDER BY created_at',
            ['org' => $org, 'iid' => $id],
        );

        return $invoice;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where    = ['i.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        if (!empty($filters['status'])) {
            $where[]            = 'i.status = :status';
            $bindings['status'] = $filters['status'];
        }
        if (!empty($filters['patient_id'])) {
            $where[]         = 'i.patient_id = :pid';
            $bindings['pid'] = (int) $filters['patient_id'];
        }
        if (!empty($filters['from'])) {
            $where[]          = 'i.created_at >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[]        = 'i.created_at <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[]      = "(i.invoice_no LIKE :q
                              OR p.mrn LIKE :q
                              OR CONCAT(p.first_name, ' ', p.last_name) LIKE :q)";
            $bindings['q'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['outstanding'])) {
            $where[] = "i.status IN ('issued','partially_paid','overdue')";
        }

        $clause  = ' WHERE ' . implode(' AND ', $where);
        $perPage = max(1, min(100, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;

        $total = (int) (Database::selectOne(
            'SELECT COUNT(*) AS c FROM invoices i JOIN patients p ON p.id = i.patient_id' . $clause,
            $bindings,
        )['c'] ?? 0);

        $rows = Database::select(
            'SELECT i.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name, p.mrn
               FROM invoices i
               JOIN patients p ON p.id = i.patient_id'
            . $clause
            . ' ORDER BY i.created_at DESC
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

    public function forEncounter(int $encounterId): ?array
    {
        return $this->firstWhere(['encounter_id' => $encounterId]);
    }

    /**
     * Recompute paid_total from the payment ledger and derive the status.
     *
     * The ledger is the source of truth — paid_total is a cached sum, so it is
     * rebuilt from SUM(payments) rather than incremented. That way a failed,
     * refunded or corrected payment cannot leave the header drifting away from
     * the rows underneath it.
     */
    public function recalculatePayments(int $invoiceId): array
    {
        $org = $this->scopeBinding();

        return Database::transaction(function () use ($org, $invoiceId): array {
            $invoice = Database::selectOne(
                'SELECT * FROM invoices WHERE organization_id = :org AND id = :id FOR UPDATE',
                ['org' => $org, 'id' => $invoiceId],
            );
            if ($invoice === null) {
                throw new \App\Core\NotFoundException('Invoice not found');
            }

            $paid = (string) (Database::selectOne(
                'SELECT COALESCE(SUM(amount), 0) AS paid
                   FROM payments
                  WHERE organization_id = :org AND invoice_id = :iid AND status = \'succeeded\'',
                ['org' => $org, 'iid' => $invoiceId],
            )['paid'] ?? '0');

            $refunded = (string) (Database::selectOne(
                'SELECT COALESCE(SUM(amount), 0) AS refunded
                   FROM refunds
                  WHERE organization_id = :org AND invoice_id = :iid AND status = \'completed\'',
                ['org' => $org, 'iid' => $invoiceId],
            )['refunded'] ?? '0');

            $net    = \App\Services\Billing\Money::round(
                \App\Services\Billing\Money::subtract($paid, $refunded),
            );
            $grand  = (string) $invoice['grand_total'];
            $status = self::deriveStatus((string) $invoice['status'], $net, $grand, $refunded, $invoice['due_date']);

            Database::statement(
                'UPDATE invoices SET paid_total = :paid, status = :status, updated_at = :now
                  WHERE organization_id = :org AND id = :id',
                ['paid' => $net, 'status' => $status, 'now' => now(), 'org' => $org, 'id' => $invoiceId],
            );

            return Database::selectOne(
                'SELECT * FROM invoices WHERE organization_id = :org AND id = :id',
                ['org' => $org, 'id' => $invoiceId],
            ) ?? [];
        });
    }

    /**
     * The §6 status set: draft, issued, partially_paid, paid, overdue,
     * cancelled, refunded.
     *
     * Terminal states (draft, cancelled) are never derived away.
     */
    private static function deriveStatus(
        string $current,
        string $paid,
        string $grand,
        string $refunded,
        ?string $dueDate,
    ): string {
        $M = \App\Services\Billing\Money::class;

        if ($current === 'draft' || $current === 'cancelled') {
            return $current;
        }

        // Fully refunded after having been paid.
        if (!$M::isZero($refunded) && $M::compare($refunded, $grand) >= 0) {
            return 'refunded';
        }

        if ($M::compare($paid, $grand) >= 0 && !$M::isZero($grand)) {
            return 'paid';
        }

        if (!$M::isZero($paid)) {
            return 'partially_paid';
        }

        if ($dueDate !== null && $dueDate < gmdate('Y-m-d')) {
            return 'overdue';
        }

        return 'issued';
    }
}
