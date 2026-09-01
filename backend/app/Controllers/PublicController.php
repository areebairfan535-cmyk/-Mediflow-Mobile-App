<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;

/**
 * The two lists a clinic needs BEFORE it has an account (§22 onboarding).
 *
 * §22's first step is "choose plan", which happens before an organization
 * exists — so the price list cannot sit behind the tenant guard, and neither
 * can the markets, because the sign-up form has to offer a country.
 *
 * Both are public facts a visitor would read on a pricing page. Nothing here
 * is tenant data, nothing is per-user, and only what a chooser needs is
 * returned: no adoption counts, no internal ids beyond the ones the sign-up
 * call sends straight back.
 */
final class PublicController extends Controller
{
    public function plans(Request $request): never
    {
        $plans = Database::select(
            'SELECT id, slug, name, description, price_monthly, price_yearly, currency_code,
                    max_doctors, max_staff, max_patients, max_storage_mb,
                    max_invoices_month, max_appointments_month, max_ai_calls_month, features
               FROM plans
              WHERE is_active = 1
              ORDER BY sort_order, price_monthly',
        );

        foreach ($plans as $i => $plan) {
            $plans[$i]['features'] = is_string($plan['features'] ?? null)
                ? (json_decode($plan['features'], true) ?: [])
                : ($plan['features'] ?? []);
        }

        $this->ok(['plans' => $plans]);
    }

    /** Markets currently open to new clinics (§23). */
    public function countries(Request $request): never
    {
        $this->ok([
            'countries' => Database::select(
                'SELECT code, name, currency_code, currency_symbol, timezone
                   FROM countries
                  WHERE is_active = 1
                  ORDER BY name',
            ),
        ]);
    }
}
