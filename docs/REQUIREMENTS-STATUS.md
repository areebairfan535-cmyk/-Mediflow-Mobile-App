# MediFlow — requirement ka hisaab

Har requirement jo aap ne bheji, uska status. Har nayi requirement ke baad
yeh file update hoti hai.

**Aakhri update:** 10 September 2026 (26 requirements, 1,291 tests green)

---

## Kul hisaab

| | |
|---|---|
| Requirements bheji gayin | **26** |
| Poori ho chukin | **26** |
| Test suites | **26** (ab repo mein: `backend/database/`) |
| Kul tests | **1,291 — sab green** |
| Teeno app chal kar dekhi gayin | ✅ clinic 5174, admin 5173, patient 8082 |
| GitHub par | **34 commits abhi nahi gaye** (branch `requirements-sweep`) — folder mein sab hai, online purana hai |

Aakhri wali line pehle "sab kuch push ho chuka" kehti thi. Woh sach tha jab
likhi gayi, aur baad mein sach nahi raha — kaam hota raha aur push roka gaya.
Aisi line sab se khatarnaak hoti hai: ghalat honay par bhi ittminaan deti
hai. Push hote hi yeh badal deni hai.

---

## Pehle session mein (1–17)

Yeh sab poori ho chukin aur push ho chukin.

| # | Requirement | Kya hua |
|---|---|---|
| 1 | Patient Profile | ✅ |
| 2 | Dashboard | ✅ |
| 3 | Appointments Management | ✅ |
| 4 | Medical Records & History | ✅ |
| 5 | Billing & Payments | ✅ PayPal add kiya |
| 6 | Notifications & Updates | ✅ |
| 7 | Doctor Dashboard | ✅ |
| 8 | Consultation Workflow | ✅ pichle visits add kiye |
| 9 | Digital Prescription Generation | ✅ |
| 10 | Killer MVP Workflow | ✅ `test-mvp.sh` |
| 11 | Super Admin Dashboard | ✅ |
| 12 | Management & Control Features | ✅ |
| 13 | Core Ecosystem & Medical Records | ✅ |
| 14 | Medical Billing & Payments Engine | ✅ |
| 15 | Insurance & Claims Module | ✅ |
| 16 | AI Module | ✅ |
| 17 | Security, Compliance & Multi-Tenancy | ✅ patient data export banaya (GDPR Art. 15/20, HIPAA §164.524) |

---

## Is session mein (18–23)

### 18 — Backend & Architecture Details ✅

Do gaps thay, dono bhare:

- **Controllers mein 30 direct SQL** → **0**. `PlatformController` 626 se 423 lines.
- **`app/Models/` khaali directory thi** → 26 model classes ka asli layer.
- Saath hi: **services mein 104 direct SQL** → **0**. 14 naye repositories.

Faida sirf safai nahi: "kaun se columns secret hain" ab ek chhoti file parh
kar pata chal jata hai, har query audit kiye baghair.

### 19 — API Structure & Endpoints ✅ *(10 Sept ko dobara jaanchi — neeche)*

Baarah groups mein se 10 sahi thay. Do ke naam ghalat, do bilkul ghayab.

- `/labs` aur `/billing` groups banaye (purane paths bhi chalte hain)
- **`/notifications` — staff ka inbox tha hi nahi.** Sirf `/patient/notifications` tha
- **`/prescriptions` — koi list hi nahi thi**, sirf id se milti thi
- `GET /api/v1` pehle 404 deta tha, ab apne groups ginwata hai
- Naye: `/labs/orders/{id}`, `/labs/results`, `/payments/{id}`

**Suite:** `test-api.sh` — 58 *(ab 97)*

### 20 — Database Architecture & Tables ✅

Saari 31 tables maujood, 99 foreign keys *(ab 100)*, sab InnoDB, har tenant
table mein `organization_id`.

- **`staff` table bani hui thi magar khaali aur bekaar** — code mein kahin use
  hi nahi hoti thi. Ab zinda: employee number, department, designation, hire
  date — database se Team screen tak.

Nota: requirement "28 tables" kehti hai magar us mein **31 naam** ginwaye hain.
Ginti spec mein ghalat hai, project mein nahi.

**Suite:** `test-schema.sh` — 44 *(ab 97)*

### 21 — Security & Audit Logging ✅

Audit log pehle se mukammal tha (user, action, resource, timestamp, route,
method, IP, user-agent, request-id, aur old/new values). Financial reads bhi
log hote hain, sirf clinical nahi.

Chaar controls ke gaps bhare:

- **HSTS** — ab bhejte hain, magar sirf jab request waqai TLS par ho
- **CSP** — `default-src 'none'`
- **`X-Powered-By`** PHP version leak kar raha tha — hataya
- **Backups aur disaster recovery bilkul nahi thay** — `backup.php` aur
  `restore.php` banaye, aur restore ko scratch database par **aazma kar dekha**

