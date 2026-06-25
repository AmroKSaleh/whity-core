<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP prompts/list handler (WC-7755fc38).
 *
 * Returns the prompts the caller is allowed to see. Open prompts (no required
 * role/permission) are visible to everyone including unauthenticated callers.
 * Protected prompts are filtered out when the caller's token is absent or
 * invalid, or when RoleChecker denies the required grant.
 *
 * Filtering is soft-auth: a bad or missing token never throws — it simply
 * limits the visible set to open prompts. RBAC is still hard-enforced in
 * PromptsGetHandler when the caller tries to retrieve a protected prompt.
 */
final class PromptsListHandler implements MethodHandler
{
    public function __construct(
        private readonly PromptRegistry $registry,
        private readonly RoleChecker    $roleChecker,
        private readonly TokenValidator $tokenValidator,
    ) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $principal = $bearerToken !== null
            ? $this->tokenValidator->validateMcpToken($bearerToken)
            : null;
        $tenantId = TenantContext::getTenantId();

        $result = [];
        foreach ($this->registry->all() as $prompt) {
            if (!$this->callerCanSee($prompt, $principal, $tenantId)) {
                continue;
            }
            $result[] = [
                'name'        => $prompt->name,
                'description' => $prompt->description,
                'arguments'   => array_map(
                    static fn (PromptArgument $a): array => [
                        'name'        => $a->name,
                        'description' => $a->description,
                        'required'    => $a->required,
                    ],
                    $prompt->arguments,
                ),
            ];
        }

        return ['prompts' => $result];
    }

    private function callerCanSee(Prompt $prompt, ?McpPrincipal $principal, ?int $tenantId): bool
    {
        if ($prompt->isOpen()) {
            return true;
        }
        if ($principal === null || $tenantId === null) {
            return false;
        }
        if ($prompt->requiredPermission !== null) {
            return $this->roleChecker->hasPermission($principal->userId, $prompt->requiredPermission, $tenantId);
        }
        if ($prompt->requiredRole !== null) {
            return $this->roleChecker->hasRole($principal->userId, $prompt->requiredRole, $tenantId);
        }
        return true;
    }
}
