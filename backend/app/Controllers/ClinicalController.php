<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\ClinicalRepository;
use App\Services\AuditService;
use App\Services\NotificationService;
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

    /** One lab order and everything reported against it (§19). */
    public function showLabOrder(Request $request): never
    {
        $order = $this->repo($request)->findLabOrderDetailed($request->intParam('id'));

        if ($order === null) {
            throw new NotFoundException('Lab order not found');
        }

        (new AuditService())->logPatientAccess(
            $request, (int) $order['patient_id'], 'lab_order', (int) $order['id'],
        );

        $this->ok(['lab_order' => $order]);
    }

    /**
     * Results across orders (§19).
     *
     * The order list already carries each order's results, but it cannot
     * answer "has anything come back abnormal today" — an order holding one
     * critical value looks like every other completed order. `?flag=critical`
     * can.
     */
    public function labResults(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'patient_id' => 'nullable|integer',
            'flag'       => 'nullable|in:normal,low,high,critical',
            'from'       => 'nullable|date',
            'to'         => 'nullable|date',
        ]);

        $this->ok(['lab_results' => $this->repo($request)->labResults($filters)]);
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

        // Where it was done and what it cost — the clinic's own lab or one
        // the patient chose. Both optional: a result is a result.
        $labName = trim((string) ($request->body['lab_name'] ?? ''));
        $charge  = $request->body['total_charge'] ?? null;
        if ($charge !== null && $charge !== '' && (!is_numeric($charge) || (float) $charge < 0)) {
            throw new ValidationException(['total_charge' => ['The charge must be a number.']]);
        }
        $charge = ($charge === null || $charge === '') ? null : number_format((float) $charge, 2, '.', '');

        $repo->recordLabResults(
            $orderId, (int) $order['patient_id'], $results, $request->userId(),
            $labName === '' ? null : $labName, $charge,
        );

        (new AuditService())->log(
            $request, 'update', 'lab_order', $orderId,
            ['status' => $order['status']],
            ['status' => 'completed', 'results' => count($results)],
            (int) $order['patient_id'],
        );

        // §20 lab.result_ready. Results that sit in the system unannounced are
        // the reason people ring the clinic to ask whether they are back yet.
        // Outside the write, and swallowed if it fails: the results are
        // recorded either way, and a lost notification is not worth losing them.
        try {
            (new NotificationService($request->organizationId(), $request->userId()))->notifyPatient(
                (int) $order['patient_id'],
                'lab.result_ready',
                [
                    'order_no'     => $order['order_no'] ?? (string) $orderId,
                    'subject_type' => 'lab_order',
                    'subject_id'   => $orderId,
                ],
            );
        } catch (\Throwable $e) {
            error_log('[notify] lab result notification failed: ' . $e->getMessage());
        }

        // The owner: which patient, which tests, which lab, what it cost.
        try {
            $patient = (new \App\Repositories\PatientRepository())
                ->forOrganization($request->organizationId())->find((int) $order['patient_id']);
            $names   = array_map(static fn (array $t): string => (string) $t['test_name'], $order['tests'] ?? []);
            if ($names === []) {
                $names = array_map(static fn (array $r): string => (string) $r['test_name'], $results);
            }
            $notifications = new NotificationService($request->organizationId(), $request->userId());
            foreach ((new \App\Services\RbacService())->ownersOf((int) $request->organizationId()) as $ownerId) {
                if ($ownerId === (int) $request->userId()) {
                    continue;
                }
                $notifications->notifyUser($ownerId, 'lab.completed.owner', [
                    'patient'      => trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')) ?: 'A patient',
                    'tests'        => implode(', ', $names),
                    'lab'          => $labName !== '' ? $labName : 'the clinic lab',
                    'charge'       => $charge === null ? 'not recorded' : number_format((float) $charge, 2),
                    'subject_type' => 'lab_order',
                    'subject_id'   => $orderId,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[notify] lab completion notification failed: ' . $e->getMessage());
        }

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

        // §26: a checksum nothing compares is decoration. DocumentStore says
        // outright that the checksum "is what makes the kept copy worth having
        // — it can be shown to be the same bytes", and until now nothing ever
        // showed it: every document was written with a SHA-256 and served
        // without one being computed.
        //
        // What is at stake is that an issued invoice or prescription is the
        // specific piece of paper a patient or a pharmacy is holding. Serving
        // bytes that no longer match what was recorded, silently, is worse
        // than serving nothing: it looks authoritative and is not.
        //
        // A row written before the column existed carries no checksum. Those
        // are served as before — refusing them would break access to real
        // records to enforce a rule they were never written under.
        if (!empty($document['checksum_sha256'])) {
            $actual = (string) hash_file('sha256', $absolute);

            if (!hash_equals((string) $document['checksum_sha256'], $actual)) {
                error_log(sprintf(
                    '[integrity] medical_document %d: stored bytes do not match '
                    . 'the recorded checksum (recorded %s, found %s)',
                    (int) $document['id'],
                    (string) $document['checksum_sha256'],
                    $actual,
                ));

                (new AuditService())->log(
                    $request, 'integrity_failed', 'medical_document', (int) $document['id'],
                    ['checksum_sha256' => $document['checksum_sha256']],
                    ['checksum_sha256' => $actual],
                    (int) $document['patient_id'],
                );

                throw new \App\Core\ConflictException(
                    'This document does not match the checksum recorded when it was '
                    . 'stored, so it has not been served. Please contact the clinic.'
                );
            }
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
