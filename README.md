# MediFlow

Multi-tenant healthcare practice, medical billing and revenue-cycle platform.
Built to the *MediFlow Complete Development Plan* — section numbers below
(§n) refer to that document.

> **Status: every section of the plan is built and tested.**
> Phases 1–6, the §21 super-admin panel, §22 subscriptions, §23 markets, and
> the patient-facing work §3 asks for — booking, released reports, printable
> documents and the §20 delivery worker. What is left is credentials, not
> code: payment-gateway keys, SMTP/SMS, and an AI key. See [Roadmap](#roadmap).

---

## What exists today

```
mediflow/
├── backend/        Core PHP 8.2 REST API   — Phases 1-6 DONE
├── clinic_web/     React + Vite            — Doctor/clinic app, RUNNING
├── admin_web/      React + Vite            — Admin console, RUNNING
├── patient_app/    React Native + Expo     — Patient app, RUNNING
└── docs/
```

A working, tested system: 42 tables, token auth with rotation, RBAC across
10 roles, enforced multi-tenancy, audit logging, plan limits that actually
refuse writes, generated PDFs, a notification worker, and the §27 workflow
running end to end — book, consult, diagnose, prescribe, invoice, take
payment, notify the patient. The patient books, reschedules, reads their
record and opens their reports from the phone.

**562/562 end-to-end assertions pass** (78 foundation + 55 clinical +
94 billing + 90 patient + 82 insurance + 58 AI + 52 subscription +
53 platform). Each suite resets what it depends on and creates what it needs,
so they can be re-run in any order without re-seeding.

| App | URL | For |
|---|---|---|
| Clinic | `http://localhost:5174` | Doctors, nurses, reception |
| Admin | `http://localhost:5173` | Org owners, platform admins |
| Patient app | `http://localhost:8082` (or Expo Go) | Patients |
| API | `http://localhost:8000/api/v1` | All three clients |

---

## Screenshots

Captured from the running system against the seeded demo clinic.

### Clinic — the doctor's workspace (§4)

| My day | Consultation | Billing |
|:---:|:---:|:---:|
| <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/clinic-my-day.png" width="260" alt="Today's appointment list with waiting, completed, billed and outstanding tiles" /> | <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/clinic-consultation.png" width="260" alt="Consultation screen with an allergy banner, complaint and examination, vitals and diagnosis" /> | <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/clinic-billing.png" width="260" alt="Invoice list with draft, issued and partially paid statuses in PKR" /> |
| Waiting, completed, billed and outstanding at a glance — and one tap into the visit. | Allergies surface before anything else. Complaint → diagnosis → prescription → labs → invoice, in order. | Invoices, payments and balances, with the money never held as a float (§6). |

### Admin — organisation and platform (§21, §22)

| Roles & permissions | Plan & usage | Audit log |
|:---:|:---:|:---:|
| <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/admin-roles.png" width="260" alt="Ten roles listed with their slugs and permission counts" /> | <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/admin-plan-usage.png" width="260" alt="Subscription plan with usage against doctor, patient and storage limits" /> | <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/admin-audit-log.png" width="260" alt="Audit log of access to patient records and financial data" /> |
| 10 roles over 51 permissions. A route declares the slug it needs; the role holds it or the request is refused with 403 (§11). | Limits that actually refuse writes, not just decorate a settings page (§22). | Who touched which record, and when (§16). |

### Patient app (§3)

| Home | Visits | Bills |
|:---:|:---:|:---:|
| <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/patient-home.png" width="200" alt="Patient home showing allergies, outstanding balance and a health summary" /> | <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/patient-visits.png" width="200" alt="Patient visit history with dates and doctors" /> | <img src="https://raw.githubusercontent.com/areebairfan535-cmyk/-Mediflow-Mobile-App/main/docs/screenshots/patient-bills.png" width="200" alt="Patient invoice list with amounts and payment status" /> |
| Allergies, balance and health summary — scoped to identity, not permission. | Every visit, with what was diagnosed and prescribed. | What is owed, and what has been paid. |

---

## Quick start

Requires XAMPP (PHP 8.2 + MariaDB). No Composer, no npm — §24 specifies
Core PHP.

> ⚠️ **Use XAMPP's PHP, not whatever `php` resolves to on your PATH.**
> Every command below spells out `C:/xampp/php/php.exe` for a reason: the API
> needs the **`mbstring`** extension, which XAMPP enables and many standalone
> PHP builds do not. Start the server with the wrong PHP and every request
> returns `500 — Call to undefined function mb_strlen()`. That looks like
> broken code, but it is only a missing extension.
>
> Confirm before you start:
>
> ```bash
> C:/xampp/php/php.exe -v          # expect PHP 8.2.x
> C:/xampp/php/php.exe -m | grep mbstring   # must print: mbstring
> ```

```bash
# 1. Start MariaDB from the XAMPP Control Panel, then:
C:/xampp/mysql/bin/mysql.exe -u root \
  -e "CREATE DATABASE IF NOT EXISTS mediflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Configure
cd C:/Users/Mr Shahram/mediflow/backend
cp .env.example .env          # XAMPP defaults work as-is

# 3. Build the schema and demo data
C:/xampp/php/php.exe database/migrate.php
C:/xampp/php/php.exe database/seed.php            # org, roles, users, plans
C:/xampp/php/php.exe database/seed_clinical.php   # patients, medicines, today's clinic
C:/xampp/php/php.exe database/seed_billing.php    # service catalogue and prices
C:/xampp/php/php.exe database/seed_insurance.php  # insurers and patient policies

# 4. Run the API
C:/xampp/php/php.exe -S 127.0.0.1:8000 -t public

# 5. Verify
bash database/smoke-test.sh              # 78 assertions
bash database/smoke-test-clinical.sh     # 55 assertions
bash database/smoke-test-billing.sh      # 94 assertions
bash database/smoke-test-patient.sh      # 90 assertions
bash database/smoke-test-insurance.sh    # 82 assertions
bash database/smoke-test-ai.sh           # 58 assertions
bash database/smoke-test-subscription.sh # 52 assertions
bash database/smoke-test-platform.sh     # 53 assertions
```

Each suite resets the state it depends on at startup — stale open
consultations, spent insurance cover — so they can be re-run in any order
without re-seeding.

`curl http://127.0.0.1:8000/api/v1/health` should return `{"data":{"status":"ok",...}}`.

Then start whichever front-end you need (each in its own terminal):

```bash
cd clinic_web && npm install && npm run dev   # http://localhost:5174
cd admin_web   && npm install && npm run dev   # http://localhost:5173
cd patient_app && npm install && npx expo start --web --port 8082
```

On a phone, run the patient app with `--lan` instead and scan the QR code with
Expo Go. The app works out the API address from whichever machine served it
the bundle, so nothing needs configuring — but the API has to be listening on
`0.0.0.0:8000` rather than `127.0.0.1`, or the phone cannot see it at all.

Both proxy `/api` to `:8000`, so the browser stays on one origin.

### Demo accounts

Password for all: `Password123`

| Email | Role | Sees |
|---|---|---|
| `admin@mediflow.test` | platform admin | Super-admin panel, all tenants (§21) |
| `owner@clinic.test` | org_owner | Everything in Demo Clinic |
| `doctor@clinic.test` | doctor | Clinical only — blocked from team management |
| `reception@clinic.test` | receptionist | Front desk, booking, taking payment |
| `billing@clinic.test` | billing_staff | Invoices and refund requests — cannot approve its own |
| `patient@demo.test` | patient | The mobile app — own record only |

### Useful commands

```bash
php database/migrate.php --status   # what has run
php database/migrate.php --fresh    # drop everything and rebuild
php database/seed.php               # idempotent, safe to re-run
bash database/smoke-test.sh         # 53 end-to-end assertions

# All eight suites, one line:
for t in "" -clinical -billing -patient -insurance -ai -subscription -platform; do
  bash database/smoke-test$t.sh | tail -2
done
```

---

## Architecture

Three clients, one API (§2, §24):

```
patient_app (RN/Expo)  ─┐
clinic_web  (React)    ─┼─►  REST API  ─►  MySQL
admin_web   (React)    ─┘   /api/v1/*
```

### Request pipeline (§12)

```
Routes → Middleware → Controller → Validator → Service → Repository → Model → DB
```

The rule from §12 is enforced, not merely aspirational:

- **Controllers** validate and delegate. No SQL, no business rules. Most
  methods are under 15 lines.
- **Services** hold business rules — lockout policy, tenant switching,
  owner-cannot-be-removed, what a successful login returns.
- **Repositories** are the only layer that writes SQL, always with bound
  parameters (§17).

| Layer | Location | Responsibility |
|---|---|---|
| Router | `app/Core/Router.php` | Path matching, `{params}`, groups, middleware aliases |
| Middleware | `app/Middleware/` | Throttle → Auth → Tenant → Permission |
| Controller | `app/Controllers/` | HTTP in, JSON out |
| Validator | `app/Core/Validator.php` | Rule strings, type casting, field errors |
| Service | `app/Services/` | Business rules, transactions |
| Repository | `app/Core/Repository.php`, `app/Repositories/` | SQL + tenant scoping |
| Database | `app/Core/Database.php` | PDO, transactions, UTC session |

---

## Three decisions worth knowing

### 1. Tenant isolation is structural, not remembered (§10)

§10 requires isolation "in every authorization path". A per-endpoint
`WHERE organization_id = ?` written by hand fails the first time somebody
forgets it — and one forgotten check is a cross-tenant patient-data leak.

So `Repository` injects the tenant predicate into every read and write:

```php
protected bool $tenantScoped = true;   // default

$repo->forOrganization($request->organizationId())->where([...]);
// -> ... WHERE ... AND organization_id = :__org
```

- A tenant-scoped repository used with no tenant bound **throws**, rather than
  silently querying across all organizations.
- `create()` stamps `organization_id` from the bound tenant, so a caller cannot
  write into someone else's organization even by passing one.
- `update()` refuses to reassign `organization_id`.
- `findOrFail()` returns 404 for another tenant's row — indistinguishable from
  a row that does not exist, which is the correct disclosure behaviour.
- The only way across tenants is `withoutTenantScope()`, used exclusively by
  `PlatformController` (§21) and CLI scripts. One greppable escape hatch.

`TenantMiddleware` re-verifies membership on every request. The
`X-Organization-Id` header is a *request*, never a grant.

### 2. Opaque rotating tokens, not JWTs (§11)

- 64 hex chars from `random_bytes(32)`; only the SHA-256 hash is stored, so a
  database dump yields no usable sessions.
- Access token 30 min, refresh token 30 days.
- **Rotation**: spending a refresh token revokes it *and* every access token
  minted from it, then issues a fresh pair. Replaying a spent refresh token
  fails — which is how stolen-token reuse becomes visible.
- A refresh token presented to a normal endpoint is rejected. It is only
  spendable at `POST /auth/refresh`.
- Opaque beats JWT here because a revocation must take effect immediately when
  a device is removed or a password changes. A stateless JWT cannot do that.

### 3. DATETIME, never TIMESTAMP

The app stores UTC everywhere and renders in the organization's timezone at
the edge — required by §23, where one deployment serves PK, US, GB and AE.

`TIMESTAMP` columns broke this in two ways, both found by the test suite:

1. MySQL/MariaDB implicitly attach `DEFAULT CURRENT_TIMESTAMP ON UPDATE
   CURRENT_TIMESTAMP` to the **first** `TIMESTAMP` column of a table when
   `explicit_defaults_for_timestamp` is off (the MariaDB 10.4 default). That
   silently rewrote `auth_tokens.expires_at` on every update of the row, so
   every session token died after exactly one use.
2. `TIMESTAMP` converts on read/write using the session timezone, so the same
   row reads differently from a differently configured client.

Fix: `DATETIME` throughout, plus `SET SESSION time_zone = '+00:00'` in
`Database::init()` so `NOW()`, `CURRENT_TIMESTAMP` and `UTC_TIMESTAMP()` all
agree with what PHP writes.

---

## Database — 42 tables

Created in full during Phase 1 so later phases add behaviour, not destructive
migrations.

| Migration | Tables |
|---|---|
| `001_foundation` | countries, organizations, users, roles, permissions, role_permissions, organization_users, auth_tokens, rate_limits |
| `002_directory` | patients, doctors, doctor_schedules, staff, allergies, medical_conditions |
| `003_clinical` | appointments, **encounters**, diagnoses, medications, prescriptions, prescription_items, lab_orders, lab_results, procedures, clinical_notes, medical_documents |
| `004_billing` | services, service_prices, invoices, invoice_items, payments, refunds |
| `005_insurance` | insurance_providers, insurance_policies, claims, claim_items |
| `006_system` | notifications, audit_logs, plans, subscriptions, subscription_items, migrations |
| `007_integrity` | one login → one chart: `UNIQUE (organization_id, user_id)` on patients |
| `009_notification_dismiss` | `dismissed_at` — clearing an inbox hides a notification without deleting the record that it was sent |
| `008_role_uniqueness` | folds duplicated system roles together and keys uniqueness on a scope column, because `UNIQUE (organization_id, slug)` never fires for the NULL-org system roles |

Schema notes that matter:

- **`encounters` is the hub.** Every diagnosis, prescription, lab order,
  procedure, note and invoice hangs off one encounter (§5).
- **`invoices` and `payments` are separate tables** (§6) — one invoice takes
  many payments, because part-payment is the normal case. `balance_due` is a
  stored generated column so "outstanding" queries stay simple.
- **`invoice_items` snapshots** `service_code`, `description` and `unit_price`.
  An issued invoice must never change because the catalogue was edited later.
- **`service_prices` is separate from `services`** and keyed by country +
  currency + date range — §6 and §23 both require it.
- **Money is `DECIMAL`, never `FLOAT`.**
- **`medical_documents` stores paths and ACLs, not bytes** (§19).
- **`audit_logs` is append-only.** No update or delete path is exposed.

---

## API — implemented endpoints

All under `/api/v1` (§14). Versioned so a v2 can ship without breaking
installed mobile apps.

### Public
```
GET    /health
POST   /auth/register
POST   /auth/login
POST   /auth/refresh
```

### Authenticated (no tenant required — this is what makes onboarding work)
```
POST   /auth/logout
POST   /auth/logout-all
POST   /auth/change-password
POST   /auth/switch-organization
GET    /auth/sessions
DELETE /auth/sessions/{id}
GET    /me
PUT    /me
POST   /organizations                       create a clinic; caller becomes owner
```

### Tenant-scoped
```
GET    /me/context                          identity + role + permission list
GET    /organizations/current
PUT    /organizations/current               perm: organization.update
GET    /organizations/current/roles         perm: member.view
GET    /organizations/current/members       perm: member.view
POST   /organizations/current/members       perm: member.create
PUT    /organizations/current/members/{userId}/role     perm: member.update
PUT    /organizations/current/members/{userId}/status   perm: member.update
DELETE /organizations/current/members/{userId}          perm: member.delete
GET    /audit-logs                          perm: audit.view
GET    /audit-logs/patient/{patientId}      perm: audit.view
GET    /audit-logs/{type}/{id}              perm: audit.view
```

### Clinical — Phase 2 (§3, §4, §5, §19)
```
GET    /patients                              perm: patient.view
POST   /patients                              perm: patient.create
GET    /patients/{id}                         chart + allergies + conditions + visits
PUT    /patients/{id}
DELETE /patients/{id}                         deactivates; clinical rows are never deleted
GET    /patients/{id}/allergies
POST   /patients/{id}/allergies
DELETE /patients/{id}/allergies/{allergyId}
GET    /patients/{id}/conditions
POST   /patients/{id}/conditions
PUT    /patients/{id}/conditions/{conditionId}
GET    /patients/{patientId}/prescriptions
GET    /patients/{patientId}/documents
POST   /patients/{patientId}/documents        multipart upload

GET    /doctors
GET    /doctors/dashboard                     today's workload for the signed-in doctor
POST   /doctors
GET    /doctors/{id}
PUT    /doctors/{id}
GET    /doctors/{id}/schedule
PUT    /doctors/{id}/schedule                 replaces the weekly template
GET    /doctors/{id}/available-slots?date=    free slots, computed from schedule − bookings

GET    /appointments?date=&doctor_id=&status=
POST   /appointments
GET    /appointments/{id}
PUT    /appointments/{id}/reschedule
PUT    /appointments/{id}/status

GET    /encounters
POST   /encounters                            from an appointment, or a walk-in
GET    /encounters/{id}                       the whole visit in one payload
PUT    /encounters/{id}                       symptoms, examination, vitals
POST   /encounters/{id}/complete
POST   /encounters/{id}/cancel
POST   /encounters/{id}/diagnoses
POST   /encounters/{id}/procedures
POST   /encounters/{id}/notes
POST   /encounters/{id}/lab-orders
DELETE /encounters/{id}/{kind}/{childId}

GET    /prescriptions/medications?search=     catalogue with pre-filled defaults
POST   /prescriptions
GET    /prescriptions/{id}
PUT    /prescriptions/{id}
POST   /prescriptions/{id}/issue
POST   /prescriptions/{id}/cancel

GET    /lab-orders
POST   /lab-orders/{id}/results
GET    /documents/{id}/download

GET    /services                              perm: service.view
POST   /services                              perm: service.manage — code is permanent
PUT    /services/{id}                         name, category, taxable, retired
POST   /services/{id}/prices                  supersedes; the old price is kept

GET    /prescriptions/{id}/pdf                printable, issued only
GET    /invoices/{id}/pdf                     printable, drafts refused
```

### The patient app (§3) — scoped by identity, not by permission

None of these takes a patient id: each resolves the record from the signed-in
account, which is why they carry no `perm:` guard. A `patient_id` in the body
is ignored rather than honoured.

```
GET    /patient/dashboard                     one call fills the home screen
GET    /patient/profile
PUT    /patient/profile                       own details + contact; NOT blood
                                              group, allergies or insurance

GET    /patient/doctors?search=                book with whom
GET    /patient/doctors/{id}/slots?date=        the clinic's own free slots
POST   /patient/appointments                   book
POST   /patient/appointments/{id}/reschedule   move
POST   /patient/appointments/{id}/cancel

GET    /patient/records | /prescriptions | /lab-results | /documents
GET    /patient/documents/{id}/download        released reports only
GET    /patient/prescriptions/{id}/pdf
GET    /patient/bills | /invoices/{id}
GET    /patient/invoices/{id}/pdf

GET    /patient/notifications
POST   /patient/notifications/read | /{id}/read
DELETE /patient/notifications                  clear (all=true for the lot)
DELETE /patient/notifications/{id}
```

### AI assistants — Phase 6 (§9, §28)

Optional. With no provider configured every route below returns **503** with a
message saying so, and nothing else in the system is affected.

```
GET    /ai/status                             is a provider live, and which
POST   /encounters/{id}/ai/draft-note         perm: ai.draft_note   → a DRAFT
POST   /clinical-notes/{id}/approve           perm: encounter.update
DELETE /clinical-notes/{id}                   discard an unapproved draft
GET    /encounters/{id}/ai/billing-suggestions perm: ai.suggest_billing
GET    /claims/{id}/ai/review                 perm: ai.check_claim
GET    /patients/{id}/ai/summary              perm: ai.draft_note — advisory
GET    /search?q=                             perm: patient.view — SQL, not AI
```

`/search` is filed with the AI routes because §25 lists it in the AI phase,
and answered by SQL because a receptionist with a phone number on the line
needs the same answer in the same 40ms every time. It works with AI switched
off.

Note what is **not** there: no endpoint through which the AI writes a final
record. Drafting and approving are separate calls with separate permissions —
§9's "human confirmation" made structural rather than promised.

### Subscription & plan limits (§22)

```
GET    /plans                                 perm: member.view
GET    /organizations/current/subscription    perm: member.view — plan + usage
PUT    /organizations/current/subscription    perm: subscription.manage
```

`POST /organizations` accepts an optional `plan` slug (§22 onboarding:
choose plan → organization created). Omitted means Free.

### Platform admin (§21) — the only cross-tenant surface
```
GET    /platform/dashboard
GET    /platform/organizations
GET    /platform/organizations/{id}
PUT    /platform/organizations/{id}/status
PUT    /platform/organizations/{id}/plan      move a clinic between plans

GET    /platform/plans                        the price list, with adoption counts
POST   /platform/plans
PUT    /platform/plans/{id}                   name, prices and limits; slug is immutable

GET    /platform/countries                    §23 market config
POST   /platform/countries                    open a market — a row, not a release
PUT    /platform/countries/{id}                currency, timezone, tax, invoice prefix

GET    /platform/audit-logs                   the cross-tenant trail (§16)
```

Two things worth knowing about this surface:

- **A platform admin gets the same refusals a clinic owner would.** Moving a
  clinic onto a plan smaller than its current usage returns 422 with the list
  of what is in the way. An admin who means it raises the plan's limits
  instead — which is visible, where a silent override would not be.
- **Actions done *to* a clinic are filed *under* that clinic.** Suspending an
  organization or changing its plan lands in its own audit trail, so the
  clinic can see what happened to it. Plan and country edits belong to nobody
  and live only in the platform trail — which is why that endpoint exists at
  all: `GET /audit-logs` is tenant-scoped and could never show them.

### Response envelope

One shape for all three clients:

```jsonc
// success
{ "data": { ... }, "meta": { "page": 1, "total": 42 } }

// failure
{ "error": { "message": "...", "code": "validation_failed",
             "fields": { "email": ["Email must be a valid email address."] } } }
```

### Headers

| Header | Purpose |
|---|---|
| `Authorization: Bearer <access_token>` | Authentication |
| `X-Organization-Id: <id>` | Choose the tenant when a user belongs to several |
| `X-Device-Name`, `X-Device-Id` | Labels the session in the device manager |

---

## RBAC — 10 roles, 51 permissions

Permissions are `module.action` slugs across five modules: `admin`,
`clinical`, `billing`, `insurance`, `ai`.

| Role | Perms | Shape |
|---|---:|---|
| `org_owner` | 51 | Everything, including the plan and AI assistants |
| `org_admin` | 49 | No subscription or custom-role control |
| `doctor` | 26 | Clinical + can raise invoices; no team management |
| `billing_staff` | 20 | Revenue cycle: invoices, payments, claims |
| `receptionist` | 15 | Front desk, booking, taking payment |
| `nurse` | 12 | Vitals, patient assist, lab logistics |
| `accountant` | 8 | Financial oversight, **no clinical access** |
| `lab_staff` | 5 | Lab orders and results |
| `pharmacist` | 3 | Dispensing against prescriptions |
| `patient` | 0 | Own records only — see below |

`patient` holds **no** permissions, deliberately. Every `/patient/*` route
resolves the record from `patients.user_id` and none takes a patient id, so
that surface is scoped by identity, not by permission. Granting the
obvious-looking slugs was an actual hole the Phase 4 suite caught:
`invoice.view` also opens the clinic-wide `GET /invoices`, and
`appointment.cancel` also opens `PUT /appointments/{id}/status` for every
appointment in the clinic.

The seeder prints what each role actually **holds**, not what its list asked
for, and names any slug it had to drop — a role quietly missing one permission
is the kind of bug that surfaces months later as "it works for me".

Declared on the route, checked before the controller runs:

```php
$router->post('/invoices', [InvoiceController::class, 'store'], ['perm:invoice.create']);
```

Several slugs mean *any of* (OR). Requiring several at once is deliberately not
expressible — that is a policy decision and belongs in the service next to the
rule it protects.

---

## Security (§17)

| Control | Where |
|---|---|
| SQL injection | Bound parameters only; identifiers regex-validated; `ORDER BY` parsed and re-emitted |
| Password hashing | `password_hash()` with `PASSWORD_DEFAULT` |
| Brute force | 5 failed logins → 15-minute lockout, incremented in one atomic statement |
| Rate limiting | Fixed-window buckets in MySQL; 120/min general, 10/min on auth |
| Account enumeration | "Invalid email or password" is identical for unknown email and wrong password |
| Token theft | Hashed at rest; rotation invalidates the family |
| Tenant isolation | Structural in `Repository`; re-verified per request |
| Audit trail | Append-only, with old/new values on changes |
| Secret leakage | Passwords and tokens redacted before an audit row is written |
| Error disclosure | Stack traces logged, never returned when `APP_DEBUG=false` |
| Headers | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` |

**Compliance:** the schema and controls are built *for* HIPAA/GDPR-style
requirements (§18), but per §18 no compliance claim should be made without the
audit, contracts and controls to back it. HTTPS termination, encryption at
rest, backups and DR are deployment concerns, not yet configured.

---

## Test suite

### Foundation — `bash database/smoke-test.sh` (51 assertions)

| Section | Covers |
|---|---|
| 1 | Health, 404 routing, 405 method mismatch |
| 2 | Login, bad password, no account enumeration, hash never returned, refresh token rejected as access token |
| 3 | Tenant resolved from session; forged `X-Organization-Id` refused |
| 4 | Owner allowed; receptionist and doctor blocked from team + audit |
| 5 | Clinic owner blocked from platform panel; admin allowed |
| 6 | Onboarding, and **cross-tenant access denied in both directions** |
| 7 | Rotation, replay rejection, old access token dies with its parent |
| 8 | Session list, logout, token dead afterwards |
| 9 | Login and failed-login recorded; no password ever in the trail |
| 10 | Validation shape, weak password, unknown country, SQL injection attempt |

### Clinical — `bash database/smoke-test-clinical.sh` (55 assertions)

| Section | Covers |
|---|---|
| 1 | Register, auto-MRN, duplicate-phone refusal, chart load, search |
| 2 | Doctor list, weekly schedule, free-slot computation, static-route precedence |
| 3 | Booking, **double-booking refused**, **overlap refused**, past date, outside hours, illegal status jumps |
| 4 | Start consultation from an appointment, appointment follows it, second consultation refused, vitals range checks |
| 5 | Catalogue search, prescribe, empty prescription refused, **allergy warning**, issue, issued-is-immutable |
| 6 | Complete, appointment completes with it, completed chart read-only, empty visit cannot complete, one open consultation per doctor |
| 7 | Lab results recorded once, second attempt refused |
| 8 | Receptionist blocked from diagnosing and prescribing, allowed to register and book |
| 9 | Patient record views and prescription events land in the audit trail |

---

## Three more decisions from Phase 2

### Times are the clinic's, not the viewer's

Everything is stored in UTC, but a doctor's `09:00–13:00` is a wall-clock time
*at that clinic*. Schedule windows are therefore resolved through the
organization's timezone and converted to UTC before being compared with
bookings, and the front-end formats every time back into that same zone.

Without this, one deployment serving Karachi and London clinics would mean
different hours by the same numbers, and `DATE(scheduled_at) = today` would
silently drop appointments that fall on the other side of the UTC boundary.
Day-bounded queries use half-open UTC ranges for the same reason.

### The allergy check warns, it does not block

`PrescriptionService` matches every prescribed medicine against the patient's
recorded allergies and returns warnings alongside the saved prescription.

It does not refuse the write. A clinician may prescribe against a recorded
allergy for good reason, and software that silently overrides a clinical
decision is more dangerous than software that surfaces it loudly. The clinic
app renders the warnings in the same red banner as the allergy list.

### A visit cannot be completed empty

`complete()` refuses unless the encounter has at least a diagnosis, a
procedure, a prescription, or recorded symptoms/examination. Completing is the
point at which a visit becomes a billable, permanent record — an empty one is
almost always a mis-click, and Phase 3 will invoice from exactly this event.

---


---

## Three more decisions from Phase 3

### Money is never a float, and never comes from the client

Every amount is a decimal **string** handled by `Services\Billing\Money`
(bcmath, scale 6 internally, rounded to 2 on the way out). In PHP
`0.1 + 0.2 !== 0.3`, and an invoice a paisa out is a bug a clinic notices and
a regulator cares about.

More importantly, `InvoiceFactory` looks up every price, tax rate and total
itself. A request names a service, a quantity and a discount — nothing more.
The billing suite proves it: posting `grand_total: "1.00"` alongside a
PKR 4,000 service still produces an invoice for PKR 4,680.

### Tax behaviour is a strategy, not an `if`

§23 forbids hard-coded country behaviour, so how tax applies is a class:
`TaxExclusiveRule` (PK, AE, US — tax added on top), `TaxInclusiveRule` (GB —
tax extracted from the listed price), `TaxExemptRule`. `TaxRules::forCountry()`
is the only place a country code maps to behaviour. Adding a market is a line
there plus a `countries` row — `InvoiceService` never changes.

### The invoice number is allocated at issue, not at creation

Drafts carry a placeholder (`DRAFT-A1B2C3...`). The real number comes from the
organization's sequence only when the invoice is issued, inside a transaction
that row-locks the counter.

Abandoned drafts therefore leave no gaps in the issued sequence — which is what
a tax authority looks at — and two invoices issued at the same instant cannot
collide. Once issued the lines are immutable: corrections happen by cancelling
and re-issuing, because silently editing a document the patient already holds
is not a correction.

Payments follow the same discipline. `invoices.paid_total` is a cached SUM of
the payment ledger, **rebuilt** after every write rather than incremented, so
the header can never drift from the rows beneath it.


---

## Two decisions from Phase 5

### Coverage is reserved on submission, not on approval

The moment a claim goes to the insurer, that money is spoken for. If the
reservation waited for approval, a second claim could spend the same ceiling
while the first was still undecided — and the clinic would discover it was
over-claimed only when the second one came back short.

So `submit()` moves `coverage_used` immediately, and a rejection or partial
approval releases exactly the shortfall. The suite asserts the counter after
each step, because a leak here is invisible until a patient is refused cover
they actually had.

### An insurer's payment goes through the normal ledger

`markPaid()` does not set a flag on the invoice. It records a `payments` row
with `method = 'insurance'`, so `paid_total`, the balance and the invoice
status all derive from the same place as every cash payment.

The alternative — a second, parallel notion of "paid" for insurance money — is
how the header and the ledger drift apart, and then nobody can say what a
patient actually owes.

The eligibility split is applied in a fixed order (deductible → copay →
ceiling → remainder to the patient) and returns the full working, not just the
two totals, so a biller disputing a decision can show it.

---

## Three decisions from Phase 6 — the AI module

### The provider is a strategy, and "none" is one of them

§9 says what the assistants must do and names no provider anywhere — not even
in the §24 stack. That gap is modelled as one: the assistants talk to an
`AiProvider` interface, and `AiProviders::resolve()` is the only place a name
maps to a class. With nothing configured it returns a `NullProvider` that
explains itself, so every AI route answers 503 with a message and the clinic
carries on by hand.

That is not a degraded mode — §26 puts AI outside the MVP, so **unconfigured
is the normal state**, and the AI suite runs in it deliberately: 34 of its 51
assertions prove that an unusable assistant breaks nothing else.

### Nothing the model writes is a record until a person says so

A drafted note is saved immediately — losing a clinician's work would be its
own bug — but with `approved_by = NULL`, and the chart treats it as a draft.
Approving is a **separate endpoint with a separate permission**, and it accepts
an edited body, because a clinician correcting the machine is the entire point.

Billing works the same way: the assistant returns suggestions with the
evidence behind each one, priced from the clinic's own catalogue (a code the
model invented is dropped and reported, never billed), and the invoice appears
only when a person ticks lines and presses the button. The claim assistant
never gates Submit — a biller who disagrees with it is usually the one who is
right.

### A stub provider, so the safety gates are testable today

`AI_PROVIDER=stub` answers from rules — SOAP headings around the clinician's
own words, catalogue names matched literally against the visit text, missing
fields counted on a claim. It is not intelligence and says so in every answer
it returns, and it refuses to load when `APP_ENV=production`.

It exists because the approval gates are the safety-critical half of §9, and
leaving them untested until somebody buys an API key was not acceptable. The
AI suite flips `.env` to the stub, proves the full path — draft → unapproved →
clinician approves → recorded against their name — and restores `.env` on exit,
including when a step fails.

---

## Four decisions from the patient-facing work

### The patient books through the clinic's own timetable

`/patient/doctors/{id}/slots` is not a second availability calculation — it
calls the same `AppointmentService` the front desk uses. One timetable means
the app can never offer a slot reception would refuse, and the working-hours,
double-booking, overlap and monthly-plan-limit rules apply to a patient
booking exactly as they do to a receptionist's.

`patient_id` comes from the session and is never read from the request. The
suite asserts that: posting somebody else's id books for yourself, not for
them.

### A report you can see listed but cannot open is not a record you have

The download route already refused anything not marked `patient_visible`, and
answered 404 — not 403 — for another patient's file. The only thing missing
was a way in: the clinic route is gated on `document.view`, which the patient
role deliberately does not hold. So the patient portal exposes the same
handler under its own identity-scoped path. Opening a report is audited like
every other read of a medical record (§16).

### The PDF writer is 250 lines, not a library

§24 fixes the stack at Core PHP with no Composer, so dompdf is out. What a
prescription and an invoice actually need — text in columns, a few rules, a
table, one signature line — is well inside what the format can be driven to
do directly, using the base-14 fonts every reader already has, so nothing is
embedded and the file stays a few kilobytes.

Two rules fall out of *what* is being printed rather than how:

- **A draft invoice will not print** (409). Its number is a placeholder and
  its lines can still change; a printed copy would be a document nobody could
  rely on.
- **Nothing is stored.** `pdf_path` exists in the schema, but a saved file is
  a second copy of the truth, and the moment a clinician corrects a dosage the
  two disagree. The document is rendered from the record every time.

The billing suite checks the cross-reference table, not just the `%PDF` magic
bytes — a PDF with wrong offsets opens as a blank page in some readers and not
at all in others, which no assertion on the first eight bytes would catch.

### Queued is not sent, and "not configured" is not "failed"

`NotificationService` queues; `database/notify.php` delivers. Booking an
appointment must not wait on an SMTP handshake, and a dead SMS gateway must
not roll back a consultation.

The worker separates three outcomes, and the difference is the whole design:

| | meaning | what happens next |
|---|---|---|
| **sent** | it left | done |
| **skipped** | no credentials for that channel | closed off, with the reason written to the row |
| **failed** | configured, and it did not work | retried, up to five attempts, then given up on |

Marking an unconfigured channel *skipped* rather than leaving it queued is what
stops a clinic with no SMS gateway from accumulating a queue it will never
drain. Meanwhile the in-app copy — the one the patient actually reads — never
depended on the worker at all.

Channels are the Strategy pattern §13 asks for: `SmtpChannel`, `SmsChannel`,
`PushChannel`, each its own class. Adding WhatsApp is one more file.

```bash
php database/notify.php            # send what is due — put this on cron
php database/notify.php --status   # which channels are live, what is waiting
php database/notify.php --watch    # keep running, every 60s
```

## Two decisions from §22 — subscriptions

### A limit that is only displayed is not a limit

Plans, usage bars and a price list are the easy half. `SubscriptionService::
assertWithin()` is called at the top of every create path the plan counts —
patients, doctors, staff, appointments, invoices, storage, AI calls — and
throws **402 `plan_limit_reached`**, not 403.

The distinction matters to the client: 403 means "you may not", 402 means "you
have run out", and only one of those is fixed by upgrading. The suite proves
it against a brand-new organization on Free, which is what a real sign-up
gets — the demo clinic is seeded on Professional so its own data never trips
the wire.

Standing limits (doctors, staff, patients, storage) are counted from the
source tables, never from a stored counter: a clinic that removes a doctor
must get that seat back the same second. Only AI calls — which have no table
of their own — are tallied in `subscription_items`, and only after the
provider actually answered, so a failed call costs nothing.

### A downgrade that does not fit is refused, not applied

Switching to a smaller plan while over its limits would leave the clinic
instantly non-compliant with its own subscription, and nothing in the UI could
say which patients or accounts had become "extra". So `changePlan()` compares
every limit against current usage first and returns the full list of what is
in the way. Reduce, then switch.

## Three decisions from the last sweep of the plan

Re-reading §3, §4 and §25 line by line turned up three things the earlier
passes had skimmed. All three are small, and all three were listed.

### Money belongs on the doctor's dashboard, but only their own

§4 asks for "revenue and outstanding amounts" there and it was missing —
the screen showed appointment counts alone.

What it shows now is this doctor's own visits: billed today, collected today,
and everything of theirs still unpaid. Not the practice ledger, which is a
different question with a different audience — a clinician between patients
should not have to read the clinic's accounts to see how their morning went.
The ledger stays in Billing, where the people who own it work.

### A health summary is counts, not a retelling

§3 lists a "health summary" on the patient's dashboard. The temptation is to
restate the record — recent visits, current medicines, the last diagnosis —
which produces a fifth screen showing what four screens already show, and a
fifth place for them to disagree.

So it is eight numbers and one date: visits, prescriptions, lab orders,
allergies, active conditions, upcoming visits, unpaid invoices, and when they
were last seen. Enough to answer "where do I stand with this clinic" without
opening anything; not enough to be a second copy of the chart.

### The "intelligent search" is deliberately not AI

§25 lists both a patient summary and an intelligent search in the AI phase.
The summary belongs there: ordering nine visits and four conditions by what
matters is judgement, and that is what a model is for. It is advisory, it
writes nothing, and the suite asserts that summarising a chart leaves the
chart alone.

The search does not. A receptionist with a patient on the phone needs the same
answer, in the same instant, every time — and needs it whether or not the
clinic has bought an AI key. So `/search` is SQL across five surfaces at once:
patient names, MRNs, phones and emails; invoice and prescription numbers;
diagnoses; and prescribed medicines. What makes it worth having is not
cleverness but reach — "who did I diagnose with this" and "who is on that
medicine" are questions the patient list cannot answer at all.

Both are tenant-scoped like everything else. The suite checks that a search
for another clinic's patient returns nothing.

## Roadmap

Phase estimates are the plan's own (§25) and assume a team, not one developer.

| Phase | Scope | Plan estimate | Status |
|---|---|---|---|
| 1 | Architecture, DB, auth, RBAC, orgs, API framework, audit | 4–6 wk | **Done** |
| 2 | Patients, doctors, appointments, encounters, records, prescriptions | 6–8 wk | **Done** |
| 3 | Services, invoices, payments, refunds, reports | 5–7 wk | **Done** |
| 4 | Patient mobile app | 4–6 wk | **Done** |
| 5 | Insurance policies and claims | 6–10 wk | **Done** |
| 6 | AI billing / documentation / claim assistants | 6–10 wk | **Done** — needs a provider key to switch on |
| — | §22 subscriptions: plans, usage, enforced limits | — | **Done** |
| — | §21/§23 super admin: plan catalogue, markets, cross-tenant trail | — | **Done** |
| — | §3 patient booking, released reports; §4 prescription/invoice PDF; §20 delivery worker | — | **Done** |
| — | §4 doctor revenue, §3 health summary, §25 patient summary + search | — | **Done** |
| — | Sign-in and account screens across all three apps (§11) | — | **Done** |

MVP is Phase 1–4 (§26); insurance and AI sit outside the first release and are
built behind that line — insurance as its own module, AI as an optional one
that is off until a key is configured. The demo that sells the product is §27:

```
doctor logs in → opens appointment → consultation → diagnosis → prescription
     ✅ built        ✅ built           ✅ built       ✅ built     ✅ built

→ select services → generate invoice → take payment → receipt → notify patient
     ✅ built           ✅ built          ✅ built     ✅ built     ✅ built
```

**All ten steps run today**, the last of them into the patient app's inbox —
the patient sees the prescription, the invoice and the payment without anyone
telling them to look.

### Configuration still required, by phase

Nothing is needed for Phase 1. Later phases need real credentials, and the
code must not assume they exist until then:

| Phase | Needs |
|---|---|
| 2 / 4 | SMTP host, SMS gateway key (§20) |
| 3 | Payment gateway keys — Stripe, or JazzCash / Easypaisa for PK (§7) |
| 5 | Insurer claim formats and billing-code sets (§8) |
| 6 | `AI_PROVIDER=anthropic` + `ANTHROPIC_API_KEY` (§9) |

Placeholders are commented out in `.env.example`.

To switch the assistants on locally:

```bash
# real assistants
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
AI_MODEL=claude-opus-5        # optional

# or offline, rules-only — for development and the test suite.
# Refused when APP_ENV=production.
AI_PROVIDER=stub
```

`GET /ai/status` reports which one answered, and the clinic app shows its AI
buttons only when that says a provider is live.

---

## Conventions

- PHP 8.2, `declare(strict_types=1)` everywhere.
- Timestamps are UTC in the database; format at the edge.
- Money is `DECIMAL(14,2)`; use the `money()` helper before binding.
- New tenant table → `organization_id BIGINT UNSIGNED NOT NULL` + FK
  `ON DELETE CASCADE` + include it in the leading index.
- New repository → set `$tenantScoped`, `$fillable`, `$hidden` deliberately.
- New endpoint → declare its `perm:` slug on the route, and seed that slug into
  the roles that should hold it.
- Never call `withoutTenantScope()` outside `PlatformController` or a CLI script.
- `.env` is gitignored and must stay that way.
