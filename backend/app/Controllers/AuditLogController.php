<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Repositories\AuditLogRepository;

/**
 * Audit trail reads (§16).
 *
 * GET /api/v1/audit-logs                       — filtered search
 * GET /api/v1/audit-logs/patient/{patientId}   — "who accessed this record?"
 * GET /api/v1/audit-logs/{type}/{id}           — trail for one resource
 *
 * All three are tenant-scoped and gated on audit.view, which by default only
 * org_owner and org_admin hold.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'user_id'       => 'nullable|integer',
            'action'        => 'nullable|string|max:80',
            'resource_type' => 'nullable|string|max:60',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $result = (new AuditLogRepository())->search(
            (int) $request->organizationId(),
            $filters,
            $page,
            $perPage,
        );

        $this->ok($result['data'], $result['meta']);
    }

    public function forPatient(Request $request): never
    {
        $this->ok([
            'entries' => (new AuditLogRepository())->forPatient(
                (int) $request->organizationId(),
                $request->intParam('patientId'),
            ),
        ]);
    }

    public function forResource(Request $request): never
    {
        $this->ok([
            'entries' => (new AuditLogRepository())->forResource(
                (int) $request->organizationId(),
                (string) $request->param('type'),
                $request->intParam('id'),
            ),
        ]);
    }
}
