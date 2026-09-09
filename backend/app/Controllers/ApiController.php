<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;

/**
 * GET /api/v1 — what this API offers (§19).
 *
 * The base route used to 404, which is a poor answer to the first request
 * anybody makes. §19 names twelve endpoint groups; this lists them, so a new
 * consumer can find the surface without being handed a document, and so
 * "which version am I talking to" has an answer.
 *
 * Public on purpose, and deliberately says nothing about any clinic: these are
 * the shapes the API has, not the data in it.
 */
final class ApiController extends Controller
{
    /**
     * The endpoint groups of §19, each with the one line that says what it is
     * for. Sub-resources are not listed — this is a map, not a manual.
     */
    private const GROUPS = [
        'auth'          => 'Register, sign in, refresh a session, reset a password.',
        'patients'      => 'Patient records, allergies and conditions.',
        'doctors'       => 'Clinician profiles, schedules and availability.',
        'appointments'  => 'Booking, rescheduling and the day list.',
        'encounters'    => 'Consultations, with diagnoses, procedures and notes.',
        'prescriptions' => 'Prescribing, issuing and the medication catalogue.',
        'labs'          => 'Lab orders and the results reported against them.',
        'billing'       => 'The service catalogue, invoices and financial reports.',
        'payments'      => 'Payments taken and refunds requested or approved.',
        'insurance'     => 'Providers, patient policies and eligibility.',
        'claims'        => 'Insurance claims from submission to settlement.',
        'notifications' => 'The inbox, and marking or clearing what is in it.',
    ];

    public function index(Request $request): never
    {
        $base = '/api/v1';

        $endpoints = [];
        foreach (self::GROUPS as $group => $purpose) {
            $endpoints[] = [
                'group'   => $group,
                'path'    => $base . '/' . $group,
                'purpose' => $purpose,
            ];
        }

        $this->ok([
            'name'      => $GLOBALS['__config']['name'] ?? 'MediFlow',
            'version'   => 'v1',
            'base'      => $base,
            'endpoints' => $endpoints,
            'notes'     => [
                'auth'   => 'Bearer access token on every route except /health, '
                          . '/public/* and /auth/*.',
                'tenant' => 'Send X-Organization-Id to say which clinic you are '
                          . 'working in; the token records the last one you used.',
            ],
        ]);
    }
}