**Suite:** `test-security.sh` — 34 *(ab 38)*

### 22 — Compliance & Localization ✅

Chaaron markets (PK, US, GB, AE) pehle se configured thay. Compliance ki
honesty bhi durust thi — README saaf kehta hai bagair audit ke formal claim
nahi.

- **`date_format`, `timezone`, `currency_symbol` database mein thay magar
  documents unhein parhte hi nahi thay.** Har invoice `d M Y` aur `UTC`
  chhapti thi. Karachi clinic ki invoice par `09 Sep` chhapta tha jis din
  wahan **10 tareekh** ho chuki hoti thi.

**Suite:** `test-localization.sh` — 29 *(ab 35)*

### 23 — File Management & Notifications ✅

Notification engine pehle se mazboot tha. File storage bhi zyada tar.

- **`whatsapp` enum mein tha magar uski class nahi thi** — ab `WhatsAppChannel`
  hai, band haalat mein (SKIPPED)
- **Prescription aur invoice ke PDF kabhi store hi nahi hote thay** — 63 issued
  prescriptions aur 528 issued invoices mein se **ek par bhi `pdf_path` nahi
  tha**. Ab issue karte waqt SHA-256 ke saath file rakhi jaati hai. Draft phir
  bhi store nahi hota.

**Suite:** `test-files.sh` — 41 *(ab 59)*

### 24 — Development Phases & Timeline ✅

Banane ko kuch nahi tha — chhe-o phases ki har cheez pehle se maujood.
Deliverable: har phase ka saboot ek jagah.

**Dastavez:** `PHASES.md`

### 25 — MVP Scope & Killer Workflow ✅

Do hisse. Killer workflow pehle se poora (`test-mvp.sh` 27/27). Naya hissa
exclusions ka tha — yeh sabit karna ke saat cheezein jo mana thin, woh **nahi**
banin. Saat-o ka daayra mehfooz nikla.

Do baareek farq: lab mein *ordering* hai magar *lab management* nahi; insurance
ka *module* hai magar insurer se *integration* nahi.

**Dastavez:** `MVP-SCOPE.md`

### 26 — Onboarding, Differentiator & Future Expansions ✅

Teen hisse. Onboarding flow poora maujood tha — naya suite ek bilkul naya
clinic banata hai (jo test shuru hote waqt maujood nahi tha) aur usay pehle
patient tak le jata hai. Differentiator positioning hai; revenue cycle waqai
chalta hai. Future expansions explicitly future.

**10 September ko dobara jaanchi** ("same wahi karna hai"). Onboarding sahi
tha — 25/25 green, aur paanchon qadam tarteeb se. Baaki do hisse **sirf
zubani** the: code chalta tha, magar likha kahin nahi tha. Ab README mein do
hisse aa gaye:

- **"What this is, and what it is not"** — patient app front door hai, iska
  saboot ye ke wahan se paisa diya ja sakta hai (`POST /invoices/{id}/pay`),
  aur amount client se nahi aati, invoice se padhi jati hai. Billing engine
  hai, iska saboot demo DB ke asli aadad: 1,849 invoices (205 seedha visit
  se), 841 payments, 345 claims, aur payer split — 14,634,664 patient par,
  595,094 insurer par.
- **"Future expansions"** — chhe raaste, har ek ke saamne **do** cheezein:
  aaj kya mojood hai jis par woh banega, aur kya waqai baaki hai.

