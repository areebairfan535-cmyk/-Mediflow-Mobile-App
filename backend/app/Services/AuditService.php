<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Repositories\AuditLogRepository;

/**
 * Audit trail writer (§16).
 *
 * Every call takes the Request so the actor, tenant, route, IP and request-id
 * are captured without the caller assembling them. Services call this at the
 * point of the change, where the old and new values are both still in hand.
 *
 * Values are redacted before storage: an audit row must record that a
 * password or token changed, never what it changed to.
 */
final class AuditService
{
    /** Keys whose values are never written to the audit trail. */
    private const REDACTED = [
        'password', 'password_confirmation', 'token', 'access_token',
        'refresh_token', 'token_hash', 'secret', 'api_key', 'card_number', 'cvv',
    ];

    public function __construct(
        private readonly AuditLogRepository $logs = new AuditLogRepository(),
    ) {
    }

    /**
     * Record one action.
     *
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function log(
        Request $request,
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        ?array $old = null,
        ?array $new = null,
        ?int $patientId = null,
    ): void {
        $this->logs->record([
            'organization_id' => $request->organizationId(),
            'user_id'         => $request->userId(),
            'action'          => $action,
            'resource_type'   => $resourceType,
            'resource_id'     => $resourceId,
            'patient_id'      => $patientId,
            'old_values'      => $old !== null ? $this->redact($old) : null,
            'new_values'      => $new !== null ? $this->redact($new) : null,
            'route'           => $request->path,
            'method'          => $request->method,
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'request_id'      => $request->requestId,
        ]);
    }

    /**
     * Record an action against a named organization rather than the request's.
     *
     * Platform routes (§21) carry no tenant, so `log()` would file "your plan
     * was changed" or "your clinic was suspended" under organization NULL —
     * where the clinic it happened to can never see it. Those two facts belong
     * in the affected clinic's own trail, whoever pressed the button.
     */
    public function logForOrganization(
        Request $request,
        int $organizationId,
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        ?array $old = null,
        ?array $new = null,
    ): void {
        $this->logs->record([
            'organization_id' => $organizationId,
            'user_id'         => $request->userId(),
            'action'          => $action,
            'resource_type'   => $resourceType,
            'resource_id'     => $resourceId,
            'patient_id'      => null,
            'old_values'      => $old !== null ? $this->redact($old) : null,
            'new_values'      => $new !== null ? $this->redact($new) : null,
            'route'           => $request->path,
            'method'          => $request->method,
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'request_id'      => $request->requestId,
        ]);
    }

    /**
     * Record only what actually changed. An audit row saying "these 3 fields
     * changed" is far more useful than one echoing all 40 columns.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function logUpdate(
        Request $request,
        string $resourceType,
        int $resourceId,
        array $before,
        array $after,
        ?int $patientId = null,
    ): void {
        $changedOld = [];
        $changedNew = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;
            // Loose compare: DB round-trips turn 1 into '1' and that is not a change.
            if ((string) ($previous ?? '') !== (string) ($value ?? '')) {
                $changedOld[$key] = $previous;
                $changedNew[$key] = $value;
            }
        }

        if ($changedNew === []) {
            return;   // nothing changed; do not write a noise row
        }

        $this->log(
            $request,
            'update',
            $resourceType,
            $resourceId,
            $changedOld,
            $changedNew,
            $patientId,
        );
    }

    /**
     * Authentication events, which happen before a tenant exists.
     * $userId is passed explicitly because a failed login has no Request user.
     */
    public function logAuth(
        Request $request,
        string $action,
        ?int $userId,
        ?string $detail = null,
    ): void {
        $this->logs->record([
            'organization_id' => null,
            'user_id'         => $userId,
            'action'          => $action,          // login, login_failed, logout, refresh
            'resource_type'   => 'auth',
            'resource_id'     => $userId,
            'new_values'      => $detail !== null
                ? json_encode(['detail' => $detail], JSON_UNESCAPED_UNICODE)
                : null,
            'route'           => $request->path,
            'method'          => $request->method,
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'request_id'      => $request->requestId,
        ]);
    }

    /**
     * Reading a patient record is itself an auditable event under §16 —
     * "record ACCESS to sensitive patient records", not just writes.
     */
    public function logPatientAccess(
        Request $request,
        int $patientId,
        string $resourceType = 'patient',
        ?int $resourceId = null,
    ): void {
        $this->log($request, 'view', $resourceType, $resourceId ?? $patientId, null, null, $patientId);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }
        return $values;
    }
}
