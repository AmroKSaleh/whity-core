<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * End-to-end audit trail for membership grants and revocations (#889).
 *
 * WHAT THIS PINS, AND WHY IT IS A SEPARATE FILE FROM THE UNIT TESTS
 * -----------------------------------------------------------------
 * {@see \Tests\Core\Audit\AuditLoggerTest} proves the writer maps the events
 * correctly from a hand-built payload. That is necessary and not sufficient: the
 * defect #889 describes was never in the writer, it was that nothing DISPATCHED,
 * and later that what was dispatched did not carry enough to be worth reading.
 * Both halves are only observable by driving the real handlers against a real
 * engine with a real {@see HookManager} and a real {@see AuditLogger} subscribed
 * to it — a mocked hook manager (which the sibling handler suites use) asserts
 * that a method was called, which is exactly the thing that was already true.
 *
 * THE CENTRAL CASE is {@see self::testARevocationStillExplainsWhatWasLostAfterTheRowIsGone()}:
 * grant, revoke, then delete nothing else and ask the trail what happened. The
 * membership row is gone by then, so every fact in the assertion can only have
 * come from the audit row.
 *
 * Runs on in-memory SQLite by default and on real PostgreSQL when PHPUNIT_PG_DSN
 * is set, because the two engines disagree about booleans, integer parameters
 * and JSON in ways that only surface in CI's PostgreSQL shard.
 */
final class MembershipAuditTrailRealEngineTest extends TestCase
{
    private PDO $pdo;
    private HookManager $hooks;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSchema();

        // A REAL hook manager with the REAL audit logger subscribed — the
        // production wiring from public/index.php, minus the HTTP stack.
        $this->hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribe($this->hooks);

        // setTestTenant LOCKS TenantContext, so nothing may set it afterwards.
        // The audit rows do not depend on it anyway: every membership payload
        // carries its own tenant_id, which the writer prefers over context.
        MockRequestFactory::setTestTenant(1);
        AuditContext::set(99, '203.0.113.7');
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        AuditContext::reset();
    }

    // ==================== The case #889 exists for ====================

    /**
     * Grant a role, revoke it, and ask the trail what was taken away.
     *
     * Before this, `GET /api/v1/roles/{id}/assignments` could say who holds a
     * role now and nothing could say who used to — a revocation deletes the
     * membership row, and the two membership events were dispatched into a
     * writer that had no map entry for them. The assertions below deliberately
     * read ONLY the audit row, after proving the membership row is gone.
     */
    public function testARevocationStillExplainsWhatWasLostAfterTheRowIsGone(): void
    {
        $this->seedProfile(70, 'revoked@example.com');
        $this->seedMembership(70, 1, 2);
        $ouId = $this->seedOu(1, 'Engineering');

        $handler = $this->handler();

        $granted = $handler->addMembership(
            $this->request('POST', '/api/users/70/memberships', ['role_id' => 1, 'ou_id' => $ouId]),
            ['id' => '70']
        );
        $this->assertSame(201, $granted->getStatusCode(), $granted->getBody());
        $membershipId = json_decode($granted->getBody(), true)['data']['id'];

        $revoked = $handler->removeMembership(
            $this->request('DELETE', "/api/users/70/memberships/{$membershipId}"),
            ['id' => '70', 'membershipId' => (string) $membershipId]
        );
        $this->assertSame(200, $revoked->getStatusCode(), $revoked->getBody());

        // The row that held the answer is gone. Everything asserted below can
        // therefore only be coming from the audit trail.
        $stillThere = $this->pdo->prepare('SELECT COUNT(*) FROM memberships WHERE id = ?');
        $stillThere->execute([$membershipId]);
        $this->assertSame(0, (int) $stillThere->fetchColumn(), 'the revocation must really delete the row');

        $row = $this->auditRow('user.membership.removed');
        $this->assertNotNull($row, 'a revocation must be recorded at all — this is the #889 defect');

        $this->assertSame('user', $row['target_type']);
        $this->assertSame('70', (string) $row['target_id'], 'the person whose authority changed');
        $this->assertSame('1', (string) $row['tenant_id']);
        $this->assertSame('99', (string) $row['actor_user_id'], 'who removed the access');
        $this->assertNotEmpty($row['created_at'], 'and when');

        $meta = json_decode($row['metadata'], true);
        $this->assertSame(1, $meta['role_id'], 'WHAT was taken away');
        $this->assertSame('admin', $meta['role_name'], 'by name, so the row survives the role being deleted');
        $this->assertSame($ouId, $meta['ou_id'], 'and in which OU scope');
        $this->assertSame($membershipId, $meta['membership_id']);
        $this->assertSame('active', $meta['status']);
        $this->assertNotEmpty($meta['granted_at'], 'how long they had held it');
    }

    /**
     * The grant half of the same story, so the pair reads as a sequence.
     */
    public function testAGrantIsRecordedAgainstTheUserWithTheRoleInMetadata(): void
    {
        $this->seedProfile(71, 'granted@example.com');
        $this->seedMembership(71, 1, 2);

        $response = $this->handler()->addMembership(
            $this->request('POST', '/api/users/71/memberships', ['role_id' => 1]),
            ['id' => '71']
        );
        $this->assertSame(201, $response->getStatusCode(), $response->getBody());

        $row = $this->auditRow('user.membership.added');
        $this->assertNotNull($row);
        $this->assertSame('user', $row['target_type']);
        $this->assertSame('71', (string) $row['target_id']);

        $meta = json_decode($row['metadata'], true);
        $this->assertSame(1, $meta['role_id']);
        $this->assertSame('admin', $meta['role_name']);
        $this->assertFalse($meta['is_primary'], 'an extra role never claims the primary row');
    }

    /**
     * The accepted cost of choosing the user as the target, stated as a test
     * rather than only as a comment.
     *
     * A ROLE's page cannot answer "who was removed from this role" with a
     * `target_id` filter, because the role id lives in metadata and #885's
     * filter works on the target. That is a missing INDEX over data the trail
     * already holds — recoverable later by querying metadata. Targeting the
     * role instead would have been unrecoverable, because rows already written
     * can never be re-pointed. This test exists so a future change that quietly
     * flips the target has to argue with something.
     */
    public function testTheRoleIsDeliberatelyNotTheTarget(): void
    {
        $this->seedProfile(72, 'target@example.com');
        $this->seedMembership(72, 1, 2);

        $this->handler()->addMembership(
            $this->request('POST', '/api/users/72/memberships', ['role_id' => 1]),
            ['id' => '72']
        );

        $byRole = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'user.membership.added' AND target_type = 'role'"
        );
        $byRole->execute();
        $this->assertSame(0, (int) $byRole->fetchColumn(), 'no membership row may target the role');

        // The role id is still recorded — in metadata, where a later index can
        // reach it — so the information is deferred, not discarded.
        $meta = $this->auditMetadata('user.membership.added');
        $this->assertSame(1, $meta['role_id']);
    }

    // ==================== One row per act, not per row touched ==============

    /**
     * Creating a user creates a membership and produces exactly ONE audit row.
     *
     * The churn decision: a second `user.membership.added` beside `user.created`
     * would double the trail's volume on the most common membership write in the
     * product for no information gain, which is precisely the flood #889 warns
     * about. `user.created` carries the membership detail instead.
     */
    public function testCreatingAUserProducesOneRowCarryingTheGrantedRole(): void
    {
        $response = $this->handler()->create($this->request('POST', '/api/users', [
            'email' => 'fresh@example.com',
            'password' => 'secret-123',
            'role' => 'admin',
            'tenantId' => 1,
        ]));
        $this->assertSame(201, $response->getStatusCode(), $response->getBody());

        $this->assertSame(
            0,
            $this->countRows('user.membership.added'),
            'one administrative act must not write two audit rows'
        );

        $row = $this->auditRow('user.created');
        $this->assertNotNull($row);
        $meta = json_decode($row['metadata'], true);
        $this->assertSame(1, $meta['role_id'], 'the authority granted is still recorded');
        $this->assertSame('admin', $meta['role_name']);
        $this->assertFalse($meta['promoted'], 'a genuinely new membership, not a reinstatement');
    }

    /**
     * Removing a user from a tenant takes EVERY membership they hold there, and
     * the single audit row enumerates all of them.
     *
     * Three rows deleted, one row written: the act is one click, and emitting
     * one audit row per deleted membership is how a bulk operation drowns a
     * trail. The count comes from the DELETE itself, so a capped list is
     * visibly capped rather than quietly short.
     */
    public function testRemovingAUserRecordsEveryRoleThatWasLost(): void
    {
        $this->seedProfile(73, 'multi@example.com');
        $this->seedMembership(73, 1, 2, 'active', true);
        $this->seedMembership(73, 1, 1);
        $this->seedMembership(73, 1, 3);

        $response = $this->handler()->delete($this->request('DELETE', '/api/users/73'), ['id' => '73']);
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        $this->assertSame(1, $this->countRows('user.deleted'), 'one act, one row');

        $meta = $this->auditMetadata('user.deleted');
        $this->assertSame(3, $meta['memberships_removed'], 'the authoritative count comes from the DELETE');
        $this->assertCount(3, $meta['roles_lost']);

        $lostRoleIds = array_map(static fn (array $m): int => $m['role_id'], $meta['roles_lost']);
        sort($lostRoleIds);
        $this->assertSame([1, 2, 3], $lostRoleIds, 'every role the person held here is named');

        $lostNames = array_map(static fn (array $m): ?string => $m['role_name'], $meta['roles_lost']);
        $this->assertContains('moderator', $lostNames, 'by name, not just id');

        // Exactly one of the three was the primary row. Asserted because a bare
        // (bool) cast of PostgreSQL's 'f' is TRUE, which would report every
        // membership as primary — and this is the only place a DB-read boolean
        // reaches the trail, so nothing else would catch it in the PG shard.
        $primaryFlags = array_filter(
            array_map(static fn (array $m): bool => $m['is_primary'], $meta['roles_lost'])
        );
        $this->assertCount(1, $primaryFlags, 'exactly one membership was the primary row');

        // And the rows really are gone, so the trail is the only record.
        $left = $this->pdo->prepare('SELECT COUNT(*) FROM memberships WHERE profile_id = ? AND tenant_id = 1');
        $left->execute([73]);
        $this->assertSame(0, (int) $left->fetchColumn());
    }

    /**
     * A PRIMARY role reassignment records which role it moved from and to.
     *
     * This endpoint, not the memberships endpoints, is where most authority on
     * this platform actually moves — and it was reporting `role_changed: true`,
     * which says that authority changed while making it impossible to say into
     * what. A column of booleans cannot answer "who held admin on the 14th".
     */
    public function testAPrimaryRoleReassignmentRecordsFromAndTo(): void
    {
        $this->seedProfile(74, 'promoted@example.com');
        $this->seedMembership(74, 1, 2);

        $response = $this->handler()->update(
            $this->request('PATCH', '/api/users/74', ['role_id' => 1]),
            ['id' => '74']
        );
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        $meta = $this->auditMetadata('user.updated');
        $this->assertTrue($meta['role_changed']);
        $this->assertSame(2, $meta['previous_role_id']);
        $this->assertSame('user', $meta['previous_role_name']);
        $this->assertSame(1, $meta['role_id']);
        $this->assertSame('admin', $meta['role_name']);
    }

    // ==================== The paths that used to be silent ==================

    /**
     * SSO/JIT provisioning and verified-email domain policy both grant real
     * authority with no administrator in the loop, and both reach the table
     * through {@see MembershipRepository::insert()} — which held no hook manager
     * at all, so all of it happened in silence.
     */
    public function testTheRepositoryAnnouncesAProvisionedMembership(): void
    {
        $this->seedProfile(75, 'jit@example.com');

        $repo = new MembershipRepository($this->pdo, $this->hooks);
        $repo->insert(75, 1, 1);

        $row = $this->auditRow('user.membership.added');
        $this->assertNotNull($row, 'auto-provisioned authority must not be invisible');
        $this->assertSame('user', $row['target_type']);
        $this->assertSame('75', (string) $row['target_id']);

        $meta = json_decode($row['metadata'], true);
        $this->assertSame(1, $meta['role_id']);
        $this->assertSame('admin', $meta['role_name']);
        $this->assertSame('active', $meta['status']);
    }

    /**
     * The repository's own delete reads the row first, so it can say what went.
     * It has no production caller yet; wiring it now means the first one to
     * adopt it inherits a complete row instead of discovering the omission from
     * an empty incident timeline.
     */
    public function testTheRepositoryAnnouncesWhatItDeleted(): void
    {
        $this->seedProfile(76, 'repo-del@example.com');
        $repo = new MembershipRepository($this->pdo, $this->hooks);
        $membershipId = $repo->insert(76, 1, 3);

        $this->assertSame(1, $repo->delete($membershipId, 1));

        $meta = $this->auditMetadata('user.membership.removed');
        $this->assertSame(3, $meta['role_id']);
        $this->assertSame('moderator', $meta['role_name']);
        $this->assertSame($membershipId, $meta['membership_id']);
    }

    /**
     * A delete that matched nothing is not a revocation and must not be written.
     *
     * A cross-tenant or not-found call touches zero rows; recording it would put
     * a revocation that never happened into an append-only trail, where it
     * cannot be taken back.
     */
    public function testANoOpDeleteWritesNoRevocation(): void
    {
        $this->seedProfile(78, 'noop@example.com');
        $repo = new MembershipRepository($this->pdo, $this->hooks);
        $membershipId = $repo->insert(78, 1, 2);

        // Tenant 2 does not own this membership.
        $this->assertSame(0, $repo->delete($membershipId, 2));

        $this->assertSame(
            0,
            $this->countRows('user.membership.removed'),
            'a delete that removed nothing is not a revocation'
        );
    }

    /**
     * Accepting an invitation is how most people GET a role here. The
     * invitation was recorded; the membership it produced was not.
     */
    public function testAcceptingAnInvitationIsRecordedAsAGrant(): void
    {
        $service = new InvitationService(
            $this->pdo,
            new ProfileProvisioner($this->pdo),
            $this->hooks
        );

        $invite = $service->invite(1, 'invitee@example.com', 3);
        $this->assertSame(InvitationService::INVITE_CREATED, $invite['result']);

        $accepted = $service->accept($invite['token'], 'a-strong-password-1');
        $this->assertSame(InvitationService::ACCEPT_JOINED, $accepted['result']);

        $row = $this->auditRow('user.membership.added');
        $this->assertNotNull($row, 'an accepted invitation grants authority and must be audited');
        $this->assertSame('user', $row['target_type']);
        $this->assertSame((string) $accepted['profile_id'], (string) $row['target_id']);

        $meta = json_decode($row['metadata'], true);
        $this->assertSame(3, $meta['role_id']);
        $this->assertSame('moderator', $meta['role_name']);
        $this->assertSame('invitation', $meta['via'], 'how the authority was obtained');
    }

    /**
     * An invitation that grants nothing must not look like a grant.
     *
     * Accepting when an ACTIVE membership already exists burns the token and
     * adds no authority, so a row saying otherwise would be a false positive in
     * exactly the place someone goes looking for unexplained access.
     */
    public function testAcceptingAnInvitationForAnExistingMemberGrantsNothingAndSaysNothing(): void
    {
        $this->seedProfile(79, 'already@example.com');

        $service = new InvitationService(
            $this->pdo,
            new ProfileProvisioner($this->pdo),
            $this->hooks
        );

        // Invited while they hold nothing here, so the invitation is issued...
        $invite = $service->invite(1, 'already@example.com', 3);
        $this->assertSame(InvitationService::INVITE_CREATED, $invite['result']);

        // ...and admitted by another route before the link is clicked.
        $this->seedMembership(79, 1, 2);

        $accepted = $service->accept($invite['token'], 'a-strong-password-1');
        $this->assertSame(InvitationService::ACCEPT_ALREADY_MEMBER, $accepted['result']);

        $this->assertSame(
            0,
            $this->countRows('user.membership.added'),
            'burning a token for somebody already here is not a grant'
        );
    }

    // ==================== Helpers ====================

    private function handler(): UsersApiHandler
    {
        return new UsersApiHandler($this->pdo, $this->hooks);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => 99, 'active_tenant_id' => 1];

        return $request;
    }

    /**
     * The most recent audit row for an action, or null when none was written.
     *
     * @return array<string, mixed>|null
     */
    private function auditRow(string $action): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$action]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * The decoded metadata of the most recent audit row for an action.
     *
     * Asserts the row exists first: every caller is about to read fields out of
     * it, and "the key was missing" is a far worse failure message than "no
     * audit row was written", which is the defect this suite exists to catch.
     *
     * @return array<string, mixed>
     */
    private function auditMetadata(string $action): array
    {
        $row = $this->auditRow($action);
        $this->assertNotNull($row, "expected an audit row for {$action}");

        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) $row['metadata'], true);

        return $meta;
    }

    private function countRows(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
        $stmt->execute([$action]);

        return (int) $stmt->fetchColumn();
    }

    private function seedProfile(int $id, string $email): void
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, 'active', NOW(), NOW())"
        )->execute([$id, strstr($email, '@', true) ?: $email]);

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())"
        )->execute([$id, $email]);
    }

    private function seedMembership(
        int $profileId,
        int $tenantId,
        int $roleId,
        string $status = 'active',
        bool $isPrimary = false
    ): void {
        // Boolean literals, not bound 1/0: PostgreSQL refuses the integer a
        // bound PHP bool becomes for a BOOLEAN column and SQLite accepts it,
        // which is the divergence class that only fails in CI's Postgres shard.
        $primary = $isPrimary ? 'true' : 'false';
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (?, ?, ?, NULL, {$primary}, ?, NOW())"
        )->execute([$profileId, $tenantId, $roleId, $status]);
    }

    private function seedOu(int $tenantId, string $name): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO organizational_units (tenant_id, parent_id, name, slug, description, created_at)
             VALUES (?, NULL, ?, ?, '', NOW())"
        );
        $stmt->execute([$tenantId, $name, strtolower($name) . '-' . $tenantId]);

        return (int) $this->pdo->lastInsertId();
    }

    private static function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        // INSERT OR IGNORE is translated to ON CONFLICT DO NOTHING on the
        // PostgreSQL path by SchemaFromMigrations, so one spelling serves both
        // engines. Base roles `admin` (1) and `user` (2) come from migrations;
        // `moderator` (3) is test-only.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (3, 'moderator', '', NULL, NOW())");

        return $pdo;
    }
}
