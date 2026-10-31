<?php

declare(strict_types=1);

namespace Tests\Core\Document\Fields;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\RecordTypeValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Fields\TenantRecordFieldProvider;
use Whity\Core\Document\Fields\TodayFieldProvider;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * A workspace plugin's record type as a document record type (WHIT-742):
 * supplied per workspace, read with the record type's own permission, and
 * printed as a reader sees it. Runs on SQLite by default and on PostgreSQL
 * when PHPUNIT_PG_DSN is set.
 */
final class TenantRecordFieldProviderRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const READER = 10;

    private PDO $pdo;
    private TenantRecordRepository $records;
    private DocumentFieldRegistry $registry;
    private string $language = 'en';

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'Rana Haddad', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'Someone Else', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1, 'active', datetime('now')),
                (2000, 20, 2, 1, 'active', datetime('now'))
        ");
        $this->records = new TenantRecordRepository($this->pdo);

        $manifest = RecordTypeValidatorTest::manifest();
        $manifest['records']['order']['fields']['taken_by'] = ['type' => 'person', 'label' => ['en' => 'Taken by', 'ar' => 'استلمه']];
        $manifest['records']['order']['fields']['flavours'] = ['type' => 'multi_choice', 'label' => ['en' => 'Flavours', 'ar' => 'النكهات'], 'options' => [
            ['value' => 'vanilla', 'label' => ['en' => 'Vanilla', 'ar' => 'فانيلا']],
            ['value' => 'cocoa', 'label' => ['en' => 'Cocoa', 'ar' => 'كاكاو']],
        ]];
        $plugin = PluginManifest::fromArray($manifest);
        $live = [self::TENANT => [$plugin]];

        $this->registry = new DocumentFieldRegistry();
        $this->registry->add(new TodayFieldProvider());
        $this->registry->addTenantSource(fn (int $tenantId): array => TenantRecordFieldProvider::forManifests(
            $live[$tenantId] ?? [],
            $this->records,
            null,
            fn (): string => $this->language,
            fn (int $tenant, int $profileId): ?string => $this->records->memberName($tenant, $profileId),
        ));
    }

    public function testTheRecordTypeExistsOnlyWhereThePluginIsOn(): void
    {
        self::assertTrue($this->registry->has('CakeOrders.order', self::TENANT));
        self::assertFalse($this->registry->has('CakeOrders.order', self::OTHER_TENANT));
        self::assertFalse($this->registry->has('CakeOrders.order'), 'not known without a workspace');
        self::assertSame([['key' => 'CakeOrders.order', 'label' => 'Cake order']], $this->registry->recordTypes(self::TENANT));
        self::assertSame([], $this->registry->recordTypes(self::OTHER_TENANT));
    }

    public function testItsFieldsAreRecordPathsWithTheirLabelsAndRequiredFlags(): void
    {
        $paths = $this->registry->paths(self::TENANT, 'CakeOrders.order');
        self::assertArrayHasKey('record.customer', $paths);
        self::assertArrayHasKey('record.price', $paths);
        self::assertArrayHasKey('today', $paths, 'and the fields every template has');

        self::assertSame(['record.customer' => true, 'record.pickup_on' => true], $this->registry->requiredPaths(self::TENANT, 'CakeOrders.order'));

        $this->language = 'ar';
        $this->registry->resetRequest();
        $groups = $this->registry->groups(self::TENANT, 'CakeOrders.order');
        self::assertSame('طلب كيك', $groups[0]['group']);
        self::assertSame('الزبون', $groups[0]['items'][0]['label']);
    }

    public function testValuesPrintAsAReaderSeesThem(): void
    {
        $parent = $this->records->create(self::TENANT, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Clinic', 'pickup_on' => '2026-11-01'], self::READER);
        $id = $this->records->create(self::TENANT, 'CakeOrders', 'order', 'draft', [
            'customer' => 'Example Bakery',
            'cake_type' => 'wedding',
            'weight_kg' => 2.5,
            'price' => 18000,
            'pickup_on' => '2026-11-05',
            'paid' => true,
            'parent' => $parent,
            'taken_by' => self::READER,
            'flavours' => ['vanilla', 'cocoa'],
        ], self::READER);

        $resolved = $this->registry->resolve(self::TENANT, self::READER, static fn (string $p): bool => $p === 'cakeorders:read', 'CakeOrders.order', (string) $id);

        self::assertNotNull($resolved);
        self::assertSame('#' . $id . ' · Example Bakery', $resolved['label']);
        $v = $resolved['values'];
        self::assertSame('Example Bakery', $v['record.customer']);
        self::assertSame('Wedding', $v['record.cake_type']);
        self::assertSame('2.5', $v['record.weight_kg']);
        self::assertSame('18.000 JOD', $v['record.price'], 'JOD has three decimals');
        self::assertSame('5 November 2026', $v['record.pickup_on']);
        self::assertSame('Yes', $v['record.paid']);
        self::assertSame('Example Clinic', $v['record.parent']);
        self::assertSame('Rana Haddad', $v['record.taken_by']);
        self::assertSame('Vanilla, Cocoa', $v['record.flavours']);
        self::assertArrayHasKey('today', $v);
    }

    public function testAnEmptyFieldIsNull(): void
    {
        $id = $this->records->create(self::TENANT, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Bakery', 'pickup_on' => '2026-11-05'], self::READER);

        $resolved = $this->registry->resolve(self::TENANT, self::READER, static fn (): bool => true, 'CakeOrders.order', (string) $id);

        self::assertNotNull($resolved);
        self::assertNull($resolved['values']['record.price']);
        self::assertNull($resolved['values']['record.parent']);
    }

    public function testWithoutTheReadPermissionARecordIsNotThere(): void
    {
        $id = $this->records->create(self::TENANT, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Bakery', 'pickup_on' => '2026-11-05'], self::READER);
        $none = static fn (): bool => false;

        self::assertNull($this->registry->resolve(self::TENANT, self::READER, $none, 'CakeOrders.order', (string) $id));
        self::assertSame([], $this->registry->records(self::TENANT, self::READER, $none, 'CakeOrders.order', '', 10));
    }

    public function testAnotherWorkspacesRecordIsNotFound(): void
    {
        $theirs = $this->records->create(self::OTHER_TENANT, 'CakeOrders', 'order', 'draft', ['customer' => 'Theirs', 'pickup_on' => '2026-11-05'], 20);

        self::assertNull($this->registry->resolve(self::TENANT, self::READER, static fn (): bool => true, 'CakeOrders.order', (string) $theirs));
    }

    public function testAPersonOutsideTheWorkspaceHasNoName(): void
    {
        self::assertSame('Rana Haddad', $this->records->memberName(self::TENANT, 10));
        self::assertNull($this->records->memberName(self::TENANT, 20), 'a member of another workspace only');
    }

    public function testThePickerSearchesByTitle(): void
    {
        $this->records->create(self::TENANT, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Bakery', 'pickup_on' => '2026-11-05'], self::READER);
        $this->records->create(self::TENANT, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Clinic', 'pickup_on' => '2026-11-05'], self::READER);

        $found = $this->registry->records(self::TENANT, self::READER, static fn (): bool => true, 'CakeOrders.order', 'clinic', 10);

        self::assertCount(1, $found);
        self::assertStringEndsWith('Example Clinic', $found[0]['label']);
    }
}
