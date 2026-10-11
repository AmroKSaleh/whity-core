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
 * This test also keeps KNOWN_UNDOCUMENTED honest in both directions it can rot:
 * no phantom entries (routes removed from index.php without removing the
 * opt-out), and no graduated entries (routes CoreApiSchemas now declares but
 * that were left on the opt-out list). The second matters because the published
 * spec's gap is documented as being exactly this list — see
 * docs/wiki/OpenAPI-Schema-Generation.md, "The two documents: published vs live".
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
        // WC-9b87 / WC-628738f5: tenant email-domain policy admin endpoints are now
        // declared in CoreApiSchemas::tenantEmailDomainRoutes().
        // WC-e6287 / WC-f3b17bd2: identity-provider admin CRUD, the public
        // enabled-providers list, and connected-accounts management are now
        // declared in CoreApiSchemas::identityRoutes().
        // WC-ae16: the OIDC sign-in redirect flow itself stays undocumented —
        // start/callback are 302 browser redirects, not JSON APIs.
        'GET /api/auth/sso/{provider}/start',
        'GET /api/auth/sso/{provider}/callback',
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
        // WC-email: operator-only email settings — SMTP write-only password +
        // live send-test. OpenAPI schema to follow in a documentation task.
        'GET /api/settings/mail/status',
        'PUT /api/settings/mail/smtp-password',
        'POST /api/settings/mail/test',
        // WC-jobs-api: generic async-job submission + status API. OpenAPI schema
        // (JobCreateRequest / JobResponse / JobListResponse components) to follow
        // in a dedicated documentation task, per the KNOWN_UNDOCUMENTED-first path.
        'POST /api/jobs',
        'GET /api/jobs',
        'GET /api/jobs/{id}',
        // WC-status-page: the public service-status feed. Its response is a
        // nested components/incidents document whose OpenAPI components land
        // with the status-page documentation task, per the
        // KNOWN_UNDOCUMENTED-first path this file establishes.
        'GET /api/status',
        // WC-error-tracking: the operator-only error inbox and the write-only
        // DSN credential. OpenAPI components land with the error-tracking
        // documentation task, per the KNOWN_UNDOCUMENTED-first path.
        'GET /api/errors',
        'GET /api/errors/{id}',
        'PATCH /api/errors/{id}',
        'GET /api/settings/error-tracking',
        'PUT /api/settings/error-tracking/dsn',
        // WC-desktop-plugins: a device's catalog + download of obfuscated desktop
        // plugin packages, consumed by the desktop app (not the web UI). OpenAPI
        // components land with the desktop-plugins documentation task, per the
        // KNOWN_UNDOCUMENTED-first path this file establishes.
        'GET /api/desktop-plugins',
        'GET /api/desktop-plugins/{name}/versions/{version}/download',
        // WC-desktop-app-updates: the desktop app's self-update feed (latest
        // published app release), consumed by the desktop app (not the web UI).
        // OpenAPI components land with the desktop-updates documentation task,
        // per the KNOWN_UNDOCUMENTED-first path this file establishes.
        'GET /api/desktop-app-updates/latest',
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
     * The direction the other two assertions cannot see.
     *
     * testEveryLiveRouteIsDocumentedOrOptedOut subtracts BOTH the declarations
     * and the opt-out list, so a route sitting in both is subtracted twice and
     * passes. testKnownUndocumentedHasNoPhantomEntries only catches entries
     * whose route left index.php. Neither notices a route that is still live,
     * has SINCE been declared in CoreApiSchemas, and was left on the opt-out
     * list — the list then overstates the gap and nothing fails.
     *
     * That is not hypothetical: three graduations (WC-388a61e3 auth + 2FA,
     * WC-9b87 tenant email-domain, WC-e6287 identity) were each pruned by hand,
     * and the comments marking them are still above this list. This assertion
     * is what makes the next one fail loudly instead of relying on memory.
     */
    public function testKnownUndocumentedHasNoGraduatedEntries(): void
    {
        $declaredRoutes = $this->extractDeclaredRoutes();

        // An empty catalogue would make the intersection below empty too, and
        // this gate would pass by matching nothing at all.
        $this->assertNotEmpty(
            $declaredRoutes,
            'CoreApiSchemas::routes() returned no routes; this gate would pass vacuously.'
        );

        $graduated = array_values(array_intersect(self::KNOWN_UNDOCUMENTED, $declaredRoutes));
        $this->assertSame(
            [],
            $graduated,
            "KNOWN_UNDOCUMENTED lists routes that CoreApiSchemas now declares:\n"
            . implode("\n", $graduated)
            . "\n\nThese are documented — remove them from KNOWN_UNDOCUMENTED so the "
            . "opt-out list keeps matching the real published/live gap."
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
