<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ConflictException;
use App\Core\Controller;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\ServiceRepository;
use App\Services\AuditService;
use App\Services\InvoiceService;
use App\Services\PatientService;
use App\Services\PaymentService;
use App\Services\ReportService;

/**
 * Billing endpoints (§6, §7).
 *
 * Note what these methods never do: compute money. Amounts are produced by
 * InvoiceFactory and PaymentService; the controller only carries the request
 * across and audits the outcome.
 */
final class BillingController extends Controller
{
    private function catalogue(Request $request): ServiceRepository
    {
        return (new ServiceRepository())->forOrganization($request->organizationId());
    }

    // ---------------- service catalogue ----------------

    public function services(Request $request): never
    {
        $q = $this->validateQuery($request, [
            'search'   => 'nullable|string|max:120',
            'category' => 'nullable|in:consultation,followup,procedure,lab,imaging,injection,room,other',
        ]);

        $country = \App\Core\Database::selectOne(
            'SELECT country_id FROM organizations WHERE id = :id',
            ['id' => $request->organizationId()],
        );

        $this->ok([
            'services'    => $this->catalogue($request)->catalogue(
                $q['search'] ?? null,
                $q['category'] ?? null,
                $country === null ? null : (int) $country['country_id'],
            ),
            'departments' => $this->catalogue($request)->departments(),
        ]);
    }

    public function storeService(Request $request): never
    {
        $data = $this->validate($request, [
            'code'        => 'required|string|max:40',
            'name'        => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
            'department'  => 'nullable|string|max:120',
            'category'    => 'nullable|in:consultation,followup,procedure,lab,imaging,injection,room,other',
            'is_taxable'  => 'nullable|boolean',
            // Price is optional here; a service with no price simply cannot be
            // invoiced until one is set, which the factory enforces.
            'price'          => 'nullable|numeric|min:0',
            'currency_code'  => 'nullable|string|size:3',
            'tax_rate'       => 'nullable|numeric|between:0,1',
            'max_discount_pct' => 'nullable|numeric|between:0,100',
        ]);

        $repo = $this->catalogue($request);

        if ($repo->findByCode((string) $data['code']) !== null) {
            throw new \App\Core\ConflictException('A service with this code already exists.');
        }

        $service = $repo->create(array_only($data, [
            'code', 'name', 'description', 'department', 'category', 'is_taxable',
        ]) + ['is_active' => 1]);

        if (isset($data['price'])) {
            $settings = (new \App\Repositories\OrganizationRepository())
                ->settings((int) $request->organizationId());

            $repo->addPrice((int) $service['id'], [
                'currency_code'    => $data['currency_code'] ?? ($settings['currency_code'] ?? 'USD'),
                'price'            => $data['price'],
                'tax_rate'         => $data['tax_rate'] ?? null,
                'max_discount_pct' => $data['max_discount_pct'] ?? 0,
                'effective_from'   => gmdate('Y-m-d'),
            ]);
        }

        (new AuditService())->log($request, 'create', 'service', (int) $service['id'], null, $data);

        $this->created(['service' => $service, 'prices' => $repo->prices((int) $service['id'])]);
    }

    public function updateService(Request $request): never
    {
        $data = $this->validate($request, [
            'name'        => 'nullable|string|max:200',
            'description' => 'nullable|string|max:500',
            'department'  => 'nullable|string|max:120',
            'category'    => 'nullable|in:consultation,followup,procedure,lab,imaging,injection,room,other',
            'is_taxable'  => 'nullable|boolean',
            'is_active'   => 'nullable|boolean',
        ]);

        $id     = $request->intParam('id');
        $repo   = $this->catalogue($request);
        $before = $repo->findOrFail($id, 'Service');
        $after  = $repo->update($id, $data);

        (new AuditService())->logUpdate($request, 'service', $id, $before, $after);

        $this->ok(['service' => $after]);
    }

    /**
     * Add a price. The previous open price is closed automatically, so past
     * invoices keep the price they were raised at.
     */
    public function addServicePrice(Request $request): never
    {
        $data = $this->validate($request, [
            'price'            => 'required|numeric|min:0',
            'currency_code'    => 'required|string|size:3',
            'country_id'       => 'nullable|integer',
            'tax_rate'         => 'nullable|numeric|between:0,1',
            'max_discount_pct' => 'nullable|numeric|between:0,100',
            'effective_from'   => 'nullable|date',
        ]);

        $id   = $request->intParam('id');
        $repo = $this->catalogue($request);
        $repo->findOrFail($id, 'Service');

        $price = $repo->addPrice($id, $data);

        (new AuditService())->log($request, 'create', 'service_price', (int) $price['id'], null, $data);

        $this->created(['price' => $price, 'prices' => $repo->prices($id)]);
    }

