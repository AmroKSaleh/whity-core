<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantNotificationSettingsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Notification\TenantNotificationSettingsRepository;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see TenantNotificationSettingsApiHandler}: RBAC gating
 * (settings:manage), tenant scoping, config upsert + redacted list, and the
 * write-only credentials path (encrypted at rest, never returned).
 */
final class TenantNotificationSettingsApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;

    private PDO $pdo;
    private TenantNotificationSettingsRepository $repo;
    private EncryptedSecretStore $secrets;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1,'a','a'),(2,'b','b') ON CONFLICT (id) DO NOTHING");
        $this->repo = new TenantNotificationSettingsRepository($this->pdo);
        $this->secrets = EncryptedSecretStore::fromEnv(['ENCRYPTION_KEY' => str_repeat('k', 32)]);
        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function handler(bool $grant): TenantNotificationSettingsApiHandler
    {
        $rc = $this->createMock(RoleChecker::class);
        $rc->method('hasPermissionForProfile')->willReturn($grant);

        return new TenantNotificationSettingsApiHandler($rc, $this->repo, $this->secrets, new NullLogger());
    }

    private function req(string $method, string $path, string $body = ''): Request
    {
        $r = new Request($method, $path, [], $body);
        $r->user = (object) ['profile_id' => 10];

        return $r;
    }

    public function testListRequiresSettingsManage(): void
    {
        $response = $this->handler(false)->list($this->req('GET', '/api/notification-settings'));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testMissingActorIs403(): void
    {
        // A request with no authenticated user is rejected even if the permission
        // check would pass, because there is no profile to authorize.
        $req = new Request('GET', '/api/notification-settings');
        self::assertSame(403, $this->handler(true)->list($req)->getStatusCode());
    }

    public function testUpdateChannelThenListRoundTrip(): void
    {
        $handler = $this->handler(true);
        $body = json_encode(['transport' => 'smtp', 'from_address' => 'noreply@acme.test', 'config' => ['host' => 'h']]);
        self::assertIsString($body);

        $put = $handler->updateChannel($this->req('PUT', '/api/notification-settings/email', $body), ['channel' => 'email']);
        self::assertSame(200, $put->getStatusCode());

        $list = json_decode($handler->list($this->req('GET', '/api/notification-settings'))->getBody(), true);
        self::assertIsArray($list);
        self::assertCount(1, $list['data']);
        self::assertSame('noreply@acme.test', $list['data'][0]['from_address']);
        self::assertFalse($list['data'][0]['has_credentials']);
    }

    public function testSetCredentialsEncryptsAtRestAndNeverReturnsThem(): void
    {
        $handler = $this->handler(true);
        $handler->updateChannel($this->req('PUT', '/api/notification-settings/email', '{}'), ['channel' => 'email']);

        $secret = 'smtp-password-supersecret-0001';
        $body = json_encode(['credentials' => $secret]);
        self::assertIsString($body);
        $resp = $handler->setCredentials($this->req('PUT', '/api/notification-settings/email/credentials', $body), ['channel' => 'email']);
        self::assertSame(204, $resp->getStatusCode());

        // Stored blob is ENCRYPTED (not the plaintext) but decrypts back to it.
        $stored = $this->repo->findWithCredentials(self::TENANT_A, 'email');
        self::assertNotNull($stored);
        $blob = $stored['credentials_encrypted'];
        self::assertIsString($blob);
        self::assertNotSame($secret, $blob, 'the secret must be encrypted at rest');
        self::assertSame($secret, $this->secrets->decrypt($blob));

        // The API only ever exposes has_credentials, never the secret/blob.
        $listBody = $handler->list($this->req('GET', '/api/notification-settings'))->getBody();
        self::assertStringNotContainsString($secret, $listBody);
        self::assertStringNotContainsString($blob, $listBody);
        $list = json_decode($listBody, true);
        self::assertIsArray($list);
        self::assertTrue($list['data'][0]['has_credentials']);
    }

    public function testClearCredentials(): void
    {
        $handler = $this->handler(true);
        $handler->updateChannel($this->req('PUT', '/api/notification-settings/email', '{}'), ['channel' => 'email']);
        $handler->setCredentials($this->req('PUT', '/api/notification-settings/email/credentials', '{"credentials":"x"}'), ['channel' => 'email']);
        $resp = $handler->setCredentials($this->req('PUT', '/api/notification-settings/email/credentials', '{"credentials":null}'), ['channel' => 'email']);
        self::assertSame(204, $resp->getStatusCode());

        $row = $this->repo->findForChannel(self::TENANT_A, 'email');
        self::assertNotNull($row);
        self::assertFalse($row['has_credentials']);
    }

    public function testUpdateRejectsInvalidEmail(): void
    {
        $resp = $this->handler(true)->updateChannel(
            $this->req('PUT', '/api/notification-settings/email', '{"from_address":"not-an-email"}'),
            ['channel' => 'email']
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testCredentialsRequiresField(): void
    {
        $resp = $this->handler(true)->setCredentials(
            $this->req('PUT', '/api/notification-settings/email/credentials', '{}'),
            ['channel' => 'email']
        );
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testDeleteChannel(): void
    {
        $handler = $this->handler(true);
        $handler->updateChannel($this->req('PUT', '/api/notification-settings/email', '{}'), ['channel' => 'email']);

        self::assertSame(204, $handler->deleteChannel($this->req('DELETE', '/api/notification-settings/email'), ['channel' => 'email'])->getStatusCode());
        self::assertSame(404, $handler->deleteChannel($this->req('DELETE', '/api/notification-settings/email'), ['channel' => 'email'])->getStatusCode());
    }
}
