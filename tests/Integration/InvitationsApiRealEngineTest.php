<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InvitationAcceptHandler;
use Whity\Api\InvitationsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\InvitationMailer;
use Whity\Core\Identity\InvitationService;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * WHIT-417 (#797 item 3): the invitation HTTP surface on a real engine.
 *
 * Drives the REAL {@see InvitationsApiHandler} and {@see InvitationAcceptHandler}
 * with a real RoleChecker, mailer, throttle, audit logger and settings service
 * against the migration-built schema. What it is here to prove, beyond the
 * happy path:
 *
 *  - the invite response and the invitation list NEVER reveal whether an
 *    address already has an account anywhere on the platform — a tenant
 *    administrator can type any address into that form;
 *  - accepting works end to end for BOTH an unknown address (choose a password)
 *    and an address that already has a profile in another tenant (no password
 *    asked, none applied);
 *  - the accept endpoint answers ONE message for unknown / expired / revoked /
 *    already-used tokens;
 *  - the admin endpoints are tenant-scoped and permission-gated, and both ends
 *    are rate-limited.
 */
final class InvitationsApiRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const ADMIN_A = 10;
    private const ADMIN_B = 11;
    private const NOPERM_A = 12;

    private PDO $pdo;
    private InvitationService $service;
    private InvitationsApiHandler $handler;
    private InvitationAcceptHandler $acceptHandler;
    private SettingsService $settings;
    /** @var Mailer&object{sent: list<array{to: string, subject: string, body: string}>} */
    private Mailer $mailer;

    /** A profile that already exists, with a membership in Tenant B only. */
    private int $existingProfileId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = $this->makeSchema();
        $this->service = new InvitationService($this->pdo, new ProfileProvisioner($this->pdo));
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_INVITATION, 'true');

        $this->mailer = new class implements Mailer {
            /** @var list<array{to: string, subject: string, body: string}> */
            public array $sent = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody];
            }
        };

        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $roleChecker = new RoleChecker($this->databaseFor($this->pdo), $registry);
        $audit = new AuditLogger($this->pdo, new NullLogger());

        $this->handler = new InvitationsApiHandler(
            $this->pdo,
            $this->service,
            $roleChecker,
            $audit,
            new DatabaseSharedStore($this->pdo),
            $this->settings,
            new InvitationMailer($this->mailer, 'https://app.test/accept-invitation', new EmailLayout(), $this->settings)
        );
        $this->acceptHandler = new InvitationAcceptHandler(
            $this->service,
            new DatabaseSharedStore($this->pdo),
            $audit
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        unset($_GET['token']);
    }

    // ==================== create ====================

    public function testAdminCanInviteAndTheLinkIsMailed(): void
    {
        $res = $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test', 'role' => 'user']);

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $row = $this->decode($res)['data'];
        self::assertSame('new@example.test', $row['email']);
        self::assertSame('pending', $row['status']);
        self::assertSame('user', $row['role_name']);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('new@example.test', $this->mailer->sent[0]['to']);
        self::assertStringContainsString('https://app.test/accept-invitation?token=', $this->mailer->sent[0]['body']);
    }

    public function testTheInviteResponseIsIdenticalForAKnownAndAnUnknownAddress(): void
    {
        $known = $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'bob@elsewhere.example']);
        $unknown = $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'ghost@example.test']);

        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(
            array_keys($this->decode($known)['data']),
            array_keys($this->decode($unknown)['data']),
            'The response shape must not tell an administrator which addresses already have accounts.'
        );
        foreach ([$known, $unknown] as $res) {
            self::assertStringNotContainsString('profile_id', $res->getBody());
            self::assertStringNotContainsString('requires_password', $res->getBody());
        }
    }

    public function testTheMailIsIdenticalForAKnownAndAnUnknownAddressExceptForTheLink(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'bob@elsewhere.example']);
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'ghost@example.test']);

        self::assertCount(2, $this->mailer->sent);
        self::assertSame($this->mailer->sent[0]['subject'], $this->mailer->sent[1]['subject']);
        self::assertSame(
            $this->stripToken($this->mailer->sent[0]['body']),
            $this->stripToken($this->mailer->sent[1]['body']),
            'A forwarded invitation must not disclose whether its recipient already had an account.'
        );
    }

    public function testInvitingAnActiveMemberIs409(): void
    {
        $this->pdo->exec(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES ('
            . $this->existingProfileId . ', ' . self::TENANT_A . ", 2, 'active', NOW())"
        );

        $res = $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'bob@elsewhere.example']);

        self::assertSame(409, $res->getStatusCode());
        self::assertSame([], $this->mailer->sent, 'A refused invitation must send nothing.');
    }

    public function testMalformedEmailAndUnknownRoleAndForeignOuAreAllRejected(): void
    {
        self::assertSame(422, $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'not-an-email'])->getStatusCode());
        self::assertSame(422, $this->create(self::TENANT_A, self::ADMIN_A, ['email' => ''])->getStatusCode());
        self::assertSame(
            422,
            $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'a@b.test', 'role' => 'no-such-role'])->getStatusCode()
        );
        // OU 20 belongs to Tenant B.
        self::assertSame(
            422,
            $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'a@b.test', 'ou_id' => 20])->getStatusCode()
        );
    }

    public function testCreateRejectsACallerWithoutUsersWrite(): void
    {
        $res = $this->create(self::TENANT_A, self::NOPERM_A, ['email' => 'new@example.test']);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame([], $this->mailer->sent);
    }

    public function testInvitingIsRateLimitedPerActor(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertSame(
                201,
                $this->create(self::TENANT_A, self::ADMIN_A, ['email' => "flood{$i}@example.test"])->getStatusCode(),
                "invite {$i}"
            );
        }

        $throttled = $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'one-too-many@example.test']);

        self::assertSame(429, $throttled->getStatusCode());
        self::assertArrayHasKey('retry-after', array_change_key_case($throttled->getHeaders()));
    }

    // ==================== list / revoke / resend ====================

    public function testListIsTenantScopedAndCarriesNoAccountExistenceSignal(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'bob@elsewhere.example']);
        $this->create(self::TENANT_B, self::ADMIN_B, ['email' => 'other@example.test']);

        $res = $this->list(self::TENANT_A, self::ADMIN_A);

        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $rows = $this->decode($res)['data'];
        self::assertCount(1, $rows);
        self::assertSame('bob@elsewhere.example', $rows[0]['email']);
        self::assertArrayNotHasKey('profile_id', $rows[0]);
        self::assertStringNotContainsString('token', $res->getBody());
    }

    public function testListRejectsACallerWithoutUsersRead(): void
    {
        self::assertSame(403, $this->list(self::TENANT_A, self::NOPERM_A)->getStatusCode());
    }

    public function testRevokeIsTenantScoped(): void
    {
        $id = $this->decode($this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']))['data']['id'];

        self::assertSame(404, $this->revoke(self::TENANT_B, self::ADMIN_B, $id)->getStatusCode());
        self::assertSame(200, $this->revoke(self::TENANT_A, self::ADMIN_A, $id)->getStatusCode());
        // Already revoked — the second attempt finds nothing pending.
        self::assertSame(404, $this->revoke(self::TENANT_A, self::ADMIN_A, $id)->getStatusCode());
    }

    public function testResendMailsAFreshLinkAndKillsTheOldOne(): void
    {
        $id = $this->decode($this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']))['data']['id'];
        $firstToken = $this->tokenFromMail(0);

        self::assertSame(200, $this->resend(self::TENANT_A, self::ADMIN_A, $id)->getStatusCode());

        self::assertCount(2, $this->mailer->sent);
        self::assertNotSame($firstToken, $this->tokenFromMail(1));
        self::assertSame(404, $this->preview($firstToken)->getStatusCode());
        self::assertSame(200, $this->preview($this->tokenFromMail(1))->getStatusCode());
    }

    public function testResendIsTenantScoped(): void
    {
        $id = $this->decode($this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']))['data']['id'];

        self::assertSame(404, $this->resend(self::TENANT_B, self::ADMIN_B, $id)->getStatusCode());
    }

    // ==================== preview ====================

    public function testPreviewTellsANewAddressToChooseAPasswordAndAnExistingOneNotTo(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']);
        $newPreview = $this->decode($this->preview($this->tokenFromMail(0)))['data'];

        self::assertTrue($newPreview['requires_password']);
        self::assertSame('tenant-a', $newPreview['tenant_name']);

        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'bob@elsewhere.example']);
        $existingPreview = $this->decode($this->preview($this->tokenFromMail(1)))['data'];

        self::assertFalse($existingPreview['requires_password']);
    }

    public function testPreviewWithoutAValidTokenIs404(): void
    {
        self::assertSame(404, $this->preview('')->getStatusCode());
        self::assertSame(404, $this->preview(bin2hex(random_bytes(32)))->getStatusCode());
    }

    // ==================== accept ====================

    public function testANewAddressAcceptsByChoosingAPassword(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']);

        $res = $this->accept($this->tokenFromMail(0), 'a-long-enough-password');

        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame('joined', $this->decode($res)['data']['status']);
        self::assertSame(
            '1',
            $this->scalar("SELECT COUNT(*) FROM profile_emails WHERE email = 'new@example.test'")
        );
    }

    public function testANewAddressAcceptingWithNoPasswordIs422AndTheLinkStillWorks(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']);
        $token = $this->tokenFromMail(0);

        self::assertSame(422, $this->accept($token, null)->getStatusCode());
        self::assertSame(200, $this->preview($token)->getStatusCode(), 'The invitation must survive a missing password.');
        self::assertSame(200, $this->accept($token, 'a-long-enough-password')->getStatusCode());
    }

    public function testAnExistingProfileAcceptsWithoutAPasswordAndGainsAMembership(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'bob@elsewhere.example']);

        $res = $this->accept($this->tokenFromMail(0), null);

        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame('joined', $this->decode($res)['data']['status']);
        self::assertSame(
            '2',
            $this->scalar('SELECT COUNT(*) FROM memberships WHERE profile_id = ' . $this->existingProfileId),
            'The person is now in both tenants — one identity, two memberships.'
        );
        self::assertSame(
            '1',
            $this->scalar("SELECT COUNT(*) FROM profiles WHERE display_name = 'Bob'"),
            'Accepting must never mint a second identity for an address that already had one.'
        );
    }

    public function testEveryWayAnInvitationTokenCanFailGivesTheSameAnswer(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'used@example.test']);
        $used = $this->tokenFromMail(0);
        $this->accept($used, 'a-long-enough-password');

        $id = $this->decode($this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'revoked@example.test']))['data']['id'];
        $revoked = $this->tokenFromMail(1);
        $this->revoke(self::TENANT_A, self::ADMIN_A, $id);

        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'expired@example.test']);
        $expired = $this->tokenFromMail(2);
        $this->pdo->prepare('UPDATE invitations SET expires_at = :past WHERE token_hash = :hash')
            ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 60), ':hash' => hash('sha256', $expired)]);

        $unknown = bin2hex(random_bytes(32));

        $bodies = [];
        foreach ([$used, $revoked, $expired, $unknown] as $token) {
            $res = $this->accept($token, 'a-long-enough-password');
            self::assertSame(400, $res->getStatusCode());
            $bodies[] = $res->getBody();
        }

        self::assertCount(1, array_unique($bodies), 'Used, revoked, expired and unknown must be indistinguishable.');
    }

    public function testAcceptingIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->accept(bin2hex(random_bytes(32)), 'a-long-enough-password');
        }

        $throttled = $this->accept(bin2hex(random_bytes(32)), 'a-long-enough-password');

        self::assertSame(429, $throttled->getStatusCode());
    }

    public function testAcceptingWithAWeakPasswordIs422AndCreatesNothing(): void
    {
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']);

        $res = $this->accept($this->tokenFromMail(0), 'short');

        self::assertSame(422, $res->getStatusCode());
        self::assertSame(
            '0',
            $this->scalar("SELECT COUNT(*) FROM profile_emails WHERE email = 'new@example.test'")
        );
    }

    public function testASuspendedInviteeIsRefusedRatherThanReinstated(): void
    {
        $this->pdo->exec(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES ('
            . $this->existingProfileId . ', ' . self::TENANT_A . ", 2, 'suspended', NOW())"
        );
        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'bob@elsewhere.example']);

        self::assertSame(409, $this->accept($this->tokenFromMail(0), null)->getStatusCode());
        self::assertSame(
            'suspended',
            $this->scalar(
                'SELECT status FROM memberships WHERE tenant_id = ' . self::TENANT_A
                . ' AND profile_id = ' . $this->existingProfileId
            )
        );
    }

    // ==================== per-tenant TTL ====================

    public function testTheInvitationLifetimeFollowsTheTenantsOwnSetting(): void
    {
        $this->settings->setTenant(self::TENANT_A, SettingsRegistry::INVITATION_TTL_DAYS, '1');

        $this->create(self::TENANT_A, self::ADMIN_A, ['email' => 'new@example.test']);

        $expiresAt = $this->scalar('SELECT expires_at FROM invitations WHERE tenant_id = ' . self::TENANT_A);
        $days = (strtotime($expiresAt) - time()) / 86400;

        self::assertGreaterThan(0.9, $days);
        self::assertLessThan(1.1, $days, 'The tenant override must win over the 7-day default.');
        self::assertStringContainsString('1 day', $this->mailer->sent[0]['body']);
    }

    // ==================== helpers ====================

    /** @param array<string, mixed> $body */
    private function create(int $tenantId, int $actorId, array $body): Response
    {
        return $this->handler->create($this->req($tenantId, $actorId, 'POST', '/api/invitations', $body));
    }

    private function list(int $tenantId, int $actorId): Response
    {
        return $this->handler->list($this->req($tenantId, $actorId, 'GET', '/api/invitations'));
    }

    private function revoke(int $tenantId, int $actorId, int $id): Response
    {
        return $this->handler->revoke(
            $this->req($tenantId, $actorId, 'DELETE', '/api/invitations/' . $id),
            ['id' => $id]
        );
    }

    private function resend(int $tenantId, int $actorId, int $id): Response
    {
        return $this->handler->resend(
            $this->req($tenantId, $actorId, 'POST', '/api/invitations/' . $id . '/resend'),
            ['id' => $id]
        );
    }

    private function preview(string $token): Response
    {
        TenantContext::reset();
        $request = new Request(
            'GET',
            '/api/invitations/accept?token=' . urlencode($token),
            [ClientIp::HEADER => '203.0.113.7'],
            ''
        );

        return $this->acceptHandler->preview($request);
    }

    private function accept(string $token, ?string $password): Response
    {
        TenantContext::reset();
        $body = ['token' => $token];
        if ($password !== null) {
            $body['password'] = $password;
        }
        $request = new Request(
            'POST',
            '/api/invitations/accept',
            ['Content-Type' => 'application/json', ClientIp::HEADER => '203.0.113.7'],
            (string) json_encode($body)
        );

        return $this->acceptHandler->accept($request);
    }

    /** @param array<string, mixed>|null $body */
    private function req(int $tenantId, int $actorId, string $method, string $path, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $request = new Request(
            $method,
            $path,
            ['Content-Type' => 'application/json'],
            $body !== null ? (string) json_encode($body) : ''
        );
        $request->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    /** The raw token out of the nth sent mail — the only place it ever exists. */
    private function tokenFromMail(int $index): string
    {
        self::assertArrayHasKey($index, $this->mailer->sent);
        $matches = [];
        self::assertSame(1, preg_match('/token=([0-9a-f]{64})/', $this->mailer->sent[$index]['body'], $matches));

        return (string) ($matches[1] ?? '');
    }

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

    private function stripToken(string $body): string
    {
        return (string) preg_replace('/[0-9a-f]{64}/', 'TOKEN', $body);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * Two tenants, each with an admin-role member (users:read + users:write via
     * the seeded `admin` role) and, in Tenant A, a member holding neither.
     * Bob already exists with a membership in Tenant B ONLY — he is the
     * "someone who already has an account" this feature exists for.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_A . ", 'tenant-a', 'tenant-a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_B . ", 'tenant-b', 'tenant-b')");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (2, 'user', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (101, 'no-perm', '', 0, datetime('now'))");

        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, " . self::TENANT_A . ", NULL, 'A-Eng', 'a-eng', '', datetime('now')),
                (20, " . self::TENANT_B . ", NULL, 'B-Sales', 'b-sales', '', datetime('now'))
        ");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (" . self::ADMIN_A . ", 'admin-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::ADMIN_B . ", 'admin-b', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::NOPERM_A . ", 'noperm-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (" . self::ADMIN_A . ", " . self::TENANT_A . ", 1,   'active', datetime('now')),
                (" . self::ADMIN_B . ", " . self::TENANT_B . ", 1,   'active', datetime('now')),
                (" . self::NOPERM_A . ", " . self::TENANT_A . ", 101, 'active', datetime('now'))
        ");

        $pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Bob', '\$2y\$10\$original', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->existingProfileId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$this->existingProfileId, 'bob@elsewhere.example']);
        $pdo->exec(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES ('
            . $this->existingProfileId . ', ' . self::TENANT_B . ", 2, 'active', NOW())"
        );

        SchemaFromMigrations::syncSequences($pdo);

        return $pdo;
    }
}
