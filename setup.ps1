# MediFlow - one-command setup.
#
#   .\setup.ps1
#
# Creates the database, builds the schema, fills it with the demo clinic, and
# installs the front-end packages. Safe to run again: every step checks what is
# already there, so an interrupted run is fixed by running it once more.
#
# It changes nothing outside this folder and the `mediflow` database.
#
# Two Windows PowerShell habits are deliberate here, and both have bitten this
# script already:
#
#   * Plain ASCII only. PowerShell reads a .ps1 as ANSI unless it carries a
#     byte-order mark, and it accepts curly quotes as real string delimiters -
#     so one em-dash in a comment arrives as three bytes, one of which closes a
#     string, and the parse error lands eighty lines away from the dash.
#
#   * External programs go through Invoke-Native. npm prints its deprecation
#     notices to stderr; with $ErrorActionPreference = 'Stop' PowerShell turns
#     those into a terminating NativeCommandError, and setup dies on a warning
#     about a package nobody asked about.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path

function Step($text) { Write-Host "`n=== $text" -ForegroundColor Cyan }
function Good($text) { Write-Host "  $text" -ForegroundColor Green }
function Warn($text) { Write-Host "  $text" -ForegroundColor Yellow }
function Bad($text)  { Write-Host "  $text" -ForegroundColor Red }

# Run an external program, keep its exit code, and do not let anything it
# prints to stderr be mistaken for a failure.
function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string] $Exe,
        [string[]] $Arguments = @(),
        [switch]   $Quiet
    )

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($Quiet) {
            & $Exe @Arguments 2>&1 | Out-Null
        } else {
            & $Exe @Arguments 2>&1 | ForEach-Object { Write-Host "  $_" }
        }
        return $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previous
    }
}

Write-Host "MediFlow setup" -ForegroundColor White
Write-Host "==============" -ForegroundColor White

# ---------------------------------------------------------------
# What we need before anything else
# ---------------------------------------------------------------
Step "Looking for PHP, MariaDB and Node"

# XAMPP's usual home, then anything on PATH.
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path $php)) {
    $found = (Get-Command php -ErrorAction SilentlyContinue).Source
    if ($found) { $php = $found }
}
if (-not (Test-Path $php)) {
    Bad "PHP not found. Install XAMPP (https://www.apachefriends.org) and run this again."
    exit 1
}
Good "PHP    $php"

$mysql = 'C:\xampp\mysql\bin\mysql.exe'
if (-not (Test-Path $mysql)) {
    $found = (Get-Command mysql -ErrorAction SilentlyContinue).Source
    if ($found) { $mysql = $found }
}
if (-not (Test-Path $mysql)) {
    Bad "MariaDB client not found. Install XAMPP and run this again."
    exit 1
}
Good "MySQL  $mysql"

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    Bad "Node.js not found. Install it from https://nodejs.org and run this again."
    exit 1
}
Good "npm    found"

# ---------------------------------------------------------------
# Is the server actually up?
# ---------------------------------------------------------------
Step "Checking MariaDB is running"

$code = Invoke-Native $mysql @('-u', 'root', '-e', 'SELECT 1;') -Quiet
if ($code -ne 0) {
    Bad "MariaDB is not answering."
    Warn "Open the XAMPP Control Panel and press Start next to MySQL, then run this again."
    exit 1
}
Good "answering on localhost"

# ---------------------------------------------------------------
# The database itself. migrate.php builds the tables but does not
# create the database, so that happens here.
# ---------------------------------------------------------------
Step "Creating the database"

$create = 'CREATE DATABASE IF NOT EXISTS mediflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
$code = Invoke-Native $mysql @('-u', 'root', '-e', $create) -Quiet
if ($code -ne 0) { Bad "Could not create the database."; exit 1 }
Good "mediflow ready"