    // ---------------- invoices ----------------

    public function invoices(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'status'      => 'nullable|in:draft,issued,partially_paid,paid,overdue,cancelled,refunded',
            'patient_id'  => 'nullable|integer',
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
            'search'      => 'nullable|string|max:120',
            'outstanding' => 'nullable|boolean',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $result = InvoiceService::for($request)->search($filters, $page, $perPage);

        $this->ok($result['data'], $result['meta']);
    }

    /**
     * The printable invoice (§6).
     *
     * A draft is refused: its number is a placeholder and its lines can still
     * change, so a printed copy would be a document nobody could rely on.
     */
    public function invoicePdf(Request $request): never
    {
        $id      = $request->intParam('id');
        $invoice = InvoiceService::for($request)->show($id);

        PatientService::for($request)->assertMayAccess($request, (int) $invoice['patient_id']);

        if ($invoice['status'] === 'draft') {
            throw new ConflictException(
                'This invoice is still a draft. Issue it first — a draft has no final number.'
            );
        }

        $clinic = (new \App\Repositories\OrganizationRepository())
            ->settings((int) $request->organizationId()) ?? [];

        (new AuditService())->logPatientAccess(
            $request, (int) $invoice['patient_id'], 'invoice', $id,
        );

        $this->file(
            \App\Services\Documents\ClinicDocuments::invoice($invoice, $clinic),
            $invoice['invoice_no'] . '.pdf',
        );
    }

    public function showInvoice(Request $request): never
    {
        $id      = $request->intParam('id');
        $invoice = InvoiceService::for($request)->show($id);

        PatientService::for($request)->assertMayAccess($request, (int) $invoice['patient_id']);

        (new AuditService())->logPatientAccess(
            $request, (int) $invoice['patient_id'], 'invoice', $id,
        );

        $this->ok(['invoice' => $invoice]);
    }

    public function storeInvoice(Request $request): never
    {
        $data = $this->validate($request, [
            'patient_id'   => 'required|integer',
            'encounter_id' => 'nullable|integer',
            'notes'        => 'nullable|string|max:1000',
            'due_date'     => 'nullable|date',
        ]);

        $items = $request->body['items'] ?? null;
        if (!is_array($items)) {
            throw new ValidationException(['items' => ['Send an items array.']]);
        }
        $data['items'] = $items;

        $invoice = InvoiceService::for($request)->createDraft($data);

        (new AuditService())->log(
            $request, 'create', 'invoice', (int) $invoice['id'], null,
            ['grand_total' => $invoice['grand_total'], 'lines' => count($invoice['items'])],
            (int) $invoice['patient_id'],
        );

        $this->created(['invoice' => $invoice]);
    }

    /** §27: consultation → invoice, in one call. */
    public function invoiceFromEncounter(Request $request): never
    {
        $data = $this->validate($request, ['due_date' => 'nullable|date']);

        $result = InvoiceService::for($request)
            ->createFromEncounter($request->intParam('id'), $data['due_date'] ?? null);

        (new AuditService())->log(
            $request, 'create', 'invoice', (int) $result['invoice']['id'], null,
            ['from_encounter' => $request->intParam('id'),
             'grand_total'    => $result['invoice']['grand_total']],
            (int) $result['invoice']['patient_id'],
        );

        // `skipped` is returned, not swallowed: a biller must see what could
        // not be charged automatically.
        $this->created(['invoice' => $result['invoice'], 'skipped' => $result['skipped']]);
    }

    public function updateInvoice(Request $request): never
    {
        $data = $this->validate($request, [
            'notes'    => 'nullable|string|max:1000',
            'due_date' => 'nullable|date',
        ]);
        if (array_key_exists('items', $request->body)) {
            $data['items'] = $request->body['items'];
        }

        $id      = $request->intParam('id');
        $invoice = InvoiceService::for($request)->updateDraft($id, $data);

        (new AuditService())->log(
            $request, 'update', 'invoice', $id, null,
            ['grand_total' => $invoice['grand_total']], (int) $invoice['patient_id'],
        );

        $this->ok(['invoice' => $invoice]);
    }

    public function issueInvoice(Request $request): never
    {
        $data = $this->validate($request, ['due_date' => 'nullable|date']);

        $id      = $request->intParam('id');
        $invoice = InvoiceService::for($request)->issue($id, $data['due_date'] ?? null);

        (new AuditService())->log(
            $request, 'update', 'invoice', $id, null,
            ['status' => 'issued', 'invoice_no' => $invoice['invoice_no']],
            (int) $invoice['patient_id'],
        );

        $this->ok(['invoice' => $invoice]);
    }

