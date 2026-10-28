<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\McpExposure;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * Which routes the MCP server offers agents (ADR-0006 §4, WHIT-695).
 */
final class McpExposureTest extends TestCase
{
    public function testGatedRoutesAreExposed(): void
    {
        self::assertTrue(McpExposure::exposes(['requiredPermission' => 'users:read', 'requiredRole' => null, 'schema' => []]));
        self::assertTrue(McpExposure::exposes(['requiredPermission' => null, 'requiredRole' => 'admin', 'schema' => []]));
    }

    public function testUngatedRoutesAreNotExposed(): void
    {
        self::assertFalse(McpExposure::exposes(['requiredPermission' => null, 'requiredRole' => null, 'schema' => ['summary' => 'x']]));
        self::assertFalse(McpExposure::exposes(['requiredPermission' => '', 'requiredRole' => '', 'schema' => null]));
        self::assertFalse(McpExposure::exposes([]));
    }

    public function testUngatedRoutesMayOptIn(): void
    {
        self::assertTrue(McpExposure::exposes(['requiredPermission' => null, 'requiredRole' => null, 'schema' => ['mcp' => true]]));
        // Only a real true opts in; a truthy string is a typo, not a decision.
        self::assertFalse(McpExposure::exposes(['requiredPermission' => null, 'requiredRole' => null, 'schema' => ['mcp' => 'yes']]));
    }

    /**
     * The core surface, end to end: session and credential machinery,
     * public endpoints and webhooks stay off; the self-authorizing opt-ins
     * stay on.
     */
    public function testCoreRoutesKeepCredentialMachineryOffTheAgentSurface(): void
    {
        $exposed = [];
        foreach (CoreApiSchemas::routes() as $route) {
            $exposed[$route['method'] . ' ' . $route['path']] = McpExposure::exposes($route);
        }

        foreach ([
            'POST /api/login',
            'POST /api/auth/refresh',
            'POST /api/auth/2fa/disable',
            'PATCH /api/me',
            'POST /api/me/emails',
            'DELETE /api/me/identities/{id:\d+}',
            'POST /api/auth/password/reset',
            'POST /api/payments/webhook/{provider}',
            'POST /api/billing/webhook',
            'GET /api/public/forms/{slug}',
            'GET /api/health',
        ] as $hidden) {
            self::assertArrayHasKey($hidden, $exposed, "route moved: {$hidden}");
            self::assertFalse($exposed[$hidden], "should not be offered to agents: {$hidden}");
        }

        foreach ([
            'GET /api/me',
            'GET /api/me/notifications',
            'GET /api/me/inbox',
            'POST /api/data-types/{type}/{id}/trash',
            'POST /api/documents/{id:\d+}/routes/{routeId:\d+}/actions',
            'GET /api/users',
        ] as $shown) {
            self::assertTrue($exposed[$shown] ?? false, "should be offered to agents: {$shown}");
        }
    }
}
