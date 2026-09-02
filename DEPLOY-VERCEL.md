# Deploying MediFlow to Vercel

MediFlow is three deployable pieces plus a database. Vercel can host the three
pieces; the database has to live somewhere else, because **Vercel does not run
databases** — that is a platform limitation, not a configuration you can change.

| Piece | Where it goes |
|---|---|
| `backend/` — Core PHP API | Vercel (community PHP runtime) |
| `clinic_web/` — React + Vite | Vercel (static) |
| `admin_web/` — React + Vite | Vercel (static) |
| MySQL 8 | A free managed MySQL host — see Step 1 |

You will end up with **three Vercel projects** built from this one repository,
each with a different **Root Directory**.

---

## Read this before you start

Two things behave differently on Vercel than they do on your laptop:

**Uploaded documents will not persist.** `ClinicalController` writes files to
`backend/storage/app/public/documents`. Vercel's filesystem is read-only apart
from `/tmp`, and `/tmp` is wiped between requests. Everything else works;
uploading a patient document will fail. To fix it properly the upload path has
to move to object storage (Vercel Blob, S3, Cloudinary). Until then, avoid
demonstrating document upload.

**The PHP runtime is community-maintained.** Vercel supports PHP through
[`vercel-php`](https://github.com/vercel-community/php), not officially. It
works, but if the deploy fails on an unknown runtime, check that repository for
the current version and update the number in `backend/vercel.json`.

---

## Step 1 — Get a MySQL database

Any managed MySQL 8 works. Free options:

- **[Aiven](https://aiven.io/free-mysql-database)** — free MySQL plan
- **[Railway](https://railway.app)** — add a MySQL service
- **[Clever Cloud](https://www.clever-cloud.com)** — free MySQL add-on

Create the database and keep these five values, you need them twice:

```
host      port      database      username      password
```

---

## Step 2 — Load the schema

From your laptop, point the backend at the new database and run the migration
and seeds against it:

```bash
cd backend
# temporarily edit .env with the cloud values, then:
C:/xampp/php/php.exe database/migrate.php
C:/xampp/php/php.exe database/seed.php
C:/xampp/php/php.exe database/seed_clinical.php
C:/xampp/php/php.exe database/seed_billing.php
C:/xampp/php/php.exe database/seed_insurance.php
```

Put your local values back in `.env` afterwards — that file never leaves your
machine.

---

## Step 3 — Deploy the API

1. Go to [vercel.com/new](https://vercel.com/new) and import this repository.
2. **Root Directory:** `backend`
3. **Project Name:** something you will recognise, e.g. `mediflow-api` (this deployment used `mediflow-mobile-app`)
4. Under **Environment Variables**, add:

   | Name | Value |
   |---|---|
   | `DB_HOST` | your database host |
   | `DB_PORT` | usually `3306` |
   | `DB_DATABASE` | your database name |
   | `DB_USERNAME` | your database user |
   | `DB_PASSWORD` | your database password |
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `CORS_ORIGINS` | leave blank for now — Step 5 fills it in |

5. **Deploy.**

Check it worked:

```
https://mediflow-mobile-app.vercel.app/api/v1/health
```

You should get `{"data":{"status":"ok","database":"connected",...}}`.
If `database` says anything else, the five MySQL values are wrong.

**Copy the deployed URL.** The next step needs it.

---

## Step 4 — Point the front-ends at the API

In this repository, open **both** of these files:

- `clinic_web/vercel.json`
- `admin_web/vercel.json`

Replace `REPLACE-WITH-YOUR-API.vercel.app` with your real API host from Step 3:

```json
{ "source": "/api/:path*", "destination": "https://mediflow-api.vercel.app/api/:path*" }
```

Commit and push. This rewrite makes the browser see the API on its own origin —
the same trick the Vite dev proxy uses locally — so no application code changes.

---

## Step 5 — Deploy the two front-ends

Import the **same repository** twice more:

| Project | Root Directory | Suggested name |
|---|---|---|
| Clinic app | `clinic_web` | `mediflow-clinic` |
| Admin console | `admin_web` | `mediflow-admin` |

Vercel detects Vite automatically; `vercel.json` already pins the build command
and output directory, so leave those alone.

Once both are live, go back to the **API project → Settings → Environment
Variables** and set:

```
CORS_ORIGINS = https://mediflow-clinic.vercel.app,https://mediflow-admin.vercel.app
```

Redeploy the API so it picks the value up.

---

## Step 6 — Check it end to end

Open the clinic app and sign in with a seeded account:

| Email | Password | Sees |
|---|---|---|
| `owner@clinic.test` | `Password123` | Everything in the demo clinic |
| `doctor@clinic.test` | `Password123` | Clinical screens only |
| `admin@mediflow.test` | `Password123` | Admin console, all tenants |

If the login spins and fails, open the browser console. A CORS error means
Step 5 was missed; a 500 means the database variables are wrong.

---

## The patient app

`patient_app/` is React Native and does not belong on Vercel. Ship it with EAS:

```bash
cd patient_app
npx eas-cli build -p android --profile preview
```

It works out the API address from whatever served it, so point it at the
deployed API when you build for real distribution.

---

## If you would rather not split it up

Everything — PHP, MySQL and both front-ends — runs on a single **Railway**
project with no filesystem limitation and no separate database provider. That
is the shorter path if the marking criteria do not specifically require Vercel.
