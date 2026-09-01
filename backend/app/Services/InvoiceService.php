<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\EncounterRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\PatientRepository;
use App\Repositories\ServiceRepository;
use App\Services\Billing\InvoiceFactory;
use App\Services\Billing\Money;
use App\Services\Billing\TaxRules;

/**
 * Invoicing (§6).
 *
 * Lifecycle:
 *   draft ──issue──► issued ──payment──► partially_paid ──►  paid
 *     │                 │                                     │
 *     └──cancel──► cancelled                          refund──► refunded
 *                       └──── past due ────► overdue
 *
 * Two rules shape everything here:
 *
 *  1. A DRAFT is editable; an ISSUED invoice is not. Once the patient has the
 *     document, changing its lines silently is not a correction, it is a
 *     forgery. Corrections happen by cancelling and re-issuing.
 *
 *  2. The invoice NUMBER is allocated at issue, not at creation. Abandoned
 *     drafts must not punch holes in the issued sequence — tax authorities
 *     care about gaps.
 */
final class InvoiceService extends Service
{
    private function invoices(): InvoiceRepository
    {
        return (new InvoiceRepository())->forOrganization($this->requireOrganization());
    }

    private function patients(): PatientRepository
    {
        return (new PatientRepository())->forOrganization($this->requireOrganization());
    }

    private function encounters(): EncounterRepository
    {
        return (new EncounterRepository())->forOrganization($this->requireOrganization());
    }

    private function catalogue(): ServiceRepository
    {
        return (new ServiceRepository())->forOrganization($this->requireOrganization());
    }

    /** Effective org settings: currency, tax rate, country, invoice prefix (§23). */
    private function settings(): array
    {
        return (new OrganizationRepository())->settings($this->requireOrganization()) ?? [];
    }

    private function factory(array $settings): InvoiceFactory
    {
        return new InvoiceFactory(
            $this->catalogue(),
            TaxRules::forCountry($settings['country_code'] ?? null),
            isset($settings['country_id']) ? (int) $settings['country_id'] : $this->countryId($settings),
            (string) ($settings['currency_code'] ?? 'USD'),
            Money::of($settings['tax_rate'] ?? 0),
        );
    }

