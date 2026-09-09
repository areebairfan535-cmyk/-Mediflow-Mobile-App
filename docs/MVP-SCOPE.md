# MediFlow — MVP ka daayra aur Killer Workflow

Requirement do baatein kehti hai: kya **nahi** banana, aur woh **ek flow** jo
poore product ko sabit karta hai. Dono ka hisaab.

Har cheez 9 September 2026 ke live database aur code se dekhi gayi.

---

## Hissa 1 — Jo nahi banana tha, woh nahi bana

Scope creep asli khatra hota hai: har feature maqool lagta hai, aur MVP kabhi
release nahi hota. Requirement ne saat cheezein mana ki thin. Saat-o ka daayra
mehfooz raha.

| Jo mana tha | Tasdeeq kaise ki | Natija |
|---|---|---|
| **Hospital ERP** | payroll, salary, ward, bed, admission, roster, leave — koi table dhoondi | ek bhi nahi ✅ |
| **Full pharmacy** | stock, inventory, dispensing, batch, supplier, purchase — koi table dhoondi | ek bhi nahi ✅ |
| **Lab management** | sample, specimen, equipment, analyser, barcode — koi table dhoondi | ek bhi nahi ✅ |
| **Insurance integrations** | `claim_format` ki values, aur claim code mein koi outbound HTTP call | sirf `manual`, koi call nahi ✅ |
| **AI diagnosis** | AI service kya kya likh sakti hai | sirf 2 cheezein, `diagnoses` nahi ✅ |
| **Telemedicine** | video, call — koi table dhoondi | ek bhi nahi ✅ |
| **Wearables** | device, wearable, vital stream — koi table dhoondi | ek bhi nahi ✅ |

### Do baareek farq jo samajhna zaroori hai

**Lab: ordering hai, lab management nahi.**
`lab_orders` aur `lab_results` maujood hain — doctor test mangwa sakta hai aur
nateeja darj ho sakta hai. Yeh **clinical record** ka hissa hai. Lab management
alag cheez hai: sample tracking, machine, technician workflow, barcode. Woh
nahi bana, aur banna bhi nahi chahiye tha.

**Insurance: module hai, integration nahi.**
Phase 5 mein insurance banaya gaya — providers, policies, claims, rejection
tracking. Magar kisi insurer ke system se **baat nahi hoti**. `claim_format`
har provider par `manual` hai aur claim ke code mein ek bhi outbound HTTP call
nahi. Yani claim andar track hota hai, bahar bheja nahi jata.

Yeh farq aap ke teacher ko bataane laayak hai — requirement ne "insurance
integrations" mana ki thi, insurance module nahi.

**AI: mashwara deta hai, faisla nahi karta.**
Poori AI service sirf do cheezein likhti hai:

1. Note ki approval — aur woh tabhi chalti hai jab **koi clinician** khud
   approve kare
2. Claim par ek risk score — jo sirf mashwara hai, claim ka status chhoota tak
   nahi

Draft note `is_ai_drafted = 1` aur `approved_by = NULL` ke saath save hoti hai.
Jab tak insaan manzoor na kare, woh chart ka hissa nahi — patient ke export
mein bhi nahi jati.

AI `diagnoses` table mein **kabhi** nahi likhti.

---

## Hissa 2 — Killer Workflow

Requirement ka flow:

> Doctor login → appointment open → consultation/diagnosis → prescription →
> services select karke invoice → payment + receipt → patient ko foran mobile
> app par sab nazar aaye

Yeh poora flow ek test suite chalati hai: **`test-mvp.sh` — 27/27 pass**

| Qadam | Kya test hota hai |
|---|---|
| 1. Doctor login | token milta hai, dashboard khulta hai |
| 2. Appointment open | aaj ka appointment consultation ban jata hai |
| 3. Consultation | vitals, chief complaint, diagnosis darj |
| 4. Prescription | dawaiyan add, issue, aur PDF khulta hai |
| 5. Invoice | visit se draft banti hai, issue hoti hai, total sahi |
| 6. Payment | receipt banti hai, invoice `paid` par jati hai, balance 0 |
| 7. Patient app | dashboard, records mein diagnosis, dawaiyan, PDF, bill, receipt, aur teenon notifications |

Aakhri qadam sab se ahem hai — woh sirf yeh nahi dekhta ke API ne 200 diya,
balki yeh ke **patient ke phone par woh cheez waqai nazar aa rahi hai**. Kaam
tab poora hai jab patient usay dekh le, tab nahi jab server "ok" keh de.

---

## Kul

| | |
|---|---|
| Exclusions | 7 / 7 ka daayra mehfooz |
| Killer workflow | 27 / 27 tests pass |

MVP wahi hai jo hona chahiye tha: ek chhota, poora product jo shuru se aakhir
tak chalta hai — na ke aadha hospital system.
