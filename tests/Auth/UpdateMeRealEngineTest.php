<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * Real-engine (in-memory SQLite) tests for the self-service profile update
 * endpoint {@see AuthHandler::handleUpdateMe()} (PATCH /api/me, WC-64).
 *
 * Mocked PDO can only pin the request/response contract; it cannot prove a write
 * actually lands in the database nor that the per-tenant uniqueness/scoping SQL
 * behaves under real semantics. These tests drive the handler against a genuine
 * SQL engine seeded with a users/roles schema close to production
 * (UNIQUE(tenant_id, email)), so the persisted row is read back and the security
 * invariants are exercised for real. The pattern mirrors
 * {@see \Tests\Api\UsersApiHandlerRealEngineTest}.
 *
 * Authentication is driven exactly as the live endpoint sees it: the acting user
 * is resolved from a signed access-token JWT placed in the `access_token` cookie
 * (the endpoint is self-only — the id never comes from the request body).
 *
 * Covered invariants:
 *  - self-update of email persists and is scoped to the caller's tenant;
 *  - self-update of password persists a verifiable bcrypt hash (and the user can
 *    authenticate with the new password);
 *  - the endpoint can only ever edit the authenticated user — there is no id in
 *    the body, so a foreign id cannot be targeted, and a token whose user belongs
 *    to another tenant cannot reach across the tenant boundary;
 *  - a wrong/absent current password is rejected with no write;
 *  - email uniqueness is enforced within the tenant.
 */
