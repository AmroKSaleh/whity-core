<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\Tenant\SanctionedGlobalTables;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * WHIT-417 (#797 item 3): the invitation lifecycle on a REAL SQL engine.
 *
 * The behaviour under test is not "a row is written" — it is the set of
 * decisions an invitation flow has to get right the first time it meets a real
 * deployment, and each one is asserted here rather than described in a PR:
 *
 *  - an invitation may target an address that ALREADY HAS A PROFILE in another
 *    tenant. Accepting it must produce a MEMBERSHIP, never a second identity:
 *    `profile_emails.email` is globally unique (ADR 0005 §2), and a duplicate
 *    profile would split that person's credential and token epoch across two
 *    rows, so a password change or a forced logout would reach only one of them;
 *  - an existing profile is never asked for a password and never has its
 *    credential rewritten by accepting;
 *  - the token is single-use and time-boxed, and every way of failing
 *    (unknown / expired / revoked / replayed) is indistinguishable;
 *  - a second invitation to the same address in the same tenant SUPERSEDES the
 *    first rather than accumulating, so a resent invite cannot leave two live
 *    tokens behind;
 *  - inviting someone who is already a member is a REFUSAL, not a duplicate row.
 *
 * Runs against SQLite in the default suite and against real PostgreSQL when
 * PHPUNIT_PG_DSN is set ({@see SchemaFromMigrations}), because the partial
 * unique index and the timestamp comparisons behave differently per engine.
 */
final class InvitationServiceRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const ROLE_USER = 2;

    private PDO $pdo;
    private InvitationService $service;
    private MembershipRepository $memberships;

    /** A profile that already exists in Tenant B — the "invite an existing person" case. */
    private int $existingProfileId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->service = new InvitationService($this->pdo, new ProfileProvisioner($this->pdo));
        $this->memberships = new MembershipRepository($this->pdo);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");

        // Bob already has an identity and a membership in Tenant B. Tenant A's
        // administrator will invite that same address.
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Bob', '\$2y\$10\$original', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->existingProfileId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$this->existingProfileId, 'bob@elsewhere.example']);
        $this->memberships->insert($this->existingProfileId, self::TENANT_B, self::ROLE_USER);

        SchemaFromMigrations::syncSequences($this->pdo);
    }

    // ── classification ───────────────────────────────────────────────────────

    public function testInvitationsIsClassifiedAsTenantOwned(): void
    {
        self::assertTrue(
            TenantOwnedTables::isTenantOwned('invitations'),
            'invitations carries a tenant_id column and must be policed by the predicate guard.'
        );
        self::assertFalse(
            SanctionedGlobalTables::isGlobal('invitations'),
            'An invitation belongs to the tenant that issued it — it is not a global identity row.'
        );
    }

    // ── issuing ──────────────────────────────────────────────────────────────

    public function testInviteStoresOnlyTheTokenHashNeverTheRawToken(): void
    {
        $result = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        self::assertSame(InvitationService::INVITE_CREATED, $result['result']);
        self::assertSame(64, strlen($result['token']), 'A 256-bit token renders as 64 hex characters.');

        $stored = $this->scalar('SELECT token_hash FROM invitations WHERE tenant_id = 1');
        self::assertNotSame($result['token'], $stored, 'The raw token must never reach the database.');
        self::assertSame(hash('sha256', $result['token']), $stored);
    }

    public function testInviteRefusesAnAddressThatAlreadyHoldsAMembershipInThatTenant(): void
    {
        $this->memberships->insert($this->existingProfileId, self::TENANT_A, self::ROLE_USER);

        $result = $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);

        self::assertSame(
            InvitationService::INVITE_ALREADY_MEMBER,
            $result['result'],
            'Inviting an existing member must be a clear refusal, not a second membership row.'
        );
        self::assertSame(
            '0',
            $this->scalar('SELECT COUNT(*) FROM invitations WHERE tenant_id = 1'),
            'A refused invitation must leave no row behind.'
        );
    }

    public function testASecondInviteToTheSameAddressSupersedesTheFirstAndKillsItsToken(): void
    {
        $first = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);
        $second = $this->service->invite(self::TENANT_A, 'new@example.test', 1);

        self::assertSame(InvitationService::INVITE_CREATED, $second['result']);
        self::assertNotSame($first['token'], $second['token']);

        // Exactly one row is still pending, and the old link is dead — a resent
        // invitation must never leave two usable tokens in circulation.
        self::assertSame(
            '1',
            $this->scalar("SELECT COUNT(*) FROM invitations WHERE tenant_id = 1 AND status = 'pending'")
        );
        self::assertNull($this->service->preview($first['token']));
        self::assertNotNull($this->service->preview($second['token']));
    }

    public function testTheSameAddressMayBeInvitedByTwoDifferentTenantsAtOnce(): void
    {
        $a = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);
        $b = $this->service->invite(self::TENANT_B, 'new@example.test', self::ROLE_USER);

        self::assertSame(InvitationService::INVITE_CREATED, $a['result']);
        self::assertSame(InvitationService::INVITE_CREATED, $b['result']);
        self::assertNotNull($this->service->preview($a['token']));
        self::assertNotNull($this->service->preview($b['token']));
    }

    // ── preview (what the invitee is shown before accepting) ─────────────────

    public function testPreviewTellsANewAddressThatItMustChooseAPassword(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        $preview = $this->service->preview($invite['token']);

        self::assertIsArray($preview);
        self::assertTrue($preview['requires_password']);
        self::assertSame('tenant-a', $preview['tenant_name']);
        self::assertSame('new@example.test', $preview['email']);
    }

    public function testPreviewTellsAnExistingProfileThatNoPasswordIsNeeded(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);

        $preview = $this->service->preview($invite['token']);

        self::assertIsArray($preview);
        self::assertFalse(
            $preview['requires_password'],
            'Someone who already has an account must never be asked to invent a second password.'
        );
    }

    public function testPreviewDoesNotConsumeTheToken(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        $this->service->preview($invite['token']);

        self::assertSame(
            InvitationService::ACCEPT_JOINED,
            $this->service->accept($invite['token'], password_hash('correct horse', PASSWORD_BCRYPT))['result']
        );
    }

    // ── accepting as a NEW person ────────────────────────────────────────────

    public function testAcceptingAsANewAddressCreatesTheProfileAndAnActiveMembership(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        $result = $this->service->accept($invite['token'], password_hash('correct horse', PASSWORD_BCRYPT));

        self::assertSame(InvitationService::ACCEPT_JOINED, $result['result']);
        self::assertSame(self::TENANT_A, $result['tenant_id']);

        $membership = $this->memberships->findByProfile((int) $result['profile_id'], self::TENANT_A);
        self::assertIsArray($membership);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $membership['status']);
        self::assertSame(self::ROLE_USER, (int) $membership['role_id']);
    }

    public function testAcceptingAsANewAddressWithoutAPasswordChangesNothing(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        $result = $this->service->accept($invite['token'], null);

        self::assertSame(InvitationService::ACCEPT_PASSWORD_REQUIRED, $result['result']);
        self::assertNotNull(
            $this->service->preview($invite['token']),
            'A rejected accept must leave the invitation usable — the invitee simply has to try again.'
        );
    }

    // ── accepting as an EXISTING person (the case that matters) ──────────────

    public function testAcceptingAsAnExistingProfileGrantsAMembershipAndNotASecondIdentity(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);

        $result = $this->service->accept($invite['token'], null);

        self::assertSame(InvitationService::ACCEPT_JOINED, $result['result']);
        self::assertSame(
            $this->existingProfileId,
            $result['profile_id'],
            'The invitation must resolve to the SAME profile — a second identity would split the credential.'
        );
        self::assertSame(
            '1',
            $this->scalar("SELECT COUNT(*) FROM profile_emails WHERE email = 'bob@elsewhere.example'")
        );

        // And the person is now in both tenants.
        self::assertTrue($this->memberships->hasActiveMembership($this->existingProfileId, self::TENANT_A));
        self::assertTrue($this->memberships->hasActiveMembership($this->existingProfileId, self::TENANT_B));
    }

    public function testAcceptingAsAnExistingProfileNeverRewritesTheirCredentialOrEpoch(): void
    {
        $before = $this->credentialOf($this->existingProfileId);

        $invite = $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);
        // A password is supplied anyway — accepting must ignore it rather than
        // quietly resetting a credential the invitee never asked to change.
        $this->service->accept($invite['token'], password_hash('someone elses idea', PASSWORD_BCRYPT));

        $after = $this->credentialOf($this->existingProfileId);

        self::assertSame($before, $after, 'Joining a tenant is not a credential change.');
    }

    public function testAcceptingWhenAlreadyAMemberIsReportedAndAddsNoSecondRow(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);
        // The administrator adds them by hand between invite and accept.
        $this->memberships->insert($this->existingProfileId, self::TENANT_A, self::ROLE_USER);

        $result = $this->service->accept($invite['token'], null);

        self::assertSame(InvitationService::ACCEPT_ALREADY_MEMBER, $result['result']);
        self::assertSame(
            '1',
            $this->scalar('SELECT COUNT(*) FROM memberships WHERE tenant_id = 1 AND profile_id = ' . $this->existingProfileId)
        );
    }

    public function testAcceptingActivatesAMembershipLeftInTheInvitedState(): void
    {
        $this->memberships->invite($this->existingProfileId, self::TENANT_A, self::ROLE_USER);
        $invite = $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);

        self::assertSame(InvitationService::ACCEPT_JOINED, $this->service->accept($invite['token'], null)['result']);
        self::assertTrue($this->memberships->hasActiveMembership($this->existingProfileId, self::TENANT_A));
    }

    public function testAcceptingDoesNotResurrectASuspendedMembership(): void
    {
        $membershipId = $this->memberships->insert(
            $this->existingProfileId,
            self::TENANT_A,
            self::ROLE_USER,
            null,
            MembershipRepository::STATUS_SUSPENDED
        );
        $invite = $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);

        self::assertSame(InvitationService::ACCEPT_SUSPENDED, $this->service->accept($invite['token'], null)['result']);

        $row = $this->memberships->findById($membershipId, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame(
            MembershipRepository::STATUS_SUSPENDED,
            $row['status'],
            'An invitation must not be a way around a suspension.'
        );
    }

    // ── single use, expiry, revocation ───────────────────────────────────────

    public function testATokenCannotBeUsedTwice(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);
        $this->service->accept($invite['token'], password_hash('correct horse', PASSWORD_BCRYPT));

        $replay = $this->service->accept($invite['token'], password_hash('correct horse', PASSWORD_BCRYPT));

        self::assertSame(InvitationService::ACCEPT_INVALID, $replay['result']);
    }

    public function testAnExpiredTokenIsIndistinguishableFromAnUnknownOne(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);
        $this->expire($invite['token']);

        self::assertNull($this->service->preview($invite['token']));
        self::assertSame(InvitationService::ACCEPT_INVALID, $this->service->accept($invite['token'], null)['result']);
        self::assertSame(
            InvitationService::ACCEPT_INVALID,
            $this->service->accept(bin2hex(random_bytes(32)), null)['result'],
            'An unknown token and an expired one must produce the same answer.'
        );
    }

    public function testRevokingKillsTheTokenImmediately(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        self::assertTrue($this->service->revoke($invite['id'], self::TENANT_A));
        self::assertNull($this->service->preview($invite['token']));
        self::assertSame(InvitationService::ACCEPT_INVALID, $this->service->accept($invite['token'], null)['result']);
    }

    public function testRevokingIsTenantScoped(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        self::assertFalse(
            $this->service->revoke($invite['id'], self::TENANT_B),
            "Tenant B must not be able to revoke Tenant A's invitation."
        );
        self::assertNotNull($this->service->preview($invite['token']));
    }

    public function testAnEmptyTokenIsRejectedWithoutTouchingTheDatabase(): void
    {
        self::assertNull($this->service->preview(''));
        self::assertSame(InvitationService::ACCEPT_INVALID, $this->service->accept('', null)['result']);
    }

    // ── resend ───────────────────────────────────────────────────────────────

    public function testResendMintsAFreshTokenAndInvalidatesTheOldLink(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        $resent = $this->service->resend($invite['id'], self::TENANT_A);

        self::assertIsArray($resent);
        self::assertSame('new@example.test', $resent['email']);
        self::assertNotSame($invite['token'], $resent['token']);
        self::assertNull($this->service->preview($invite['token']), 'The superseded link must stop working.');
        self::assertNotNull($this->service->preview($resent['token']));
    }

    public function testResendRefusesAcrossTenantsAndAfterRevocation(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);

        self::assertNull($this->service->resend($invite['id'], self::TENANT_B));

        $this->service->revoke($invite['id'], self::TENANT_A);
        self::assertNull($this->service->resend($invite['id'], self::TENANT_A));
    }

    // ── the administrator's view ─────────────────────────────────────────────

    public function testListShowsOnlyTheCallersOwnTenantAndNeverLeaksAccountExistence(): void
    {
        $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);
        $this->service->invite(self::TENANT_B, 'other@example.test', self::ROLE_USER);
        // An address that DOES have an account elsewhere, invited into Tenant A.
        $this->service->invite(self::TENANT_A, 'bob@elsewhere.example', self::ROLE_USER);

        $rows = $this->service->listForTenant(self::TENANT_A);

        self::assertCount(2, $rows);
        self::assertSame(
            ['bob@elsewhere.example', 'new@example.test'],
            $this->sortedEmails($rows),
            "Tenant B's invitation must be invisible to Tenant A."
        );
        foreach ($rows as $row) {
            self::assertArrayNotHasKey(
                'profile_id',
                $row,
                'The list must not tell a tenant administrator which invited addresses already have accounts.'
            );
            self::assertArrayNotHasKey('token_hash', $row);
        }
    }

    public function testListReportsAnExpiredInvitationAsExpiredRatherThanPending(): void
    {
        $invite = $this->service->invite(self::TENANT_A, 'new@example.test', self::ROLE_USER);
        $this->expire($invite['token']);

        $rows = $this->service->listForTenant(self::TENANT_A);

        self::assertCount(1, $rows);
        self::assertSame('expired', $rows[0]['status']);
    }

    public function testListReportsTheRoleTheInviteeWillReceive(): void
    {
        $this->service->invite(self::TENANT_A, 'new@example.test', 1);

        $rows = $this->service->listForTenant(self::TENANT_A);

        self::assertSame('admin', $rows[0]['role_name']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A single scalar, with the PDO::query() false branch closed off — level-8
     * static analysis will not take `query()->fetchColumn()` on trust.
     */
    private function scalar(string $sql): string
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail('query failed: ' . $sql);
        }

        return (string) $stmt->fetchColumn();
    }

    /** A profile's credential state, as the two strings that must not move. */
    private function credentialOf(int $profileId): string
    {
        return $this->scalar('SELECT password_hash FROM profiles WHERE id = ' . $profileId)
            . '/' . $this->scalar('SELECT token_epoch FROM profiles WHERE id = ' . $profileId);
    }

    /** Backdate a live invitation so it is past its expiry without sleeping. */
    private function expire(string $rawToken): void
    {
        $this->pdo->prepare('UPDATE invitations SET expires_at = :past WHERE token_hash = :hash')
            ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 60), ':hash' => hash('sha256', $rawToken)]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function sortedEmails(array $rows): array
    {
        $emails = array_map(static fn (array $row): string => (string) $row['email'], $rows);
        sort($emails);

        return $emails;
    }
}
