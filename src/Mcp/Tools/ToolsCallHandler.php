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
use Whity\Sdk\Mcp\McpToolContext;
use Whity\Mcp\JsonRpc\ErrorCode;
use Throwable;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Core\Request;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpScope;

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
 * Protocol errors (unknown tool, forbidden, a missing tool name) are thrown as
 * McpException so the Dispatcher returns the correct JSON-RPC error codes.
 * Arguments that fail the inputSchema and application errors (4xx/5xx from the
 * handler) are returned as isError:true results, as the spec requires: clients
 * show those to the model so it can correct its call, while a protocol error
 * often never reaches it (WHIT-710).
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
        // SDK 1.43. Optional, so an installation with no tool-authoring plugin
        // behaves exactly as before and no existing construction site changes.
        private readonly ?AuthoredToolRegistry  $authoredTools = null,
        // WHIT-784: where an agent's Ask-first call is stored for its person
        // to approve. Null: Ask first is refused, as before proposals existed.
        private readonly ?AgentProposalRepository $proposals = null,
    ) {}

    /**
     * Run a call a person approved (WHIT-784). Every check runs again for the
     * agent and its granting person, in the proposal's tenant; only the Ask-first
     * stop is passed, because the approval is that question answered. Off is
     * still refused: a grant changed since the agent asked wins.
     *
     * @param array<string, mixed> $arguments
     * @throws McpException
     */
    public function runApproved(McpPrincipal $principal, string $toolName, array $arguments): mixed
    {
        return $this->run($principal, $toolName, $arguments, true);
    }

    /**
     * Run a HAND-AUTHORED tool (SDK 1.43).
     *
     * Deliberately parallel to {@see self::executeResolved()} in the order it
     * does things — validate the schema, enforce RBAC, then run — because the
     * two paths must be equally strict and the easiest way to keep them so is
     * for them to read the same way.
     *
     * What differs is where authority comes from. A derived tool inherits the
     * matched ROUTE's `requiredPermission`/`requiredRole`; an authored tool has
     * no route, so the requirement is the one its descriptor declared. The
     * registry has already refused any descriptor that declared no audience at
     * all, so by the time execution reaches here the tool is either gated or
     * deliberately marked open.
     *
     * @param array<string, mixed> $arguments
     * @param array{name: string, description: string, inputSchema: array<string, mixed>, handler: callable, requiredRole: ?string, requiredPermission: ?string, plugin: string} $tool
     * @return array{content: list<array{type: string, text: string}>, isError?: bool}
     * @throws McpException On RBAC failure.
     */
    private function executeAuthored(
        string $toolName,
        array $arguments,
        array $tool,
        McpPrincipal $principal,
    ): array {
        // The SAME validator, against the SAME schema tools/list advertised, so
        // a handler can never be handed arguments the published schema said
        // were impossible.
        $invalid = $this->validateArguments($toolName, $tool['inputSchema'], $arguments);
        if ($invalid !== null) {
            return $invalid;
        }

        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'No tenant context');
        }

        if (!McpScope::of($principal)->allowsPermission($tool['requiredPermission'])) {
            throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
        }
        if ($tool['requiredPermission'] !== null) {
            if (!$this->roleChecker->hasPermissionForProfile($principal->userId, $tool['requiredPermission'], $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        }
        if ($tool['requiredRole'] !== null) {
            if (!$this->roleChecker->hasRoleForProfile($principal->userId, $tool['requiredRole'], $tenantId)) {
                throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
            }
        }

        // BOTH are checked when both are set — an `elseif` here would silently
        // ignore the role whenever a permission was also declared, which reads
        // as "belt and braces" in the descriptor and would be one of them.

        // The tenant the gate above was checked in, and the caller, handed to
        // the tool explicitly (SDK 1.46, #1419): without it a plugin's tool
        // could only scope its queries by reaching into TenantContext, a core
        // internal. A handler written for 1.43-1.45 takes one parameter and
        // PHP ignores the extra argument.
        $context = new McpToolContext($tenantId, $principal->profileId);

        try {
            $result = ($tool['handler'])($arguments, $context);
        } catch (McpException $e) {
            // A handler that speaks the protocol deliberately (a domain refusal
            // as FORBIDDEN, say) is relayed as it meant it.
            throw $e;
        } catch (Throwable $e) {
            // The guarantee the interface makes: a throwing tool costs its
            // caller an error, not the dispatcher. One plugin's bug must not
            // take down every other tool in the session.
            $this->warn(sprintf(
                "[Mcp] authored tool '%s' from plugin '%s' threw %s: %s",
                $toolName,
                $tool['plugin'],
                $e::class,
                $e->getMessage(),
            ));

            throw new McpException(ErrorCode::INTERNAL_ERROR, "Tool '{$toolName}' failed");
        }

        return $this->asToolResult($result);
    }

    /**
     * Wrap a handler's return value in the MCP tool-result envelope.
     *
     * A handler returns domain data, not protocol. Making it construct the
     * envelope itself would put the protocol shape into every plugin, where a
     * mistake surfaces to the model as a malformed response rather than as a
     * plugin bug.
     */
    /** @return array{content: list<array{type: string, text: string}>} */
    private function asToolResult(mixed $result): array
    {
        if (is_string($result)) {
            return ['content' => [['type' => 'text', 'text' => $result]]];
        }

        return ['content' => [[
            'type' => 'text',
            'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ?: '{}',
        ]]];
    }

    /**
     * Check and coerce arguments; on failure, the tool error the model sees.
     *
     * A tool execution error, not a protocol error (MCP server/tools, Error
     * Handling): it is the model's mistake to fix, and clients pass these to the
     * model while they may drop a JSON-RPC error. The expected arguments are
     * listed so one retry can get it right instead of a guess per field. The
     * tool is one this caller can already see, so its schema is no secret.
     *
     * @param array<string, mixed>  $schema
     * @param array<string, mixed> &$arguments
     * @return array{content: list<array{type: string, text: string}>, isError: true}|null
     */
    private function validateArguments(string $toolName, array $schema, array &$arguments): ?array
    {
        try {
            $this->schemaValidator->validate($schema, $arguments);
            return null;
        } catch (McpException $e) {
            if ($e->errorCode !== ErrorCode::INVALID_PARAMS) {
                throw $e;
            }
        }

        $text = "Invalid arguments for {$toolName}: {$e->getMessage()}.";
        $expected = self::describeArguments($schema);
        if ($expected !== '') {
            $text .= " Expected: {$expected}.";
        }

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }

    /**
     * "id (integer, required), name (string)" from an inputSchema.
     *
     * @param array<string, mixed> $schema
     */
    private static function describeArguments(array $schema): string
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required   = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $parts = [];
        foreach ($properties as $name => $property) {
            $type = is_array($property) && is_string($property['type'] ?? null) ? $property['type'] : 'any';
            $parts[] = $name . ' (' . $type . (in_array($name, $required, true) ? ', required' : '') . ')';
        }
        return implode(', ', $parts);
    }

    private function warn(string $message): void
    {
        error_log($message);
    }

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

        return $this->run($principal, $toolName, $arguments, false);
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws McpException
     */
    private function run(McpPrincipal $principal, string $toolName, array $arguments, bool $approved): mixed
    {
        // Route the AI principal into AuditContext so that any hook-fired audit
        // entries (e.g. user.created by a mutation tool) also capture the MCP
        // actor rather than null.
        // WHIT-753: the actor is the principal's: the person, or an agent for them.
        AuditContext::setActor($principal->actor());

        // 3. Resolve tool name → route declaration, or a hand-authored tool.
        //
        // DERIVED IS TRIED FIRST, matching the collision rule the registry
        // enforces: a route-backed name is the one the OpenAPI document and the
        // typed clients already describe, so it must be the one that answers.
        // Checking authored first would let a plugin shadow a published
        // operation by naming a tool after it.
        $declaration = $this->toolDeriver->findDeclarationByName($toolName);
        // A tool tools/list would not show is not callable by name either
        // (ADR-0006 §4, WHIT-695). Answered as unknown, not forbidden: there is
        // nothing here for this caller, whoever they are.
        if ($declaration !== null && !$this->toolDeriver->isExposed($toolName)) {
            throw new McpException(ErrorCode::METHOD_NOT_FOUND, "Unknown tool: {$toolName}");
        }
        if ($declaration === null) {
            $authored = $this->authoredTools?->get($toolName);
            if ($authored !== null) {
                try {
                    $this->assertInTokenToolsets($principal, $this->authoredTools?->toolsetOf($toolName));
                    $proposal = $this->assertAgentMayRun($principal, $toolName, $arguments, $this->authoredTool($toolName), $authored, $approved);
                    if ($proposal !== null) {
                        return $proposal;
                    }
                    return $this->executeAuthored($toolName, $arguments, $authored, $principal);
                } finally {
                    $this->auditLogger?->record('mcp.tools.call', [
                        'tenant_id'     => $principal->tenantId,
                        'actor_user_id' => $principal->userId,
                        'target_type'   => 'tool',
                        'metadata'      => [
                            'tool'   => $toolName,
                            // ADR-0006 §6: which kind of principal called.
                            'principal_kind' => $principal->principalKind,
                            // Named so an authored tool is distinguishable in
                            // the trail: it ran plugin code directly rather
                            // than dispatching through the router, so "which
                            // route did this hit" has no answer for it.
                            'plugin' => $authored['plugin'],
                            'args'   => $this->redactArgs($arguments),
                        ],
                    ]);
                }
            }

            throw new McpException(ErrorCode::METHOD_NOT_FOUND, "Unknown tool: {$toolName}");
        }

        // From here the tool is known — audit the invocation regardless of
        // outcome (RBAC denial, handler error, or success).
        try {
            $this->assertInTokenToolsets($principal, $this->toolDeriver->toolsetOf($toolName));
            $proposal = $this->assertAgentMayRun($principal, $toolName, $arguments, $this->toolDeriver->toolFor($toolName) ?? [], $this->toolDeriver->buildAccessMap()[$toolName] ?? [], $approved);
            if ($proposal !== null) {
                return $proposal;
            }
            return $this->executeResolved($toolName, $arguments, $declaration, $principal);
        } finally {
            $this->auditLogger?->record('mcp.tools.call', [
                'tenant_id'    => $principal->tenantId,
                'actor_user_id' => $principal->userId,
                'target_type'  => 'tool',
                // Pre-strip at the call site (defense-in-depth). AuditLogger
                // also sanitizes metadata before the INSERT, but future
                // AuditLoggerInterface implementations may not.
                'metadata'     => ['tool' => $toolName, 'principal_kind' => $principal->principalKind, 'args' => $this->redactArgs($arguments)],
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
     * WHIT-753, WHIT-784: what an agent's scope grant lets it do with this
     * tool. Off is refused. Ask first is not executed: it is stored as a
     * proposal for the granting person, and the proposal is the call's result.
     * Returns that result, or null when the call may run now.
     *
     * Checked only after every other gate (exposure, token toolsets), and
     * before RBAC runs in the execution path, which approval repeats.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $access
     * @return array<string, mixed>|null
     * @throws McpException
     */
    private function assertAgentMayRun(McpPrincipal $principal, string $toolName, array $arguments, array $tool, array $access, bool $approved): ?array
    {
        if (!AgentScopeGrant::appliesTo($principal)) {
            return null;
        }
        $mode = ToolsListHandler::agentModeFor($tool, $access, $principal);
        if ($mode === AgentScopeGrant::OFF) {
            throw new McpException(ErrorCode::FORBIDDEN, 'This agent is not granted this operation.');
        }
        if ($mode === AgentScopeGrant::ALLOW || $approved) {
            return null;
        }
        if ($this->proposals === null) {
            throw new McpException(ErrorCode::FORBIDDEN, 'This operation needs a person to approve it, and approvals are not available here.');
        }

        // The schema is checked now, so a person is never asked to approve a
        // call that could not run.
        $schema  = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : ['type' => 'object'];
        $invalid = $this->validateArguments($toolName, $schema, $arguments);
        if ($invalid !== null) {
            return $invalid;
        }

        $permission = is_string($access['requiredPermission'] ?? null) ? $access['requiredPermission'] : null;
        $summary    = is_string($tool['description'] ?? null) && $tool['description'] !== '' ? $tool['description'] : $toolName;
        $id = $this->proposals->create(
            $principal->tenantId,
            $principal->jti,
            $principal->userId,
            $toolName,
            $arguments,
            array_values(array_filter($principal->scope, 'is_string')),
            $permission !== null ? [$permission] : [],
            $summary,
        );

        return [
            'content' => [[
                'type' => 'text',
                'text' => (string) json_encode([
                    'proposal' => [
                        'id'          => $id,
                        'status'      => AgentProposalRepository::WAITING,
                        'summary'     => $summary,
                        'permissions' => $permission !== null ? [$permission] : [],
                        'expiresInDays' => intdiv(AgentProposalRepository::LIFETIME_SECONDS, 86400),
                    ],
                    'message' => 'Not done yet: this needs the person you act for to approve it. They will see it in their approvals.',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }

    /**
     * The tool object of an authored tool, as tools/list publishes it.
     *
     * @return array<string, mixed>
     */
    private function authoredTool(string $name): array
    {
        foreach ($this->authoredTools?->toolObjects() ?? [] as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }
        return [];
    }

    /**
     * WHIT-749: a token that names toolsets may only CALL tools in them, not
     * just list them (owner decision 3). Refused as forbidden rather than
     * answered "unknown": the tool exists and the caller may well hold its
     * permission; it is this token that can't reach it, and saying which
     * toolsets it can reach tells the person what to change.
     *
     * @throws McpException
     */
    private function assertInTokenToolsets(McpPrincipal $principal, ?string $toolset): void
    {
        $scope = McpScope::of($principal);
        if (!$scope->allowsToolset($toolset)) {
            throw new McpException(
                ErrorCode::FORBIDDEN,
                'This token can only use the toolsets: ' . implode(', ', $scope->toolsetEntries()),
            );
        }
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
            $invalid = $this->validateArguments($toolName, $inputSchema, $arguments);
            if ($invalid !== null) {
                return $invalid;
            }
        }

        $method  = strtoupper((string) ($declaration['method'] ?? 'GET'));
        $rawPath = (string) ($declaration['path'] ?? '');

        // 4. Substitute path parameters from arguments into the route path.
        [$concretePath, $pathParamNames] = $this->substitutePath($rawPath, $arguments);

        // 5. Apply the router version prefix to get the stored (versioned) path.
        //    For plugin routes already stored with the version prefix, the first
        //    match attempt produces a double-prefixed path that the router rejects;
        //    the fallback retries with the path as-is.
        $versionedPath = $this->router->versionedPath($concretePath);
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

        // WHIT-695: the token's permission narrowing, on top of RBAC below.
        $gate = is_string($requiredPermission) && $requiredPermission !== '' ? $requiredPermission : null;
        if (!McpScope::of($principal)->allowsPermission($gate)) {
            throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
        }

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
            // A constraint may carry {n} / {n,m} quantifiers (Router::pathToPattern()).
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::(?:[^{}]|\{\d+(?:,\d*)?\})+)?\}#',
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
        // Post-identity-cutover handlers read the caller off the CANONICAL
        // {profile_id, active_tenant_id} claims, so stamp those (the legacy
        // {user_id, tenant_id} aliases are retained for any consumer still on
        // them). Without profile_id, a handler that reads $request->user->profile_id
        // gets null.
        $claims = [
            'profile_id'       => $principal->userId,
            'active_tenant_id' => $principal->tenantId,
            'user_id'          => $principal->userId,
            'tenant_id'        => $principal->tenantId,
            'type'             => 'mcp',
            'principal_kind'   => $principal->principalKind,
            'scope'            => $principal->scope,
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
