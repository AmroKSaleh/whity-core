<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\ManifestValidator;

/**
 * WHIT-758: the whole-manifest check for `plugin.json`.
 *
 * The refusals here are the ones a registry can't make on its own, because
 * they are about how the parts of a manifest point at each other, plus the
 * format rules the design register sets for every visible string.
 */
final class ManifestValidatorTest extends TestCase
{
    /**
     * Example Bakery's cake orders: every section this format accepts.
     *
     * @return array<string, mixed>
     */
    public static function bakeryManifest(): array
    {
        return [
            'manifest' => 'whity.plugin/1',
            'plugin' => [
                'name' => 'CakeOrders',
                'version' => '1.0.0',
                'label' => ['en' => 'Cake orders', 'ar' => 'طلبات الكيك'],
                'description' => ['en' => 'Custom cake orders for Example Bakery.', 'ar' => 'طلبات الكيك الخاصة لمخبز المثال.'],
            ],
            'requires' => ['sdk' => '^1.47', 'core' => '^0.2'],
            'permissions' => [
                ['slug' => 'cake_orders:read', 'label' => ['en' => 'See cake orders', 'ar' => 'عرض طلبات الكيك']],
                ['slug' => 'cake_orders:manage', 'label' => ['en' => 'Manage cake orders', 'ar' => 'إدارة طلبات الكيك']],
            ],
            'roles' => [
                'baker' => [
                    'label' => ['en' => 'Baker', 'ar' => 'خباز'],
                    'permissions' => ['cake_orders:read'],
                ],
                'shop_manager' => [
                    'label' => ['en' => 'Shop manager', 'ar' => 'مدير المحل'],
                    'parent' => 'baker',
                    'permissions' => ['cake_orders:read', 'cake_orders:manage'],
                ],
            ],
            'screens' => [[
                'id' => 'cake-orders-today',
                'label' => ['en' => 'Today\'s orders', 'ar' => 'طلبات اليوم'],
                'permission' => 'cake_orders:read',
                'group' => 'plugins',
                'order' => 10,
                'icon' => 'cake',
                'blocks' => [['type' => 'heading', 'text' => 'Today', 'level' => 2]],
            ]],
            'tables' => ['cake_orders' => 'tenant', 'cake_order_lines' => 'tenant'],
            'dataTypes' => [
                'order' => [
                    'table' => 'cake_orders',
                    'label' => ['en' => 'Cake order', 'ar' => 'طلب كيك'],
                    'cascade_delete' => [['table' => 'cake_order_lines', 'column' => 'order_id', 'label' => 'lines']],
                    'permissions' => ['read' => 'cake_orders:read', 'delete' => 'cake_orders:manage'],
                ],
            ],
            'limits' => [[
                'key' => 'cakeorders.orders.max',
                'type' => 'int',
                'default' => -1,
                'description' => ['en' => 'Open cake orders a workspace may hold.', 'ar' => 'عدد طلبات الكيك المفتوحة المسموح بها.'],
            ]],
        ];
    }

    public function testTheBakeryManifestIsValid(): void
    {
        $result = ManifestValidator::validate(self::bakeryManifest());

        self::assertSame([], $result['errors']);
        self::assertTrue($result['ok']);
    }

