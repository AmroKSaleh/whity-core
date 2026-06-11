<?php

declare(strict_types=1);

namespace Tests\OpenAPI;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\OpenAPI\SchemaBuilder;
use Whity\OpenAPI\SchemaGenerator;

/**
 * WC-166: the OpenAPI ENGINE — component-schema registration, per-route typed
 * requestBody/response declaration with $ref, a route-level mechanism usable
 * by plugin routes through the SDK, deterministic output, and validation.
 *
 * Population of the admin resources is #167; this suite proves the machinery.
 */
final class SchemaEngineTest extends TestCase
{
    private static string $pluginsDir;

    public static function setUpBeforeClass(): void
    {
        self::$pluginsDir = sys_get_temp_dir() . '/whity_oapi_' . uniqid();
        mkdir(self::$pluginsDir . '/OapiTyped', 0755, true);
        mkdir(self::$pluginsDir . '/OapiPlain', 0755, true);

        // A plugin declaring typed request/response bodies + the component
        // schemas they reference, through the SDK route-array 'schema' key.
        file_put_contents(self::$pluginsDir . '/OapiTyped/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace OapiTyped;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'OapiTyped'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'POST',
            'path' => '/api/oapi/widgets',
            'handler' => static fn (Request $r): Response => Response::json(['ok' => true], 201),
            'requiredRole' => null,
            'schema' => [
                'summary' => 'Create a widget',
                'tags' => ['widgets'],
                'request' => 'WidgetCreate',
                'responses' => [
                    201 => 'Widget',
                    400 => ['description' => 'Validation failed'],
                ],
                'components' => [
                    'WidgetCreate' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => ['name' => ['type' => 'string']],
                    ],
                    'Widget' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);

        // A plugin with NO schema declaration: legacy default operation (BC).
        file_put_contents(self::$pluginsDir . '/OapiPlain/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace OapiPlain;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'OapiPlain'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/oapi/plain',
            'handler' => static fn (Request $r): Response => Response::json(['ok' => true]),
            'requiredRole' => 'admin',
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$pluginsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::$pluginsDir);
    }

    // ==================== SchemaBuilder: components + refs ====================

    public function testRegistersComponentSchemas(): void
    {
        $builder = new SchemaBuilder('T', '1.0.0');
        $builder->addComponentSchema('User', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]);

        $spec = $builder->build();
        $this->assertSame('object', $spec['components']['schemas']['User']['type']);
    }

    public function testIdenticalComponentRedefinitionIsIdempotent(): void
    {
        $builder = new SchemaBuilder('T', '1.0.0');
        $schema = ['type' => 'object'];
        $builder->addComponentSchema('User', $schema);
        $builder->addComponentSchema('User', $schema);

        $this->assertCount(1, $builder->build()['components']['schemas']);
    }

    public function testConflictingComponentRedefinitionThrows(): void
    {
        $builder = new SchemaBuilder('T', '1.0.0');
        $builder->addComponentSchema('User', ['type' => 'object']);

        $this->expectException(\InvalidArgumentException::class);
        $builder->addComponentSchema('User', ['type' => 'string']);
    }

    public function testRefHelperBuildsComponentReference(): void
    {
        $this->assertSame(['$ref' => '#/components/schemas/User'], SchemaBuilder::ref('User'));
    }

    // ==================== SchemaBuilder: determinism + validation ====================

    public function testBuildIsDeterministicallyOrdered(): void
    {
        $builder = new SchemaBuilder('T', '1.0.0');
        $builder->addComponentSchema('Zebra', ['type' => 'object']);
        $builder->addComponentSchema('Alpha', ['type' => 'object']);
        $builder->addPath('/api/z', 'GET', ['responses' => ['200' => ['description' => 'ok']]]);
        $builder->addPath('/api/a', 'POST', ['responses' => ['200' => ['description' => 'ok']]]);
        $builder->addPath('/api/a', 'GET', ['responses' => ['200' => ['description' => 'ok']]]);

        $spec = $builder->build();

        $this->assertSame(['/api/a', '/api/z'], array_keys($spec['paths']), 'Paths must be sorted');
        $this->assertSame(['get', 'post'], array_keys($spec['paths']['/api/a']), 'Methods must be sorted');
        $this->assertSame(['Alpha', 'Zebra'], array_keys($spec['components']['schemas']), 'Schemas must be sorted');
    }

