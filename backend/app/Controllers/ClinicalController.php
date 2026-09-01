<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\ClinicalRepository;
use App\Services\AuditService;
use App\Services\PatientService;

/**
 * Lab orders/results and medical documents.
 *
 * Documents follow §19: the bytes go to storage, only metadata and an access
 * rule go in the database.
 */
final class ClinicalController extends Controller
{
    private function repo(Request $request): ClinicalRepository
    {
        return (new ClinicalRepository())->forOrganization($request->organizationId());
    }

    // ---------------- labs ----------------

    public function labOrders(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'patient_id' => 'nullable|integer',
            'status'     => 'nullable|in:ordered,sample_collected,processing,completed,cancelled',
        ]);

        $this->ok(['lab_orders' => $this->repo($request)->labOrders($filters)]);
    }

    /** Body: { results: [{test_name, value, unit, reference_range, flag, comments}, ...] } */
    public function recordLabResults(Request $request): never
    {
        $results = $request->body['results'] ?? null;
        if (!is_array($results) || $results === []) {
            throw new ValidationException(['results' => ['Add at least one result.']]);
        }

        $errors = [];
        foreach (array_values($results) as $i => $r) {
            if (!is_array($r) || trim((string) ($r['test_name'] ?? '')) === '') {
                $errors[] = 'Result ' . ($i + 1) . ' needs a test name.';
            }
            if (isset($r['flag']) && !in_array($r['flag'], ['normal', 'low', 'high', 'critical'], true)) {
                $errors[] = 'Result ' . ($i + 1) . ': flag must be normal, low, high or critical.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException(['results' => $errors]);
        }

        $orderId = $request->intParam('id');
        $repo    = $this->repo($request);
        $order   = $repo->findLabOrder($orderId);

        if ($order === null) {
            throw new NotFoundException('Lab order not found');
        }
        if ($order['status'] === 'completed') {
            throw new \App\Core\ConflictException('Results for this order have already been recorded.');
        }

        $repo->recordLabResults($orderId, (int) $order['patient_id'], $results, $request->userId());

        (new AuditService())->log(
            $request, 'update', 'lab_order', $orderId, null,
            ['status' => 'completed', 'results' => count($results)],
            (int) $order['patient_id'],
        );

        $this->ok(['lab_orders' => $repo->labOrders(['patient_id' => (int) $order['patient_id']])]);
    }

    // ---------------- documents ----------------

    public function documents(Request $request): never
    {
        $patientId = $request->intParam('patientId');
        PatientService::for($request)->assertMayAccess($request, $patientId);

        // A patient sees only what the clinic marked patient_visible.
        $patientVisibleOnly = $request->roleSlug() === 'patient';

        $this->ok(['documents' => $this->repo($request)->documents($patientId, $patientVisibleOnly)]);
    }

    public function upload(Request $request): never
    {
        $patientId = $request->intParam('patientId');

        $file = $request->file('file');
        if ($file === null) {
            throw new ValidationException(['file' => ['No file uploaded (expected multipart field `file`).']]);
        }

        $config = $GLOBALS['__config']['storage'];
        $maxBytes = ((int) $config['max_upload_mb']) * 1024 * 1024;

        if ($file['size'] > $maxBytes) {
            throw new ValidationException(
                ['file' => ["File must be {$config['max_upload_mb']}MB or smaller."]]
            );
        }

        // §22: storage is a plan limit, counted in whole megabytes. Checked
        // against the size of THIS file, so an upload that would cross the
        // ceiling is refused rather than accepted and then over quota.
        \App\Services\SubscriptionService::for($request)
            ->assertWithin('storage', (int) ceil($file['size'] / 1048576));

        // Allow-list, not deny-list: anything not named here cannot be stored.
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/dicom'     => 'dcm',
        ];

        $detected = function_exists('mime_content_type')
            ? (mime_content_type($file['tmp']) ?: $file['type'])
            : $file['type'];

        if (!isset($allowed[$detected])) {
            throw new ValidationException(
                ['file' => ['Only PDF and image files are accepted. Detected: ' . $detected]]
            );
        }

        $data = $this->validate($request, [
            'title'        => 'required|string|max:255',
            'category'     => 'nullable|in:prescription,lab_report,imaging,invoice,insurance,discharge,consent,other',
            'encounter_id' => 'nullable|integer',
            'visibility'   => 'nullable|in:clinic_only,patient_visible',
        ]);

        // Tenant-partitioned path; the filename is generated, never taken from
        // the upload, so a crafted name cannot escape the directory.
        $relative = sprintf(
            'documents/%d/%s.%s',
            (int) $request->organizationId(),
            bin2hex(random_bytes(16)),
            $allowed[$detected],
        );
        $absolute = rtrim((string) $config['root'], '/\\') . '/' . $relative;

        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0775, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Could not create the storage directory.');
        }
        if (!move_uploaded_file($file['tmp'], $absolute) && !rename($file['tmp'], $absolute)) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }

        $document = $this->repo($request)->addDocument([
            'patient_id'      => $patientId,
            'encounter_id'    => $data['encounter_id'] ?? null,
            'category'        => $data['category'] ?? 'other',
            'title'           => $data['title'],
            'storage_path'    => $relative,
            'mime_type'       => $detected,
            'size_bytes'      => $file['size'],
            'checksum_sha256' => hash_file('sha256', $absolute) ?: null,
            'visibility'      => $data['visibility'] ?? 'clinic_only',
        ], $request->userId());

        (new AuditService())->log(
            $request, 'create', 'medical_document', (int) $document['id'], null,
            ['title' => $document['title'], 'category' => $document['category']],
            $patientId,
        );

        $this->created(['document' => $document]);
    }

    /** Streams the bytes. Every download is audited — §16 counts access, not just writes. */
    public function download(Request $request): never
    {
        $document = $this->repo($request)->findDocument($request->intParam('id'));
        if ($document === null) {
            throw new NotFoundException('Document not found');
        }

        PatientService::for($request)->assertMayAccess($request, (int) $document['patient_id']);

        if ($request->roleSlug() === 'patient' && $document['visibility'] !== 'patient_visible') {
            throw new NotFoundException('Document not found');
        }

        $config   = $GLOBALS['__config']['storage'];
        $absolute = rtrim((string) $config['root'], '/\\') . '/' . $document['storage_path'];

        if (!is_file($absolute)) {
            throw new NotFoundException('The stored file is missing.');
        }

        (new AuditService())->log(
            $request, 'view', 'medical_document', (int) $document['id'], null, null,
            (int) $document['patient_id'],
        );

        header('Content-Type: ' . $document['mime_type']);
        header('Content-Length: ' . (string) filesize($absolute));
        header(
            'Content-Disposition: inline; filename="'
            . str_replace('"', '', (string) $document['title']) . '"'
        );
        readfile($absolute);
        exit;
    }
}