    public function cancelInvoice(Request $request): never
    {
        $data = $this->validate($request, ['reason' => 'required|string|max:500']);

        $id      = $request->intParam('id');
        $invoice = InvoiceService::for($request)->cancel($id, (string) $data['reason']);

        (new AuditService())->log(
            $request, 'update', 'invoice', $id, null,
            ['status' => 'cancelled', 'reason' => $data['reason']],
            (int) $invoice['patient_id'],
        );

        $this->ok(['invoice' => $invoice]);
    }

    // ---------------- payments ----------------

    public function recordPayment(Request $request): never
    {
        $data = $this->validate($request, [
            'amount'      => 'required|numeric',
            'method'      => 'nullable|in:cash,bank_transfer,card,online,insurance,adjustment',
            'gateway'     => 'nullable|string|max:60',
            'gateway_ref' => 'nullable|string|max:191',
            'paid_at'     => 'nullable|datetime',
            'notes'       => 'nullable|string|max:500',
        ]);

        $result = PaymentService::for($request)->record($request->intParam('id'), $data);

        (new AuditService())->log(
            $request, 'create', 'payment', (int) $result['payment']['id'], null,
            ['amount' => $result['payment']['amount'], 'method' => $result['payment']['method'],
             'receipt_no' => $result['payment']['receipt_no']],
            (int) $result['payment']['patient_id'],
        );

        $this->created([
            'payment' => $result['payment'],
            'invoice' => InvoiceService::for($request)->show($request->intParam('id')),
        ]);
    }

    public function payments(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'patient_id' => 'nullable|integer',
            'invoice_id' => 'nullable|integer',
            'method'     => 'nullable|in:cash,bank_transfer,card,online,insurance,adjustment',
            'status'     => 'nullable|in:pending,succeeded,failed,refunded',
            'from'       => 'nullable|date',
            'to'         => 'nullable|date',
        ]);

        $this->ok(['payments' => PaymentService::for($request)->ledger($filters)]);
    }

    // ---------------- refunds ----------------

    public function requestRefund(Request $request): never
    {
        $data = $this->validate($request, [
            'amount' => 'nullable|numeric',
            'reason' => 'required|string|max:500',
        ]);

        $refund = PaymentService::for($request)->requestRefund($request->intParam('id'), $data);

        (new AuditService())->log(
            $request, 'create', 'refund', (int) $refund['id'], null,
            ['amount' => $refund['amount'], 'reason' => $refund['reason']],
        );

        $this->created(['refund' => $refund]);
    }

    public function pendingRefunds(Request $request): never
    {
        $this->ok(['refunds' => PaymentService::for($request)->pendingRefunds()]);
    }

    public function approveRefund(Request $request): never
    {
        $result = PaymentService::for($request)->approveRefund($request->intParam('id'));

        (new AuditService())->log(
            $request, 'update', 'refund', $request->intParam('id'), null,
            ['status' => 'completed', 'amount' => $result['refund']['amount']],
        );

        $this->ok($result);
    }

    public function rejectRefund(Request $request): never
    {
        $data = $this->validate($request, ['reason' => 'nullable|string|max:500']);

        $refund = PaymentService::for($request)
            ->rejectRefund($request->intParam('id'), $data['reason'] ?? null);

        (new AuditService())->log(
            $request, 'update', 'refund', $request->intParam('id'), null, ['status' => 'rejected'],
        );

        $this->ok(['refund' => $refund]);
    }

    // ---------------- reports ----------------

    public function reports(Request $request): never
    {
        $q = $this->validateQuery($request, [
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        // Default window: this month to date.
        $from = (string) ($q['from'] ?? gmdate('Y-m-01'));
        $to   = (string) ($q['to'] ?? gmdate('Y-m-d'));

        $service = ReportService::for($request);

        $this->ok([
            'summary'        => $service->summary($from, $to),
            'by_method'      => $service->byPaymentMethod($from, $to),
            'top_services'   => $service->topServices($from, $to),
            'by_doctor'      => $service->byDoctor($from, $to),
        ]);
    }

    public function agedReceivables(Request $request): never
    {
        $this->ok(ReportService::for($request)->agedReceivables());
    }

    public function markOverdue(Request $request): never
    {
        $count = InvoiceService::for($request)->markOverdue();

        $this->ok(['message' => "$count invoice(s) marked overdue.", 'updated' => $count]);
    }
}