# The backend reads its settings from .env. The file in the archive is already
# set up for a stock XAMPP (root, no password); this only helps if it is gone.
$envPath = Join-Path $root 'backend\.env'
if (-not (Test-Path $envPath)) {
    Warn ".env was missing - writing one for a stock XAMPP"

    $envLines = @(
        'APP_NAME=MediFlow',
        'APP_ENV=local',
        'APP_DEBUG=true',
        'APP_URL=http://localhost:8000',
        'APP_TIMEZONE=UTC',
        '',
        'DB_HOST=127.0.0.1',
        'DB_PORT=3306',
        'DB_DATABASE=mediflow',
        'DB_USERNAME=root',
        'DB_PASSWORD=',
        '',
        'AUTH_ACCESS_TTL_MINUTES=30',
        'AUTH_REFRESH_TTL_DAYS=30',
        'AUTH_MAX_FAILED_LOGINS=5',
        'AUTH_LOCKOUT_MINUTES=15',
        '',
        'RATE_LIMIT_ENABLED=true',
        'RATE_LIMIT_WINDOW=60',
        'RATE_LIMIT_MAX=120',
        'RATE_LIMIT_AUTH_MAX=10',
        '',
        'MAX_UPLOAD_MB=25',
        'CORS_ORIGINS=*',
        'AI_PROVIDER=stub'
    )
    Set-Content -Path $envPath -Value $envLines -Encoding UTF8
    Good "backend\.env written"
}

# ---------------------------------------------------------------
# Schema, then data. The order matters: each seeder builds on the
# one before it, and the last one is what stops the patient app
# opening onto empty screens.
# ---------------------------------------------------------------
Step "Building the schema"

Push-Location (Join-Path $root 'backend')
try {
    $code = Invoke-Native $php @('database\migrate.php')
    if ($code -ne 0) { Bad "Migrations failed."; exit 1 }

    Step "Filling it with the demo clinic"

    $seeders = @(
        @{ file = 'seed.php';           what = 'countries, permissions, roles, plans, staff' },
        @{ file = 'seed_clinical.php';  what = 'medicines, schedules, patients, appointments' },
        @{ file = 'seed_billing.php';   what = 'services and the price list' },
        @{ file = 'seed_insurance.php'; what = 'insurers and policies' },
        @{ file = 'seed_demo.php';      what = 'the demo patient visit, prescription and bills' }
    )

    foreach ($s in $seeders) {
        Write-Host ("  {0,-22} {1}" -f $s.file, $s.what)
        $code = Invoke-Native $php @(('database\' + $s.file)) -Quiet
        if ($code -ne 0) {
            Bad ($s.file + " failed. Run it on its own to see why:")
            Warn ("cd backend; " + $php + " database\" + $s.file)
            exit 1
        }
    }
    Good "demo clinic ready"
}
finally {
    Pop-Location
}

# ---------------------------------------------------------------
# Front ends
# ---------------------------------------------------------------
Step "Installing the front-end packages (this is the slow part)"

foreach ($app in @('clinic_web', 'admin_web', 'patient_app')) {
    $dir = Join-Path $root $app
    if (-not (Test-Path $dir)) { Warn "$app is missing - skipped"; continue }

    if (Test-Path (Join-Path $dir 'node_modules')) {
        Good "$app already has its packages"
        continue
    }

    Write-Host "  $app ..."
    Push-Location $dir
    try {
        # Through cmd, so npm's own warnings stay warnings.
        $code = Invoke-Native 'cmd.exe' @('/c', 'npm install --no-audit --no-fund') -Quiet
        if ($code -ne 0) {
            Bad "npm install failed in $app"
            Warn "Run it by hand to see why:  cd $app; npm install"
            exit 1
        }
        Good "$app done"
    }
    finally {
        Pop-Location
    }
}

# ---------------------------------------------------------------
Write-Host @"

===============================================================
Setup complete.

Start these in three separate terminals, and leave them running:

  cd backend       ; C:\xampp\php\php.exe -S 0.0.0.0:8000 -t public
  cd clinic_web    ; npm run dev        ->  http://localhost:5174
  cd admin_web     ; npm run dev        ->  http://localhost:5173
  cd patient_app   ; npx expo start     ->  scan the QR with Expo Go

Sign in with any of these. The password for all of them is Password123:

  doctor@clinic.test      the clinic app, as a doctor
  reception@clinic.test   the clinic app, as the front desk
  owner@clinic.test       the clinic app or the admin console
  admin@mediflow.test     the admin console, as platform staff
  patient@demo.test       the patient app

For the phone: same Wi-Fi, and the one firewall rule in SETUP.md.
===============================================================

"@ -ForegroundColor White
