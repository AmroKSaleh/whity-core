# Fetches the pinned FrankenPHP Windows release and stages it (with this
# template's php.ini) under src-tauri/resources/frankenphp/, where
# tauri.conf.json's bundle.resources ships it and src-tauri/src/php_host/
# spawns it from. Run once before `npm run tauri dev` / `npm run tauri build`
# on a fresh clone — the runtime binary + its ~50 sibling DLLs are gitignored
# (not committed) to avoid bloating the repo with binary release artifacts.
#
# Windows-only for now, matching this template's "Windows verified, macOS
# deferred" status (see README). A macOS/Linux equivalent is future work.

$ErrorActionPreference = "Stop"

# Pinned, not "latest" — a build should never silently pick up a new
# FrankenPHP release without this script being updated deliberately.
$Version = "1.12.7"

# The FrankenPHP project does not publish a checksums file for this release,
# so there is no upstream hash to verify against. This is OUR OWN trust
# anchor instead: the SHA256 of the exact zip this template was built and
# tested against (verified end-to-end: DemoCatalog CRUD + a real native
# print round trip). It protects against a corrupted/tampered download
# (network MITM, CDN issue) — update it deliberately alongside $Version.
#
# CONFIRMED IN PRACTICE (not hypothetical): this hash was updated once
# already, hours after the FIRST verification pass, because the upstream
# v1.12.7 Windows asset at this same URL changed underneath the same version
# tag (byte-identical except 6 bytes) — GitHub release assets are NOT
# guaranteed immutable just because the tag doesn't change; a maintainer can
# re-upload in place. The checksum check caught this correctly (refused the
# new, not-yet-verified binary) rather than silently accepting drift. Re-ran
# the full verification suite (health check, DemoCatalog CRUD, native print
# round trip) against the new binary before updating this pin.
$ExpectedSha256 = "5c1bb1f0c07765bae0c16e402e9ba30d0316f18f38431d9b4c2de4f1af4a8c87"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Dest = Join-Path $RepoRoot "src-tauri\resources\frankenphp"
$ZipUrl = "https://github.com/php/frankenphp/releases/download/v$Version/frankenphp-windows-x86_64.zip"
$ZipPath = Join-Path $env:TEMP "frankenphp-windows-x86_64-$Version.zip"

Write-Host "Downloading FrankenPHP v$Version for Windows..."
Invoke-WebRequest -Uri $ZipUrl -OutFile $ZipPath

$ActualSha256 = (Get-FileHash -Path $ZipPath -Algorithm SHA256).Hash
if ($ActualSha256.ToLower() -ne $ExpectedSha256.ToLower()) {
    Remove-Item $ZipPath -ErrorAction SilentlyContinue
    throw "FrankenPHP download checksum mismatch: expected $ExpectedSha256, got $ActualSha256. Refusing to stage an unverified binary."
}
Write-Host "Checksum verified."

if (Test-Path $Dest) {
    Write-Host "Removing existing $Dest"
    Remove-Item -Recurse -Force $Dest
}
New-Item -ItemType Directory -Force -Path $Dest | Out-Null

Write-Host "Extracting to $Dest..."
Expand-Archive -Path $ZipPath -DestinationPath $Dest -Force
Remove-Item $ZipPath

# Prune to what this app actually uses. PHP extension DLLs under ext/ are
# lazy-loaded only when named in php.ini (never an implicit dependency), so
# dropping the ones we don't reference is safe — verified by a full rebuild
# + re-test (DemoCatalog CRUD + native print) after this exact prune list.
# Everything else removed here is dev tooling/docs never touched at runtime
# (debugger, PHP embed .lib for compiling against PHP, PHAR CLI, ini
# templates we don't use, SBOM/license/readme files).
$KeepExtensions = @("php_pdo_sqlite.dll", "php_sqlite3.dll", "php_mbstring.dll")
Get-ChildItem (Join-Path $Dest "ext") -Filter "php_*.dll" | Where-Object {
    $KeepExtensions -notcontains $_.Name
} | Remove-Item -Force

Remove-Item (Join-Path $Dest "dev") -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "extras") -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "lib") -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "deplister.exe") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "php-win.exe") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "phpdbg.exe") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "php8phpdbg.dll") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "php8embed.lib") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "phar.phar.bat") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "pharcommand.phar") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "php.ini-development") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "php.ini-production") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "README.md") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "license.txt") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "news.txt") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "readme-redist-bins.txt") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $Dest "snapshot.txt") -Force -ErrorAction SilentlyContinue

# This template's own php.ini (enables pdo_sqlite/sqlite3/mbstring — none of
# which FrankenPHP's Windows release enables by default; confirmed by running
# it without one and hitting "could not find driver" / undefined-function
# errors). PHP on Windows auto-discovers php.ini from the executable's own
# directory, so it must sit next to frankenphp.exe, not just under php-host/.
Copy-Item (Join-Path $RepoRoot "php-host\php.ini") (Join-Path $Dest "php.ini") -Force

Write-Host "FrankenPHP staged at $Dest"
