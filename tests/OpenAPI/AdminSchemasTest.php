<?php

declare(strict_types=1);

namespace Tests\OpenAPI;

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
 */
final class AdminSchemasTest extends TestCase
{
    private const SPEC_PATH = __DIR__ . '/../../public/openapi.json';

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
     * Regenerate exactly as `generate:openapi` does (plugins + core catalogue).
     *
     * @return array{spec: array<string, mixed>, errors: list<string>}
     */
    private static function regenerate(): array
    {
        $router = new Router();
        $loader = new PluginLoader(dirname(__DIR__, 2) . '/plugins', $router, null, new HookManager());
        $loader->load();
        CoreApiSchemas::registerRoutes($router);

        return (new SchemaGenerator('Whity Core API', '1.0.0', $loader, $router))->generateAndValidate();
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
            ['/api/users', 'get', 'responses', '200', 'UserListResponse'],
            ['/api/users', 'post', 'requestBody', null, 'UserCreateRequest'],
            ['/api/users/{id}', 'patch', 'requestBody', null, 'UserUpdateRequest'],
            ['/api/roles', 'get', 'responses', '200', 'RoleListResponse'],
            ['/api/roles/{id}', 'get', 'responses', '200', 'RoleDetailResponse'],
            ['/api/tenants', 'post', 'requestBody', null, 'TenantCreateRequest'],
            ['/api/tenants', 'get', 'responses', '200', 'TenantListResponse'],
            ['/api/ous', 'get', 'responses', '200', 'OuListResponse'],
            ['/api/ous/{id}', 'get', 'responses', '200', 'OuDetailResponse'],
            ['/api/delegations', 'get', 'responses', '200', 'DelegationListResponse'],
            ['/api/delegations', 'post', 'requestBody', null, 'DelegationCreateRequest'],
            ['/api/audit-logs', 'get', 'responses', '200', 'AuditLogListResponse'],
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

        $op = $paths['/api/users/{id}']['patch'] ?? null;
        $this->assertNotNull($op);
        $byName = array_column($op['parameters'] ?? [], null, 'name');
        $this->assertSame('integer', $byName['id']['schema']['type'] ?? null, '{id:\d+} must declare an integer path param');
    }

    public function testPermissionGatedEndpointsCarrySecurity(): void
    {
        $paths = self::committedSpec()['paths'] ?? [];

        $this->assertSame(
            [['bearerAuth' => []]],
            $paths['/api/delegations']['get']['security'] ?? null,
            'Permission-gated routes (requiredPermission, no role) must still declare security'
        );
        $this->assertSame(
            [['bearerAuth' => []]],
            $paths['/api/audit-logs']['get']['security'] ?? null
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
