<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Request;

/**
 * Spy parser: captures every payload handed to create() while still minting
 * real tokens, so issuance-time claims can be asserted without intercepting
 * Set-Cookie headers (unavailable under the CLI SAPI).
 */
final class ClaimCapturingJwtParser extends JwtParser
{
    /** @var list<array{payload: array<string, mixed>, type: string}> */
    public array $captured = [];

    public function create(array $payload, int $expiresIn = 3600, string $type = 'access'): string
    {
        $this->captured[] = ['payload' => $payload, 'type' => $type];

        return parent::create($payload, $expiresIn, $type);
    }

    /**
     * @return array<string, mixed>|null The last captured payload of a type.
     */
    public function lastPayloadOfType(string $type): ?array
    {
        foreach (array_reverse($this->captured) as $entry) {
            if ($entry['type'] === $type) {
                return $entry['payload'];
            }
        }

        return null;
    }
}

/**
 * WC-d4340daf: dual-claim issuance and refresh re-mint (ADR 0005 §5).
 *
 * During the dual-claim window the auth endpoints mint tokens that carry BOTH
 * the legacy {user_id, tenant_id} claims and the new {profile_id,
 * active_tenant_id} claims — but only when the login identity actually resolves
 * to a profile with an active membership (or system tenant 0). Pre-migration
 * users (no profiles/profile_emails/memberships rows yet — the users→profiles
 * data migration is the NEXT task) keep receiving legacy-only tokens so the
 * membership gate in TokenValidator can never brick them.
 *
 * Runs on real SQLite locally and real PostgreSQL in CI (PHPUNIT_PG_DSN).
 */
final class DualClaimAuthFlowTest extends TestCase
{
    private const SECRET = 'dual-claim-flow-secret-key-padded-min-32-byte-key';
    private const PASSWORD = 'testpassword123';
    private const TENANT_ID = 1;

    private const MIGRATED_EMAIL = 'migrated@example.com';
    private const LEGACY_EMAIL = 'legacy@example.com';
    private const SUSPENDED_EMAIL = 'suspended@example.com';

    private PDO $pdo;
    private ClaimCapturingJwtParser $jwtParser;
    private AuthHandler $handler;
    private MembershipRepository $memberships;

    private int $migratedUserId;
    private int $migratedProfileId;
    private int $legacyUserId;
    private int $suspendedProfileId;

    protected function setUp(): void
    {
        $_COOKIE = [];

        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = new ClaimCapturingJwtParser(self::SECRET);
        $this->memberships = new MembershipRepository($this->pdo);
        $this->handler = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
        );

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");

        // A "migrated" user: users row + profile + globally-unique email + active membership.
        $this->migratedUserId = $this->seedUser(self::MIGRATED_EMAIL);
        $this->migratedProfileId = $this->seedProfile('Migrated', self::MIGRATED_EMAIL);
        $this->memberships->insert($this->migratedProfileId, self::TENANT_ID, 1);

        // A pre-migration "legacy" user: users row only, no identity rows at all.
        $this->legacyUserId = $this->seedUser(self::LEGACY_EMAIL);

        // A migrated-but-suspended user: profile exists, membership suspended.
        $this->seedUser(self::SUSPENDED_EMAIL);
        $this->suspendedProfileId = $this->seedProfile('Suspended', self::SUSPENDED_EMAIL);
        $suspendedMembershipId = $this->memberships->insert($this->suspendedProfileId, self::TENANT_ID, 1);
        $this->memberships->suspend($suspendedMembershipId, self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ── issuance: login ────────────────────────────────────────────────────────

    public function testLoginMintsDualClaimTokensForMigratedUser(): void
    {
        $response = $this->login(self::MIGRATED_EMAIL);
        self::assertSame(200, $response->getStatusCode());

        foreach (['access', 'refresh'] as $type) {
            $payload = $this->jwtParser->lastPayloadOfType($type);
            self::assertIsArray($payload, "A {$type} token must have been minted.");

            // New claims (ADR 0005 §5).
            self::assertSame($this->migratedProfileId, $payload['profile_id'] ?? null, "{$type}: profile_id");
            self::assertSame(self::TENANT_ID, $payload['active_tenant_id'] ?? null, "{$type}: active_tenant_id");

            // Legacy claims are STILL issued during the dual window.
            self::assertSame($this->migratedUserId, $payload['user_id'] ?? null, "{$type}: legacy user_id");
            self::assertSame(self::TENANT_ID, $payload['tenant_id'] ?? null, "{$type}: legacy tenant_id");
        }
    }

    public function testLoginMintsLegacyOnlyTokensForUnmigratedUser(): void
    {
        $response = $this->login(self::LEGACY_EMAIL);
        self::assertSame(200, $response->getStatusCode());

        foreach (['access', 'refresh'] as $type) {
            $payload = $this->jwtParser->lastPayloadOfType($type);
            self::assertIsArray($payload);

            self::assertArrayNotHasKey(
                'profile_id',
                $payload,
                "{$type}: an unmigrated user must NOT get new claims — the membership gate would reject them."
            );
            self::assertArrayNotHasKey('active_tenant_id', $payload);
            self::assertSame($this->legacyUserId, $payload['user_id'] ?? null);
        }
    }

    public function testLoginMintsLegacyOnlyTokensWhenMembershipSuspended(): void
    {
        // A suspended membership must not be baked into a token that the
        // validator's membership gate would then reject on every request.
        $response = $this->login(self::SUSPENDED_EMAIL);
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('profile_id', $payload);
        self::assertArrayNotHasKey('active_tenant_id', $payload);
    }

    public function testIssuedDualClaimTokenValidates(): void
    {
        // End-to-end: what login mints must pass the validator's membership gate.
        $this->login(self::MIGRATED_EMAIL);
        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);

        $token = $this->jwtParser->create($payload, 900, 'access');
        $_COOKIE['access_token'] = $token;

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertIsArray($validator->validateAccessToken());
    }

