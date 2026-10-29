<?php

declare(strict_types=1);

namespace Whity\Sdk\Mcp;

/**
 * Who an authored MCP tool is acting for (SDK 1.46, #1419).
 *
 * The second argument the host passes to a {@see \Whity\Sdk\PluginMcpToolsInterface}
 * handler: `fn(array $arguments, McpToolContext $context): mixed`.
 *
 * WHY THIS EXISTS
 * ---------------
 * A route handler learns its tenant from the `Request` it is given. An authored
 * tool had no request, no principal and no context argument, so the only way
 * for it to scope a query was to reach into core's internal tenant state — a
 * class with no contract and no compatibility promise — or not to scope at all,
 * which in a multi-tenant plugin is a cross-tenant read. This is the MCP path's
 * equivalent of the request's JWT claims.
 *
 * WHICH TENANT
 * ------------
 * `tenantId` is the tenant the host has ALREADY authorized this call in: the
 * one the tool's `requiredRole` / `requiredPermission` gate was checked against.
 * A tool must scope every read and write to it. It is never a value the model
 * supplied, so an argument naming another tenant cannot widen what the tool
 * sees.
 *
 * AN OBJECT RATHER THAN MORE ARGUMENTS
 * ------------------------------------
 * So that adding a fact later (the principal kind, the granted scopes) is a new
 * property and not a signature change for every handler already written.
 * Passed explicitly, not read from ambient state, so it cannot be read at the
 * wrong moment or for the wrong call.
 *
 * Immutable value object.
 */
final class McpToolContext
{
    /**
     * @param int $tenantId  The tenant this call is authorized in. Scope every
     *                       query to it.
     * @param int $profileId The calling person's profile id: the owner of the
     *                       MCP token, or of the session, the call came in on.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $profileId,
    ) {
    }
}
