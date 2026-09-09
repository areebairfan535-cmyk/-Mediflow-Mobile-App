<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Invoice;

/**
 * Invoices and their line items (§6).
 *
 * Items are written through the parent: an invoice is only meaningful as a
 * header plus its lines, and the header totals are derived from them.
 */
final class InvoiceRepository extends Repository
{
    protected string $model = Invoice::class;

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
     * Invoices whose due date has passed and which are still owed on.
     *
     * Only the columns the overdue run needs, because the caller notifies each
     * patient and nothing else.
     *
     * @return list<array<string,mixed>>
     */
    public function pastDue(string $today): array
    {
        return $this->query(
            'SELECT id, patient_id, invoice_no, currency_code, grand_total, paid_total
               FROM invoices
              WHERE organization_id = :org
                AND status IN (\'issued\', \'partially_paid\')
                AND due_date IS NOT NULL
                AND due_date < :today',
            ['org' => $this->scopeBinding(), 'today' => $today],
        );
    }

    /**
     * Flag a known set of invoices overdue.
     *
     * The ids come from pastDue() rather than from a caller, so the IN list is
     * built from integers this class produced.
     *
     * @param list<int> $ids
     */
    public function markOverdue(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $list = implode(',', array_map('intval', $ids));

        return Database::statement(
            "UPDATE invoices SET status = 'overdue', updated_at = :now
              WHERE organization_id = :org AND id IN ($list)",
            ['now' => now(), 'org' => $this->scopeBinding()],
        );
    }

    /** Which market this clinic bills in (§23). */
    public function countryId(): ?int
    {
        $row = Database::selectOne(
            'SELECT country_id FROM organizations WHERE id = :id',
            ['id' => $this->scopeBinding()],
        );

        return $row === null ? null : (int) $row['country_id'];
    }

    /**
     * The money line on a doctor's own dashboard (§8).
     *
     * `outstanding` is deliberately NOT limited to the day: everything this
     * doctor's visits have billed and not been paid is what the word means,
     * and a figure that reset at midnight would be useless.
     *
     * The two day bounds are a half-open UTC range for the clinic's local day,
     * computed by the caller because only it knows the clinic's timezone (§23).
     *
     * All three figures skip drafts and cancellations, and they have to skip
     * the same ones or the tiles argue with each other on screen. A draft is
     * not billed — it has no invoice number, the patient has never seen it,
     * and it may never be issued. Counting one as revenue while leaving its
     * balance out of `outstanding` showed a doctor money billed, less money
     * collected, and nothing owed, all at once.
     *
     * @return array<string,mixed>
     */
    public function doctorDayMoney(int $doctorId, string $fromUtc, string $toUtc): array
    {
        return Database::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN i.created_at >= :from AND i.created_at < :to
                                   AND i.status NOT IN (\'cancelled\', \'draft\')
                                  THEN i.grand_total END), 0) AS billed_today,
                COALESCE(SUM(CASE WHEN i.created_at >= :from2 AND i.created_at < :to2
                                   AND i.status NOT IN (\'cancelled\', \'draft\')
                                  THEN i.paid_total END), 0)  AS collected_today,
                COALESCE(SUM(CASE WHEN i.status NOT IN (\'cancelled\', \'draft\')
                                  THEN i.balance_due END), 0) AS outstanding
               FROM invoices i
               JOIN encounters e ON e.id = i.encounter_id
              WHERE i.organization_id = :org
                AND e.doctor_id = :doctor',
            [
                'org'    => $this->scopeBinding(),
                'doctor' => $doctorId,
                'from'   => $fromUtc, 'to'  => $toUtc,
                'from2'  => $fromUtc, 'to2' => $toUtc,
            ],
        ) ?? [];
    }

    /**
     * Read an invoice and hold it for the rest of the caller's transaction.
     *
     * Taking a payment is check-then-write: read the balance, decide the
     * payment fits, insert it. Without the lock two cashiers taking the last
     * payment at the same moment could both pass the balance check and overpay
     * the invoice.
     *
     * @return array<string,mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM invoices WHERE organization_id = :org AND id = :id FOR UPDATE',
            ['org' => $this->scopeBinding(), 'id' => $id],
        );
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

            // `refunded` belongs in this sum, and leaving it out was a bug.
            //
            // A refund is subtracted once, below, from the refunds table. A
            // payment is also stamped `refunded` — but only when the whole of
            // it has been given back (PaymentRepository::markRefunded). So a
            // full refund used to be taken off twice: once by the payment
            // dropping out of this sum, once by the subtraction below. A
            // partial refund, which leaves the payment `succeeded`, came out
            // right. That is why this only ever went wrong on full refunds.
            //
            // `refunded` here means "this money arrived, and later went back",
            // which is a payment that happened. `pending` and `failed` are
            // money that never arrived, and stay out.
            $paid = (string) (Database::selectOne(
                'SELECT COALESCE(SUM(amount), 0) AS paid
                   FROM payments
                  WHERE organization_id = :org AND invoice_id = :iid
                    AND status IN (\'succeeded\', \'refunded\')',
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
