<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\SanctionedGlobalTables;

/**
 * Tests for the SanctionedGlobalTables allowlist (WC-188).
 *
 * This is the single source of truth for tables that are intentionally NOT
 * tenant-scoped. The upcoming tenant-predicate static guard consumes it, so its
 * contract (membership, case-insensitivity, documented rationale, and the
 * fail-closed default for unknown tables) is pinned here.
 */
final class SanctionedGlobalTablesTest extends TestCase
{
    public function testRevokedTokensIsSanctionedGlobal(): void
    {
        self::assertTrue(
            SanctionedGlobalTables::isGlobal('revoked_tokens'),
            'revoked_tokens is global by design — a jti is platform-unique.'
        );
        self::assertContains('revoked_tokens', SanctionedGlobalTables::all());
        self::assertNotNull(
            SanctionedGlobalTables::reasonFor('revoked_tokens'),
            'Every sanctioned global table must carry a documented rationale.'
        );
    }

    public function testMembershipIsCaseInsensitive(): void
    {
        self::assertTrue(SanctionedGlobalTables::isGlobal('REVOKED_TOKENS'));
        self::assertSame(
            SanctionedGlobalTables::reasonFor('revoked_tokens'),
            SanctionedGlobalTables::reasonFor('Revoked_Tokens')
        );
    }

    public function testTenantScopedTablesAreNotGlobalAndHaveNoReason(): void
    {
        // A representative sample of tenant-owned tables must NOT be on the
        // allowlist — the guard must require a tenant predicate for them.
        foreach (['users', 'roles', 'audit_log', 'persons', 'relations', 'permission_delegations'] as $table) {
            self::assertFalse(
                SanctionedGlobalTables::isGlobal($table),
                "{$table} is tenant-scoped and must NOT be a sanctioned global table."
            );
            self::assertNull(SanctionedGlobalTables::reasonFor($table));
        }
    }

    public function testEverySanctionedTableHasANonEmptyRationale(): void
    {
        $tables = SanctionedGlobalTables::all();
        self::assertNotEmpty($tables);

        foreach ($tables as $table) {
            $reason = SanctionedGlobalTables::reasonFor($table);
            self::assertIsString($reason);
            self::assertNotSame('', trim($reason), "Sanctioned global table '{$table}' must document why it is global.");
        }
    }

    public function testListIsKeptMinimal(): void
    {
        // A guard against accidental scope creep: adding a table here removes it
        // from tenant-isolation enforcement, so the list must stay deliberate.
        // Bump this only alongside a documented decision.
        // 7 (WC-235): email_verifications — verification tokens for the globally-
        // unique profile_emails. 8 (WC-7ad4): external_identities — federated
        // (SSO/OIDC) account links to global profiles; identity is per-person,
        // not per-tenant. Both reviewed as legitimately global (rows join only to
        // global tables, no tenant_id).
        self::assertLessThanOrEqual(
            8,
            count(SanctionedGlobalTables::all()),
            'The sanctioned global-table allowlist should stay minimal; review any growth.'
        );
    }
}
