<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

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
 * MCP tools/call handler (WC-2e6944d5, WC-b570dccd).
 *
 * Executes one MCP tool per JSON-RPC call:
 *   1. Validates the bearer token to obtain the AI principal (userId/tenantId).
 *   2. Resolves the tool name back to a route declaration via ToolDeriver.
 *   3. Validates and coerces arguments against the derived inputSchema so the
 *      server-side validation spec is always identical to what the AI client
 *      received from tools/list (WC-b570dccd).
 *   4. Synthesizes a versioned HTTP Request from the tool arguments.
 *   5. Resolves the live handler + access controls via Router::match().
 *   6. Enforces requiredPermission / requiredRole through RoleChecker —
 *      the same component HTTP uses, so MCP can never diverge from HTTP authz.
 *   7. Invokes the route handler and wraps its Response in the MCP result shape.
 *
 * Protocol errors (unknown tool, forbidden, invalid params) are thrown as
 * McpException so the Dispatcher returns the correct JSON-RPC error codes.
 * Application errors (4xx/5xx from the handler) are returned as isError:true
 * content per the MCP specification.
 */
final class ToolsCallHandler implements MethodHandler
{
    public function __construct(
        private readonly ToolDeriver            $toolDeriver,
        private readonly Router                 $router,
        private readonly RoleChecker            $roleChecker,
        private readonly TokenValidator         $tokenValidator,
        private readonly InputSchemaValidator   $schemaValidator = new InputSchemaValidator(),
        private readonly ?AuditLoggerInterface  $auditLogger = null,
    ) {}