final class UpdateMeRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-update-me-padded-for-hs256-min-32-byte-key';

    private PDO $pdo;
    private JwtParser $jwtParser;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
        $this->jwtParser = new JwtParser(self::SECRET);
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    // ==================== self-update persists ====================

    public function testSelfEmailUpdatePersistsScopedToTenant(): void
    {
        $this->seedUser(10, 1, 'old@example.com', 'secret-123', 2);
        $this->authenticateAs(10, 1, 'old@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'new@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(200, $response->getStatusCode());

        $email = $this->pdo->query('SELECT email FROM users WHERE id = 10')->fetchColumn();
        $this->assertSame('new@example.com', $email, 'The new email must be persisted.');

        $data = json_decode($response->getBody(), true)['user'];
        $this->assertSame('new@example.com', $data['email']);
        $this->assertSame(10, $data['id']);
        $this->assertSame('user', $data['role']);
        $this->assertArrayNotHasKey('password', $data, 'The password hash must never leak.');
    }

    public function testSelfPasswordUpdatePersistsVerifiableBcryptHash(): void
    {
        $this->seedUser(11, 1, 'pw@example.com', 'old-password', 2);
        $this->authenticateAs(11, 1, 'pw@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest([
                'password' => 'brand-new-pass',
                'current_password' => 'old-password',
            ])
        );

        $this->assertSame(200, $response->getStatusCode());

        $hash = (string) $this->pdo->query('SELECT password FROM users WHERE id = 11')->fetchColumn();
        $this->assertTrue(
            password_verify('brand-new-pass', $hash),
            'The new password must be persisted as a verifiable bcrypt hash.'
        );
        $this->assertFalse(
            password_verify('old-password', $hash),
            'The old password must no longer verify.'
        );
        // The stored value is a hash, never the plaintext.
        $this->assertNotSame('brand-new-pass', $hash);
    }

    // ==================== self-only authorization ====================

    public function testCannotUpdateAnotherUserEvenWhenIdIsSentInBody(): void
    {
        // Acting user 12; a victim user 99 exists in the same tenant.
        $this->seedUser(12, 1, 'actor@example.com', 'secret-123', 2);
        $this->seedUser(99, 1, 'victim@example.com', 'victim-pass', 2);
        $this->authenticateAs(12, 1, 'actor@example.com', 'user');

        // The endpoint takes no id from the body; an injected `id`/`user_id` must
        // be ignored and only the acting user (12) may ever change.
        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest([
                'id' => 99,
                'user_id' => 99,
                'email' => 'hijacked@example.com',
                'current_password' => 'secret-123',
            ])
        );

        $this->assertSame(200, $response->getStatusCode());

        // The victim is untouched; the actor's own email changed.
        $this->assertSame(
            'victim@example.com',
            $this->pdo->query('SELECT email FROM users WHERE id = 99')->fetchColumn(),
            'A self-service update must never edit another user.'
        );
        $this->assertSame(
            'hijacked@example.com',
            $this->pdo->query('SELECT email FROM users WHERE id = 12')->fetchColumn(),
            'Only the authenticated user is updated.'
        );
    }

    public function testCannotReachAcrossTenants(): void
    {
        // User 40 belongs to tenant 2, but the token claims tenant 1 (a forged or
        // stale tenant claim). The (id, tenant_id) scoped lookup finds nothing, so
        // the request is rejected and tenant 2's row is untouched.
        $this->seedUser(40, 2, 'crosstenant@example.com', 'secret-123', 2);
        $this->authenticateAs(40, 1, 'crosstenant@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'moved@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'crosstenant@example.com',
            $this->pdo->query('SELECT email FROM users WHERE id = 40')->fetchColumn(),
            'A token scoped to the wrong tenant must not edit a user in another tenant.'
        );
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        // No access_token cookie set.
        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'x@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== current-password gate ====================

    public function testWrongCurrentPasswordIsRejectedWithNoWrite(): void
    {
        $this->seedUser(20, 1, 'guard@example.com', 'correct-pass', 2);
        $this->authenticateAs(20, 1, 'guard@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'changed@example.com', 'current_password' => 'WRONG'])
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'guard@example.com',
            $this->pdo->query('SELECT email FROM users WHERE id = 20')->fetchColumn(),
            'A wrong current password must not persist any change.'
        );
    }

    public function testMissingCurrentPasswordIsRejected(): void
    {
        $this->seedUser(21, 1, 'nocur@example.com', 'correct-pass', 2);
        $this->authenticateAs(21, 1, 'nocur@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'changed@example.com'])
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== validation ====================

    public function testEmailUniquenessWithinTenantIsEnforced(): void
    {
        $this->seedUser(30, 1, 'taken@example.com', 'pw1', 2);
        $this->seedUser(31, 1, 'me@example.com', 'secret-123', 2);
        $this->authenticateAs(31, 1, 'me@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'taken@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            'me@example.com',
            $this->pdo->query('SELECT email FROM users WHERE id = 31')->fetchColumn(),
            'A duplicate email within the tenant must be rejected without change.'
        );
    }

    public function testSameEmailInAnotherTenantIsAllowed(): void
    {
        // tenant 2 already uses this email; the caller in tenant 1 may take it.
        $this->seedUser(32, 2, 'shared@example.com', 'pw2', 2);
        $this->seedUser(33, 1, 'me2@example.com', 'secret-123', 2);
        $this->authenticateAs(33, 1, 'me2@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'shared@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'shared@example.com',
            $this->pdo->query('SELECT email FROM users WHERE id = 33')->fetchColumn()
        );
    }

    public function testInvalidEmailFormatIsRejected(): void
    {
        $this->seedUser(34, 1, 'fmt@example.com', 'secret-123', 2);
        $this->authenticateAs(34, 1, 'fmt@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'not-an-email', 'current_password' => 'secret-123'])
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTooShortPasswordIsRejected(): void
    {
        $this->seedUser(35, 1, 'short@example.com', 'secret-123', 2);
        $this->authenticateAs(35, 1, 'short@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['password' => 'short', 'current_password' => 'secret-123'])
        );

        $this->assertSame(400, $response->getStatusCode());
        // Unchanged: the original password still verifies.
        $hash = (string) $this->pdo->query('SELECT password FROM users WHERE id = 35')->fetchColumn();
        $this->assertTrue(password_verify('secret-123', $hash));
    }

    public function testNoFieldsProvidedIsRejected(): void
    {
        $this->seedUser(36, 1, 'empty@example.com', 'secret-123', 2);
        $this->authenticateAs(36, 1, 'empty@example.com', 'user');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['current_password' => 'secret-123'])
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== Helpers ====================

    private function handler(): AuthHandler
    {
        $tokenValidator = new TokenValidator($this->jwtParser, $this->pdo);

        return new AuthHandler($this->pdo, $this->jwtParser, $tokenValidator);
    }

    /**
     * Place a valid access-token cookie for the given user, exactly as the live
     * stack does, so the self-only handler resolves THIS user from the token.
     */
    private function authenticateAs(int $userId, int $tenantId, string $email, string $role): void
    {
        $token = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
        ], 900, 'access');

        $_COOKIE['access_token'] = $token;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return new Request('PATCH', '/api/me', [], (string) json_encode($body));
    }

    private function seedUser(int $id, int $tenantId, string $email, string $plainPassword, int $roleId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, $email, password_hash($plainPassword, PASSWORD_BCRYPT), $roleId]);
    }

    /**
     * In-memory SQLite seeded with a users/roles schema mirroring production:
     * users has the UNIQUE(tenant_id, email) constraint the email-uniqueness
     * check relies on, and the seeded base roles (admin=1, user=2) so the public
     * response can resolve the role name.
     */
    private static function makeSqliteSchema(): PDO
    {
        return SchemaFromMigrations::make();
    }
}
