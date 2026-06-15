<?php

declare(strict_types=1);

namespace Tests\OpenAPI;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\OpenAPI\SchemaGenerator;

/**
 * WC-167: the admin resources (users, roles, tenants, organizational units,
 * delegations, audit logs) publish typed request/response shapes through the
 * WC-166 engine, and the committed public/openapi.json is the SNAPSHOT —
 * regenerating must reproduce it byte-for-byte (modulo line endings on
 * autocrlf checkouts), so any route/schema change that skips
 * `generate:openapi` fails loudly here.
 *
 * Runs in SEPARATE PROCESSES: regeneration loads the reference plugins from a
 * temp copy (see $referencePluginsDir), and other suites load the same plugin
 * classes from their real paths — sharing one process would fatal on
 * redeclare. Isolation keeps both worlds clean.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminSchemasTest extends TestCase
{
    private const SPEC_PATH = __DIR__ . '/../../public/openapi.json';

    /**
     * Temp dir holding ONLY the committed reference plugins. The committed
     * spec is the CORE BASELINE (core routes + reference plugins): a real
     * plugin deploy-copied into plugins/ on a dev machine (gitignored, see
     * plugins/.gitignore) must not fail this suite — deployments regenerate
     * their own spec as a deploy step (docs/wiki/Plugin-Distribution.md) and
     * that file is never committed.
     */
    private static string $referencePluginsDir;

    public static function setUpBeforeClass(): void
    {
        $source = dirname(__DIR__, 2) . '/plugins';
        self::$referencePluginsDir = sys_get_temp_dir() . '/whity_reference_plugins_' . uniqid();
        mkdir(self::$referencePluginsDir, 0755, true);

        copy($source . '/ExamplePlugin.php', self::$referencePluginsDir . '/ExamplePlugin.php');
        self::copyDirectory($source . '/HelloWorld', self::$referencePluginsDir . '/HelloWorld');
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDirectory(self::$referencePluginsDir);
    }

    /**
     * @return array<string, mixed>
     */
    private static function committedSpec(): array
    {
        $decoded = json_decode((string) file_get_contents(self::SPEC_PATH), true);
        self::assertIsArray($decoded, 'public/openapi.json must be valid JSON');

        return $decoded;
    }

    /**
     * Regenerate exactly as `generate:openapi` does (core catalogue first,
     * then plugins — the runtime first-registration-wins ordering, WC-169),
     * but over the REFERENCE plugins only (see $referencePluginsDir).
     *
     * @return array{spec: array<string, mixed>, errors: list<string>}
     */
    private static function regenerate(): array
    {
        // WC-206: use '/v1' prefix to produce '/api/v1/' paths matching the
        // live application routing and the committed public/openapi.json.
        $router = new Router('/v1');
        $loader = new PluginLoader(self::$referencePluginsDir, $router, null, new HookManager());
        CoreApiSchemas::registerRoutes($router);
        $loader->load();

        return (new SchemaGenerator('Whity Core API', \Whity\Core\CoreVersion::VERSION, $loader, $router))->generateAndValidate();
    }

    private static function copyDirectory(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $to . '/' . $items->getSubPathname();
            $item->isDir() ? mkdir($target, 0755, true) : copy($item->getPathname(), $target);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ==================== populated components ====================

    public function testComponentsSchemasArePopulatedForAllSixResources(): void
    {
        $schemas = self::committedSpec()['components']['schemas'] ?? [];

        $this->assertNotSame([], $schemas, 'components.schemas must no longer be empty');
        foreach (['User', 'Role', 'Tenant', 'OrganizationalUnit', 'Delegation', 'AuditLogEntry', 'Error'] as $required) {
            $this->assertArrayHasKey($required, $schemas, "Component schema '{$required}' must be published");
        }

        // Spot-check shapes mirror runtime: camelCase user fields, audit pagination.
        $this->assertSame('integer', $schemas['User']['properties']['tenantId']['type'] ?? null);
        $this->assertSame('string', $schemas['Delegation']['properties']['permission']['type'] ?? null);
        $this->assertArrayHasKey('Pagination', $schemas);
    }

    /**
     * Every resource's representative endpoints carry $ref'd request/response
     * bodies (the contract rule: each resource has shapes WITH $ref).
     */
    public function testEndpointsRefTheirShapes(): void
    {
        $paths = self::committedSpec()['paths'] ?? [];

        $cases = [
            ['/api/v1/users', 'get', 'responses', '200', 'UserListResponse'],
            ['/api/v1/users', 'post', 'requestBody', null, 'UserCreateRequest'],
            ['/api/v1/users/{id}', 'patch', 'requestBody', null, 'UserUpdateRequest'],
            ['/api/v1/roles', 'get', 'responses', '200', 'RoleListResponse'],
            ['/api/v1/roles/{id}', 'get', 'responses', '200', 'RoleDetailResponse'],
            ['/api/v1/tenants', 'post', 'requestBody', null, 'TenantCreateRequest'],
            ['/api/v1/tenants', 'get', 'responses', '200', 'TenantListResponse'],
            ['/api/v1/ous', 'get', 'responses', '200', 'OuListResponse'],
            ['/api/v1/ous/{id}', 'get', 'responses', '200', 'OuDetailResponse'],
            ['/api/v1/permissions', 'get', 'responses', '200', 'PermissionCatalogueResponse'],
            ['/api/v1/delegations', 'get', 'responses', '200', 'DelegationListResponse'],
            ['/api/v1/delegations', 'post', 'requestBody', null, 'DelegationCreateRequest'],
            ['/api/v1/audit-logs', 'get', 'responses', '200', 'AuditLogListResponse'],
            ['/api/v1/frontend/features', 'get', 'responses', '200', 'FrontendFeatureListResponse'],
        ];

        foreach ($cases as [$path, $method, $kind, $status, $component]) {
            $op = $paths[$path][$method] ?? null;
            $this->assertNotNull($op, "{$method} {$path} must be in the spec");

            $schema = $kind === 'requestBody'
                ? ($op['requestBody']['content']['application/json']['schema'] ?? null)
                : ($op['responses'][$status]['content']['application/json']['schema'] ?? null);

            $this->assertSame(
                '#/components/schemas/' . $component,
                $schema['$ref'] ?? null,
                "{$method} {$path} {$kind}" . ($status !== null ? " {$status}" : '') . " must \$ref {$component}"
            );
        }
    }

    public function testParameterizedPathsDeclareTheirParameters(): void
    {
        $paths = self::committedSpec()['paths'] ?? [];

        $op = $paths['/api/v1/users/{id}']['patch'] ?? null;
        $this->assertNotNull($op);
        $byName = array_column($op['parameters'] ?? [], null, 'name');
        $this->assertSame('integer', $byName['id']['schema']['type'] ?? null, '{id:\d+} must declare an integer path param');
    }

    public function testPermissionGatedEndpointsCarrySecurity(): void
    {
        $paths = self::committedSpec()['paths'] ?? [];

        $this->assertSame(
            [['bearerAuth' => []]],
            $paths['/api/v1/delegations']['get']['security'] ?? null,
            'Permission-gated routes (requiredPermission, no role) must still declare security'
        );
        $this->assertSame(
            [['bearerAuth' => []]],
            $paths['/api/v1/audit-logs']['get']['security'] ?? null
        );
    }

    // ==================== snapshot + validity ====================

    /**
     * THE SNAPSHOT GUARD: the committed spec must equal a fresh regeneration
     * byte-for-byte. Changing routes/schemas without running generate:openapi
     * fails here.
     */
    public function testCommittedSpecIsTheCurrentRegenerationSnapshot(): void
    {
        ['spec' => $spec, 'errors' => $errors] = self::regenerate();

        $this->assertSame([], $errors, 'The regenerated spec must be valid');
        $this->assertSame(
            str_replace("\r\n", "\n", (string) file_get_contents(self::SPEC_PATH)),
            SchemaGenerator::encode($spec),
            'public/openapi.json is stale: run `php public/index.php generate:openapi` and commit the result'
        );
    }

    public function testRegenerationIsDeterministic(): void
    {
        $first = SchemaGenerator::encode(self::regenerate()['spec']);
        $second = SchemaGenerator::encode(self::regenerate()['spec']);

        $this->assertSame($first, $second);
    }

    /**
     * Drift alarm: every catalogue route must exist VERBATIM (method + path,
     * constraint syntax included) among the live registrations in
     * public/index.php — the catalogue documents real routes, not aspirations.
     */
    public function testCatalogueRoutesMatchTheLiveRegistrations(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        preg_match_all(
            "/\\\$router->register\\(\\s*'(GET|POST|PATCH|PUT|DELETE)',\\s*'([^']+)'/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $live = [];
        foreach ($matches as $match) {
            $live[$match[1] . ' ' . $match[2]] = true;
        }
        $this->assertNotSame([], $live, 'The live registrations must be extractable');

        foreach (CoreApiSchemas::routes() as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            $this->assertArrayHasKey(
                $key,
                $live,
                "Catalogue route '{$key}' is not registered in public/index.php — the published contract would lie"
            );
        }
    }

    public function testCommittedSpecHasNoDanglingRefs(): void
    {
        $spec = self::committedSpec();
        $schemas = $spec['components']['schemas'] ?? [];

        $refs = [];
        $collect = static function (array $node) use (&$collect, &$refs): void {
            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $refs[] = $value;
                } elseif (is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($spec);

        $this->assertNotSame([], $refs, 'The spec must actually use $refs');
        foreach ($refs as $ref) {
            $name = substr($ref, strlen('#/components/schemas/'));
            $this->assertArrayHasKey($name, $schemas, "Committed spec has a dangling ref: {$ref}");
        }
    }
}