    public function testValidateAcceptsAWellFormedSpec(): void
    {
        $builder = new SchemaBuilder('T', '1.0.0');
        $builder->addComponentSchema('User', ['type' => 'object']);
        $builder->addPath('/api/users', 'GET', [
            'responses' => ['200' => [
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => SchemaBuilder::ref('User')]],
            ]],
        ]);

        $this->assertSame([], $builder->validate(), 'A well-formed spec must produce no validation errors');
    }

    public function testValidateFlagsDanglingRefsAndMissingResponses(): void
    {
        $builder = new SchemaBuilder('T', '1.0.0');
        $builder->addPath('/api/bad', 'GET', [
            'responses' => ['200' => [
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => SchemaBuilder::ref('Ghost')]],
            ]],
        ]);
        $builder->addPath('/api/worse', 'POST', ['summary' => 'no responses at all']);

        $errors = $builder->validate();

        $this->assertNotSame([], $errors);
        $this->assertTrue(
            (bool) array_filter($errors, static fn (string $e): bool => str_contains($e, 'Ghost')),
            'A $ref to an unregistered component must be flagged'
        );
        $this->assertTrue(
            (bool) array_filter($errors, static fn (string $e): bool => str_contains($e, '/api/worse')),
            'An operation without responses must be flagged'
        );
    }

    // ==================== route declaration mechanism ====================

    public function testRouterStoresRouteSchemaDeclarations(): void
    {
        $router = new Router();
        $schema = ['summary' => 'List things', 'responses' => [200 => ['description' => 'ok']]];
        $router->register('GET', '/api/things', static fn () => null, null, null, null, $schema);

        $routes = $router->getRoutes();
        $this->assertSame($schema, $routes[0]['schema'] ?? null);
    }

    public function testPluginRouteSchemaSurvivesRegistrationIntoTheRouter(): void
    {
        [$router] = $this->loadFixtures();

        $typed = null;
        foreach ($router->getRoutes() as $route) {
            if ($route['path'] === '/api/oapi/widgets') {
                $typed = $route;
            }
        }

        $this->assertNotNull($typed, 'The typed plugin route must be registered');
        $this->assertSame('Create a widget', $typed['schema']['summary'] ?? null, "The SDK route 'schema' key must survive into the router");
    }

    // ==================== generator: typed ops, $refs, hoisting, BC ====================

    public function testGeneratorEmitsTypedRequestAndResponsesWithRefs(): void
    {
        $spec = $this->generateFromFixtures();

        $op = $spec['paths']['/api/oapi/widgets']['post'] ?? null;
        $this->assertNotNull($op, 'The typed route must be in the spec');

        $this->assertSame(
            '#/components/schemas/WidgetCreate',
            $op['requestBody']['content']['application/json']['schema']['$ref'] ?? null
        );
        $this->assertSame(
            '#/components/schemas/Widget',
            $op['responses']['201']['content']['application/json']['schema']['$ref'] ?? null
        );
        $this->assertSame('Validation failed', $op['responses']['400']['description'] ?? null);
        $this->assertSame('Create a widget', $op['summary'] ?? null);
        $this->assertSame(['widgets'], $op['tags'] ?? null);
    }

    public function testGeneratorHoistsDeclaredComponents(): void
    {
        $spec = $this->generateFromFixtures();

        $this->assertArrayHasKey('WidgetCreate', $spec['components']['schemas']);
        $this->assertArrayHasKey('Widget', $spec['components']['schemas']);
        $this->assertSame(['name'], $spec['components']['schemas']['WidgetCreate']['required']);
    }

    public function testUndeclaredRouteKeepsLegacyDefaultOperation(): void
    {
        $spec = $this->generateFromFixtures();

        $op = $spec['paths']['/api/oapi/plain']['get'] ?? null;
        $this->assertNotNull($op, 'Schema-less routes must still appear (BC)');
        $this->assertArrayHasKey('200', $op['responses']);
        $this->assertArrayHasKey('401', $op['responses']);
        $this->assertSame([['bearerAuth' => []]], $op['security'] ?? null, 'requiredRole still maps to bearerAuth security');
    }

    public function testGeneratedSpecIsDeterministicAndValid(): void
    {
        $first = json_encode($this->generateFromFixtures(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $second = json_encode($this->generateFromFixtures(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertSame($first, $second, 'Regeneration must be byte-identical');

        $builder = new SchemaBuilder('T', '1.0.0');
        $this->assertSame([], $this->generateErrors(), 'The generated spec must pass validation');
        unset($builder);
    }

    // ==================== helpers ====================

    /**
     * @return array{0: Router, 1: PluginLoader}
     */
    private function loadFixtures(): array
    {
        $router = new Router();
        $loader = new PluginLoader(self::$pluginsDir, $router, null, new HookManager());
        $loader->load();

        return [$router, $loader];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateFromFixtures(): array
    {
        [$router, $loader] = $this->loadFixtures();
        $generator = new SchemaGenerator('Whity Core API', '1.0.0', $loader, $router);

        return $generator->generate();
    }

    /**
     * @return list<string>
     */
    private function generateErrors(): array
    {
        [$router, $loader] = $this->loadFixtures();
        $generator = new SchemaGenerator('Whity Core API', '1.0.0', $loader, $router);

        return $generator->generateAndValidate()['errors'];
    }
}
