<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP prompts/get handler (WC-7755fc38).
 *
 * Retrieves a named prompt template, validates any required arguments, enforces
 * RBAC for protected prompts, substitutes {{arg_name}} placeholders with
 * caller-supplied values, and returns the rendered messages in MCP format.
 *
 * Open prompts (no requiredRole / requiredPermission) can be retrieved by any
 * caller including unauthenticated ones. Protected prompts throw UNAUTHENTICATED
 * when the bearer token is absent and FORBIDDEN when the caller lacks the grant.
 */
final class PromptsGetHandler implements MethodHandler
{
    public function __construct(
        private readonly PromptRegistry $registry,
        private readonly RoleChecker    $roleChecker,
        private readonly TokenValidator $tokenValidator,
    ) {}

    /**
     * @param array<string, mixed>|null $params
     * @throws McpException On validation failures, unknown prompt name, or RBAC denial.
     */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        // 1. Validate name parameter.
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new McpException(ErrorCode::INVALID_PARAMS, 'Missing required parameter: name');
        }

        // 2. Look up the prompt.
        $prompt = $this->registry->find($name);
        if ($prompt === null) {
            throw new McpException(ErrorCode::METHOD_NOT_FOUND, "Unknown prompt: {$name}");
        }

        // 3. Enforce RBAC for protected prompts.
        if (!$prompt->isOpen()) {
            $principal = $bearerToken !== null
                ? $this->tokenValidator->validateMcpToken($bearerToken)
                : null;
            if ($principal === null) {
                throw new McpException(ErrorCode::UNAUTHENTICATED, 'Unauthenticated');
            }
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                throw new McpException(ErrorCode::UNAUTHENTICATED, 'No tenant context');
            }
            if ($prompt->requiredPermission !== null) {
                if (!$this->roleChecker->hasPermission($principal->userId, $prompt->requiredPermission, $tenantId)) {
                    throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
                }
            } elseif ($prompt->requiredRole !== null) {
                if (!$this->roleChecker->hasRole($principal->userId, $prompt->requiredRole, $tenantId)) {
                    throw new McpException(ErrorCode::FORBIDDEN, 'Forbidden');
                }
            }
        }

        // 4. Collect and validate arguments.
        /** @var array<string, string> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        foreach ($prompt->arguments as $arg) {
            if ($arg->required && !array_key_exists($arg->name, $arguments)) {
                throw new McpException(
                    ErrorCode::INVALID_PARAMS,
                    "Missing required argument: {$arg->name}",
                );
            }
        }

        // 5. Render messages with argument substitution.
        $messages = [];
        foreach ($prompt->messages as $message) {
            $text       = $this->substituteArguments($message->content, $arguments);
            $messages[] = [
                'role'    => $message->role,
                'content' => ['type' => 'text', 'text' => $text],
            ];
        }

        return [
            'description' => $prompt->description,
            'messages'    => $messages,
        ];
    }

    /**
     * Replace {{arg_name}} placeholders with provided argument values.
     * Unmatched placeholders (optional args not supplied) are left unchanged.
     *
     * @param array<string, string> $arguments
     */
    private function substituteArguments(string $template, array $arguments): string
    {
        return (string) preg_replace_callback(
            '#\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}#',
            static fn (array $m): string => $arguments[$m[1]] ?? $m[0],
            $template,
        );
    }
}
