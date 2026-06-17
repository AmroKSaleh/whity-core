<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\CoreVersion;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\OpenAPI\SchemaGenerator;

/**
 * Dynamic OpenAPI document endpoint (WC-209).
 *
 * Serves `GET /api/openapi.json` by regenerating the OpenAPI 3.0 spec from the
 * LIVE router at request time — no file is read or written. The running app's
 * {@see Router} already carries every currently-registered route (core plus all
 * enabled plugins), so the spec always describes exactly what the app would
 * serve right now.
 *
 * Why dynamic: product plugins live out-of-repo and are never committed, so the
 * committed `public/openapi.json` only ever holds CORE routes. A plugin
 * installed, uninstalled or hot-reloaded after the last manual
 * `generate:openapi` would be invisible to the schema-driven plugin CRUD UI
 * until someone remembered to regenerate. Generating per request removes that
 * silent staleness entirely.
 *
 * The endpoint is intentionally unauthenticated (matching the static
 * `/openapi.json` already served by Caddy): it exposes only route SHAPES
 * (method/path/schema), never any tenant data. It is registered UNVERSIONED so
 * the document URL never moves under a version segment.
 *
 * Worker safety: no request-scoped state is kept in statics, so a FrankenPHP
 * worker serving many requests cannot leak one request's spec into another. The
 * spec is rebuilt per request — O(routes), cheap — so there is no cache to
 * invalidate when plugins reload.
 *
 * @phpstan-consistent-constructor
 */
class OpenApiHandler
{
    private Router $router;

    private PluginLoader $pluginLoader;

    /**
     * @param Router       $router       The live router carrying every registered route.
     * @param PluginLoader $pluginLoader The live plugin loader (legacy generator fallback).
     */
    public function __construct(Router $router, PluginLoader $pluginLoader)
    {
        $this->router = $router;
        $this->pluginLoader = $pluginLoader;
    }

    /**
     * Handle `GET /api/openapi.json`.
     *
     * Builds the spec from the live router and returns it as a deterministic
     * JSON document. On any failure the underlying error is logged via
     * error_log and a generic 500 is returned — the raw exception text never
     * reaches the client.
     *
     * @param Request $request The incoming request (unused; the endpoint takes no input).
     * @return Response The OpenAPI document, or a generic 500 on failure.
     */
    public function handle(Request $request): Response
    {
        try {
            $json = $this->buildSpec();
        } catch (Throwable $e) {
            error_log('[openapi] dynamic spec generation failed: ' . $e->getMessage());

            return Response::error('Failed to generate OpenAPI document', 500);
        }

        return new Response(200, $json, ['Content-Type' => 'application/json']);
    }

    /**
     * Build and encode the OpenAPI document from the live router.
     *
     * Isolated as a protected seam so tests can force the failure path. The
     * generator reads {@see Router::getRoutes()} as its source of truth, so the
     * output reflects whatever is registered at the moment of the call.
     *
     * @return string The deterministic JSON OpenAPI document.
     */
    protected function buildSpec(): string
    {
        $generator = new SchemaGenerator(
            'Whity Core API',
            CoreVersion::VERSION,
            $this->pluginLoader,
            $this->router
        );

        return SchemaGenerator::encode($generator->generate());
    }
}
