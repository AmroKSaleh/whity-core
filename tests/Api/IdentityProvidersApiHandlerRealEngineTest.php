<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\IdentityProvidersApiHandler;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see IdentityProvidersApiHandler} (WC-e6287): the admin
 * CRUD surface, client-secret encryption-at-rest + never-returned, input
 * validation, and tenant scoping via TenantContext. (Route-level RBAC gating on
 * auth_providers:manage is enforced by the middleware, not the handler.)
 */
final class IdentityProvidersApiHandlerRealEngineTest extends TestCase
{
    private const KEY = 'idp_test_key_0123456789abcdef0123456789';

    private PDO $pdo;
    private IdentityProvidersApiHandler $handler;
    private EncryptedSecretStore $secrets;
    private int $tenantId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $this->handler = new IdentityProvidersApiHandler($this->pdo, $this->secrets);

        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        if ($stmt === false) {
            self::fail('prepare failed');
        }
        $stmt->execute([':n' => 'Acme', ':s' => 'acme']);
        $this->tenantId = (int) $this->pdo->lastInsertId();
        TenantContext::setTenantId($this->tenantId);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): \Whity\Sdk\Http\Response
    {
        return $this->handler->create(new Request('POST', '/api/identity-providers', [], (string) json_encode($body)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $d = json_decode($res->getBody(), true);
        self::assertIsArray($d);
        return $d;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'provider_key'  => 'google',
            'display_name'  => 'Google',
            'client_id'     => 'client-123.apps.googleusercontent.com',
            'client_secret' => 'super-secret-value',
            'issuer'        => 'https://accounts.google.com',
            'discovery_url' => 'https://accounts.google.com/.well-known/openid-configuration',
        ], $overrides);
    }

    public function testCreateEncryptsSecretAtRestAndNeverReturnsIt(): void
    {
        $res = $this->post($this->validBody());
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        $data = $this->decode($res)['data'];
        self::assertTrue($data['has_secret']);
        // The plaintext secret appears nowhere in the response.
        self::assertStringNotContainsString('super-secret-value', $res->getBody());
        self::assertArrayNotHasKey('client_secret', $data);
        self::assertArrayNotHasKey('client_secret_encrypted', $data);

        // Stored at rest as ciphertext that decrypts back to the plaintext.
        $stmt = $this->pdo->query('SELECT client_secret_encrypted FROM identity_providers LIMIT 1');
        self::assertNotFalse($stmt);
        $ciphertext = (string) $stmt->fetchColumn();
        self::assertStringNotContainsString('super-secret-value', $ciphertext);
        self::assertSame('super-secret-value', $this->secrets->decrypt($ciphertext));
    }

    public function testCreateRejectsUnknownProviderKey(): void
    {
        self::assertSame(422, $this->post($this->validBody(['provider_key' => 'facebook']))->getStatusCode());
    }

    public function testCreateRequiresCoreFields(): void
    {
        self::assertSame(422, $this->post($this->validBody(['display_name' => '']))->getStatusCode());
        self::assertSame(422, $this->post($this->validBody(['client_id' => '']))->getStatusCode());
        self::assertSame(422, $this->post($this->validBody(['issuer' => '']))->getStatusCode());
    }

    public function testCreateRejectsNonHttpsIssuer(): void
    {
        self::assertSame(422, $this->post($this->validBody(['issuer' => 'http://insecure.example']))->getStatusCode());
    }

    public function testCreateRejectsDuplicateProviderPerTenant(): void
    {
        self::assertSame(201, $this->post($this->validBody())->getStatusCode());
        self::assertSame(409, $this->post($this->validBody())->getStatusCode());
    }

    public function testOverLongFieldIsRejectedWith422NotA500(): void
    {
        $res = $this->post($this->validBody(['display_name' => str_repeat('a', 256)]));
        self::assertSame(422, $res->getStatusCode(), $res->getBody());
    }

    public function testUpdateToDuplicateProviderKeyIs409(): void
    {
        $this->post($this->validBody(['provider_key' => 'google']));
        $msId = (int) $this->decode($this->post($this->validBody([
            'provider_key' => 'microsoft',
            'display_name' => 'MS',
        ])))['data']['id'];

        // Renaming the microsoft provider's key to the already-taken google → 409.
        $res = $this->handler->update(
            new Request('PATCH', '/api/identity-providers/' . $msId, [], (string) json_encode(['provider_key' => 'google'])),
            ['id' => (string) $msId]
        );
        self::assertSame(409, $res->getStatusCode(), $res->getBody());
    }

    public function testDisabledProviderRoundTripsAsDisabled(): void
    {
        $id = (int) $this->decode($this->post($this->validBody(['enabled' => false])))['data']['id'];
        $res = $this->handler->update(
            new Request('PATCH', '/api/identity-providers/' . $id, [], (string) json_encode(['display_name' => 'x'])),
            ['id' => (string) $id]
        );
        self::assertFalse($this->decode($res)['data']['enabled']);
    }

    public function testListReturnsProvidersWithoutSecret(): void
    {
        $this->post($this->validBody());
        $res = $this->handler->list(new Request('GET', '/api/identity-providers', [], ''));
        self::assertSame(200, $res->getStatusCode());
        $data = $this->decode($res)['data'];
        self::assertCount(1, $data);
        self::assertArrayNotHasKey('client_secret_encrypted', $data[0]);
        self::assertTrue($data[0]['has_secret']);
    }

    public function testUpdateWithoutSecretKeepsStoredSecret(): void
    {
        $id = (int) $this->decode($this->post($this->validBody()))['data']['id'];

        $res = $this->handler->update(
            new Request('PATCH', '/api/identity-providers/' . $id, [], (string) json_encode(['display_name' => 'Google Workspace'])),
            ['id' => (string) $id]
        );
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame('Google Workspace', $this->decode($res)['data']['display_name']);

        // The secret is unchanged and still decrypts.
        $stmt = $this->pdo->query("SELECT client_secret_encrypted FROM identity_providers WHERE id = {$id}");
        self::assertNotFalse($stmt);
        self::assertSame('super-secret-value', $this->secrets->decrypt((string) $stmt->fetchColumn()));
    }

    public function testUpdateNonexistentIs404(): void
    {
        $res = $this->handler->update(
            new Request('PATCH', '/api/identity-providers/9999', [], (string) json_encode(['display_name' => 'x'])),
            ['id' => '9999']
        );
        self::assertSame(404, $res->getStatusCode());
    }

    public function testDeleteRemovesProviderThen404(): void
    {
        $id = (int) $this->decode($this->post($this->validBody()))['data']['id'];

        $del = $this->handler->delete(new Request('DELETE', '/api/identity-providers/' . $id, [], ''), ['id' => (string) $id]);
        self::assertSame(204, $del->getStatusCode());

        $again = $this->handler->delete(new Request('DELETE', '/api/identity-providers/' . $id, [], ''), ['id' => (string) $id]);
        self::assertSame(404, $again->getStatusCode());
    }

    public function testTenantContextRequired(): void
    {
        TenantContext::reset();
        self::assertSame(400, $this->handler->list(new Request('GET', '/api/identity-providers', [], ''))->getStatusCode());
    }
}
