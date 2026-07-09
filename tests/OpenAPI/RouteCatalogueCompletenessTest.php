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
        // WC-235: public self-service registration — OpenAPI schema to follow in
        // a dedicated documentation task (mirrors the auth-routes rollout).
        'POST /api/register',
        // WC-235: public email verification (request a link + confirm a token) —
        // OpenAPI schema to follow in the same documentation task as /api/register.
        'POST /api/email/request-verification',
        'POST /api/email/verify',
        // WC-235: pending-registration review (admin-approval activation) —
        // system-tenant-only; OpenAPI schema to follow in the same documentation
        // task as /api/register.
        'GET /api/registrations/pending',
        'POST /api/registrations/{id}/approve',
        'POST /api/registrations/{id}/reject',
        // WC-b-device-tokens: device (native-client) enrollment + credential
        // exchange; OpenAPI schema to follow in a dedicated documentation task.
        'POST /api/devices',
        'GET /api/devices',
        'DELETE /api/devices/{id}',
        'POST /api/devices/token',
        // WC-b-logout-others: sign out of all other sessions/devices; OpenAPI
        // schema to follow in the same documentation task.
        'POST /api/me/logout-others',
        // WC-f-sessions-table: interactive session list + per-session / all-others
        // revoke; OpenAPI schema to follow in a dedicated documentation task.
        'GET /api/me/sessions',
        'DELETE /api/me/sessions/{id}',
        'DELETE /api/me/sessions',
        // WC-388a61e3: auth + 2FA routes are now declared in CoreApiSchemas::authRoutes().
        // WC-206: unversioned infrastructure probes (registerUnversioned).
        // Kept undocumented for now — schema to be added in a follow-up task.
        'GET /api/version',
        // WC-9b87: tenant email-domain policy admin endpoints — OpenAPI schema
        // declarations to follow in a separate documentation task.
        'DELETE /api/email-domains/{id}',
        'GET /api/email-domains',
        'POST /api/email-domains',
        // WC-e6287: per-tenant identity-provider (SSO/OIDC) admin endpoints —
        // OpenAPI schema to follow in a dedicated documentation task.
        'GET /api/identity-providers',
        'POST /api/identity-providers',
        'PATCH /api/identity-providers/{id}',
        'DELETE /api/identity-providers/{id}',
        // WC-ae16: public federated sign-in (OIDC) — browser redirect + callback;
        // OpenAPI schema not applicable (302 redirect flow, not a JSON API).
        'GET /api/auth/sso/{provider}/start',
        'GET /api/auth/sso/{provider}/callback',
        // WC-f3b17bd2: authenticated connected-accounts management — OpenAPI schema
        // to follow in a dedicated documentation task.
        'GET /api/me/identities',
        'DELETE /api/me/identities/{id}',
        // WC-d279a9b3: MCP Streamable-HTTP endpoints — OpenAPI schema not
        // applicable (MCP uses its own JSON-RPC discovery surface, not OpenAPI).
        'GET /mcp',
        'POST /mcp',
        // WC-2686308f: MCP token management — schema to follow in documentation task.
        'DELETE /api/mcp/tokens/{jti}',
        'GET /api/mcp/tokens',
        'POST /api/mcp/tokens',
        // WC-0208ce4d: MCP admin endpoints — OpenAPI schema to follow once
        // the generate:openapi snapshot is regenerated in a dedicated task.
        'DELETE /api/admin/mcp/tokens/{jti}',
        'GET /api/admin/mcp/tokens',
        'GET /api/admin/mcp/tools',
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
     * @return list<string> "METHOD /normalized/path" for every $router->register() and
     * $router->registerUnversioned() in index.php
     */
    private function extractLiveRoutes(): array
    {
        $indexPhp = file_get_contents(__DIR__ . '/../../public/index.php');
        $this->assertIsString($indexPhp, 'Could not read public/index.php');

        // Capture both register() and registerUnversioned() — paths are as
        // written in the source (no version prefix applied by this extractor).
        preg_match_all(
            '/\$router->(?:register|registerUnversioned)\s*\(\s*\'(GET|POST|PATCH|DELETE|PUT)\'\s*,\s*\'([^\']+)\'/',
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
