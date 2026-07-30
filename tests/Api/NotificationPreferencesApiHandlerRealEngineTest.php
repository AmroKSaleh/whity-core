<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\NotificationPreferencesApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Request;

/**
 * Real-engine tests for {@see NotificationPreferencesApiHandler}: the self-scoped
 * GET/PUT preference surface with a mocked TokenValidator. Proves the round-trip,
 * the transactional-disable rejection (422, no write), and the 401 fail-closed.
 */
final class NotificationPreferencesApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const CALLER = 101;

    private PDO $pdo;
    private NotificationPreferenceRepository $repo;
    private NotificationPreferenceResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $this->repo = new NotificationPreferenceRepository($this->pdo);
        $this->resolver = new NotificationPreferenceResolver($this->repo);
    }

    /** @param array<string, mixed>|null $claims */
    private function handler(?array $claims): NotificationPreferencesApiHandler
    {
        $tv = $this->createMock(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn($claims);

        return new NotificationPreferencesApiHandler($tv, $this->repo, $this->resolver);
    }

    private function authed(): NotificationPreferencesApiHandler
    {
        return $this->handler(['profile_id' => self::CALLER, 'active_tenant_id' => self::TENANT_A]);
    }

    private function putBody(string $json): Request
    {
        return new Request('PUT', '/api/me/notification-preferences', [], $json);
    }

    public function testListReturnsPrefsAndTransactionalPrefixes(): void
    {
        $this->repo->set(self::TENANT_A, self::CALLER, '*', 'email', false);

        $response = $this->authed()->list(new Request('GET', '/api/me/notification-preferences'));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(1, $body['data']);
        self::assertSame('email', $body['data'][0]['channel']);
        self::assertFalse($body['data'][0]['enabled']);
        self::assertContains('security.', $body['transactional_prefixes']);
    }

    public function testUpdateUpsertsBatch(): void
    {
        $json = json_encode(['preferences' => [
            ['type' => '*', 'channel' => 'email', 'enabled' => false],
            ['type' => 'project.mention', 'channel' => 'email', 'enabled' => true],
        ]]);
        self::assertIsString($json);

        $response = $this->authed()->update($this->putBody($json));
        self::assertSame(200, $response->getStatusCode());

        $stored = $this->repo->listForProfile(self::TENANT_A, self::CALLER);
        self::assertCount(2, $stored);
    }

    public function testUpdateRejectsDisablingTransactionalTypeAndWritesNothing(): void
    {
        $json = json_encode(['preferences' => [
            ['type' => 'marketing.promo', 'channel' => 'email', 'enabled' => false],
            ['type' => 'security.login_alert', 'channel' => 'email', 'enabled' => false], // not allowed
        ]]);
        self::assertIsString($json);

        $response = $this->authed()->update($this->putBody($json));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('security.login_alert', $response->getBody());
        self::assertCount(0, $this->repo->listForProfile(self::TENANT_A, self::CALLER), 'no partial write on a rejected batch');
    }

    public function testUpdateRejectsMalformedBody(): void
    {
        self::assertSame(422, $this->authed()->update($this->putBody('{"nope":1}'))->getStatusCode());
        self::assertSame(422, $this->authed()->update($this->putBody('not json'))->getStatusCode());
    }

    public function testEndpointsRequireAuth(): void
    {
        self::assertSame(401, $this->handler(null)->list(new Request('GET', '/api/me/notification-preferences'))->getStatusCode());
        self::assertSame(401, $this->handler(null)->update($this->putBody('{"preferences":[]}'))->getStatusCode());
    }
}
