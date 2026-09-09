<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\OrganizationRepository;
use App\Services\AuditService;
use App\Services\PlatformService;
use App\Services\PlatformSettings;
use App\Services\RbacService;
use App\Services\SubscriptionService;
use App\Services\Payments\PaymentGateways;

/**
 * Super Admin Panel (§21) — the only cross-tenant surface in the API.
 *
 * These routes carry 'platform' instead of 'tenant', and are the sole place
 * withoutTenantScope() is reachable over HTTP. Keeping that concentrated in
 * one controller is what makes the tenancy guarantee reviewable.
 *
 * §18: no SQL here. This controller used to hold twenty-four raw statements;
 * they moved down to PlatformRepository / PlanRepository / CountryRepository,
 * and the rules around them to PlatformService. What is left is what a
 * controller is for — validate the input, call one thing, record what happened,
 * answer.
 *
 * GET /api/v1/platform/dashboard
 * GET /api/v1/platform/organizations
 * GET /api/v1/platform/organizations/{id}
 * PUT /api/v1/platform/organizations/{id}/status
 */
final class PlatformController extends Controller
{
    public function dashboard(Request $request): never
    {
        $this->ok(PlatformService::for($request)->dashboard());
    }

    public function organizations(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'status' => 'nullable|in:active,suspended,cancelled',
            'search' => 'nullable|string|max:120',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $result = PlatformService::for($request)->organizations($filters, $page, $perPage);

        $this->ok($result['data'], $this->meta($page, $perPage, $result['total']));
    }

    public function showOrganization(Request $request): never
    {
        $organizations = new OrganizationRepository();
        $id            = $request->intParam('id');

        $organization = $organizations->withoutTenantScope()->findOrFail($id, 'Organization');

        $this->ok([
            'organization' => $organizations->settings($id),
            'members'      => (new RbacService())->members($id),
            'raw'          => $organization,
        ]);
    }

    public function setOrganizationStatus(Request $request): never
    {
        $data = $this->validate($request, [
            'status' => 'required|in:active,suspended,cancelled',
        ]);

        $organizations = new OrganizationRepository();
        $id            = $request->intParam('id');
        $before        = $organizations->withoutTenantScope()->findOrFail($id, 'Organization');

        $updated = (new OrganizationRepository())
            ->withoutTenantScope()
            ->update($id, ['status' => $data['status']]);

        // Filed against the clinic it happened to, not against nobody — being
        // suspended is the single most important line in that clinic's trail.
        (new AuditService())->logForOrganization(
            $request,
            $id,
            'update',
            'organization',
            $id,
            ['status' => $before['status']],
            ['status' => $updated['status']],
        );

        $this->ok(['organization' => $updated]);
    }

    // ===============================================================
    // Plans (§21, §22) — the price list itself, not one clinic's copy
    // ===============================================================

    public function plans(Request $request): never
    {
        $this->ok(['plans' => PlatformService::for($request)->plans()]);
    }

    public function storePlan(Request $request): never
    {
        $data = $this->planInput($request, true);
        $plan = PlatformService::for($request)->createPlan($data);

        (new AuditService())->log(
            $request, 'create', 'plan', (int) $plan['id'], null, ['slug' => $data['slug']],
        );

        $this->created(['plan' => $plan]);
    }

    public function updatePlan(Request $request): never
    {
        $id     = $request->intParam('id');
        $data   = $this->planInput($request, false);
        $result = PlatformService::for($request)->updatePlan($id, $data);

        (new AuditService())->log(
            $request, 'update', 'plan', $id,
            [
                'name'          => $result['before']['name'],
                'price_monthly' => $result['before']['price_monthly'],
            ],
            [
                'name'          => $result['after']['name'],
                'price_monthly' => $result['after']['price_monthly'],
            ],
        );

        $this->ok(['plan' => $result['after']]);
    }

