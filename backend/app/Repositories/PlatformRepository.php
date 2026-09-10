<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;

/**
 * The super admin panel's reads (§21), across every tenant.
 *
 * Deliberately not tenant scoped, and deliberately read-only: this is the one
 * place in the codebase that looks at all clinics at once, so keeping it in a
 * single reviewable class is what makes the tenancy guarantee checkable. There
 * is no create/update here — the panel writes through the ordinary
 * repositories and services, which keeps every rule those enforce in play.
 *
 * The SQL below used to live in PlatformController. §18 says controllers do
 * not write SQL; a repository is the layer that may.
 */
final class PlatformRepository extends Repository
{
    // Aggregates span tables, so there is no single table to bind to. The
    // base class only needs one for find()/create()/update(), none of which
    // this repository offers.
    protected string $table        = 'organizations';
    protected bool   $tenantScoped = false;

    /**
     * The dashboard's headline counters (§21).
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $row = $this->query(
            'SELECT
               (SELECT COUNT(*) FROM organizations WHERE status = \'active\')      AS active_organizations,
               (SELECT COUNT(*) FROM organizations)                               AS total_organizations,
               (SELECT COUNT(*) FROM users WHERE status = \'active\')             AS active_users,
               -- §21 asks for ACTIVE doctors. The doctors row outlives the
               -- person leaving, so counting the table counts everyone who
               -- ever worked here; the account status is what says who still
               -- does. Both numbers are returned — the panel shows the live
               -- one and keeps the total as context.
               (SELECT COUNT(*) FROM doctors d
                  JOIN users du ON du.id = d.user_id
                 WHERE du.status = \'active\')                                    AS doctors,
               (SELECT COUNT(*) FROM doctors)                                     AS doctors_total,
               (SELECT COUNT(*) FROM patients WHERE status = \'active\')          AS patients,
               (SELECT COUNT(*) FROM appointments)                                AS appointments,
               (SELECT COUNT(*) FROM invoices)                                    AS invoices,
               (SELECT COUNT(*) FROM claims)                                      AS claims',
        )[0] ?? [];

        return array_map('intval', $row);
    }

    /**
     * The three money figures, over ONE set of invoices.
     *
     * They used to be taken over three different sets. `overdue` was in
     * outstanding but missing from billed — which is backwards, since an
     * invoice has to have been billed before it can fall overdue. On the demo
     * data that hid 2.7 million from the revenue figure and left the panel
     * showing more owed than had ever been charged.
     *
     * So: billed is every invoice that became a real document and was not
     * cancelled, collected is what came in against those, and outstanding is
     * the difference. Anyone can now check the screen by subtracting, which is
     * the first thing a person does with three numbers like these.
     *
     * Drafts are excluded because they are not bills yet, and cancelled ones
     * because they are bills that were withdrawn.
     *
     * @return array<string,mixed>
     */
    public function money(): array
    {
        return $this->query(
            'SELECT
               COALESCE(SUM(grand_total), 0)               AS billed_total,
               COALESCE(SUM(paid_total), 0)                AS collected_total,
               COALESCE(SUM(grand_total - paid_total), 0)  AS outstanding_total
               FROM invoices
              WHERE status IN (\'issued\', \'partially_paid\', \'paid\', \'overdue\')',
        )[0] ?? [];
    }

    public function failedPaymentCount(): int
    {
        return (int) ($this->query(
            'SELECT COUNT(*) AS c FROM payments WHERE status = \'failed\'',
        )[0]['c'] ?? 0);
    }

    /**
     * How many clinics sit on each plan, split by subscription status.
     *
     * @return list<array<string,mixed>>
     */
    public function subscriptionBreakdown(): array
    {
        return $this->query(
            'SELECT p.name AS plan, s.status, COUNT(*) AS organizations
               FROM subscriptions s
               JOIN plans p ON p.id = s.plan_id
              GROUP BY p.name, s.status
              ORDER BY p.name',
        );
    }

    // ---------------------------------------------------------------
    // Organization directory
    // ---------------------------------------------------------------

    /**
     * One page of the clinic directory, with the counts the panel lists.
     *
     * @param array{status?:string,search?:string} $filters
     * @return array{data: list<array<string,mixed>>, total: int}
     */
    public function organizations(array $filters, int $page, int $perPage): array
    {
        [$clause, $bindings] = $this->organizationFilter($filters);

        $total = (int) ($this->query(
            'SELECT COUNT(*) AS c FROM organizations o' . $clause,
            $bindings,
        )[0]['c'] ?? 0);

        // LIMIT/OFFSET are cast integers, not bindings: MySQL will not take a
        // placeholder there under emulated prepares.
        $rows = $this->query(
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
            . ' ORDER BY o.created_at DESC, o.id DESC
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $bindings,
        );

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * @param array{status?:string,search?:string} $filters
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function organizationFilter(array $filters): array
    {
        $where    = [];
        $bindings = [];

        if (isset($filters['status'])) {
            $where[]            = 'o.status = :status';
            $bindings['status'] = $filters['status'];
        }
        if (isset($filters['search'])) {
            $where[]       = '(o.name LIKE :q OR o.slug LIKE :q OR o.city LIKE :q)';
            $bindings['q'] = '%' . $filters['search'] . '%';
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $bindings];
    }

    // ---------------------------------------------------------------
    // Platform-wide audit trail
    // ---------------------------------------------------------------

    /**
     * Every audit row, cross-tenant (§16, §21).
     *
     * `GET /audit-logs` is tenant scoped and widens only to NULL-org rows whose
     * actor is a member of that clinic — so platform staff editing plans and
     * markets were being recorded and then readable by nobody. This is the
     * other half of that.
     *
     * @param array{organization_id?:int,user_id?:int,action?:string,resource_type?:string} $filters
     * @return array{data: list<array<string,mixed>>, total: int}
     */
    public function auditLogs(array $filters, int $page, int $perPage): array
    {
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

        $sql = implode(' AND ', $where);

        $total = (int) ($this->query(
            "SELECT COUNT(*) AS c FROM audit_logs a WHERE $sql",
            $bindings,
        )[0]['c'] ?? 0);

        $rows = $this->query(
            "SELECT a.*, u.name AS user_name, u.email AS user_email, o.name AS organization_name
               FROM audit_logs a
               LEFT JOIN users u         ON u.id = a.user_id
               LEFT JOIN organizations o ON o.id = a.organization_id
              WHERE $sql
              ORDER BY a.id DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $bindings,
        );

        return ['data' => $rows, 'total' => $total];
    }
}
