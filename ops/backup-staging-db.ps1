# Daily backup of the whity_staging_postgres database (whity_core).
#
# THIS FILE IS CANONICAL. A second copy is what the Windows scheduled task
# `WhityStagingDbBackup` currently executes:
#
#     C:\Projects\Whity\ops\backup-staging-db.ps1
#
# It lived ONLY there until 2026-09-11 — unversioned, on one disk, next to the
# data it exists to protect. If that disk died, the backups and the means of
# restoring them went together, which is a poor arrangement for the one script
# whose entire job is surviving a disaster.
#
# TWO COPIES DRIFT. Until the scheduled task is re-pointed at this one, a change
# made here does nothing until it is copied across, and a change made there is
# invisible to review. Compare them before trusting either:
#
#     diff <repo>/ops/backup-staging-db.ps1 C:\Projects\Whity\ops\backup-staging-db.ps1
#
# Added after a 2026-07-19 incident where the staging DB was found completely
# empty (0 tables, core_schema_migrations gone) with no backup available to
# restore from - recovery required a full migrate+seed and manual
# reconstruction of global settings from session notes. This script exists so
# that never happens again without a fallback.
#
# Runs pg_dump INSIDE the postgres container (avoids needing a pg_dump binary
# on the Windows host), gzips it, writes to a local backups dir, and prunes
# anything older than RetentionDays.
#
# IT ALSO RUNS AT STARTUP, not only at 03:00. On 2026-09-09 the daily trigger
# recorded NumberOfMissedRuns=1 and simply moved on to the next day: the machine
# was not awake at 03:00, and `StartWhenAvailable` — which was already True —
# did not fire the catch-up. The day that was skipped is invisible unless
# somebody lists the directory and notices a date is absent, which is exactly
# the kind of check nobody performs until they need the file that isn't there.
#
# So the schedule is now belt-and-braces: 03:00 daily, AND a run shortly after
# every boot. The same-day guard below makes the pair safe — a boot on a day
# that already has a successful backup logs that fact and exits 0 rather than
# dumping again. `-Force` overrides it for a deliberate pre-migration snapshot.
[CmdletBinding()]
param(
    # Take a backup even if today already has one (pre-migration snapshots).
    [switch]$Force,
    # How long to wait for the container to accept connections. The startup
    # trigger fires while Docker Desktop is still coming up, so a run that
    # demanded an immediately-ready database would fail on every boot and train
    # everyone to ignore a red task.
    [int]$WaitForDatabaseSeconds = 600
)

$ErrorActionPreference = "Stop"

$Container = "whity_staging_postgres"
$DbUser = "whity"
$DbName = "whity_core"
$BackupDir = "C:\Projects\Whity\backups"
$RetentionDays = 14

New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

$ts = Get-Date -Format "yyyyMMdd-HHmmss"
$outFile = Join-Path $BackupDir "whity_core_$ts.sql.gz"
$logFile = Join-Path $BackupDir "backup.log"

function Log($msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $msg
    Write-Output $line
    Add-Content -Path $logFile -Value $line
}

$lastSuccessFile = Join-Path $BackupDir ".last-success"

# ── the same-day guard ───────────────────────────────────────────────────────
# Two triggers (03:00 daily + at startup) must not mean two dumps a day. The
# marker holds the stamp of the last SUCCESSFUL run, so this compares dates and
# not file mtimes: a pruned or hand-copied .sql.gz cannot make the guard lie.
if (-not $Force -and (Test-Path $lastSuccessFile)) {
    $last = (Get-Content $lastSuccessFile -Raw).Trim()
    if ($last.Length -ge 8 -and $last.Substring(0, 8) -eq (Get-Date -Format "yyyyMMdd")) {
        Log ("Today already has a successful backup ({0}); nothing to do. Use -Force to override." -f $last)
        exit 0
    }
}

# ── wait for the database ────────────────────────────────────────────────────
# Only meaningful for the startup trigger, where Docker Desktop is still
# starting. Waiting beats failing: the whole point of the boot run is to cover
# the night the machine was off, and that is precisely the run most likely to
# arrive before the container does.
$deadline = (Get-Date).AddSeconds($WaitForDatabaseSeconds)
$ready = $false
while (-not $ready) {
    docker exec $Container pg_isready -U $DbUser -d postgres *> $null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    if ((Get-Date) -ge $deadline) { break }
    Start-Sleep -Seconds 10
}
if (-not $ready) {
    Log ("BACKUP FAILED: {0} did not accept connections within {1}s" -f $Container, $WaitForDatabaseSeconds)
    exit 1
}

try {
    Log ("Starting backup of {0} from {1} -> {2}" -f $DbName, $Container, $outFile)

    # pg_dump inside the container, gzip inside the container too (avoids a huge
    # stdout round-trip through PowerShell), then docker cp the artifact out.
    $tmpInContainer = "/tmp/whity_core_$ts.sql.gz"
    docker exec $Container sh -c "pg_dump -U $DbUser -d $DbName --no-owner --no-privileges | gzip -9 > $tmpInContainer"
    if ($LASTEXITCODE -ne 0) { throw "pg_dump failed inside the container (exit $LASTEXITCODE)" }

    docker cp "${Container}:${tmpInContainer}" $outFile
    if ($LASTEXITCODE -ne 0) { throw "docker cp failed (exit $LASTEXITCODE)" }

    docker exec $Container rm -f $tmpInContainer

    $size = (Get-Item $outFile).Length
    if ($size -lt 1024) { throw ("Backup file is suspiciously small ({0} bytes) - treating as a failure" -f $size) }

    Log ("Backup OK: {0} ({1} bytes)" -f $outFile, $size)
    Set-Content -Path $lastSuccessFile -Value $ts

    # ── tell the external watchdog we are alive ──────────────────────────────
    # The dead-man's switch: the watchdog alarms on SILENCE, so a backup that
    # stops running raises the alarm by not sending this. That is the only shape
    # that catches the 2026-09-09 failure, where the scheduled task simply did
    # not run — a check that reads a report cannot notice a report never made.
    #
    # Deliberately AFTER the size check above, so a truncated or failed dump
    # never reports success. And deliberately non-fatal: a backup that succeeded
    # but could not phone home is still a backup, and failing the job here would
    # turn a monitoring outage into a data-protection one.
    if ($env:WATCHDOG_URL -and $env:WATCHDOG_SECRET) {
        try {
            $uri = ($env:WATCHDOG_URL.TrimEnd('/')) + '/beat/backup'
            Invoke-RestMethod -Uri $uri -Method Post -TimeoutSec 20 `
                -Headers @{ Authorization = "Bearer $($env:WATCHDOG_SECRET)" } | Out-Null
            Log "Heartbeat sent to the watchdog."
        }
        catch {
            Log ("Heartbeat FAILED (backup itself is fine): {0}" -f $_.Exception.Message)
        }
    }
    else {
        Log "No WATCHDOG_URL/WATCHDOG_SECRET set - skipping heartbeat (nothing is watching this job)."
    }

    # Prune old backups.
    Get-ChildItem -Path $BackupDir -Filter "whity_core_*.sql.gz" |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) } |
        ForEach-Object { Log ("Pruning old backup: {0}" -f $_.Name); Remove-Item $_.FullName -Force }
}
catch {
    Log ("BACKUP FAILED: {0}" -f $_.Exception.Message)
    throw
}
