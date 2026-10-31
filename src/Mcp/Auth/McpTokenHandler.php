<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * HTTP handler for MCP token management endpoints (WC-2686308f).
 *
 *   POST   /api/mcp/tokens          — issue a new MCP token
 *   GET    /api/mcp/tokens          — list active tokens for the current profile
 *   DELETE /api/mcp/tokens/{jti}    — revoke a token
 *
 * All endpoints require a valid access token cookie (human user performing
 * the action). The issued MCP tokens are then used as Bearer tokens on
 * POST /mcp (machine-to-machine calls from AI clients).
 *
 * Post-cutover (step E): session tokens carry only profile_id / active_tenant_id.
 * The caller's profile_id and tenantId are resolved exclusively from those claims.
 */
final class McpTokenHandler
{
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly McpTokenService $mcpTokenService,
        // Validates permission entries in a requested scope (WHIT-695). Without
        // it no permission name is known, so only capability scopes are issued.
        private readonly ?PermissionRegistry $permissionRegistry = null,
    ) {}

    /**
     * POST /api/mcp/tokens
     *
     * Body: {"name": string, "scope": string[]}
     * Returns 201 with {jti, token, name, scope, expires_at} on success.
     */
    /** @param array<string, mixed> $params */
    public function create(Request $request, array $params = []): Response
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            return Response::error('Unauthenticated', 401);
        }

        $body = json_decode($request->getBody(), true);
        if (!is_array($body)) {
            return Response::error('Invalid JSON body', 400);
        }

        $name  = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $scope = $body['scope'] ?? null;

        if ($name === '' || strlen($name) > 255) {
            return Response::error('name is required and must not exceed 255 characters', 422);
        }

        if (!is_array($scope) || $scope === []) {
            return Response::error('scope must be a non-empty array of strings', 422);
        }

        foreach ($scope as $s) {
            if (!is_string($s) || $s === '') {
                return Response::error('each scope entry must be a non-empty string', 422);
            }
        }
        /** @var list<string> $scope */
        $scope = array_values(array_unique($scope));

        // WHIT-695: scopes are enforced now, so a typo is no longer harmless —
        // an unknown name would narrow the token to nothing, and a scope with
        // no capability would open every method family.
        $known  = $this->permissionRegistry !== null ? array_keys($this->permissionRegistry->getAll()) : [];
        $errors = McpScope::issuanceErrors($scope, $known);
        if ($errors !== []) {
            return Response::error(implode('; ', $errors), 422);
        }

        // WHIT-754: an agent credential, for an AI that works unattended for
        // this person. It acts under their RBAC, narrowed by its per-permission
        // grant (`grant:` entries), and its Ask-first calls become proposals
        // they approve. Without `agent`, the token is the person's own client.
        $agent = ($body['agent'] ?? false) === true;
        if (!$agent && array_filter($scope, static fn (string $s): bool => str_starts_with($s, AgentScopeGrant::PREFIX)) !== []) {
            return Response::error('grant: entries apply to agent credentials only; set agent: true', 422);
        }

        $identity = $this->requireProfileIdentity($claims);
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $identity;

        $token = $this->mcpTokenService->issue($profileId, $tenantId, $name, $scope, principalKind: $agent ? AgentScopeGrant::AGENT_KIND : 'user');
        ['jti' => $jti, 'exp' => $exp] = $this->extractClaims($token);

        return new Response(201, (string) json_encode([
            'jti'        => $jti,
            'token'      => $token,
            'name'       => $name,
            'scope'      => $scope,
            'agent'      => $agent,
            'expires_at' => date('c', (int) $exp),
        ], JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * GET /api/mcp/tokens
     *
     * Returns 200 with {"tokens": [...]} listing active tokens for the caller.
     *
     * @param array<string, mixed> $params
     */
    public function list(Request $request, array $params = []): Response
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            return Response::error('Unauthenticated', 401);
        }

        $identity = $this->requireProfileIdentity($claims);
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $identity;

        $tokens = $this->mcpTokenService->listForUser($profileId, $tenantId);

        return new Response(200, (string) json_encode(['tokens' => $tokens], JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * DELETE /api/mcp/tokens/{jti}
     *
     * Returns 204 on success, 404 if the JTI is unknown or belongs to another profile.
     *
     * @param array<string, mixed> $params
     */
    public function revoke(Request $request, array $params = []): Response
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            return Response::error('Unauthenticated', 401);
        }

        $jti = isset($params['jti']) && is_string($params['jti']) ? trim($params['jti']) : '';
        if ($jti === '') {
            return Response::error('jti is required', 400);
        }

        $identity = $this->requireProfileIdentity($claims);
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $identity;

        if (!$this->mcpTokenService->revoke($jti, $profileId, $tenantId)) {
            return Response::error('Token not found', 404);
        }

        return new Response(204, '', ['Content-Type' => 'application/json']);
    }

    /**
     * Resolve the caller's (profile_id, active_tenant_id) from validated claims.
     *
     * Fail closed (WC-idcut-E): post-cutover every session token carries a
     * positive-int profile_id and an int active_tenant_id. If either is absent or
     * the wrong type the caller has no usable identity — return null so the
     * endpoint answers 401 rather than defaulting to 0 (which would have keyed
     * MCP tokens to the system principal). This mirrors TokenValidator's own
     * positive-int profile_id gate as defense in depth.
     *
     * @param array<string, mixed> $claims Validated access-token claims.
     * @return array{0: int, 1: int}|null [profileId, tenantId], or null to reject.
     */
    private function requireProfileIdentity(array $claims): ?array
    {
        $profileId = $claims['profile_id'] ?? null;
        $tenantId  = $claims['active_tenant_id'] ?? null;

        if (!is_int($profileId) || $profileId <= 0 || !is_int($tenantId) || $tenantId < 0) {
            return null;
        }

        return [$profileId, $tenantId];
    }

    /**
     * Base64url-decode the JWT payload to extract jti and exp.
     *
     * @return array<string, mixed>
     */
    private function extractClaims(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['jti' => null, 'exp' => null];
        }
        $payload = json_decode(
            (string) base64_decode(strtr($parts[1], '-_', '+/')),
            true
        );
        return is_array($payload) ? $payload : ['jti' => null, 'exp' => null];
    }
}
