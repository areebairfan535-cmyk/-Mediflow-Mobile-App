<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * audit_logs (§16). Append-only: there is deliberately no update() or
 * delete() path exposed for these rows. An audit trail that can be edited
 * is not an audit trail.
 */
final class AuditLogRepository extends Repository
{
    protected string $table        = 'audit_logs';
    protected bool   $tenantScoped = true;
    protected bool   $timestamps   = false;

    protected array $fillable = [
        'organization_id', 'user_id', 'action', 'resource_type', 'resource_id',
        'patient_id', 'old_values', 'new_values', 'route', 'method',
        'ip_address', 'user_agent', 'request_id', 'created_at',
    ];

    /**
     * Insert without touching find() afterwards.
     *
     * Auditing must never break the request it is recording, and it must not
     * be rolled back with the business transaction it describes — a failed
     * write attempt is exactly what an auditor needs to see. So this uses its
     * own statement and swallows storage errors after logging them to disk.
     *
     * @param array<string,mixed> $attributes
     */
    public function record(array $attributes): void
    {
        $data = [
            'organization_id' => $attributes['organization_id'] ?? null,
            'user_id'         => $attributes['user_id']         ?? null,
            'action'          => $attributes['action'],
            'resource_type'   => $attributes['resource_type'],
            'resource_id'     => $attributes['resource_id']     ?? null,
            'patient_id'      => $attributes['patient_id']      ?? null,
            'old_values'      => isset($attributes['old_values'])
                ? json_encode($attributes['old_values'], JSON_UNESCAPED_UNICODE)
                : null,
            'new_values'      => isset($attributes['new_values'])
                ? json_encode($attributes['new_values'], JSON_UNESCAPED_UNICODE)
                : null,
            'route'           => $attributes['route']      ?? null,
            'method'          => $attributes['method']     ?? null,
            'ip_address'      => $attributes['ip_address'] ?? null,
            'user_agent'      => $attributes['user_agent'] ?? null,
            'request_id'      => $attributes['request_id'] ?? null,
            'created_at'      => $attributes['created_at'] ?? now(),
        ];

        try {
            Database::statement(
                'INSERT INTO audit_logs
                    (organization_id, user_id, action, resource_type, resource_id,
                     patient_id, old_values, new_values, route, method,
                     ip_address, user_agent, request_id, created_at)
                 VALUES
                    (:organization_id, :user_id, :action, :resource_type, :resource_id,
                     :patient_id, :old_values, :new_values, :route, :method,
                     :ip_address, :user_agent, :request_id, :created_at)',
                $data,
            );
        } catch (\Throwable $e) {
            error_log('[audit] failed to persist audit row: ' . $e->getMessage());
        }
    }

    /**
     * Trail for one resource, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forResource(int $organizationId, string $type, int $id): array
    {
        return Database::select(
            'SELECT a.*, u.name AS user_name, u.email AS user_email
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.organization_id = :org
                AND a.resource_type   = :type
                AND a.resource_id     = :id
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT 500',
            ['org' => $organizationId, 'type' => $type, 'id' => $id],
        );
    }

    /**
     * "Who accessed this patient's record?" — the question §16 exists to answer.
     *
     * @return list<array<string,mixed>>
     */
    public function forPatient(int $organizationId, int $patientId): array
    {
        return Database::select(
            'SELECT a.*, u.name AS user_name, u.email AS user_email
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.organization_id = :org
                AND a.patient_id      = :pid
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT 500',
            ['org' => $organizationId, 'pid' => $patientId],
        );
    }

    /**
     * Filtered, paginated audit search for the admin UI.
     *
     * @param array<string,mixed> $filters
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function search(int $organizationId, array $filters, int $page, int $perPage): array
    {
        // Tenant rows, plus the platform-level rows that belong to this
        // tenant's own people.
        //
        // Authentication happens BEFORE a tenant is known — a login has no
        // organization yet, and a failed login may have no user at all — so
        // those rows are written with organization_id NULL rather than being
        // guessed at. A clinic still needs to see "who signed in", so the
        // search widens to NULL-org rows whose actor is a member here.
        // It deliberately does not widen to NULL-org rows with no user, which
        // belong only to the platform-level trail.
        $where = [
            '(a.organization_id = :org
              OR (a.organization_id IS NULL
                  AND a.user_id IS NOT NULL
                  AND a.user_id IN (SELECT ou.user_id
                                      FROM organization_users ou
                                     WHERE ou.organization_id = :org)))',
        ];
        $bindings = ['org' => $organizationId];

        if (!empty($filters['user_id'])) {
            $where[]            = 'a.user_id = :uid';
            $bindings['uid']    = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[]            = 'a.action = :action';
            $bindings['action'] = $filters['action'];
        }
        if (!empty($filters['resource_type'])) {
            $where[]          = 'a.resource_type = :rtype';
            $bindings['rtype'] = $filters['resource_type'];
        }
        if (!empty($filters['from'])) {
            $where[]         = 'a.created_at >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[]       = 'a.created_at <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }

        $clause = ' WHERE ' . implode(' AND ', $where);

        $total = (int) (Database::selectOne(
            'SELECT COUNT(*) AS c FROM audit_logs a' . $clause,
            $bindings,
        )['c'] ?? 0);

        $perPage = max(1, min(100, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;

        $rows = Database::select(
            'SELECT a.*, u.name AS user_name, u.email AS user_email
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id'
            . $clause
            . ' ORDER BY a.created_at DESC, a.id DESC
                LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings,
        );

        return [
            'data' => $rows,
            'meta' => [
                'page'      => max(1, $page),
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }
}