    private function countryId(array $settings): ?int
    {
        $row = \App\Core\Database::selectOne(
            'SELECT country_id FROM organizations WHERE id = :id',
            ['id' => $this->requireOrganization()],
        );
        return $row === null ? null : (int) $row['country_id'];
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function show(int $id): array
    {
        $invoice = $this->invoices()->findDetailed($id);
        if ($invoice === null) {
            throw new NotFoundException('Invoice not found');
        }
        return $invoice;
    }

    /** @return array{data: list<array<string,mixed>>, meta: array<string,int>} */
    public function search(array $filters, int $page, int $perPage): array
    {
        return $this->invoices()->search($filters, $page, $perPage);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    /**
     * Create a draft from explicit lines.
     *
     * @param array<string,mixed> $data patient_id, encounter_id?, items[], notes?, due_date?
     */
    public function createDraft(array $data): array
    {
        $this->plan()->assertWithin('invoices');              // §22

        $patient  = $this->patients()->findOrFail((int) $data['patient_id'], 'Patient');
        $settings = $this->settings();

        $encounterId = null;
        if (!empty($data['encounter_id'])) {
            $encounter = $this->encounters()->findOrFail((int) $data['encounter_id'], 'Encounter');

            if ((int) $encounter['patient_id'] !== (int) $patient['id']) {
                throw new ValidationException(
                    ['encounter_id' => ['That consultation belongs to a different patient.']]
                );
            }
            if ($this->invoices()->forEncounter((int) $encounter['id']) !== null) {
                throw new ConflictException('This consultation has already been invoiced.');
            }
            $encounterId = (int) $encounter['id'];
        }

        $built = $this->factory($settings)->build($data['items'] ?? []);

        return $this->transaction(function () use ($patient, $encounterId, $data, $settings, $built): array {
            $invoice = $this->invoices()->create($this->stampCreate([
                'patient_id'    => (int) $patient['id'],
                'encounter_id'  => $encounterId,
                // Placeholder: the real number is issued later. Unique per row
                // so the column's UNIQUE key still holds for drafts.
                'invoice_no'    => 'DRAFT-' . strtoupper(bin2hex(random_bytes(6))),
                'status'        => 'draft',
                'currency_code' => (string) ($settings['currency_code'] ?? 'USD'),
                'notes'         => $data['notes'] ?? null,
                'due_date'      => $data['due_date'] ?? null,
                ...$built['totals'],
                'patient_payable' => $built['totals']['grand_total'],
            ]));

            $this->invoices()->replaceItems(
                (int) $invoice['id'],
                InvoiceFactory::stripInternals($built['items']),
            );

            return $this->show((int) $invoice['id']);
        });
    }

    /**
     * §27: turn a completed consultation into a draft invoice.
     *
     * Lines are derived from what actually happened — the consultation fee,
     * each recorded procedure, each lab order — so the biller reviews and
     * adjusts rather than retyping the visit.
     *
     * Procedures without a linked billable service are skipped and reported,
     * never silently dropped.
     *
     * @return array{invoice: array<string,mixed>, skipped: list<string>}
     */
    public function createFromEncounter(int $encounterId, ?string $dueDate = null): array
    {
        $this->plan()->assertWithin('invoices');              // §22

        $record = $this->encounters()->fullRecord($encounterId);
        if ($record === null) {
            throw new NotFoundException('Encounter not found');
        }
        if ($record['status'] !== 'completed') {
            throw new ConflictException(
                'Invoice a consultation once it is completed — this one is ' . $record['status'] . '.'
            );
        }
        if ($this->invoices()->forEncounter($encounterId) !== null) {
            throw new ConflictException('This consultation has already been invoiced.');
        }

        $catalogue = $this->catalogue();
        $lines     = [];
        $skipped   = [];

        // 1. The consultation itself.
        $consultCode = $record['type'] === 'followup' ? 'CONSULT-FU' : 'CONSULT-GEN';
        $consult     = $catalogue->findByCode($consultCode) ?? $catalogue->findByCode('CONSULT-GEN');

        if ($consult !== null) {
            $lines[] = [
                'service_id'  => (int) $consult['id'],
                'description' => $consult['name'],
                'quantity'    => 1,
            ];
        } else {
            $skipped[] = 'No consultation service in the catalogue — add one to bill the visit fee.';
        }

        // 2. Procedures performed.
        foreach ($record['procedures'] as $procedure) {
            $service = $procedure['service_id'] !== null
                ? $catalogue->find((int) $procedure['service_id'])
                : ($procedure['cpt_code'] !== null ? $catalogue->findByCode((string) $procedure['cpt_code']) : null);

            if ($service === null) {
                $skipped[] = "Procedure “{$procedure['name']}” has no matching billable service.";
                continue;
            }

            $lines[] = [
                'service_id'   => (int) $service['id'],
                'description'  => $procedure['site']
                    ? "{$service['name']} ({$procedure['site']})"
                    : $service['name'],
                'quantity'     => 1,
                'procedure_id' => (int) $procedure['id'],
            ];
        }

        // 3. Lab orders.
        foreach ($record['lab_orders'] as $order) {
            $service = $catalogue->findByCode('LAB-GEN');
            if ($service === null) {
                $skipped[] = "Lab order {$order['order_no']} has no billable service (add LAB-GEN).";
                continue;
            }
            $lines[] = [
                'service_id'   => (int) $service['id'],
                'description'  => "Laboratory — {$order['order_no']}",
                'quantity'     => 1,
                'lab_order_id' => (int) $order['id'],
            ];
        }

        if ($lines === []) {
            throw new ConflictException(
                'Nothing billable on this consultation. ' . implode(' ', $skipped)
            );
        }

        $invoice = $this->createDraft([
            'patient_id'   => (int) $record['patient_id'],
            'encounter_id' => $encounterId,
            'items'        => $lines,
            'due_date'     => $dueDate,
            'notes'        => 'Generated from consultation ' . $record['encounter_no'],
        ]);

        return ['invoice' => $invoice, 'skipped' => $skipped];
    }

    /** @param array<string,mixed> $data */
    public function updateDraft(int $id, array $data): array
    {
        $invoice = $this->invoices()->findOrFail($id, 'Invoice');
        $this->assertDraft($invoice, 'edited');

        $patch = array_only($data, ['notes', 'due_date']);
        $built = null;

        // Totals are recomputed from the lines; they are never accepted from
        // the request. Rebuilding here also re-validates prices and discounts.
        if (array_key_exists('items', $data)) {
            $built = $this->factory($this->settings())->build($data['items']);
            $patch += $built['totals'];
            $patch['patient_payable'] = $built['totals']['grand_total'];
        }

        return $this->transaction(function () use ($id, $patch, $built): array {
            if ($patch !== []) {
                $this->invoices()->update($id, $this->stampUpdate($patch));
            }

            if ($built !== null) {
                $this->invoices()->replaceItems(
                    $id,
                    InvoiceFactory::stripInternals($built['items']),
                );
            }

            return $this->show($id);
        });
    }

    /**
     * Issue: allocate the real invoice number and lock the lines.
     *
     * The number comes from the organization's own sequence, reserved inside
     * a transaction (OrganizationRepository::nextDocumentNumber takes a row
     * lock), so two invoices issued at the same moment cannot collide.
     */
    public function issue(int $id, ?string $dueDate = null): array
    {
        $invoice = $this->invoices()->findOrFail($id, 'Invoice');
        $this->assertDraft($invoice, 'issued');

        if ($this->invoices()->items($id) === []) {
            throw new ConflictException('Add at least one line before issuing.');
        }
        if (Money::isZero((string) $invoice['grand_total'])) {
            throw new ConflictException('This invoice totals zero — nothing to issue.');
        }

        $settings = $this->settings();
        $prefix   = (string) ($settings['invoice_prefix'] ?? 'INV');

        return $this->transaction(function () use ($id, $prefix, $dueDate, $invoice): array {
            $number = (new OrganizationRepository())
                ->nextDocumentNumber($this->requireOrganization(), $prefix);

            $this->invoices()->update($id, $this->stampUpdate([
                'invoice_no' => $number,
                'status'     => 'issued',
                'issue_date' => gmdate('Y-m-d'),
                // Default terms: due on receipt unless the caller sets a date.
                'due_date'   => $dueDate ?? $invoice['due_date'] ?? gmdate('Y-m-d'),
                'issued_by'  => $this->actorId,
            ]));

            $issued = $this->show($id);

            // §20: the patient is told once the invoice is a real document.
            try {
                (new NotificationService($this->organizationId, $this->actorId))->notifyPatient(
                    (int) $issued['patient_id'],
                    'invoice.issued',
                    [
                        'invoice_no'   => $issued['invoice_no'],
                        'amount'       => $issued['currency_code'] . ' ' . $issued['grand_total'],
                        'subject_type' => 'invoice',
                        'subject_id'   => $id,
                    ],
                );
            } catch (\Throwable $e) {
                error_log('[notify] invoice notification failed: ' . $e->getMessage());
            }

            return $issued;
        });
    }

    public function cancel(int $id, string $reason): array
    {
        $invoice = $this->invoices()->findOrFail($id, 'Invoice');

        if ($invoice['status'] === 'cancelled') {
            throw new ConflictException('This invoice is already cancelled.');
        }
        if ($invoice['status'] === 'refunded') {
            throw new ConflictException('A refunded invoice cannot be cancelled.');
        }

        // Money has changed hands: refund it first, so the ledger and the
        // invoice never disagree about what the patient paid.
        if (!Money::isZero((string) $invoice['paid_total'])) {
            throw new ConflictException(
                'This invoice has payments against it. Refund them before cancelling.'
            );
        }

        $this->invoices()->update($id, $this->stampUpdate([
            'status'           => 'cancelled',
            'cancelled_reason' => $reason,
        ]));

        return $this->show($id);
    }

    /**
     * Mark issued invoices whose due date has passed. Intended for a nightly
     * job; exposed so it can also be triggered from the admin UI.
     */
    public function markOverdue(): int
    {
        return \App\Core\Database::statement(
            'UPDATE invoices
                SET status = \'overdue\', updated_at = :now
              WHERE organization_id = :org
                AND status IN (\'issued\', \'partially_paid\')
                AND due_date IS NOT NULL
                AND due_date < :today',
            ['now' => now(), 'org' => $this->requireOrganization(), 'today' => gmdate('Y-m-d')],
        );
    }

    /** @param array<string,mixed> $invoice */
    private function assertDraft(array $invoice, string $verb): void
    {
        if ($invoice['status'] !== 'draft') {
            throw new ConflictException(
                "Only a draft invoice can be $verb — this one is {$invoice['status']}. "
                . 'Cancel it and raise a new one if it is wrong.'
            );
        }
    }
}
