# MediFlow — chhe phases ka hisaab

Requirement chhe development phases ginwati hai. Yeh file har phase ki har
cheez ke saamne likhti hai ke woh **kahan** hai aur uska **saboot** kya hai.

Har number 10 September 2026 ke live demo database se liya gaya hai, aur
har suite ka count us suite ko chala kar.

`test-phases.sh` in daaawon ko jaanchti hai, taake yeh file dobara chupke se
purani na ho jaye — pichli baar yeh 44 tables aur 99 foreign keys keh rahi thi
jab asal mein 45 aur 100 thay.

---

## Phase 1 — Foundation

*Architecture, database, auth, RBAC, organizations, audit logs*

| Cheez | Kahan | Saboot |
|---|---|---|
| Architecture | `Routes → Middleware → Controller → Validator → Service → Repository → Model → Database` | Controllers aur services mein **0 SQL**; 30 repositories |
| Database | 45 tables | 100 foreign keys, sab InnoDB |
| Auth | Access + refresh tokens, rotation ke saath | 7,736 token rows; purana token rotate hote hi mar jata hai |
| RBAC | 11 roles, 51 permissions, 218 mappings | `perm:` middleware har route par |
| Organizations | 53 clinics, 101 memberships | Har tenant table mein `organization_id` |
| Audit logs | 23,222 rows | user, action, resource, timestamp, route, method, IP, user-agent, request-id + old/new values |

Middleware: `Auth`, `Permission`, `PlatformAdmin`, `RateLimit`, `Tenant`

**Suites:** `smoke-test` (78) · `test-schema` (97) · `test-security` (38)

---

## Phase 2 — Core Healthcare

*Patients, doctors, appointments, consultations, records, prescriptions*

| Cheez | Rows |
|---|---|
| patients | 249 |
| doctors | 13 |
| appointments | 479 |
| encounters (consultations) | 406 |
| diagnoses | 271 |
| procedures | 173 |
| prescriptions | 195 |
| prescription_items | 256 |
| medications (catalogue) | 10 |
| allergies | 145 |
| medical_conditions | 4 |

**Suites:** `smoke-test-patient` (95) · `smoke-test-clinical` (69) · `test-rx` (22)

---

## Phase 3 — Billing

*Services, pricing, invoices, payments, refunds, financial reports*

| Cheez | Rows |
|---|---|
| services (catalogue) | 133 |
| service_prices | 216 |
| invoices | 1805 |
| invoice_items | 1962 |
| payments | 821 |
| refunds | 161 |

Financial reports: 4 routes (`/reports/financial`, `/reports/receivables`, aur
`/billing/reports/*`).

Do baatein jo qabil-e-zikr hain:

- **Refund maangna aur manzoor karna alag hain.** Jo role maang sakta hai
  (billing staff), woh `refund.approve` nahi rakhta — paisa wapis karna ek
  shakhs ka ek click nahi hona chahiye.
- **Invoice ka number issue ke waqt milta hai, banane ke waqt nahi** — warna
  chhore hue drafts numbering mein sooraakh kar dete, aur tax wale usay pasand
  nahi karte.

**Suites:** `smoke-test-billing` (111) · `test-payment` (18) · `test-billing-engine` (35)

---

## Phase 4 — Patient App

*Login, profile, search, appointments, records, bills, payments*

| Screen | File |
|---|---|
| Login / signup / forgot | `login.js`, `signup.js`, `forgot.js` |
| Dashboard | `(tabs)/index.js` |
| Profile | `(tabs)/profile.js` |
| Doctor search aur booking | `book.js` |
| Appointments | `(tabs)/appointments.js` |
| Records | `(tabs)/records.js` |
| Bills aur payment | `(tabs)/bills.js` |
| Notifications | `notifications.js` |
| Account | `account.js` |

Expo SDK 57. Payment gateway browser mein khulta hai aur app mein wapis aata hai.

**Ek design usool jo poore portal par lagu hai:** patient kabhi apna
`patient_id` nahi bhejta. Har endpoint session se record nikalta hai — "chart
47 dikhao" aisi darkhwast hai jiska jawab mumkin nahi hona chahiye.

**Suites:** `smoke-test-patient` (95) · `test-mvp` (27) · `test-location` (11)

---

## Phase 5 — Insurance

*Providers, policies, claims, status, rejection management*

| Cheez | Rows |
|---|---|
| insurance_providers | 4 |
| insurance_policies | 5 |
| claims | 335 |
| claim_items | 335 |

Claim ki halatein jo waqai istemaal hui: `submitted`, `processing`, `rejected`, `paid`
**210 claims** rejection ki wajah ke saath darj hain.

Faisla har **line** par darj hota hai, sirf kul raqam par nahi — insurer jo
chaar item manzoor kare aur paanchwan reject kare, usne kuch makhsoos kaha hai,
aur wajah usi item ke saath rehni chahiye.

**Suites:** `smoke-test-insurance` (89) · `test-claim` (13)

---

## Phase 6 — AI Module

*Documentation, billing suggestions, claim validation, AI assistant*

| Cheez | Endpoint |
|---|---|
| Clinical note draft | `POST /encounters/{id}/ai/draft-note` |
| Billing suggestions | `GET /encounters/{id}/ai/billing-suggestions` |
| Claim validation | `GET /claims/{id}/ai/review` |
| Patient summary | `GET /patients/{id}/ai/summary` |
| Status | `GET /ai/status` |

Providers strategy ke peechay: `AnthropicProvider` (asli), `StubProvider`
(tests ke liye), `NullProvider` (jab kuch configured na ho).

**Sab se ahem usool:** AI kuch bhi **final** nahi likhti.

- Draft note `is_ai_drafted = 1` aur `approved_by = NULL` ke saath save hoti hai
- Jab tak koi clinician manzoor na kare, woh chart ka hissa nahi — aur patient
  ke export ya app mein nazar nahi aati
- Claim review sirf risk score deta hai; claim ka status chhoota tak nahi

**Suite:** `smoke-test-ai` (61)

---

## Kul

| | |
|---|---|
| Phases | 6 / 6 |
| Test suites | 26 |
| Tests | 1,223 — sab paas |
| Backend | Core PHP 8, koi framework nahi |
| Apps | Patient app (Expo 57), clinic web, admin web |

---

## Timeline ke baare mein — ek saaf baat

Requirement har phase ke saath hafte likhti hai (Phase 1: 4–6 hafte, waghera).
Kul **31 se 47 hafte**.

Yeh file yeh **nahi** kehti ke kaam utne hafton mein hua. Yeh sirf yeh kehti hai
ke **har phase mein jo cheezein ginwayi gayi thin, woh sab maujood hain aur test
se sabit hain**. Woh hafte ek anumaan hain jo aap ke document mein likha hai —
kitna waqt waqai laga, woh aap hi bata sakti hain.
