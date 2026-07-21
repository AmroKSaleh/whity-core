<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TwoFactorPoliciesApiHandler;
use Whity\Auth\TwoFactorPolicyResolver;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine tests for {@see TwoFactorPoliciesApiHandler} (WC-525 PR-3): CRUD
 * validation, the partial-unique-index duplicate-scope 409, tenant scoping via
 * TenantContext, and the status() enrollment-across-scopes computation
 * (tenant-wide, OU-chain descendants, user-specific). (Route-level RBAC on
 * security:manage is enforced by middleware, not exercised here.)
 */
final class TwoFactorPoliciesApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $dbWrapper;
    private TwoFactorPoliciesApiHandler $handler;
    private int $tenantId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        self::assertNotFalse($stmt);
        $stmt->execute([':n' => 'Acme', ':s' => 'acme']);
        $this->tenantId = (int) $this->pdo->lastInsertId();
        TenantContext::setTenantId($this->tenantId);
        AuditContext::set(null, null);

        $this->dbWrapper = self::wrapSqlite($this->pdo);
        $resolver = new TwoFactorPolicyResolver($this->dbWrapper);
        $this->handler = new TwoFactorPoliciesApiHandler($this->pdo, $resolver);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testListIsEmptyByDefault(): void
    {
        $data = $this->decode($this->handler->list($this->req('GET', '/api/2fa-policies')))['data'];
        $this->assertSame([], $data);
    }

    public function testCreateTenantWidePolicy(): void
    {
        $response = $this->handler->create($this->req('POST', '/api/2fa-policies', [
            'scope_type' => 'tenant',
            'grace_period_days' => 14,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = $this->decode($response)['data'];
        $this->assertSame('tenant', $data['scope_type']);
        $this->assertNull($data['scope_id']);
        $this->assertSame(14, $data['grace_period_days']);
    }

    public function testCreateRejectsScopeIdOnTenantWidePolicy(): void
    {
        $response = $this->handler->create($this->req('POST', '/api/2fa-policies', [
            'scope_type' => 'tenant',
            'scope_id' => 5,
        ]));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateRejectsUnknownOu(): void
    {
        $response = $this->handler->create($this->req('POST', '/api/2fa-policies', [
            'scope_type' => 'ou',
            'scope_id' => 999999,
        ]));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateRejectsProfileWithNoActiveMembership(): void
    {
        $response = $this->handler->create($this->req('POST', '/api/2fa-policies', [
            'scope_type' => 'user',
            'scope_id' => 999999,
        ]));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testDuplicateTenantWidePolicyIsRejectedWith409(): void
    {
        $first = $this->handler->create($this->req('POST', '/api/2fa-policies', ['scope_type' => 'tenant']));
        $this->assertSame(201, $first->getStatusCode());

        $second = $this->handler->create($this->req('POST', '/api/2fa-policies', ['scope_type' => 'tenant']));
        $this->assertSame(409, $second->getStatusCode());
    }

    public function testDuplicatePolicyForTheSameOuIsRejectedWith409(): void
    {
        $ouId = $this->seedOu(null);

        $first = $this->handler->create($this->req('POST', '/api/2fa-policies', [
            'scope_type' => 'ou', 'scope_id' => $ouId,
        ]));
        $this->assertSame(201, $first->getStatusCode());

        $second = $this->handler->create($this->req('POST', '/api/2fa-policies', [
            'scope_type' => 'ou', 'scope_id' => $ouId,
        ]));
        $this->assertSame(409, $second->getStatusCode());
    }

    public function testUpdateChangesGracePeriod(): void
    {
        $created = $this->decode($this->handler->create(
            $this->req('POST', '/api/2fa-policies', ['scope_type' => 'tenant', 'grace_period_days' => 7])
        ))['data'];

        $response = $this->handler->update(
            $this->req('PATCH', "/api/2fa-policies/{$created['id']}", ['grace_period_days' => 30]),
            ['id' => (string) $created['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(30, $this->decode($response)['data']['grace_period_days']);
    }

    public function testUpdateUnknownIdReturns404(): void
    {
        $response = $this->handler->update(
            $this->req('PATCH', '/api/2fa-policies/999999', ['grace_period_days' => 1]),
            ['id' => '999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteRemovesThePolicy(): void
    {
        $created = $this->decode($this->handler->create(
            $this->req('POST', '/api/2fa-policies', ['scope_type' => 'tenant'])
        ))['data'];

        $response = $this->handler->delete(
            $this->req('DELETE', "/api/2fa-policies/{$created['id']}"),
            ['id' => (string) $created['id']]
        );
        $this->assertSame(204, $response->getStatusCode());

        $listed = $this->decode($this->handler->list($this->req('GET', '/api/2fa-policies')))['data'];
        $this->assertSame([], $listed);
    }

    public function testDeleteUnknownIdReturns404(): void
    {
        $response = $this->handler->delete(
            $this->req('DELETE', '/api/2fa-policies/999999'),
            ['id' => '999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStatusReportsTenantWideCoverageAndEnrollmentState(): void
    {
        $enrolledId = $this->seedProfile('alice@example.com', null, twoFactorEnabled: true);
        $unenrolledId = $this->seedProfile('bob@example.com', null, twoFactorEnabled: false);

        $this->handler->create($this->req('POST', '/api/2fa-policies', ['scope_type' => 'tenant', 'grace_period_days' => 10]));

        $data = $this->decode($this->handler->status($this->req('GET', '/api/2fa-policies/status')))['data'];
        $byProfile = [];
        foreach ($data as $entry) {
            $byProfile[$entry['profile_id']] = $entry;
        }

        $this->assertTrue($byProfile[$enrolledId]['enrolled']);
        $this->assertNull($byProfile[$enrolledId]['enforcement_deadline']);
        $this->assertFalse($byProfile[$unenrolledId]['enrolled']);
        $this->assertIsInt($byProfile[$unenrolledId]['enforcement_deadline']);
    }

    public function testStatusOuScopeCoversDescendantsOnly(): void
    {
        $rootOu = $this->seedOu(null);
        $childOu = $this->seedOu($rootOu);
        $siblingOu = $this->seedOu(null);

        $inScopeId = $this->seedProfile('carol@example.com', $childOu);
        $outOfScopeId = $this->seedProfile('dave@example.com', $siblingOu);

        $this->handler->create($this->req('POST', '/api/2fa-policies', ['scope_type' => 'ou', 'scope_id' => $rootOu]));

        $data = $this->decode($this->handler->status($this->req('GET', '/api/2fa-policies/status')))['data'];
        $profileIds = array_column($data, 'profile_id');

        $this->assertContains($inScopeId, $profileIds);
        $this->assertNotContains($outOfScopeId, $profileIds);
    }

    public function testStatusUserScopeCoversOnlyThatProfile(): void
    {
        $targetId = $this->seedProfile('erin@example.com', null);
        $otherId = $this->seedProfile('frank@example.com', null);

        $this->handler->create($this->req('POST', '/api/2fa-policies', ['scope_type' => 'user', 'scope_id' => $targetId]));

        $data = $this->decode($this->handler->status($this->req('GET', '/api/2fa-policies/status')))['data'];
        $profileIds = array_column($data, 'profile_id');

        $this->assertContains($targetId, $profileIds);
        $this->assertNotContains($otherId, $profileIds);
    }

    // ==================== Helpers ====================

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body = null): Request
    {
        return new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());

        return $decoded;
    }

    private function seedOu(?int $parentId): int
    {
        $slug = 'ou-' . uniqid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, name, slug, parent_id, created_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([$this->tenantId, $slug, $slug, $parentId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedProfile(string $email, ?int $ouId, bool $twoFactorEnabled = false): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', ?, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([explode('@', $email)[0], $twoFactorEnabled ? 1 : 0]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, 1, 1, datetime('now'))"
        )->execute([$profileId, $email]);

        $roleStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'user'");
        self::assertNotFalse($roleStmt);
        $roleId = (int) $roleStmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, \'active\', datetime(\'now\'))'
        )->execute([$profileId, $this->tenantId, $roleId, $ouId]);

        return $profileId;
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
