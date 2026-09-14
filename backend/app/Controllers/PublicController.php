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

    /**
     * The page the stub gateway sends a payer to.
     *
     * PayPal has a checkout page; the stub had only a URL that 404'd, so the
     * demo's Pay button opened an error in the phone's browser before the app
     * quietly approved the payment anyway. This is the checkout page the stub
     * was missing: it says plainly that no money moves, and its one button
     * sends the payer back into the app the same way PayPal's return does.
     *
     * Never reachable in production — the stub gateway refuses to exist there.
     */
    public function stubCheckout(Request $request): never
    {
        $reference = htmlspecialchars((string) $request->input('reference', ''), ENT_QUOTES);

        // Where "Approve" and "Cancel" go: what the gateway was told when the
        // order was created, falling back to the configured scheme. App
        // schemes only — this page must not become a redirect to anywhere.
        $pick = static function (string $given, string $fallback): string {
            $ok = preg_match('~^(mediflow|exp|exps)://~i', $given) === 1;
            return htmlspecialchars($ok ? $given : $fallback, ENT_QUOTES);
        };
        $return = $pick((string) $request->input('return', ''), (string) env('PAYMENT_RETURN_URL', 'mediflow://payment/return'));
        $cancel = $pick((string) $request->input('cancel', ''), (string) env('PAYMENT_CANCEL_URL', 'mediflow://payment/cancel'));

        $html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Demo checkout — MediFlow</title>
<style>
  body{margin:0;background:#f3f6f8;color:#10202b;font:17px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
  main{max-width:420px;margin:0 auto;padding:40px 20px}
  .card{background:#fff;border:1px solid #d6dfe4;border-radius:14px;padding:24px}
  h1{font-size:22px;margin:0 0 6px}
  p{margin:0 0 14px;color:#5b6b76}
  .ref{font-family:ui-monospace,Consolas,monospace;font-size:12px;word-break:break-all;background:#eef2f5;padding:8px 10px;border-radius:8px;color:#10202b}
  a.btn{display:block;text-align:center;text-decoration:none;font-weight:600;border-radius:10px;padding:14px;margin-top:14px}
  .approve{background:#0f6e8c;color:#fff}
  .cancel{color:#0f6e8c}
  .tag{display:inline-block;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#8a5a00;background:#fff3d6;padding:3px 10px;border-radius:999px;margin-bottom:12px}
</style>
<main><div class="card">
  <span class="tag">Demo — no money moves</span>
  <h1>Approve this payment?</h1>
  <p>This stands in for the PayPal checkout page. Approving returns you to MediFlow, where the invoice is settled and a receipt is issued.</p>
  <div class="ref">{$reference}</div>
  <a class="btn approve" href="{$return}?reference={$reference}">Approve and return to MediFlow</a>
  <a class="btn cancel" href="{$cancel}">Cancel</a>
</div></main>
HTML;

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }
}
