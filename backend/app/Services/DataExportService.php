<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\NotFoundException;
use App\Core\Service;
use App\Repositories\DataExportRepository;

/**
 * Everything the clinic holds about one patient, in one machine-readable file.
 *
 * §16 says the platform is built with HIPAA and GDPR in mind, and most of what
 * those ask for was already here: access is controlled by role, every read of a
 * patient's record is written to the audit log, sessions expire, and one
 * clinic cannot see another's data. The piece that was missing is the one the
 * regulations give to the *patient* rather than to the operator.
 *
 *   GDPR Art. 15   the right to a copy of your personal data
 *   GDPR Art. 20   in a structured, commonly used, machine-readable format
 *   HIPAA §164.524 the right of access to your designated record set
 *
 * A patient could read their record a screen at a time in the app. They could
 * not obtain it — and "you may look at it here" is not the same right as "you
 * may have it".
 *
 * Two doors, one document: the patient exports their own, and the clinic
 * exports it for somebody who asked at the desk. Both are audited, because a
 * copy of a medical record leaving the building is exactly the event an audit
 * log exists to record.
 *
 * On erasure, deliberately absent: see eraseNotice().
 */
final class DataExportService extends Service
{
    /**
     * @return array<string,mixed> the whole record, ready to be encoded
     */
    public function forPatient(int $patientId): array
    {
        $export = (new DataExportRepository())
            ->forOrganization($this->requireOrganization());

        $patient = $export->patient($patientId);

        if ($patient === null) {
            throw new NotFoundException('Patient not found');
        }

        $organization = $export->clinic();

        $encounters = $export->encounters($patientId);

        // The child records hang off the visit they happened in, which is how a
        // person reads their own history — not as eight parallel lists.
        foreach ($encounters as $i => $encounter) {
            $encounterId = (int) $encounter['id'];

            $encounters[$i]['diagnoses']      = $export->diagnoses($encounterId);
            $encounters[$i]['procedures']     = $export->procedures($encounterId);
            $encounters[$i]['clinical_notes'] = $export->approvedNotes($encounterId);
        }

        $prescriptions = $export->prescriptions($patientId);
        foreach ($prescriptions as $i => $rx) {
            $prescriptions[$i]['items'] = $export->prescriptionItems((int) $rx['id']);
        }

        $labOrders = $export->labOrders($patientId);
        foreach ($labOrders as $i => $order) {
            $labOrders[$i]['results'] = $export->labResults((int) $order['id']);
        }

        $invoices = $export->invoices($patientId);
        foreach ($invoices as $i => $invoice) {
            $invoiceId = (int) $invoice['id'];

            $invoices[$i]['items']    = $export->invoiceItems($invoiceId);
            $invoices[$i]['payments'] = $export->invoicePayments($invoiceId);
        }

        return [
            'export' => [
                'generated_at' => gmdate('c'),
                'standard'     => 'GDPR Art. 15 & 20 · HIPAA §164.524',
                'clinic'       => $organization,
                'note'         => 'Everything this clinic holds about you. Documents and '
                                . 'images are listed with their details; download each from '
                                . 'the app or ask the clinic for the files.',
            ],

            'you' => [
                'mrn'           => $patient['mrn'],
                'first_name'    => $patient['first_name'],
                'last_name'     => $patient['last_name'],
                'date_of_birth' => $patient['date_of_birth'],
                'gender'        => $patient['gender'],
                'blood_group'   => $patient['blood_group'],
                'phone'         => $patient['phone'],
                'email'         => $patient['email'],
                'address'       => $patient['address'],
                'city'          => $patient['city'],
                'emergency_contact' => [
                    'name'     => $patient['emergency_name'],
                    'phone'    => $patient['emergency_phone'],
                    'relation' => $patient['emergency_relation'],
                ],
                'app_account'   => $patient['account_email'],
                'registered_at' => $patient['created_at'],
            ],

            'allergies'          => $export->allergies($patientId),
            'medical_conditions' => $export->conditions($patientId),
            'appointments'       => $export->appointments($patientId),
            'visits'        => $encounters,
            'prescriptions' => $prescriptions,
            'lab_orders'    => $labOrders,
            'documents' => $export->documents($patientId),
            'invoices'  => $invoices,
            'insurance' => $export->insurancePolicies($patientId),
            'claims'    => $export->claims($patientId),

            'erasure' => self::eraseNotice(),
        ];
    }

    /**
     * Why there is no delete button, said plainly in the export itself.
     *
     * GDPR's right to erasure is real, and Art. 17(3) is equally real: it does
     * not apply where the data is needed for medical diagnosis or the provision
     * of care, or where the controller must keep it by law. Clinics are
     * required to retain records for years — the period varies by country and
     * is set outside this software.
     *
     * So MediFlow does not offer patients a button that deletes their medical
     * history, and it should not. What it can honestly offer is to say so, and
     * to point at the person who can act on a request that does qualify.
     *
     * @return array<string,string>
     */
    public static function eraseNotice(): array
    {
        return [
            'why_no_delete' => 'Medical records are kept for a period set by law, '
                             . 'and GDPR Art. 17(3) allows for that. This clinic cannot '
                             . 'delete a clinical record on request.',
            'what_you_can_do' => 'Ask the clinic to correct anything that is wrong, or to '
                               . 'close your app account. Closing the account removes your '
                               . 'login; the medical record stays with the clinic as its '
                               . 'own retention rules require.',
        ];
    }
}
