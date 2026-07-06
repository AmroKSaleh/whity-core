import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

/**
 * The docker container running the backend (FrankenPHP). The 2FA secret shown
 * in the enable dialog is a base32 TOTP secret; rather than add a new npm
 * dependency to compute the one-time code (the suite must not introduce new
 * packages), we reuse the OTPHP library already vendored INSIDE the backend
 * container — the exact same code path the server validates against.
 */
const BACKEND_CONTAINER =
  process.env.E2E_BACKEND_CONTAINER ?? 'whity_frankenphp';

/**
 * Compute the current 6-digit TOTP code for a base32 secret by invoking OTPHP
 * inside the backend container. This mirrors the documented manual command:
 *
 *   docker exec whity_frankenphp php -r "require '/app/vendor/autoload.php'; \
 *     echo \OTPHP\TOTP::create('SECRET')->now();"
 *
 * Returns the zero-padded 6-digit string. Throws if the container is
 * unreachable or the secret is malformed, so a 2FA spec fails loudly rather
 * than silently submitting an empty code.
 */
export async function computeTotp(secret: string): Promise<string> {
  if (!/^[A-Z2-7]+=*$/i.test(secret)) {
    throw new Error(`computeTotp: secret is not valid base32: "${secret}"`);
  }

  const phpExpr =
    "require '/app/vendor/autoload.php'; " +
    `echo \\OTPHP\\TOTP::create('${secret}')->now();`;

  const { stdout } = await execFileAsync('docker', [
    'exec',
    BACKEND_CONTAINER,
    'php',
    '-r',
    phpExpr,
  ]);

  const code = stdout.trim();
  if (!/^\d{6}$/.test(code)) {
    throw new Error(
      `computeTotp: expected a 6-digit code from the container, got "${code}"`
    );
  }
  return code;
}

/**
 * The PostgreSQL container, used as a last-resort teardown to guarantee the
 * admin 2FA baseline is restored even if the API path is unreachable.
 */
const DB_CONTAINER = process.env.E2E_DB_CONTAINER ?? 'whity_postgres';
const DB_USER = process.env.E2E_DB_USER ?? 'whity';
const DB_NAME = process.env.E2E_DB_NAME ?? 'whity_core';

/**
 * Hard-reset 2FA for an account directly in the database: disable it, clear the
 * stored secret, reset the backup-codes version, and delete any backup codes.
 *
 * This is the bulletproof teardown used by the 2FA spec's afterAll so a failed
 * run can NEVER leave admin/admin123 behind a login challenge (the seeder ships
 * no 2FA and the rest of the suite depends on plain admin login). Best-effort:
 * swallows errors so it is always safe to call from a teardown hook.
 *
 * Login reads 2FA state from `profiles` (ADR 0005); the legacy `users` table
 * was retired by the identity hard cutover (migration 042), so the reset clears
 * the profile row (resolved via the globally-unique profile_emails.email) and
 * its backup codes. NOTE: this SQL runs as a single psql -c batch, so it must
 * NOT reference the dropped `users` table — a failing leading statement aborts
 * the batch and leaves the profile 2FA-enabled, 202-challenging every later spec.
 */
export async function resetTwoFactorViaDb(email: string): Promise<void> {
  const sql =
    `UPDATE profiles SET two_factor_enabled=false, two_factor_secret=NULL, ` +
    `two_factor_backup_codes_version=0 WHERE id=(SELECT profile_id FROM profile_emails WHERE email='${email}'); ` +
    `DELETE FROM backup_codes WHERE profile_id=(SELECT profile_id FROM profile_emails WHERE email='${email}');`;
  await execFileAsync('docker', [
    'exec',
    DB_CONTAINER,
    'psql',
    '-U',
    DB_USER,
    '-d',
    DB_NAME,
    '-c',
    sql,
  ]).catch(() => undefined);
}
