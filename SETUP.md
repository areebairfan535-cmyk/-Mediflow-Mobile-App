# Running MediFlow

Everything below has been run on a clean Windows machine with XAMPP. Follow it
in order and the three apps come up with a clinic already in them — patients,
appointments, prescriptions and bills — so there is something to look at from
the first screen.

---

## What you need

| | | |
|---|---|---|
| **XAMPP** | PHP 8.2+ and MariaDB | <https://www.apachefriends.org> |
| **Node.js** | 18 or newer | <https://nodejs.org> |
| **Expo Go** | on an Android or iOS phone, for the patient app | Play Store / App Store |

No Composer. The backend is core PHP with its own autoloader — there is nothing
to install on the PHP side.

---

## 1. Start MariaDB

Open the **XAMPP Control Panel** and press **Start** next to *MySQL*.
Apache is not needed; PHP serves the API itself.

## 2. Set everything up — one command

Double-click **`setup.bat`**.

Or, from a PowerShell window in this folder:

```powershell
.\setup.ps1
```

It creates the database, builds the 43 tables, fills them with the demo clinic,
and installs the front-end packages. It takes a few minutes, mostly npm, and
says what it is doing as it goes. Safe to run again if anything is interrupted.

It also writes `backend/.env` on the first run. The archive does not carry
one: a .env is a machine's own settings and can hold credentials, so it is
kept out of anything that gets shared. `backend/.env.example` shows every
setting there is.

If PowerShell refuses to run the .ps1 directly, allow scripts for that one
window first (setup.bat does this for you):

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\setup.ps1
```

## 3. Start the three apps

Each needs its own terminal, and each must stay open.

**The API** — everything else talks to this:

```powershell
cd backend
C:\xampp\php\php.exe -S 0.0.0.0:8000 -t public
```

**The clinic app** — <http://localhost:5174>

```powershell
cd clinic_web
npm run dev
```

**The admin console** — <http://localhost:5173>

```powershell
cd admin_web
npm run dev
```

**The patient app** — scan the QR with Expo Go:

```powershell
cd patient_app
npx expo start
```

---

## Signing in

Every account uses the password **`Password123`**.

| Where | Email | What they see |
|---|---|---|
| Clinic app | `doctor@clinic.test` | Consultations, prescriptions, charts |
| Clinic app | `reception@clinic.test` | Booking, registration, taking payment |
| Clinic app | `billing@clinic.test` | Invoices, claims, refunds |
| Clinic app | `owner@clinic.test` | Everything in the clinic |
| Admin console | `admin@mediflow.test` | Every clinic, the plans, the markets |
| Admin console | `owner@clinic.test` | This clinic's team, roles, plan, audit |
| Patient app | `patient@demo.test` | Fatima Noor's own appointments and bills |

The patient app's login screen has a one-tap shortcut for the demo account.

Signing in as a doctor and then as a receptionist is worth doing: the same
screens show different things, because permissions are checked on the server,
not hidden in the interface.

---

## The phone needs two more things

The patient app runs on the phone but the API runs on this computer, so:

**Same Wi-Fi.** The phone and the laptop must be on one network.

**One firewall rule.** Windows blocks incoming connections by default, which is
why a phone can load the app and then show *"cannot reach the clinic"*. Run this
once in PowerShell and accept the prompt:

```powershell
Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile','-Command','New-NetFirewallRule -DisplayName "MediFlow dev" -Direction Inbound -Protocol TCP -LocalPort 8000,8081,8082 -Action Allow -Profile Private,Public'
```

The app works out the API address from whatever address Expo is serving on, so
there is nothing to configure by hand.

---

## Checking it works

Eight test suites cover the API end to end — 563 assertions over auth, tenancy,
permissions, the clinical flow, billing, insurance, the platform and plan
limits. They need **Git Bash** (installed with Git for Windows) and the API
running:

```bash
cd backend
bash database/smoke-test.sh
bash database/smoke-test-clinical.sh
bash database/smoke-test-billing.sh
bash database/smoke-test-insurance.sh
bash database/smoke-test-patient.sh
bash database/smoke-test-platform.sh
bash database/smoke-test-subscription.sh
bash database/smoke-test-ai.sh
```

Each prints `passed: N   failed: 0` at the end and exits non-zero if anything
fails. They are re-runnable in any order — each creates what it needs.

---

## Optional: sending real email

Notifications (appointment reminders, password reset codes) queue in the
database and go out through a worker. Without mail settings the worker marks
email *not configured* and everything else carries on working — the in-app
inbox does not depend on it.

To send for real, put a Gmail app password in `backend/.env`:

```
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=abcdefghijklmnop
MAIL_FROM=you@gmail.com
MAIL_FROM_NAME=MediFlow
```

`MAIL_PASSWORD` is **not** the Gmail password. Turn on 2-Step Verification, then
Google Account → Security → **App passwords**, and use the 16 characters it
gives you.

Check it, then leave the worker running:

```powershell
cd backend
C:\xampp\php\php.exe database\mail-test.php you@gmail.com
C:\xampp\php\php.exe database\notify.php --watch
```

---

## If something is wrong

**"Cannot reach the clinic" on the phone** — the firewall rule above, or the
API is not running, or the phone is on mobile data instead of the Wi-Fi.

**The app opens but everything is empty** — the API is talking to a database
that was never seeded. Run `.\setup.ps1` again; it is safe to repeat.

**"Database connection failed"** — MariaDB is not started. XAMPP Control Panel,
Start next to MySQL.

**Port 8000 already in use** — something else has it:

```powershell
Get-NetTCPConnection -LocalPort 8000 -State Listen | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
```

**Expo will not start** — it is already running. Same command, port 8081 or
8082, then start it again.
