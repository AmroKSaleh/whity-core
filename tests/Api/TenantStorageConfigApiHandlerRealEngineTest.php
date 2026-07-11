<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantStorageConfigApiHandler;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see TenantStorageConfigApiHandler} (WC-storage): the
 * plan gate (storage.custom_backend entitlement), secret encryption-at-rest +
 * never-returned, keep-secret-on-update, input validation, and tenant scoping via
 * TenantContext. (Route-level RBAC on storage:manage is enforced by middleware.)
 */
final class TenantStorageConfigApiHandlerRealEngineTest extends TestCase
{
    private const KEY = 'storage_cfg_key_0123456789abcdef0123456789';

    private PDO $pdo;
    private EncryptedSecretStore $secrets;
    private EntitlementService $entitlements;
    private TenantStorageConfigApiHandler $handler;
    private int $tenantId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->handler = new TenantStorageConfigApiHandler($this->pdo, $this->secrets, $this->entitlements);

        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        self::assertNotFalse($stmt);
        $stmt->execute([':n' => 'Acme', ':s' => 'acme']);
        $this->tenantId = (int) $this->pdo->lastInsertId();
        TenantContext::setTenantId($this->tenantId);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function entitle(): void
    {
        $this->entitlements->set($this->tenantId, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, 'true', null);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'driver'      => 's3',
            'endpoint'    => 'https://s3.example.com',
            'region'      => 'us-east-1',
            'bucket'      => 'acme-bucket',
            'access_key'  => 'AKIAEXAMPLE',
            'secret'      => 'super-secret-value',
            'path_style'  => true,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(array $body): \Whity\Sdk\Http\Response
    {
        return $this->handler->put(new Request('PUT', '/api/storage-config', [], (string) json_encode($body)));
    }

    private function get(): \Whity\Sdk\Http\Response
    {
        return $this->handler->get(new Request('GET', '/api/storage-config', [], ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $d = json_decode($res->getBody(), true);
        self::assertIsArray($d, $res->getBody());
        return $d;
    }

    public function testGetReturnsNullConfigAndNotEntitledByDefault(): void
    {
        $data = $this->decode($this->get())['data'];
        self::assertNull($data['config']);
        self::assertFalse($data['entitled']);
        self::assertContains('s3', $data['drivers']);
    }

    public function testPutRejectedWhenNotEntitled(): void
    {
        $res = $this->put($this->validBody());
        self::assertSame(403, $res->getStatusCode(), $res->getBody());
        // Nothing persisted.
        self::assertNull($this->decode($this->get())['data']['config']);
    }

    public function testPutSucceedsWhenEntitledAndEncryptsSecretNeverReturningIt(): void
    {
        $this->entitle();
        $res = $this->put($this->validBody());
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $config = $this->decode($res)['data'];
        self::assertTrue($config['has_secret']);
        self::assertSame('acme-bucket', $config['bucket']);
        self::assertStringNotContainsString('super-secret-value', $res->getBody());
        self::assertArrayNotHasKey('secret', $config);
        self::assertArrayNotHasKey('secret_encrypted', $config);

        // Stored at rest as ciphertext that decrypts back to the plaintext.
        $stmt = $this->pdo->query('SELECT secret_encrypted FROM tenant_storage_config LIMIT 1');
        self::assertNotFalse($stmt);
        $ciphertext = (string) $stmt->fetchColumn();
        self::assertStringNotContainsString('super-secret-value', $ciphertext);
        self::assertSame('super-secret-value', $this->secrets->decrypt($ciphertext));

        // GET now reflects it + entitled.
        $data = $this->decode($this->get())['data'];
        self::assertNotNull($data['config']);
        self::assertTrue($data['entitled']);
    }

    public function testPutRejectsNonHttpsEndpoint(): void
    {
        $this->entitle();
        self::assertSame(422, $this->put($this->validBody(['endpoint' => 'http://insecure.example']))->getStatusCode());
    }

    public function testPutRequiresCoreFields(): void
    {
        $this->entitle();
        self::assertSame(422, $this->put($this->validBody(['region' => '']))->getStatusCode());
        self::assertSame(422, $this->put($this->validBody(['bucket' => '']))->getStatusCode());
        self::assertSame(422, $this->put($this->validBody(['access_key' => '']))->getStatusCode());
    }

    public function testPutRejectsUnknownDriver(): void
    {
        $this->entitle();
        self::assertSame(422, $this->put($this->validBody(['driver' => 'gcs']))->getStatusCode());
    }

    public function testFirstConfigRequiresSecret(): void
    {
        $this->entitle();
        $body = $this->validBody();
        unset($body['secret']);
        self::assertSame(422, $this->put($body)->getStatusCode());
    }

    public function testUpdateWithoutSecretKeepsStoredSecret(): void
    {
        $this->entitle();
        self::assertSame(200, $this->put($this->validBody())->getStatusCode());

        // Change the bucket, omit the secret → the stored secret must survive.
        $body = $this->validBody(['bucket' => 'acme-bucket-2']);
        unset($body['secret']);
        $res = $this->put($body);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame('acme-bucket-2', $this->decode($res)['data']['bucket']);

        $stmt = $this->pdo->query('SELECT secret_encrypted FROM tenant_storage_config LIMIT 1');
        self::assertNotFalse($stmt);
        self::assertSame('super-secret-value', $this->secrets->decrypt((string) $stmt->fetchColumn()));
    }

    public function testDeleteRemovesConfigThen404(): void
    {
        $this->entitle();
        $this->put($this->validBody());

        $del = $this->handler->delete(new Request('DELETE', '/api/storage-config', [], ''));
        self::assertSame(204, $del->getStatusCode());
        self::assertNull($this->decode($this->get())['data']['config']);

        $again = $this->handler->delete(new Request('DELETE', '/api/storage-config', [], ''));
        self::assertSame(404, $again->getStatusCode());
    }

    public function testTenantContextRequired(): void
    {
        TenantContext::reset();
        self::assertSame(400, $this->get()->getStatusCode());
    }
}
