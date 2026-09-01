<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\OrganizationRepository;

/**
 * Super Admin Panel (§21) — the only cross-tenant surface in the API.
 *
 * These routes carry 'platform' instead of 'tenant', and are the sole place
 * withoutTenantScope() is reachable over HTTP. Keeping that concentrated in
 * one controller is what makes the tenancy guarantee reviewable.
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
        // Counters the panel needs per §21. Each is a scalar aggregate; the
        // dashboard is read-mostly so plain COUNTs are fine at this scale.
        $counts = Database::selectOne(
            'SELECT
               (SELECT COUNT(*) FROM organizations WHERE status = \'active\')      AS active_organizations,
               (SELECT COUNT(*) FROM organizations)                               AS total_organizations,
               (SELECT COUNT(*) FROM users WHERE status = \'active\')             AS active_users,
               (SELECT COUNT(*) FROM doctors)                                     AS doctors,
               (SELECT COUNT(*) FROM patients WHERE status = \'active\')          AS patients,
               (SELECT COUNT(*) FROM appointments)                                AS appointments,
               (SELECT COUNT(*) FROM invoices)                                    AS invoices,
               (SELECT COUNT(*) FROM claims)                                      AS claims',
        ) ?? [];

        $money = Database::selectOne(
            'SELECT
               COALESCE(SUM(CASE WHEN status IN (\'issued\',\'partially_paid\',\'paid\')
                                 THEN grand_total ELSE 0 END), 0) AS billed_total,
               COALESCE(SUM(paid_total), 0)                        AS collected_total,
               COALESCE(SUM(CASE WHEN status IN (\'issued\',\'partially_paid\',\'overdue\')
                                 THEN grand_total - paid_total ELSE 0 END), 0) AS outstanding_total
               FROM invoices',
        ) ?? [];

        $failedPayments = (int) (Database::selectOne(
            'SELECT COUNT(*) AS c FROM payments WHERE status = \'failed\'',
        )['c'] ?? 0);

        $subscriptions = Database::select(
            'SELECT p.name AS plan, s.status, COUNT(*) AS organizations
               FROM subscriptions s
               JOIN plans p ON p.id = s.plan_id
              GROUP BY p.name, s.status
              ORDER BY p.name',
        );

        $this->ok([
            'counts'         => array_map('intval', $counts),
            'money'          => array_map('money', $money),
            'failed_payments' => $failedPayments,
            'subscriptions'  => $subscriptions,
        ]);
    }

    public function organizations(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'status' => 'nullable|in:active,suspended,cancelled',
            'search' => 'nullable|string|max:120',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $where    = [];
        $bindings = [];
        if (isset($filters['status'])) {
            $where[]              = 'o.status = :status';
            $bindings['status']   = $filters['status'];
        }
        if (isset($filters['search'])) {
            $where[]            = '(o.name LIKE :q OR o.slug LIKE :q OR o.city LIKE :q)';
            $bindings['q']      = '%' . $filters['search'] . '%';
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) (Database::selectOne(
            'SELECT COUNT(*) AS c FROM organizations o' . $clause,
            $bindings,
        )['c'] ?? 0);

        $offset = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT o.id, o.name, o.slug, o.city, o.status, o.created_at,
                    c.code AS country_code,
                    COALESCE(o.currency_code, c.currency_code) AS currency_code,
                    (SELECT COUNT(*) FROM organization_users ou
                      WHERE ou.organization_id = o.id AND ou.status = \'active\') AS members,
                    (SELECT COUNT(*) FROM patients pt
                      WHERE pt.organization_id = o.id) AS patients,
                    (SELECT p.name FROM subscriptions s
                       JOIN plans p ON p.id = s.plan_id
                      WHERE s.organization_id = o.id
                      ORDER BY s.id DESC LIMIT 1) AS plan
               FROM organizations o
               JOIN countries c ON c.id = o.country_id'
            . $clause
            . ' ORDER BY o.created_at DESC
                LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings,
        );

        $this->ok($rows, [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'last_page' => (int) max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function showOrganization(Request $request): never
    {
        $organizations = new OrganizationRepository();
        $id            = $request->intParam('id');

        $organization = $organizations->withoutTenantScope()->findOrFail($id, 'Organization');

        $this->ok([
            'organization' => $organizations->settings($id),
            'members'      => (new \App\Services\RbacService())->members($id),
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
        (new \App\Services\AuditService())->logForOrganization(
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
        $plans = Database::select('SELECT * FROM plans ORDER BY sort_order, price_monthly');

        // Sold-to counts, because "may we retire this plan" is unanswerable
        // without them and it is the first thing anyone asks.
        foreach ($plans as $i => $plan) {
            $plans[$i]['features'] = is_string($plan['features'] ?? null)
                ? (json_decode($plan['features'], true) ?: [])
                : ($plan['features'] ?? []);

            $plans[$i]['organizations'] = (int) (Database::selectOne(
                'SELECT COUNT(*) AS c FROM subscriptions WHERE plan_id = :id',
                ['id' => (int) $plan['id']],
            )['c'] ?? 0);
        }

        $this->ok(['plans' => $plans]);
    }

    public function storePlan(Request $request): never
    {
        $data = $this->planInput($request, true);

        if (Database::selectOne('SELECT id FROM plans WHERE slug = :slug', ['slug' => $data['slug']])) {
            throw new \App\Core\ConflictException('A plan with this slug already exists.');
        }

        Database::statement(
            'INSERT INTO plans
                (slug, name, description, price_monthly, price_yearly, currency_code,
                 max_doctors, max_staff, max_patients, max_storage_mb,
                 max_invoices_month, max_appointments_month, max_ai_calls_month,
                 features, is_active, sort_order, created_at, updated_at)
             VALUES (:slug, :name, :description, :price_monthly, :price_yearly, :currency_code,
                     :max_doctors, :max_staff, :max_patients, :max_storage_mb,
                     :max_invoices_month, :max_appointments_month, :max_ai_calls_month,
                     :features, :is_active, :sort_order, :now, :now)',
            $data + ['now' => now()],
        );

        $plan = Database::selectOne('SELECT * FROM plans WHERE slug = :slug', ['slug' => $data['slug']]);

        (new \App\Services\AuditService())->log(
            $request, 'create', 'plan', (int) $plan['id'], null, ['slug' => $data['slug']],
        );

        $this->created(['plan' => $plan]);
    }

    public function updatePlan(Request $request): never
    {
        $id   = $request->intParam('id');
        $plan = Database::selectOne('SELECT * FROM plans WHERE id = :id', ['id' => $id]);

        if ($plan === null) {
            throw new \App\Core\NotFoundException('Plan not found');
        }

        $data = $this->planInput($request, false);

        // The slug is the identity clients key off; renaming it would silently
        // repoint anything that stored it. Name and prices are editable.
        unset($data['slug']);

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "$column = :$column";
        }
        $sets[] = 'updated_at = :now';

        Database::statement(
            'UPDATE plans SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $data + ['now' => now(), 'id' => $id],
        );

        $updated = Database::selectOne('SELECT * FROM plans WHERE id = :id', ['id' => $id]);

        (new \App\Services\AuditService())->log(
            $request, 'update', 'plan', $id,
            ['name' => $plan['name'], 'price_monthly' => $plan['price_monthly']],
            ['name' => $updated['name'], 'price_monthly' => $updated['price_monthly']],
        );

        $this->ok(['plan' => $updated]);
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

        $result = (new \App\Services\SubscriptionService($id, $request->userId()))
            ->changePlan((int) $data['plan_id'], $id);

        (new \App\Services\AuditService())->logForOrganization(
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

        $where    = ['1 = 1'];
        $bindings = [];

        foreach (['organization_id' => 'org', 'user_id' => 'uid'] as $field => $bind) {
            if (!empty($filters[$field])) {
                $where[]         = "a.$field = :$bind";
                $bindings[$bind] = (int) $filters[$field];
            }
        }
        foreach (['action', 'resource_type'] as $field) {
            if (!empty($filters[$field])) {
                $where[]          = "a.$field = :$field";
                $bindings[$field] = $filters[$field];
            }
        }

        $sql   = implode(' AND ', $where);
        $total = (int) (Database::selectOne(
            "SELECT COUNT(*) AS c FROM audit_logs a WHERE $sql",
            $bindings,
        )['c'] ?? 0);

        $rows = Database::select(
            "SELECT a.*, u.name AS user_name, u.email AS user_email, o.name AS organization_name
               FROM audit_logs a
               LEFT JOIN users u         ON u.id = a.user_id
               LEFT JOIN organizations o ON o.id = a.organization_id
              WHERE $sql
              ORDER BY a.id DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $bindings,
        );

        $this->ok($rows, [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'last_page' => (int) max(1, (int) ceil($total / $perPage)),
        ]);
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
        $countries = Database::select('SELECT * FROM countries ORDER BY name');

        foreach ($countries as $i => $country) {
            $countries[$i]['organizations'] = (int) (Database::selectOne(
                'SELECT COUNT(*) AS c FROM organizations WHERE country_id = :id',
                ['id' => (int) $country['id']],
            )['c'] ?? 0);
        }

        $this->ok(['countries' => $countries]);
    }

    public function storeCountry(Request $request): never
    {
        $data = $this->countryInput($request);

        if (Database::selectOne('SELECT id FROM countries WHERE code = :code', ['code' => $data['code']])) {
            throw new \App\Core\ConflictException('That country is already configured.');
        }

        Database::statement(
            'INSERT INTO countries
                (code, name, currency_code, currency_symbol, timezone, date_format,
                 default_tax_rate, invoice_prefix, is_active, created_at, updated_at)
             VALUES (:code, :name, :currency_code, :currency_symbol, :timezone, :date_format,
                     :default_tax_rate, :invoice_prefix, :is_active, :now, :now)',
            $data + ['now' => now()],
        );

        $country = Database::selectOne('SELECT * FROM countries WHERE code = :code', ['code' => $data['code']]);

        (new \App\Services\AuditService())->log(
            $request, 'create', 'country', (int) $country['id'], null, ['code' => $data['code']],
        );

        $this->created(['country' => $country]);
    }

    public function updateCountry(Request $request): never
    {
        $id      = $request->intParam('id');
        $country = Database::selectOne('SELECT * FROM countries WHERE id = :id', ['id' => $id]);

        if ($country === null) {
            throw new \App\Core\NotFoundException('Country not found');
        }

        $data = $this->countryInput($request);
        unset($data['code']);        // the code is the identity, same as a plan slug

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "$column = :$column";
        }
        $sets[] = 'updated_at = :now';

        Database::statement(
            'UPDATE countries SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $data + ['now' => now(), 'id' => $id],
        );

        $updated = Database::selectOne('SELECT * FROM countries WHERE id = :id', ['id' => $id]);

        (new \App\Services\AuditService())->log(
            $request, 'update', 'country', $id,
            ['default_tax_rate' => $country['default_tax_rate'], 'is_active' => $country['is_active']],
            ['default_tax_rate' => $updated['default_tax_rate'], 'is_active' => $updated['is_active']],
        );

        // Be exact about the blast radius. A clinic's own currency, timezone,
        // tax rate and invoice prefix are NULL until it overrides them, and
        // resolve through this row — so editing here moves every clinic in the
        // market that never set its own. That is the right behaviour for a tax
        // change, and it is why invoices snapshot their rate at issue: nothing
        // already billed is re-rated, only what is billed next.
        $this->ok([
            'country' => $updated,
            'note'    => 'Clinics in this market that have not overridden a setting follow it '
                       . 'from now on. Invoices already issued keep the rate they were issued at.',
        ]);
    }

    // ---------------------------------------------------------------

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
