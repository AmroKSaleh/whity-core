<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\NotificationTemplateRepository;
use Whity\Core\Notification\NotificationTemplateSeeder;

/**
 * Real-engine tests for {@see NotificationTemplateSeeder}: it seeds the global
 * (tenant 0) default core set idempotently, and the seeded rows resolve for any
 * tenant.
 */
final class NotificationTemplateSeederTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (0,'system','system'),(1,'a','a') ON CONFLICT (id) DO NOTHING");
    }

    public function testSeedsGlobalDefaultsIdempotently(): void
    {
        $expected = count(NotificationTemplateSeeder::defaults());

        self::assertSame($expected, NotificationTemplateSeeder::seed($this->pdo), 'first seed inserts the whole set');
        self::assertSame(0, NotificationTemplateSeeder::seed($this->pdo), 're-seeding inserts nothing (idempotent)');

        $repo = new NotificationTemplateRepository($this->pdo);
        self::assertCount($expected, $repo->listForTenant(NotificationTemplateRepository::GLOBAL_TENANT));
    }

    public function testSeededDefaultsResolveForAnyTenant(): void
    {
        NotificationTemplateSeeder::seed($this->pdo);
        $repo = new NotificationTemplateRepository($this->pdo);

        $t = $repo->resolve(1, 'password.reset', 'email', null);
        self::assertNotNull($t, 'a regular tenant inherits the global default');
        self::assertSame(NotificationTemplateRepository::GLOBAL_TENANT, $t['tenant_id']);
        self::assertNotSame('', $t['subject']);
    }
}