    // ── refresh: re-mint with the same claim model ─────────────────────────────

    public function testRefreshRemintsDualClaimsFromDualRefreshToken(): void
    {
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'user_id' => $this->migratedUserId,
            'tenant_id' => self::TENANT_ID,
            'email' => self::MIGRATED_EMAIL,
            'role' => 'admin',
            'token_epoch' => 0,
            'profile_id' => $this->migratedProfileId,
            'active_tenant_id' => self::TENANT_ID,
        ], 604800, 'refresh');

        $response = $this->handler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertSame($this->migratedProfileId, $payload['profile_id'] ?? null);
        self::assertSame(self::TENANT_ID, $payload['active_tenant_id'] ?? null);
        self::assertSame($this->migratedUserId, $payload['user_id'] ?? null, 'Legacy claims survive the re-mint.');
    }

    public function testRefreshUpgradesLegacyRefreshTokenOnceMigrated(): void
    {
        // Old refresh token minted BEFORE the claim change; the user has since
        // gained a profile + membership. The re-minted access token upgrades.
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'user_id' => $this->migratedUserId,
            'tenant_id' => self::TENANT_ID,
            'email' => self::MIGRATED_EMAIL,
            'role' => 'admin',
            'token_epoch' => 0,
        ], 604800, 'refresh');

        $response = $this->handler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertSame($this->migratedProfileId, $payload['profile_id'] ?? null);
        self::assertSame(self::TENANT_ID, $payload['active_tenant_id'] ?? null);
    }

    public function testRefreshKeepsLegacyOnlyForUnmigratedUser(): void
    {
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'user_id' => $this->legacyUserId,
            'tenant_id' => self::TENANT_ID,
            'email' => self::LEGACY_EMAIL,
            'role' => 'admin',
            'token_epoch' => 0,
        ], 604800, 'refresh');

        $response = $this->handler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('profile_id', $payload);
        self::assertArrayNotHasKey('active_tenant_id', $payload);
    }

    public function testRefreshRejectsDualTokenAfterMembershipSuspension(): void
    {
        // Token was minted while the membership was active; the membership is
        // then suspended — the refresh must be refused (revocation-by-membership,
        // ADR 0005 §5) without waiting for token expiry.
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'user_id' => $this->migratedUserId,
            'tenant_id' => self::TENANT_ID,
            'email' => self::MIGRATED_EMAIL,
            'role' => 'admin',
            'token_epoch' => 0,
            'profile_id' => $this->migratedProfileId,
            'active_tenant_id' => self::TENANT_ID,
        ], 604800, 'refresh');

        $membership = $this->memberships->findByProfile($this->migratedProfileId, self::TENANT_ID);
        self::assertIsArray($membership);
        $this->memberships->suspend((int) $membership['id'], self::TENANT_ID);

        $response = $this->handler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        self::assertSame(401, $response->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function login(string $email): \Whity\Core\Response
    {
        $request = new Request('POST', '/api/login', [], (string) json_encode([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));

        return $this->handler->handle($request);
    }

    private function seedUser(string $email): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, datetime('now'), 0)"
        );
        $stmt->execute([
            self::TENANT_ID,
            $email,
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            1,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedProfile(string $displayName, string $email): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([$displayName, password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();

        $emailStmt = $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        );
        $emailStmt->execute([$profileId, $email]);

        return $profileId;
    }
}