    /**
     * @param array<string, mixed>|null $params
     * @throws McpException On protocol-level failures (invalid params, unknown tool, forbidden).
     */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        // 1. Validate params.
        $toolName = $params['name'] ?? null;
        if (!is_string($toolName) || $toolName === '') {
            throw new McpException(ErrorCode::INVALID_PARAMS, 'Missing required parameter: name');
        }
        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        // 2. Re-validate bearer token to obtain the principal (userId).
        //    The Dispatcher already validated auth at the transport level; the
        //    re-validation here is cheap (cached path) and provides userId for
        //    the per-call permission check without global/static state.
        $principal = $bearerToken !== null
            ? $this->tokenValidator->validateBearerForMcp($bearerToken)
            : null;
        if ($principal === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'Unauthenticated');
        }

        // Route the AI principal into AuditContext so that any hook-fired audit
        // entries (e.g. user.created by a mutation tool) also capture the MCP
        // actor rather than null.
        AuditContext::set($principal->userId, null);

        // 3. Resolve tool name → route declaration.
        $declaration = $this->toolDeriver->findDeclarationByName($toolName);
        if ($declaration === null) {
            throw new McpException(ErrorCode::METHOD_NOT_FOUND, "Unknown tool: {$toolName}");
        }

        // From here the tool is known — audit the invocation regardless of
        // outcome (RBAC denial, handler error, or success).
        try {
            return $this->executeResolved($toolName, $arguments, $declaration, $principal);
        } finally {
            $this->auditLogger?->record('mcp.tools.call', [
                'tenant_id'    => $principal->tenantId,
                'actor_user_id' => $principal->userId,
                'target_type'  => 'tool',
                // Pre-strip at the call site (defense-in-depth). AuditLogger
                // also sanitizes metadata before the INSERT, but future
                // AuditLoggerInterface implementations may not.
                'metadata'     => ['tool' => $toolName, 'args' => $this->redactArgs($arguments)],
            ]);
        }
    }

    /**
     * Strip keys whose names contain sensitive substrings from a flat argument
     * map before the map is passed to the audit trail. Matches the same set of
     * forbidden substrings as {@see \Whity\Core\Audit\AuditLogger}.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function redactArgs(array $args): array
    {
        $forbidden = ['password', 'secret', 'token', 'code', 'hash', 'backup_code', 'two_factor_secret'];
        $clean = [];
        foreach ($args as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($forbidden as $needle) {
                if (str_contains($lower, $needle)) {
                    continue 2;
                }
            }
            $clean[$key] = is_array($value) ? $this->redactArgs($value) : $value;
        }
        return $clean;
    }

    /**
     * Execute a resolved tool call (steps 3b–9). Separated so the audit
     * finally block in {@see self::__invoke()} wraps the entire execution.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $declaration
     * @throws McpException On RBAC failure or unresolvable route.
     * @return array<string, mixed>
     */
    private function executeResolved(
        string $toolName,
        array $arguments,
        array $declaration,
        McpPrincipal $principal,
    ): array {
        // 3b. Validate and coerce arguments against the derived inputSchema.
        //     The same schema the AI client received from tools/list is used here,
        //     so validation can never diverge from the advertised spec.
        $inputSchema = $this->toolDeriver->getToolInputSchema($toolName);
        if (is_array($inputSchema)) {
            $this->schemaValidator->validate($inputSchema, $arguments);
        }

        $method  = strtoupper((string) ($declaration['method'] ?? 'GET'));
        $rawPath = (string) ($declaration['path'] ?? '');

        // 4. Substitute path parameters from arguments into the route path.
        [$concretePath, $pathParamNames] = $this->substitutePath($rawPath, $arguments);

        // 5. Apply the router version prefix to get the stored (versioned) path.
        //    For plugin routes already stored with the version prefix, the first
        //    match attempt produces a double-prefixed path that the router rejects;
        //    the fallback retries with the path as-is.
        $versionPrefix = $this->router->getVersionPrefix();
        $versionedPath = $this->applyVersionPrefix($concretePath, $versionPrefix);
        $matched       = $this->router->match(new Request($method, $versionedPath));

        if ($matched === null && $versionedPath !== $concretePath) {
            // Retry with the path as-is (already versioned — plugin route case).
            $matched = $this->router->match(new Request($method, $concretePath));
            if ($matched !== null) {
                $versionedPath = $concretePath;
            }
        }

        if ($matched === null) {
            throw new McpException(ErrorCode::METHOD_NOT_FOUND, "Tool route not found: {$toolName}");
        }

        // 6. Enforce access controls via RoleChecker — same component as HTTP.
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'No tenant context');
        }

        $requiredPermission = $matched['requiredPermission'] ?? null;
        $requiredRole       = $matched['requiredRole'] ?? null;

        if (is_string($requiredPermission) && $requiredPermission !== '') {
            if (!$this->roleChecker->hasPermissionForProfile($principal->userId, $requiredPermission, $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        } elseif (is_string($requiredRole) && $requiredRole !== '') {
            if (!$this->roleChecker->hasRoleForProfile($principal->userId, $requiredRole, $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        }

        // 7. Synthesize the HTTP Request from the tool arguments.
        $httpRequest = $this->buildRequest(
            $method,
            $versionedPath,
            $arguments,
            $pathParamNames,
            $declaration,
            $principal,
        );

        // 8. Invoke the route handler.
        try {
            $response = ($matched['handler'])($httpRequest, $matched['params']);
        } catch (\Throwable) {
            return [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'Internal error']],
            ];
        }

        // 9. Wrap in the MCP result shape.
        return [
            'isError' => $response->getStatusCode() >= 400,
            'content' => [['type' => 'text', 'text' => $response->getBody()]],
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Substitute {param} / {param:regex} placeholders in a route path with
     * concrete argument values.
     *
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: list<string>} [substituted path, consumed param names]
     */
    private function substitutePath(string $path, array $arguments): array
    {
        $pathParamNames = [];
        $substituted = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^{}]+)?\}#',
            function (array $matches) use ($arguments, &$pathParamNames): string {
                $name             = $matches[1];
                $pathParamNames[] = $name;
                return isset($arguments[$name]) ? (string) $arguments[$name] : $matches[0];
            },
            $path,
        );

        return [is_string($substituted) ? $substituted : $path, $pathParamNames];
    }

    /**
     * Insert the version prefix after the first path segment, matching the
     * logic in Router::versionPrefix().
     *
     * e.g. '/api/users' + '/v1' → '/api/v1/users'
     */
    private function applyVersionPrefix(string $path, string $versionPrefix): string
    {
        if ($versionPrefix === '') {
            return $path;
        }
        $pos = strpos($path, '/', 1);
        if ($pos === false) {
            return $path . $versionPrefix;
        }
        return substr($path, 0, $pos) . $versionPrefix . substr($path, $pos);
    }

    /**
     * Build the HTTP Request to pass to the route handler.
     *
     * - Path params are already embedded in $path.
     * - Query params (those declared as 'in:query' in the route schema) are
     *   appended as a query string.
     * - Body params (everything else, for non-GET verbs) are JSON-encoded into
     *   the request body.
     * - For GET/DELETE/HEAD all remaining args go to the query string.
     * - JWT claims are stashed on the request so downstream consumers can read
     *   the authenticated identity without re-decoding the token, mirroring
     *   what EnforceTenantIsolation does for the HTTP path.
     *
     * @param array<string, mixed> $arguments    Full argument set from the MCP call.
     * @param list<string>         $pathParamNames Already-consumed path param names.
     * @param array<string, mixed> $declaration  Route declaration (for schema lookup).
     */
    private function buildRequest(
        string $method,
        string $path,
        array $arguments,
        array $pathParamNames,
        array $declaration,
        McpPrincipal $principal,
    ): Request {
        // Remove path params — they are already embedded in $path.
        $remaining = array_diff_key($arguments, array_flip($pathParamNames));

        // Separate query params (declared in schema) from body params.
        $queryParamNames = $this->extractQueryParamNames((array) ($declaration['schema'] ?? []));
        $queryArgs       = array_intersect_key($remaining, array_flip($queryParamNames));
        $bodyArgs        = array_diff_key($remaining, array_flip($queryParamNames));

        // For read-only verbs all remaining args become query-string params.
        if (in_array($method, ['GET', 'DELETE', 'HEAD'], true)) {
            $queryArgs = array_merge($queryArgs, $bodyArgs);
            $bodyArgs  = [];
        }

        if ($queryArgs !== []) {
            $sep  = str_contains($path, '?') ? '&' : '?';
            $path .= $sep . http_build_query($queryArgs);
        }

        $body    = '';
        $headers = ['content-type' => 'application/json'];
        if ($bodyArgs !== []) {
            $body = (string) json_encode($bodyArgs, JSON_THROW_ON_ERROR);
        }

        $request = new Request($method, $path, $headers, $body);

        // Stash JWT claims — mirrors EnforceTenantIsolation for the HTTP path.
        $claims = [
            'user_id'        => $principal->userId,
            'tenant_id'      => $principal->tenantId,
            'type'           => 'mcp',
            'principal_kind' => $principal->principalKind,
            'scope'          => $principal->scope,
        ];
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, $claims);
        $request->user = (object) $claims;

        return $request;
    }

    /**
     * Extract names of query parameters declared in a route schema.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function extractQueryParamNames(array $schema): array
    {
        $names  = [];
        $params = $schema['parameters'] ?? [];
        if (!is_array($params)) {
            return [];
        }
        foreach ($params as $param) {
            if (!is_array($param) || ($param['in'] ?? '') !== 'query') {
                continue;
            }
            $name = $param['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }
}