    public function testAMinimalManifestNeedsOnlyFormatAndPlugin(): void
    {
        $manifest = self::bakeryManifest();
        $result = ManifestValidator::validate(['manifest' => $manifest['manifest'], 'plugin' => $manifest['plugin']]);

        self::assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function refusals(): iterable
    {
        yield 'wrong format' => [
            static fn (array $m): array => ['manifest' => 'whity.plugin/2'] + $m,
            'manifest: must be "whity.plugin/1"',
        ];
        yield 'unknown top-level section' => [
            static function (array $m): array {
                $m['automations'] = [];
                return $m;
            },
            'automations: unknown key',
        ];
        yield 'misspelt nested key' => [
            static function (array $m): array {
                $m['screens'][0]['blcks'] = [];
                return $m;
            },
            'screens[0].blcks: unknown key',
        ];
        yield 'missing Arabic label' => [
            static function (array $m): array {
                unset($m['permissions'][0]['label']['ar']);
                return $m;
            },
            'permissions[0].label.ar: required',
        ];
        yield 'bad version' => [
            static function (array $m): array {
                $m['plugin']['version'] = '7';
                return $m;
            },
            'plugin.version: required semantic version',
        ];
        yield 'duplicate permission' => [
            static function (array $m): array {
                $m['permissions'][1]['slug'] = 'cake_orders:read';
                return $m;
            },
            '"cake_orders:read" is declared twice',
        ];
        yield 'role grants an undeclared permission' => [
            static function (array $m): array {
                $m['roles']['baker']['permissions'][] = 'users:manage';
                return $m;
            },
            'roles.baker.permissions[1]: unknown permission "users:manage"',
        ];
        yield 'screen gated by an undeclared permission' => [
            static function (array $m): array {
                $m['screens'][0]['permission'] = 'cake_orders:view';
                return $m;
            },
            'screens[0].permission: required, and must be a permission this manifest declares',
        ];
        yield 'screen with a block the contract refuses' => [
            static function (array $m): array {
                $m['screens'][0]['blocks'] = [['type' => 'marquee']];
                return $m;
            },
            'screens[0].blocks: ',
        ];
        yield 'duplicate screen id' => [
            static function (array $m): array {
                $m['screens'][] = $m['screens'][0];
                return $m;
            },
            'screens[1].id: "cake-orders-today" is used twice',
        ];
        yield 'data type over an undeclared table' => [
            static function (array $m): array {
                $m['dataTypes']['order']['table'] = 'orders';
                return $m;
            },
            'dataTypes.order.table: must be a table this manifest declares',
        ];
        yield 'data type over a global table' => [
            static function (array $m): array {
                $m['tables']['cake_orders'] = 'global';
                return $m;
            },
            'dataTypes.order.table: a data type needs a tenant-scoped table',
        ];
        yield 'cascade into an undeclared table' => [
            static function (array $m): array {
                $m['dataTypes']['order']['cascade_delete'][0]['table'] = 'users';
                return $m;
            },
            'dataTypes.order.cascade_delete[0].table: must be a table this manifest declares',
        ];
        yield 'data type permission not declared' => [
            static function (array $m): array {
                $m['dataTypes']['order']['permissions']['trash'] = 'documents:manage';
                return $m;
            },
            'dataTypes.order.permissions.trash: must be a permission this manifest declares',
        ];
        yield 'limit outside the plugin namespace' => [
            static function (array $m): array {
                $m['limits'][0]['key'] = 'storage.max';
                return $m;
            },
            'limits[0].key: must start with "cakeorders."',
        ];
        yield 'int limit with a text default' => [
            static function (array $m): array {
                $m['limits'][0]['default'] = '-1';
                return $m;
            },
            'limits[0].default:',
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('refusals')]
    public function testRefusesWithAPathedReason(callable $mutate, string $expected): void
    {
        $result = ManifestValidator::validate($mutate(self::bakeryManifest()));

        self::assertFalse($result['ok']);
        self::assertNotEmpty(
            array_filter($result['errors'], static fn (string $e): bool => str_contains($e, $expected)),
            "Expected an error containing '{$expected}', got: " . implode(' | ', $result['errors']),
        );
    }

    public function testReportsEveryErrorNotJustTheFirst(): void
    {
        $manifest = self::bakeryManifest();
        $manifest['plugin']['version'] = 'x';
        $manifest['roles']['baker']['permissions'] = ['nope:nope'];
        $manifest['screens'][0]['permission'] = 'nope:nope';

        $result = ManifestValidator::validate($manifest);

        self::assertGreaterThanOrEqual(3, count($result['errors']));
    }

    /**
     * The published JSON Schema and the validator must agree on which sections
     * exist; the schema is what editors and the builder read.
     */
    public function testThePublishedSchemaNamesTheSameSections(): void
    {
        $path = dirname(__DIR__, 3) . '/sdk/resources/plugin-manifest.schema.json';
        $schema = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        self::assertIsArray($schema);
        $keys = array_keys($schema['properties']);
        sort($keys);
        $expected = ManifestValidator::TOP_LEVEL_KEYS;
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertSame(ManifestValidator::FORMAT, $schema['properties']['manifest']['const']);
    }
}
