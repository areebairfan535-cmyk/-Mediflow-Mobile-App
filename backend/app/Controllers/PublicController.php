<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Repositories\CountryRepository;
use App\Repositories\PlanRepository;

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
 * call sends straight back — which is what publicList() means on each
 * repository, as against the panel's fuller list.
 */
final class PublicController extends Controller
{
    public function plans(Request $request): never
    {
        $plans = (new PlanRepository())->publicList();

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
        $this->ok(['countries' => (new CountryRepository())->publicList()]);
    }
}