    /**
     * Move one organization onto a plan (§21).
     *
     * Runs through the same SubscriptionService as a clinic's own upgrade, so
     * the "does the clinic already exceed this plan" check applies to platform
     * staff too. An admin who genuinely means to overshoot can raise the plan's
     * limits instead — which is at least visible.
     */
    public function setOrganizationPlan(Request $request): never
    {
        $data = $this->validate($request, ['plan_id' => 'required|integer']);
        $id   = $request->intParam('id');

        (new OrganizationRepository())->withoutTenantScope()->findOrFail($id, 'Organization');

        $result = (new SubscriptionService($id, $request->userId()))
            ->changePlan((int) $data['plan_id'], $id);

        (new AuditService())->logForOrganization(
            $request, $id, 'update', 'subscription', (int) $result['subscription']['id'],
            null, ['plan' => $result['plan']['slug'], 'changed_by' => 'platform'],
        );

        $this->ok($result);
    }

    /**
     * The platform-level trail (§16, §21).
     *
     * `GET /audit-logs` is tenant-scoped and widens only to NULL-org rows whose
     * actor is a member of that clinic — so platform staff editing plans and
     * markets were being recorded and then readable by nobody. This is the
     * other half of that: every row, cross-tenant, platform admins only.
     */
    public function auditLogs(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'organization_id' => 'nullable|integer',
            'user_id'         => 'nullable|integer',
            'action'          => 'nullable|string|max:80',
            'resource_type'   => 'nullable|string|max:60',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $result = PlatformService::for($request)->auditLogs($filters, $page, $perPage);

        $this->ok($result['data'], $this->meta($page, $perPage, $result['total']));
    }

    // ===============================================================
    // Countries, currencies and tax (§21, §23)
    // ===============================================================

    /**
     * §23 forbids hard-coded country behaviour, so the country row IS the
     * configuration: currency, timezone, date format, default tax rate and
     * invoice prefix all come from here.
     */
    public function countries(Request $request): never
    {
        $this->ok(['countries' => PlatformService::for($request)->countries()]);
    }

    public function storeCountry(Request $request): never
    {
        $data    = $this->countryInput($request);
        $country = PlatformService::for($request)->createCountry($data);

        (new AuditService())->log(
            $request, 'create', 'country', (int) $country['id'], null, ['code' => $data['code']],
        );

        $this->created(['country' => $country]);
    }

    public function updateCountry(Request $request): never
    {
        $id     = $request->intParam('id');
        $data   = $this->countryInput($request);
        $result = PlatformService::for($request)->updateCountry($id, $data);

        (new AuditService())->log(
            $request, 'update', 'country', $id,
            [
                'default_tax_rate' => $result['before']['default_tax_rate'],
                'is_active'        => $result['before']['is_active'],
            ],
            [
                'default_tax_rate' => $result['after']['default_tax_rate'],
                'is_active'        => $result['after']['is_active'],
            ],
        );

        // Be exact about the blast radius. A clinic's own currency, timezone,
        // tax rate and invoice prefix are NULL until it overrides them, and
        // resolve through this row — so editing here moves every clinic in the
        // market that never set its own. That is the right behaviour for a tax
        // change, and it is why invoices snapshot their rate at issue: nothing
        // already billed is re-rated, only what is billed next.
        $this->ok([
            'country' => $result['after'],
            'note'    => 'Clinics in this market that have not overridden a setting follow it '
                       . 'from now on. Invoices already issued keep the rate they were issued at.',
        ]);
    }

    // ---------------------------------------------------------------

    /**
     * The deployment's own settings (§21).
     *
     * Each one comes back with its label, type and allowed values, so the panel
     * renders the form from this rather than keeping its own copy of the list.
     * Add a setting in PlatformSettings and it appears on the screen.
     *
     * The payment section also reports whether credentials are present. It
     * never returns them: choosing the gateway is a setting, and the key that
     * proves the account is yours is not.
     */
    public function settings(Request $request): never
    {
        $gateway = PaymentGateways::status();

        $this->ok([
            'settings' => PlatformSettings::describe(),
            'payment'  => [
                'gateway'              => $gateway['gateway'],
                'configured'           => $gateway['configured'],
                'mode'                 => $gateway['mode'],
                'reason'               => $gateway['reason'],
                'credentials_location' => 'backend/.env (PAYMENT_CLIENT_ID, PAYMENT_SECRET_KEY)',
            ],
        ]);
    }

