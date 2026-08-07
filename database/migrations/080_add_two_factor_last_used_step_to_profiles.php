<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Adds `profiles.two_factor_last_used_step` — the TOTP anti-replay floor
 * (WC-security-audit).
 *
 * Closes a real gap found by the adversarial security audit: TOTP validation
 * ({@see \Whity\Auth\TotpService::validateCode()}, the only call site being
 * {@see \Whity\Auth\AuthHandler::handle2fa()}) verified a submitted code
 * against the current +/-1 step window but never recorded that a MATCHED
 * step had already been consumed. Because a TOTP code stays valid for the
 * whole ~30-second period it belongs to no matter how many separate requests
 * check it, a captured valid code (network sniffing, shoulder-surfing,
 * malware, a compromised proxy) could authenticate MORE THAN ONCE within
 * that window — the classic TOTP replay weakness RFC 6238 recommends
 * guarding against.
 *
 * This column is the per-profile anti-replay floor: once a login accepts a
 * code at time-step S, no code matching a step <= S can ever be accepted
 * again for that profile. See `AuthHandler::consumeTotpStep()`'s atomic
 * `UPDATE ... WHERE two_factor_last_used_step IS NULL OR < :step` guard
 * (mirrors the single-use burn {@see \Whity\Auth\BackupCodesService::validateCode()}
 * already uses for backup codes), which also makes two concurrent requests
 * presenting the same code race-safe — only one can win.
 *
 * NULL means "no code has ever been accepted yet" (fresh enrollment, or a
 * profile that has never completed a 2FA login). BIGINT rather than INTEGER:
 * a Unix-time step counter (seconds/30) will not overflow a 32-bit signed int
 * until roughly the year 4147, but there is no reason to risk it on a column
 * this cheap to size correctly up front.
 *
 * `profiles` is a sanctioned GLOBAL table (ADR 0005 §1); this column carries
 * no tenant_id and needs none.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class AddTwoFactorLastUsedStepToProfiles
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE profiles ADD COLUMN IF NOT EXISTS two_factor_last_used_step BIGINT DEFAULT NULL');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS two_factor_last_used_step');
    }
}
