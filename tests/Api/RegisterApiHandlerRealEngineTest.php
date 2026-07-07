<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RegisterApiHandler;
use Whity\Core\Identity\AccountActivationPolicy;
use Whity\Core\Identity\EmailVerificationPolicy;
use Whity\Core\Identity\EmailVerificationProvider;
use Whity\Core\Request;

/**
 * Real-engine tests for public self-service registration (WC-235).
 *
 * Drives the REAL {@see RegisterApiHandler} against the full migration-built
 * schema (in-memory SQLite locally; the same handler SQL runs on the
 * postgres-integration CI job). Proves: (1) a successful signup provisions a
 * tenant + global profile + primary verified email + ACTIVE admin membership;
 * (2) the created owner account is usable (password verifies, membership is
 * active with the admin role); (3) duplicate email / duplicate workspace name
 * are rejected AND leave nothing behind (the transaction rolls back);
 * (4) invalid email, weak password, and missing workspace name are rejected.
 */
final class RegisterApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private RegisterApiHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->handler = new RegisterApiHandler($this->pdo);
    }

    protected function tearDown(): void
    {
        // Never leak the enforcement flags into other tests.
        unset($_ENV[EmailVerificationPolicy::ENV_FLAG]);
        unset($_ENV[AccountActivationPolicy::ENV_FLAG]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function register(array $body): \Whity\Sdk\Http\Response
    {
        return $this->handler->register(
            new Request('POST', '/api/register', [], (string) json_encode($body))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testRegisterProvisionsTenantProfileAndOwnerMembership(): void
    {
        $res = $this->register([
            'email'        => 'owner@acme.test',
            'password'     => 'a-strong-password',
            'tenant_name'  => 'Acme Inc',
            'display_name' => 'Acme Owner',
        ]);

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $data = $this->decode($res)['data'];
        $profileId = (int) $data['profile_id'];
        $tenantId = (int) $data['tenant_id'];
        self::assertGreaterThan(0, $profileId);
        self::assertGreaterThan(0, $tenantId);

        // Tenant created with a URL-safe slug.
        $tenant = $this->pdo->query("SELECT name, slug FROM tenants WHERE id = {$tenantId}")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('Acme Inc', $tenant['name']);
        self::assertSame('acme-inc', $tenant['slug']);

        // Primary, verified profile email. (Compare the raw value as a string to
        // stay correct across SQLite '1'/'0' and Postgres 't'/'f' boolean fetches
        // — (bool)'f' === true, so a (bool) cast would be a false-positive trap.)
        $emailRow = $this->pdo->query(
            "SELECT email, verified, is_primary FROM profile_emails WHERE profile_id = {$profileId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('owner@acme.test', $emailRow['email']);
        self::assertContains((string) $emailRow['verified'], ['1', 't', 'true']);
        self::assertContains((string) $emailRow['is_primary'], ['1', 't', 'true']);

        // ACTIVE owner membership in the new tenant, carrying the admin role.
        $membership = $this->pdo->query(
            "SELECT m.status, r.name AS role
             FROM memberships m JOIN roles r ON r.id = m.role_id
             WHERE m.profile_id = {$profileId} AND m.tenant_id = {$tenantId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('active', $membership['status']);
        self::assertSame('admin', $membership['role']);

        // The stored password hash verifies (the owner can actually log in).
        $hash = (string) $this->pdo->query("SELECT password_hash FROM profiles WHERE id = {$profileId}")
            ->fetchColumn();
        self::assertTrue(password_verify('a-strong-password', $hash));
        self::assertSame('Acme Owner', (string) $this->pdo->query(
            "SELECT display_name FROM profiles WHERE id = {$profileId}"
        )->fetchColumn());
    }

    public function testDisplayNameDefaultsToEmailLocalPart(): void
    {
        $res = $this->register([
            'email'       => 'jane@acme.test',
            'password'    => 'a-strong-password',
            'tenant_name' => 'Jane WS',
        ]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $profileId = (int) $this->decode($res)['data']['profile_id'];
        self::assertSame('jane', (string) $this->pdo->query(
            "SELECT display_name FROM profiles WHERE id = {$profileId}"
        )->fetchColumn());
    }

    public function testDuplicateEmailIsRejectedAndNothingIsCreated(): void
    {
        self::assertSame(201, $this->register([
            'email' => 'dup@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'First Workspace',
        ])->getStatusCode());

        $tenantsBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();

        $res = $this->register([
            'email' => 'dup@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'Second Workspace',
        ]);
        self::assertSame(409, $res->getStatusCode());

        // The rejected registration must NOT have created a partial tenant.
        $tenantsAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
        self::assertSame($tenantsBefore, $tenantsAfter, 'a rejected duplicate-email signup must leave no tenant behind');
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'Second Workspace'")->fetchColumn());
    }

    public function testDuplicateWorkspaceNameIsRejectedAndNoProfileCreated(): void
    {
        self::assertSame(201, $this->register([
            'email' => 'a@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'Shared Workspace',
        ])->getStatusCode());

        $res = $this->register([
            'email' => 'b@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'Shared Workspace',
        ]);
        self::assertSame(409, $res->getStatusCode());
        // The second registrant's profile must not be created (transaction rollback).
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'b@acme.test'")->fetchColumn());
    }

    public function testWeakPasswordIsRejected(): void
    {
        $res = $this->register([
            'email' => 'weak@acme.test', 'password' => 'short', 'tenant_name' => 'Weak Workspace',
        ]);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'weak@acme.test'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'Weak Workspace'")->fetchColumn());
    }

    public function testInvalidEmailIsRejected(): void
    {
        $res = $this->register([
            'email' => 'not-an-email', 'password' => 'a-strong-password', 'tenant_name' => 'X Workspace',
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testMissingWorkspaceNameIsRejected(): void
    {
        $res = $this->register([
            'email' => 'c@acme.test', 'password' => 'a-strong-password', 'tenant_name' => '',
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testWorkspaceNameWithNoAlphanumericsIsRejected(): void
    {
        $res = $this->register([
            'email' => 'd@acme.test', 'password' => 'a-strong-password', 'tenant_name' => '!!!',
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testOverLongWorkspaceNameIsRejectedWith422NotA500(): void
    {
        // A value longer than the backing VARCHAR(255) must be caught by
        // validation (422), not blow up as a Postgres 22001 → generic 500.
        $res = $this->register([
            'email'       => 'long@acme.test',
            'password'    => 'a-strong-password',
            'tenant_name' => str_repeat('a', 256),
        ]);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'long@acme.test'")->fetchColumn());
    }

    // ── WC-235: email-verification seam (default OFF; enforce via env flag) ────

    public function testVerificationDisabledByDefaultMarksEmailVerifiedAndSendsNothing(): void
    {
        // Inline anon spy so PHPStan sees the concrete type (and its $calls).
        $spy = new class implements EmailVerificationProvider {
            /** @var list<array{profileId: int, email: string}> */
            public array $calls = [];

            public function sendVerification(int $profileId, string $email): void
            {
                $this->calls[] = ['profileId' => $profileId, 'email' => $email];
            }
        };
        $handler = new RegisterApiHandler($this->pdo, $spy);

        $res = $handler->register(new Request('POST', '/api/register', [], (string) json_encode([
            'email' => 'mvp@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'MVP WS',
        ])));

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertFalse($this->decode($res)['data']['verification_required']);
        self::assertContains(
            (string) $this->pdo->query("SELECT verified FROM profile_emails WHERE email = 'mvp@acme.test'")->fetchColumn(),
            ['1', 't', 'true'],
            'MVP default: the owner email is self-attested verified'
        );
        self::assertSame([], $spy->calls, 'no verification is dispatched when the flag is off');
    }

    public function testVerificationEnforcedMarksEmailUnverifiedAndHandsOffToProvider(): void
    {
        $_ENV[EmailVerificationPolicy::ENV_FLAG] = '1';
        $spy = new class implements EmailVerificationProvider {
            /** @var list<array{profileId: int, email: string}> */
            public array $calls = [];

            public function sendVerification(int $profileId, string $email): void
            {
                $this->calls[] = ['profileId' => $profileId, 'email' => $email];
            }
        };
        $handler = new RegisterApiHandler($this->pdo, $spy);

        $res = $handler->register(new Request('POST', '/api/register', [], (string) json_encode([
            'email' => 'verify@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'Verify WS',
        ])));

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertTrue($this->decode($res)['data']['verification_required']);
        $profileId = (int) $this->decode($res)['data']['profile_id'];
        // "not verified": assert it is NOT the verified-true representation
        // rather than guessing the false one (SQLite '0'; Postgres stringifies
        // boolean false to '' and true to '1').
        self::assertNotContains(
            (string) $this->pdo->query("SELECT verified FROM profile_emails WHERE email = 'verify@acme.test'")->fetchColumn(),
            ['1', 't', 'true'],
            'enforced: the owner email starts unverified'
        );
        // The provider was handed off to exactly once, with the new profile + email.
        self::assertCount(1, $spy->calls);
        self::assertSame($profileId, $spy->calls[0]['profileId']);
        self::assertSame('verify@acme.test', $spy->calls[0]['email']);
    }

    // ── WC-235: admin-approval seam (default OFF; enforce via env flag) ────────

    public function testApprovalDisabledByDefaultProvisionsActiveOwnerAndNoApprovalFlag(): void
    {
        $res = $this->register([
            'email' => 'auto@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'Auto WS',
        ]);

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertFalse($this->decode($res)['data']['approval_required']);
        $profileId = (int) $this->decode($res)['data']['profile_id'];
        self::assertSame('active', (string) $this->pdo->query(
            "SELECT status FROM memberships WHERE profile_id = {$profileId}"
        )->fetchColumn());
    }

    public function testApprovalEnforcedProvisionsInvitedOwnerAndSetsApprovalFlag(): void
    {
        $_ENV[AccountActivationPolicy::ENV_FLAG] = '1';

        $res = $this->register([
            'email' => 'pending@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'Pending WS',
        ]);

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertTrue($this->decode($res)['data']['approval_required']);
        $profileId = (int) $this->decode($res)['data']['profile_id'];
        // The owner membership starts 'invited' (pending) — login is refused
        // until a system-tenant admin approves it. The tenant + profile + email
        // are still fully provisioned so approval only needs a status flip.
        self::assertSame('invited', (string) $this->pdo->query(
            "SELECT status FROM memberships WHERE profile_id = {$profileId}"
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM profile_emails WHERE email = 'pending@acme.test'"
        )->fetchColumn());
    }

    public function testProviderFailureUnderEnforcementStillReturns201(): void
    {
        // A verification-delivery failure must NOT undo the created account.
        $_ENV[EmailVerificationPolicy::ENV_FLAG] = '1';
        $throwing = new class implements EmailVerificationProvider {
            public function sendVerification(int $profileId, string $email): void
            {
                throw new \RuntimeException('smtp down');
            }
        };
        $handler = new RegisterApiHandler($this->pdo, $throwing);

        $res = $handler->register(new Request('POST', '/api/register', [], (string) json_encode([
            'email' => 'resilient@acme.test', 'password' => 'a-strong-password', 'tenant_name' => 'Resilient WS',
        ])));

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'resilient@acme.test'")->fetchColumn());
    }
}