Ek cheez pakdi gayi jo pehle nahi dikhi thi: `appointments.type` mein
**`teleconsult` pehle se mojood hai** aur ek booking us par hai — magar us se
koi farq nahi parta. Na video, na session. Value qubool hoti hai aur
nazarandaz ho jati hai. Yeh wahi purana jaal hai
([Ek baat jo baar baar nikli](#ek-baat-jo-baar-baar-nikli)), isliye README mein
saaf likh diya hai taake koi ise aadha bana hua telemedicine na samjhe.

Ek baat samajhne laayak: doctor ko team mein add karne se woh **bookable nahi**
hota — uska clinical profile (`POST /doctors`) alag qadam hai, aur yeh jaan
boojh kar hai. Team ka hissa hona (access) aur bookable clinician hona
(specialty, fee, slot) do alag baatein hain.

**Suite:** `test-onboarding.sh` — 25

### Saath hi: test suites repo mein aa gayin

15 suites (~400 tests) sirf temp folder mein pari thin — temp saaf hote hi
khatam ho jatin. Ab `backend/database/` mein hain, ek runner ke saath:

    bash database/run-all-tests.sh

Do bugs bhi theek kiye jo **test ke apne** thay, code ke nahi:

- Nayi suites rate limiter ka bucket saaf nahi karti thin, is liye ek suite
  doosri ki wajah se 429 deti thi — aisi failure jo asli lagti hai magar akele
  chalane par kabhi nahi hoti
- `pdftext.php` helper repo mein le jaate waqt chhoot gaya tha, jis se PDF ke
  6 checks ek bilkul theek PDF par fail ho rahe thay

---

## 10 September — wahi chhe requirements, dobara

Aap ne 19–24 dobara bhejin. Har ek mein kuch nikla, aur tqreeban har baar
ek hi shakl ka: **code sahi tha, us par nazar rakhne wali cheez nahi thi.**

| # | Dobara jaanchne par kya nikla |
|---|---|
| 19 API | Baarah groups sab chal rahe hain. Magar README ki endpoint list **82 routes** se peechay thi, aur `/billing`, `/payments`, `/insurance`, `/claims` aur staff ka `/notifications` us mein **bilkul nahi** thay — parhne wala samajhta ke insurance ka koi HTTP surface hi nahi. Ab list poori hai aur `test-api` usay router ki apni table se milata hai. |
| 20 Database | Saari tables maujood. Teen structural baatein sach thin magar koi jaanchta nahi tha: har tenant table ke `organization_id` par index, poore schema par ek hi charset, har table par primary key. |
| 21 Security | XSS ka check **fail ho hi nahi sakta tha** — dono branch `ok()` bulate thay, aur agli line response ke *body* mein ek header dhoondti thi jo kabhi mangwaya hi nahi gaya. Aur **22 jagah** audit `update` bina purani value ke likhta tha: 4,680 se 9,360 hui invoice trail mein sirf "9360" thi. |
| 22 Compliance / Localization | Tax ka **rate** configurable tha, **rule** nahi. Nayi market kholo to hamesha "upar lagao, naam Tax" milta — VAT-inclusive mulk mein har invoice rate ke barabar **zyada**. Ab `tax_mode` aur `tax_label` columns hain; Ireland poore API se khola gaya. |
| 23 Files / Notifications | Har document par SHA-256 likha jata tha aur **kabhi parha nahi jata tha** — jabke `DocumentStore` ke apne comment mein likha hai ke checksum hi us copy ko qeemti banata hai. Ab download se pehle verify hota hai; badle huye bytes 409 dete hain. |
| 24 Phases | `PHASES.md` khud purani ho chuki thi — 44 tables aur 99 foreign keys keh rahi thi jab 45 aur 100 thay. Ab `test-phases.sh` us ke daawe jaanchti hai. |

Raaste mein do bugs bhi nikle jo kisi requirement ne nahi maange thay:

- **Paged list se row gum ho sakti thi.** `invoices`, `claims`, `patients`,
  `prescriptions` aur platform ki organizations `created_at` par order karti
  thin bina tiebreaker ke. Ek hi second mein bani rows ka order defined nahi
  hota, to page ke kinare wali row **do pages par** dikhti ya **kisi par
  nahi**. `/invoices` do lagataar requests par do alag lists de raha tha.
- **Layering check chal hi nahi raha tha** jab suite `backend/database` se
  chalayi jati — chhe check zor se fail hote aur chaar **khaamoshi se pass**,
  kyunke khali grep ka matlab khali leak list hai.

---

## Ab kya baaki hai

**Aap ke document se koi nayi requirement abhi nahi aayi.**

Jo aap ne bheji, sab poori ho chuki hain. 10 September ko 19–24 dobara jaanchi
gayin aur har ek mein kuch nikla — is liye "poori ho chuki" ka matlab yeh
nahi ke dobara dekhne se kuch na milega. Aam taur par jo milta hai woh nateeje
ki ghalati nahi hoti; woh yeh hoti hai ke koi qaida sach to hai magar us par
koi pehredaar nahi.

**Do faisle aap ke zimme hain:** branch `requirements-sweep` (34 commits) main
mein merge karni hai ya PR kholna hai, aur kya MariaDB + PHP server band kar
doon jo main ne chalaye thay. Agar aap ke MediFlow document mein
aur sections hain, woh bhej dein — main wahi tarteeb rakhungi: pehle audit
(kya pehle se hai, kya nahi), phir jo kami ho woh banana, phir test se sabit
karna.

### Do chhote kaam jo mere zimme hain

- [ ] Dev posts 3, 4, 5 ke captions (post 1 aur 2 ho chuke)

---

## Ek baat jo baar baar nikli

Chaar alag requirements mein **ek hi qism ka masla** mila: cheez database mein
**maujood** thi, magar koi usay **parhta nahi tha**.

| Requirement | Kya khaali para tha |
|---|---|
| 20 | `staff` table — bani hui, 0 rows, kahin use nahi |
| 22 | `date_format`, `timezone` — resolve hote thay, documents parhte nahi thay |
| 23 | `pdf_path` — dono tables par, kabhi likha nahi gaya |
| 23 | `whatsapp` — enum mein tha, class nahi thi |

Isi liye main har requirement par sirf "column maujood hai?" nahi dekhti —
yeh dekhti hoon ke **koi usay parhta bhi hai ya nahi.** Jo cheez koi na parhe,
woh configuration nahi, diagram hai.
