<?php

declare(strict_types=1);

namespace Whity\Mcp\Connect;

use Whity\Auth\TokenValidator;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\Tools\ToolsListHandler;

/**
 * What the "Connect an AI app" wizard needs from the server (WHIT-697).
 *
 *   GET  /api/mcp/connect          — where to connect, whether it can work yet,
 *                                    and the scopes behind each plain-language preset
 *   POST /api/mcp/connect/preview  — the tools an app holding a given scope would
 *                                    see, for the caller, before any token exists
 *
 * Both are gated on mcp:tokens:manage at the route, like token issuance: the
 * wizard ends by issuing one.
 *
 * The presets are built here, not in the UI, because they are lists of
 * permission names and only the server knows which exist. A preset is a starting
 * point the UI shows the user, who still sees and confirms the scope it issues.
 *
 * Worker-safe: no request state is kept on the instance.
 */
final class McpConnectHandler
{
    /**
     * @param \Closure(int): bool $tenantMcpEnabled The same check the MCP transport applies.
     * @param string|null         $endpoint         The public MCP URL, or null when the
     *                                              deployment does not know its own address.
     */
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly ToolsListHandler $toolsListHandler,
        private readonly PermissionRegistry $permissionRegistry,
        private readonly \Closure $tenantMcpEnabled,
        private readonly bool $serverEnabled,
        private readonly ?string $endpoint,
    ) {}

    /**
     * The endpoint for an APP_URL, or null when it is unset.
     */
    public static function endpointFor(string $appUrl): ?string
    {
        $appUrl = rtrim(trim($appUrl), '/');
        return $appUrl === '' ? null : $appUrl . '/mcp';
    }

    /** @param array<string, mixed> $params */
    public function info(Request $request, array $params = []): Response
    {
        $identity = $this->identity();
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [, $tenantId] = $identity;

        $known = array_keys($this->permissionRegistry->getAll());
        sort($known);

        $reads    = array_values(array_filter($known, static fn (string $p): bool => str_ends_with($p, ':read')));
        $areas    = [];
        foreach ($known as $permission) {
            $areas[strstr($permission, ':', true) ?: $permission][] = $permission;
        }
        ksort($areas);

        return Response::json([
            'endpoint'      => $this->endpoint,
            'serverEnabled' => $this->serverEnabled,
            'tenantEnabled' => ($this->tenantMcpEnabled)($tenantId),
            'presets'       => [
                'everything' => McpScope::CAPABILITIES,
                // Without a single read permission this preset would carry no
                // permission entry, and a scope with none is not narrowed at all.
                'readOnly'   => $reads === [] ? null : [...McpScope::CAPABILITIES, ...$reads],
            ],
            'areas' => array_map(
                static fn (string $area, array $permissions): array => ['area' => $area, 'permissions' => $permissions],
                array_keys($areas),
                array_values($areas),
            ),
        ], 200);
    }

    /** @param array<string, mixed> $params */
    public function preview(Request $request, array $params = []): Response
    {
        $identity = $this->identity();
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $identity;

        $body  = json_decode($request->getBody(), true);
        $scope = is_array($body) ? ($body['scope'] ?? null) : null;
        if (!is_array($scope) || $scope === [] || array_filter($scope, static fn (mixed $s): bool => !is_string($s) || $s === '') !== []) {
            return Response::error('scope must be a non-empty array of strings', 422);
        }
        /** @var list<string> $scope */
        $scope = array_values(array_unique($scope));

        // The same rules issuance applies, so a preview never shows a scope
        // the next step would refuse to issue.
        $errors = McpScope::issuanceErrors($scope, array_keys($this->permissionRegistry->getAll()));
        if ($errors !== []) {
            return Response::error(implode('; ', $errors), 422);
        }

        $principal = new McpPrincipal(
            profileId: $profileId,
            userId: $profileId,
            tenantId: $tenantId,
            principalKind: 'preview',
            scope: $scope,
            jti: 'preview',
        );

        $tools = array_map(
            static fn (array $tool): array => [
                'name'        => (string) ($tool['name'] ?? ''),
                'description' => (string) ($tool['description'] ?? ''),
            ],
            $this->toolsListHandler->listFor($principal, $tenantId),
        );

        return Response::json(['tools' => $tools], 200);
    }

    /**
     * The caller's (profile_id, active_tenant_id), failing closed like
     * McpTokenHandler: no usable identity is a 401, never the system principal.
     *
     * @return array{0: int, 1: int}|null
     */
    private function identity(): ?array
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            return null;
        }
        $profileId = $claims['profile_id'] ?? null;
        $tenantId  = $claims['active_tenant_id'] ?? null;
        if (!is_int($profileId) || $profileId <= 0 || !is_int($tenantId) || $tenantId < 0) {
            return null;
        }
        return [$profileId, $tenantId];
    }
}