    public function updateSettings(Request $request): never
    {
        $values = $request->body['settings'] ?? null;
        if (!is_array($values) || $values === []) {
            throw new ValidationException(
                ['settings' => ['Send a settings object with at least one key.']]
            );
        }

        $before  = PlatformSettings::describe();
        $written = PlatformSettings::put($values, $request->userId());

        if ($written === []) {
            throw new ValidationException(
                ['settings' => ['None of those are settings this version knows about.']]
            );
        }

        // Worth auditing in full: one of these decides whether real cards are
        // charged, and "who turned live mode on" is a question that gets asked.
        (new AuditService())->log(
            $request, 'update', 'platform_settings', 0,
            array_column($before, 'value', 'key'),
            array_intersect_key($values, array_flip($written)),
        );

        $this->ok([
            'settings' => PlatformSettings::describe(),
            'updated'  => $written,
        ]);
    }

    // ---------------------------------------------------------------

    /**
     * @return array{page:int, per_page:int, total:int, last_page:int}
     */
    private function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'last_page' => (int) max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Plan fields, with NULL meaning unlimited on every ceiling.
     *
     * @return array<string,mixed>
     */
    private function planInput(Request $request, bool $creating): array
    {
        $rules = [
            'name'          => ($creating ? 'required' : 'nullable') . '|string|max:100',
            'description'   => 'nullable|string|max:500',
            'price_monthly' => 'nullable|numeric|min:0',
            'price_yearly'  => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'is_active'     => 'nullable|boolean',
            'sort_order'    => 'nullable|integer|between:0,9999',
        ];
        if ($creating) {
            $rules['slug'] = 'required|string|max:40';
        }

        $data = $this->validate($request, $rules);

        $limits = [
            'max_doctors', 'max_staff', 'max_patients', 'max_storage_mb',
            'max_invoices_month', 'max_appointments_month', 'max_ai_calls_month',
        ];

        $out = [];
        foreach ($limits as $limit) {
            // Absent on an update means "leave it"; explicitly null means
            // unlimited. Those are different requests and must stay different.
            if (!$creating && !array_key_exists($limit, $request->body)) {
                continue;
            }
            $value = $request->body[$limit] ?? null;
            $out[$limit] = ($value === null || $value === '') ? null : max(0, (int) $value);
        }

        if ($creating || array_key_exists('features', $request->body)) {
            $features = $request->body['features'] ?? [];
            $out['features'] = json_encode(is_array($features) ? $features : []);
        }

        foreach ($data as $key => $value) {
            if ($creating || array_key_exists($key, $request->body)) {
                $out[$key] = $value;
            }
        }

        if ($creating) {
            $out['slug']          = strtolower(trim((string) $out['slug']));
            $out['description']   ??= null;
            $out['price_monthly'] ??= 0;
            $out['price_yearly']  ??= null;
            $out['currency_code'] = strtoupper((string) ($out['currency_code'] ?? 'USD'));
            $out['is_active']     = (int) ($out['is_active'] ?? 1);
            $out['sort_order']    = (int) ($out['sort_order'] ?? 0);

            foreach ($limits as $limit) {
                $out[$limit] ??= null;
            }
        }

        if (isset($out['is_active'])) {
            $out['is_active'] = (int) $out['is_active'];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function countryInput(Request $request): array
    {
        $data = $this->validate($request, [
            'code'             => 'required|string|size:2',
            'name'             => 'required|string|max:100',
            'currency_code'    => 'required|string|size:3',
            'currency_symbol'  => 'nullable|string|max:8',
            'timezone'         => 'required|string|max:64',
            'date_format'      => 'nullable|string|max:20',
            'default_tax_rate' => 'nullable|numeric|between:0,1',
            'invoice_prefix'   => 'nullable|string|max:16',
            'is_active'        => 'nullable|boolean',
        ]);

        return [
            'code'             => strtoupper((string) $data['code']),
            'name'             => $data['name'],
            'currency_code'    => strtoupper((string) $data['currency_code']),
            // The column is NOT NULL, and a currency always has *some* mark —
            // falling back to the code prints "SGD 120" rather than failing.
            'currency_symbol'  => $data['currency_symbol'] ?: strtoupper((string) $data['currency_code']),
            'timezone'         => $data['timezone'],
            'date_format'      => $data['date_format'] ?? 'd M Y',
            'default_tax_rate' => $data['default_tax_rate'] ?? 0,
            'invoice_prefix'   => $data['invoice_prefix'] ?? 'INV',
            'is_active'        => (int) ($data['is_active'] ?? 1),
        ];
    }
}
