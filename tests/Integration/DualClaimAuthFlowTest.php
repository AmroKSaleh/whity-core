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
 * WC-idcut-E: post-cutover auth flow — single claim set only.
 *
 * After step E the dual-claim window is closed. Session/MCP JWTs carry ONLY
 * {profile_id, active_tenant_id, email, role, token_epoch}.
 * Legacy {user_id, tenant_id} claims are never emitted.
 *
 * This file tests the post-cutover shape:
 *  - Login mints ONLY profile_id/active_tenant_id (no user_id/tenant_id).
 *  - id in the response equals profileId (stable across tenant switches).
 *  - A suspended membership is refused login (generic 401).
 *  - An account with no profile_email row is refused (401).
 *  - Refresh re-mints with profile_id/active_tenant_id only.
 *  - Refresh is refused after membership suspension.
 *
 * Runs on real SQLite locally and real PostgreSQL in CI (PHPUNIT_PG_DSN).
 */
final class DualClaimAuthFlowTest extends TestCase
{
    private const SECRET = 'dual-claim-flow-secret-key-padded-min-32-byte-key';
    private const PASSWORD = 'testpassword123';
    private const TENANT_ID = 1;

    private const MIGRATED_EMAIL  = 'migrated@example.com';
    private const SUSPENDED_EMAIL = 'suspended@example.com';

    private PDO $pdo;
    private ClaimCapturingJwtParser $jwtParser;
    private AuthHandler $handler;
    private MembershipRepository $memberships;

    private int $migratedProfileId;
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

        // A fully migrated user: profile + globally-unique email + active membership.
        $this->migratedProfileId = $this->seedProfile('Migrated', self::MIGRATED_EMAIL);
        $this->memberships->insert($this->migratedProfileId, self::TENANT_ID, 1);

        // A migrated-but-suspended user: profile exists, membership suspended.
        $this->suspendedProfileId = $this->seedProfile('Suspended', self::SUSPENDED_EMAIL);
        $suspendedMembershipId = $this->memberships->insert($this->suspendedProfileId, self::TENANT_ID, 1);
        $this->memberships->suspend($suspendedMembershipId, self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ── issuance: login ────────────────────────────────────────────────────────

    /**
     * Post-cutover: login mints ONLY {profile_id, active_tenant_id}.
     * No legacy user_id/tenant_id claims must be present.
     */
    public function testLoginMintsNewClaimsOnlyForMigratedUser(): void
    {
        $response = $this->login(self::MIGRATED_EMAIL);
        self::assertSame(200, $response->getStatusCode());

        foreach (['access', 'refresh'] as $type) {
            $payload = $this->jwtParser->lastPayloadOfType($type);
            self::assertIsArray($payload, "A {$type} token must have been minted.");

            // New claims are present.
            self::assertSame($this->migratedProfileId, $payload['profile_id'] ?? null, "{$type}: profile_id");
            self::assertSame(self::TENANT_ID, $payload['active_tenant_id'] ?? null, "{$type}: active_tenant_id");

            // Legacy claims must NOT be present.
            self::assertArrayNotHasKey('user_id', $payload, "{$type}: must not carry user_id");
            self::assertArrayNotHasKey('tenant_id', $payload, "{$type}: must not carry tenant_id");
        }
    }

    /**
     * The `id` field in the login response must equal profileId — stable across
     * tenant switches (this was the stable-id-across-switch bug that step E fixes).
     */
    public function testLoginResponseIdEqualsProfileId(): void
    {
        $response = $this->login(self::MIGRATED_EMAIL);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame($this->migratedProfileId, $body['user']['id'] ?? null);
    }

    /**
     * An account with no profile_email row cannot log in post-cutover.
     * (profile_email lookup returns nothing → 401.)
     */
    public function testLoginRejectsAccountWithNoProfileEmail(): void
    {
        $response = $this->login('norecord@example.com');

        self::assertSame(
            401,
            $response->getStatusCode(),
            'An account with no profile_email row must be refused (email not found).'
        );
    }

    /**
     * A profile whose only membership is suspended must be refused login (generic 401).
     */
    public function testLoginRejectsSuspendedMembership(): void
    {
        $response = $this->login(self::SUSPENDED_EMAIL);

        self::assertSame(
            401,
            $response->getStatusCode(),
            'A profile whose only membership is suspended must be refused login (generic 401).'
        );
    }

    /**
     * End-to-end: what login mints must pass the validator's membership gate.
     */
    public function testIssuedNewClaimsTokenValidates(): void
    {
        $this->login(self::MIGRATED_EMAIL);
        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);

        $token = $this->jwtParser->create($payload, 900, 'access');
        $_COOKIE['access_token'] = $token;

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertIsArray($validator->validateAccessToken());
    }

    // ── refresh: re-mint with new claims only ──────────────────────────────────

    /**
     * Refresh re-mints with profile_id/active_tenant_id only — no legacy claims.
     */
    public function testRefreshRemintsNewClaimsOnly(): void
    {
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'profile_id'       => $this->migratedProfileId,
            'active_tenant_id' => self::TENANT_ID,
            'email'            => self::MIGRATED_EMAIL,
            'role'             => 'admin',
            'token_epoch'      => 0,
        ], 604800, 'refresh');

        $response = $this->handler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertSame($this->migratedProfileId, $payload['profile_id'] ?? null);
        self::assertSame(self::TENANT_ID, $payload['active_tenant_id'] ?? null);
        self::assertArrayNotHasKey('user_id', $payload, 'Refresh must not re-emit user_id');
        self::assertArrayNotHasKey('tenant_id', $payload, 'Refresh must not re-emit tenant_id');
    }

    /**
     * Refresh is refused after the active membership is suspended.
     */
    public function testRefreshRejectsAfterMembershipSuspension(): void
    {
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'profile_id'       => $this->migratedProfileId,
            'active_tenant_id' => self::TENANT_ID,
            'email'            => self::MIGRATED_EMAIL,
            'role'             => 'admin',
            'token_epoch'      => 0,
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
            'email'    => $email,
            'password' => self::PASSWORD,
        ]));

        return $this->handler->handle($request);
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
