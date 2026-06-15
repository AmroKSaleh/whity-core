<?php

declare(strict_types=1);

namespace Tests\OpenAPI;

use PHPUnit\Framework\TestCase;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * CI gate: every live core route in public/index.php must either have a
 * CoreApiSchemas declaration or appear in KNOWN_UNDOCUMENTED with a comment
 * explaining why. Add a declaration to CoreApiSchemas::routes() to graduate
 * a route out of the opt-out list.
 *
 * This test also verifies KNOWN_UNDOCUMENTED has no phantom entries (routes
 * that were removed from index.php without removing the opt-out).
 */
final class RouteCatalogueCompletenessTest extends TestCase
{
    /**
     * Routes that are live but not yet declared in CoreApiSchemas.
     *
     * Each entry is "METHOD /normalized/path" where {id:\d+} constraints are
     * stripped to {id}. Remove an entry here when the corresponding route is
     * added to CoreApiSchemas::routes().
     *
     * @var list<string>
     */
    private const KNOWN_UNDOCUMENTED = [
        // Auth surface — to be declared once the auth/2FA schema task lands
        'POST /api/login',
        'POST /api/login/2fa',
        'GET /api/me',
        'PATCH /api/me',
        'POST /api/auth/refresh',
        'POST /api/auth/logout',
        'POST /api/auth/2fa/setup',
        'POST /api/auth/2fa/confirm',
        'POST /api/auth/2fa/disable',
        'POST /api/auth/2fa/regenerate-codes',
        'GET /api/auth/2fa/status',
        // Family-Relations surface — to be declared in task f07c870b
        'GET /api/relationship-types',
        'GET /api/persons',
        'POST /api/persons',
        'GET /api/persons/{id}',
        'PATCH /api/persons/{id}',
        'DELETE /api/persons/{id}',
        'GET /api/persons/{id}/relations',
        'GET /api/relations',
        'GET /api/users/{id}/relations',
        'POST /api/relations',
        'DELETE /api/relations/{id}',
    ];

    public function testEveryLiveRouteIsDocumentedOrOptedOut(): void
    {
        $liveRoutes = $this->extractLiveRoutes();
        $declaredRoutes = $this->extractDeclaredRoutes();

        $undocumented = array_values(array_diff($liveRoutes, $declaredRoutes, self::KNOWN_UNDOCUMENTED));
        $this->assertSame(
            [],
            $undocumented,
            "Routes are live in index.php but have no CoreApiSchemas declaration "
            . "and are not in KNOWN_UNDOCUMENTED:\n"
            . implode("\n", $undocumented)
            . "\n\nDeclare them in CoreApiSchemas::routes() or add to KNOWN_UNDOCUMENTED with a comment."
        );
    }

    public function testKnownUndocumentedHasNoPhantomEntries(): void
    {
        $liveRoutes = $this->extractLiveRoutes();

        $phantom = array_values(array_diff(self::KNOWN_UNDOCUMENTED, $liveRoutes));
        $this->assertSame(
            [],
            $phantom,
            "KNOWN_UNDOCUMENTED contains routes that no longer exist in index.php:\n"
            . implode("\n", $phantom)
            . "\n\nRemove them from KNOWN_UNDOCUMENTED."
        );
    }

    /**
     * @return list<string> "METHOD /normalized/path" for every $router->register() in index.php
     */
    private function extractLiveRoutes(): array
    {
        $indexPhp = file_get_contents(__DIR__ . '/../../public/index.php');
        $this->assertIsString($indexPhp, 'Could not read public/index.php');

        preg_match_all(
            '/\$router->register\s*\(\s*\'(GET|POST|PATCH|DELETE|PUT)\'\s*,\s*\'([^\']+)\'/',
            $indexPhp,
            $matches
        );

        $routes = [];
        foreach ($matches[1] as $i => $method) {
            $routes[] = $method . ' ' . $this->normalizePath($matches[2][$i]);
        }

        sort($routes);
        return array_unique($routes);
    }

    /**
     * @return list<string> "METHOD /normalized/path" for every route in CoreApiSchemas::routes()
     */
    private function extractDeclaredRoutes(): array
    {
        $routes = [];
        foreach (CoreApiSchemas::routes() as $route) {
            $routes[] = $route['method'] . ' ' . $this->normalizePath($route['path']);
        }

        sort($routes);
        return array_unique($routes);
    }

    /**
     * Strip inline regex constraints ({id:\d+} → {id}) so live-router and
     * catalogue paths can be compared by structure alone.
     */
    private function normalizePath(string $path): string
    {
        return (string) preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $path);
    }
}
