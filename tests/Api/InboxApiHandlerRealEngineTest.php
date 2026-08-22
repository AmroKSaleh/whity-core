<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InboxApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Request;

/**
 * Real-engine tests for {@see InboxApiHandler}: the self-scoped inbox surface
 * over a migration-built schema, with a mocked TokenValidator supplying the
 * caller's claims. Proves the list envelope + unread count, unread filtering,
 * mark-read (owned 204 / foreign 404), mark-all-read, and the 401 fail-closed.
 */
final class InboxApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const CALLER = 101;

    private PDO $pdo;
    private NotificationRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);

        // The notification fixtures below address profiles by id, and #751 gave
        // `notifications.recipient_profile_id` a real foreign key — so the people
        // being notified have to exist before a notification can name them.
        RecipientProfiles::seed($this->pdo);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->repo = new NotificationRepository($this->pdo);
    }

    /**
     * A handler whose TokenValidator returns the given claims (null = unauthenticated).
     *
     * @param array<string, mixed>|null $claims
     */
    private function handler(?array $claims): InboxApiHandler
    {
        $tv = $this->createMock(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn($claims);

        return new InboxApiHandler($tv, $this->repo);
    }

    private function authed(): InboxApiHandler
    {
        return $this->handler(['profile_id' => self::CALLER, 'active_tenant_id' => self::TENANT_A]);
    }

    public function testListReturnsOwnNotificationsWithUnreadCount(): void
    {
        $this->repo->create(self::TENANT_A, self::CALLER, 'a', ['subject' => 'one']);
        $this->repo->create(self::TENANT_A, self::CALLER, 'a', ['subject' => 'two']);
        $this->repo->create(self::TENANT_A, 999, 'a', ['subject' => 'someone else']); // not the caller

        $response = $this->authed()->list(new Request('GET', '/api/me/notifications'));
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(2, $body['data'], 'only the caller\'s notifications');
        self::assertSame(2, $body['unread_count']);
        self::assertSame(2, $body['pagination']['total']);
        // Newest first + shaped (no tenant_id / recipient leak).
        self::assertSame('two', $body['data'][0]['subject']);
        self::assertArrayNotHasKey('tenant_id', $body['data'][0]);
        self::assertArrayNotHasKey('recipient_profile_id', $body['data'][0]);
        self::assertFalse($body['data'][0]['read']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $response = $this->handler(null)->list(new Request('GET', '/api/me/notifications'));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testUnreadOnlyFilter(): void
    {
        $this->repo->create(self::TENANT_A, self::CALLER, 'a');
        $read = $this->repo->create(self::TENANT_A, self::CALLER, 'a');
        $this->repo->markRead(self::TENANT_A, self::CALLER, $read);

        $response = $this->authed()->list(new Request('GET', '/api/me/notifications?unread=1'));
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(1, $body['data'], 'only the unread one');
        self::assertSame(1, $body['unread_count']);
    }

    public function testUnreadCountEndpoint(): void
    {
        $this->repo->create(self::TENANT_A, self::CALLER, 'a');
        $this->repo->create(self::TENANT_A, self::CALLER, 'a');

        $response = $this->authed()->unreadCount(new Request('GET', '/api/me/notifications/unread-count'));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(2, $body['unread_count']);
    }

    public function testMarkReadOwnedIs204AndForeignIs404(): void
    {
        $own = $this->repo->create(self::TENANT_A, self::CALLER, 'a');
        $foreign = $this->repo->create(self::TENANT_A, 999, 'a');

        $ok = $this->authed()->markRead(new Request('POST', "/api/me/notifications/{$own}/read"), ['id' => (string) $own]);
        self::assertSame(204, $ok->getStatusCode());
        self::assertSame(0, $this->repo->unreadCount(self::TENANT_A, self::CALLER));

        $foreignResp = $this->authed()->markRead(
            new Request('POST', "/api/me/notifications/{$foreign}/read"),
            ['id' => (string) $foreign]
        );
        self::assertSame(404, $foreignResp->getStatusCode(), 'a notification the caller does not own is 404');
        self::assertSame(1, $this->repo->unreadCount(self::TENANT_A, 999), "the foreign recipient's notification stays unread");
    }

    public function testMarkReadRejectsInvalidId(): void
    {
        $response = $this->authed()->markRead(new Request('POST', '/api/me/notifications/0/read'), ['id' => '0']);
        self::assertSame(422, $response->getStatusCode());
    }

    public function testMarkAllReadReturnsCount(): void
    {
        $this->repo->create(self::TENANT_A, self::CALLER, 'a');
        $this->repo->create(self::TENANT_A, self::CALLER, 'a');

        $response = $this->authed()->markAllRead(new Request('POST', '/api/me/notifications/read-all'));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(2, $body['marked']);
        self::assertSame(0, $this->repo->unreadCount(self::TENANT_A, self::CALLER));
    }

    public function testMarkAllReadRequiresAuth(): void
    {
        $response = $this->handler(null)->markAllRead(new Request('POST', '/api/me/notifications/read-all'));
        self::assertSame(401, $response->getStatusCode());
    }
}
