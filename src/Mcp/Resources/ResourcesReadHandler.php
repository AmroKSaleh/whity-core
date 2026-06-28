<?php

declare(strict_types=1);

namespace Whity\Mcp\Resources;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Sdk\Http\Request;

/**
 * MCP resources/read handler (WC-30513809).
 *
 * Accepts a whity-api:// URI, resolves it to a live GET route via Router::match(),
 * enforces RBAC through the same RoleChecker used by HTTP, and returns the
 * handler response body wrapped in the MCP contents format.
 *
 * Protocol errors (invalid URI, resource not found, forbidden) are thrown as
 * McpException so the Dispatcher returns the correct JSON-RPC error codes.
 * Application errors (4xx/5xx from the handler) are forwarded in contents.text
 * so the AI client can inspect the body, matching the MCP spec's intent of
 * always returning 200 JSON-RPC responses even for downstream failures.
 */
final class ResourcesReadHandler implements MethodHandler
{
    public function __construct(
        private readonly Router                $router,
        private readonly RoleChecker           $roleChecker,
        private readonly TokenValidator        $tokenValidator,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * @param array<string, mixed>|null $params
     * @throws McpException On protocol-level failures (invalid URI, not found, forbidden).
     */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        // 1. Validate the uri parameter.
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw new McpException(ErrorCode::INVALID_PARAMS, 'Missing required parameter: uri');
        }
        if (!str_starts_with($uri, ResourceDeriver::URI_SCHEME)) {
            throw new McpException(ErrorCode::INVALID_PARAMS, 'Invalid resource URI scheme');
        }

        // 2. Re-validate bearer token to obtain the principal.
        $principal = $bearerToken !== null
            ? $this->tokenValidator->validateMcpToken($bearerToken)
            : null;
        if ($principal === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'Unauthenticated');
        }

        // Route the AI principal into AuditContext so hook-fired audit entries
        // (e.g. a read that triggers a hook) also capture the MCP actor.
        AuditContext::set($principal->userId, null);

        // 3. Extract path (and query string) from URI by stripping the scheme.
        //    whity-api:///api/v1/things/42  →  /api/v1/things/42
        $fullPath = substr($uri, strlen(ResourceDeriver::URI_SCHEME));

        // Separate the path from the query string for Router::match().
        $questionMark = strpos($fullPath, '?');
        $pathForMatch = $questionMark !== false ? substr($fullPath, 0, $questionMark) : $fullPath;

        // 4. Resolve the route. Resources are always GET.
        $matched = $this->router->match(new Request('GET', $pathForMatch));
        if ($matched === null) {
            throw new McpException(ErrorCode::RESOURCE_NOT_FOUND, "Resource not found: {$uri}");
        }

        // From here the resource is known — audit the read regardless of outcome.
        try {
            return $this->executeResolved($uri, $fullPath, $matched, $principal);
        } finally {
            $this->auditLogger?->record('mcp.resources.read', [
                'tenant_id'    => $principal->tenantId,
                'actor_user_id' => $principal->userId,
                'target_type'  => 'resource',
                'metadata'     => ['uri' => $uri],
            ]);
        }
    }

    /**
     * Execute a resolved resource read (steps 5–8). Separated so the audit
     * finally block in {@see self::__invoke()} wraps the entire execution.
     *
     * @param array<string, mixed> $matched
     * @throws McpException On RBAC failure or missing tenant context.
     * @return array<string, mixed>
     */
    private function executeResolved(
        string $uri,
        string $fullPath,
        array $matched,
        McpPrincipal $principal,
    ): array {
        // 5. Enforce RBAC — same RoleChecker as the HTTP path.
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'No tenant context');
        }

        $requiredPermission = $matched['requiredPermission'] ?? null;
        $requiredRole       = $matched['requiredRole'] ?? null;

        if (is_string($requiredPermission) && $requiredPermission !== '') {
            if (!$this->roleChecker->hasPermission($principal->userId, $requiredPermission, $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        } elseif (is_string($requiredRole) && $requiredRole !== '') {
            if (!$this->roleChecker->hasRole($principal->userId, $requiredRole, $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        }

        // 6. Synthesize the HTTP Request. The full path (including any query
        //    string) is forwarded so query filters reach the handler intact.
        $httpRequest = $this->buildRequest($fullPath, $principal);

        // 7. Invoke the route handler.
        try {
            $response = ($matched['handler'])($httpRequest, $matched['params']);
        } catch (\Throwable) {
            return [
                'contents' => [[
                    'uri'      => $uri,
                    'mimeType' => 'application/json',
                    'text'     => 'Internal error',
                ]],
            ];
        }

        // 8. Wrap in MCP contents format.
        return [
            'contents' => [[
                'uri'      => $uri,
                'mimeType' => 'application/json',
                'text'     => $response->getBody(),
            ]],
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildRequest(string $path, McpPrincipal $principal): Request
    {
        $claims = [
            'user_id'        => $principal->userId,
            'tenant_id'      => $principal->tenantId,
            'type'           => 'mcp',
            'principal_kind' => $principal->principalKind,
            'scope'          => $principal->scope,
        ];

        $request = new Request('GET', $path, ['content-type' => 'application/json'], '');
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, $claims);
        $request->user = (object) $claims;

        return $request;
    }
}
