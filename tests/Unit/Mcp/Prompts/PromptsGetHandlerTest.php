<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Prompts;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptArgument;
use Whity\Mcp\Prompts\PromptMessage;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Prompts\PromptsGetHandler;

/**
 * TDD tests for PromptsGetHandler (WC-7755fc38).
 *
 * Verifies param validation, RBAC enforcement, argument substitution,
 * and MCP messages response format.
 */
final class PromptsGetHandlerTest extends TestCase
{
    private const BEARER    = 'test.bearer.token';
    private const USER_ID   = 5;
    private const TENANT_ID = 2;

    private PromptRegistry $registry;
    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    private PromptsGetHandler $handler;

    protected function setUp(): void
    {
        $this->registry       = new PromptRegistry();
        $this->roleChecker    = $this->createMock(RoleChecker::class);
        $this->tokenValidator = $this->createMock(TokenValidator::class);

        $principal = new McpPrincipal(self::USER_ID, self::TENANT_ID, 'user', ['prompts:get'], 'jti-get');
        $this->tokenValidator->method('validateMcpToken')->willReturn($principal);

        $this->handler = new PromptsGetHandler(
            $this->registry,
            $this->roleChecker,
            $this->tokenValidator,
        );

        TenantContext::setTenantId(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── Param validation ──────────────────────────────────────────────────────

    public function testInvoke_throwsInvalidParams_whenParamsNull(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(null, self::BEARER);
    }

    public function testInvoke_throwsInvalidParams_whenNameKeyMissing(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)([], self::BEARER);
    }

    public function testInvoke_throwsInvalidParams_whenNameIsEmpty(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['name' => ''], self::BEARER);
    }

    // ── Prompt lookup ─────────────────────────────────────────────────────────

    public function testInvoke_throwsMethodNotFound_forUnknownPromptName(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::METHOD_NOT_FOUND);

        ($this->handler)(['name' => 'nonexistent'], self::BEARER);
    }

    // ── RBAC enforcement ──────────────────────────────────────────────────────

    public function testInvoke_throwsUnauthenticated_whenBearerMissing_forProtectedPrompt(): void
    {
        $this->registry->register(new Prompt('admin-prompt', 'Admin', requiredRole: 'admin'));

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::UNAUTHENTICATED);

        ($this->handler)(['name' => 'admin-prompt'], null);
    }

    public function testInvoke_throwsForbidden_whenRoleNotGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(false);
        $this->registry->register(new Prompt('admin-prompt', 'Admin', requiredRole: 'admin'));

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['name' => 'admin-prompt'], self::BEARER);
    }

    public function testInvoke_throwsForbidden_whenPermissionNotGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(false);
        $this->registry->register(new Prompt('perm-prompt', 'Perm', requiredPermission: 'things:read'));

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['name' => 'perm-prompt'], self::BEARER);
    }

    public function testInvoke_doesNotCallRoleChecker_forOpenPrompt(): void
    {
        $this->roleChecker->expects($this->never())->method('hasRole');
        $this->roleChecker->expects($this->never())->method('hasPermission');

        $this->registry->register(new Prompt(
            name: 'open-prompt',
            description: 'Open',
            messages: [new PromptMessage('user', 'Hello')],
        ));

        ($this->handler)(['name' => 'open-prompt'], self::BEARER);
    }

    public function testInvoke_succeedsForOpenPrompt_withoutBearerToken(): void
    {
        $this->registry->register(new Prompt(
            name: 'open-prompt',
            description: 'Open',
            messages: [new PromptMessage('user', 'Hello')],
        ));

        $result = ($this->handler)(['name' => 'open-prompt'], null);

        self::assertArrayHasKey('messages', $result);
    }

    // ── Argument validation ───────────────────────────────────────────────────

    public function testInvoke_throwsInvalidParams_whenRequiredArgumentMissing(): void
    {
        $this->registry->register(new Prompt(
            name: 'needs-user',
            description: 'Needs user_id',
            arguments: [new PromptArgument('user_id', 'User ID', true)],
            messages: [new PromptMessage('user', 'Check {{user_id}}')],
        ));

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['name' => 'needs-user'], self::BEARER);
    }

    public function testInvoke_passesThrough_whenOptionalArgumentMissing(): void
    {
        $this->registry->register(new Prompt(
            name: 'optional-arg',
            description: 'Optional arg prompt',
            arguments: [new PromptArgument('role_name', 'Role', false)],
            messages: [new PromptMessage('user', 'Audit roles. Focus: {{role_name}}')],
        ));

        $result = ($this->handler)(['name' => 'optional-arg'], self::BEARER);

        self::assertArrayHasKey('messages', $result);
    }

    // ── Argument substitution ─────────────────────────────────────────────────

    public function testInvoke_substitutesArguments_inMessageText(): void
    {
        $this->registry->register(new Prompt(
            name: 'greet',
            description: 'Greeting',
            arguments: [new PromptArgument('name', 'Name', true)],
            messages: [new PromptMessage('user', 'Hello, {{name}}!')],
        ));

        $result = ($this->handler)(['name' => 'greet', 'arguments' => ['name' => 'Alice']], self::BEARER);

        self::assertSame('Hello, Alice!', $result['messages'][0]['content']['text']);
    }

    public function testInvoke_substitutesMultipleArguments(): void
    {
        $this->registry->register(new Prompt(
            name: 'multi',
            description: 'Multi-arg',
            arguments: [
                new PromptArgument('first', 'First', true),
                new PromptArgument('last', 'Last', true),
            ],
            messages: [new PromptMessage('user', '{{first}} {{last}}')],
        ));

        $result = ($this->handler)([
            'name'      => 'multi',
            'arguments' => ['first' => 'John', 'last' => 'Doe'],
        ], self::BEARER);

        self::assertSame('John Doe', $result['messages'][0]['content']['text']);
    }

    public function testInvoke_leavesUnknownPlaceholders_unchanged_whenArgNotProvided(): void
    {
        $this->registry->register(new Prompt(
            name: 'optional',
            description: 'Optional',
            arguments: [new PromptArgument('thing', 'Thing', false)],
            messages: [new PromptMessage('user', 'Thing: {{thing}} done.')],
        ));

        $result = ($this->handler)(['name' => 'optional', 'arguments' => []], self::BEARER);

        self::assertStringContainsString('{{thing}}', $result['messages'][0]['content']['text']);
    }

    // ── Response format ───────────────────────────────────────────────────────

    public function testInvoke_returnsMessagesWithRoleAndContent(): void
    {
        $this->registry->register(new Prompt(
            name: 'chat',
            description: 'Chat',
            messages: [
                new PromptMessage('user', 'User says this'),
                new PromptMessage('assistant', 'Assistant replies'),
            ],
        ));

        $result   = ($this->handler)(['name' => 'chat'], self::BEARER);
        $messages = $result['messages'];

        self::assertCount(2, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertSame(['type' => 'text', 'text' => 'User says this'], $messages[0]['content']);
        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame(['type' => 'text', 'text' => 'Assistant replies'], $messages[1]['content']);
    }

    public function testInvoke_includesDescription_inResult(): void
    {
        $this->registry->register(new Prompt(
            name: 'described',
            description: 'Has a description',
            messages: [new PromptMessage('user', 'Hi')],
        ));

        $result = ($this->handler)(['name' => 'described'], self::BEARER);

        self::assertSame('Has a description', $result['description']);
    }

    public function testInvoke_succeedsWithRoleGranted_forRoleProtectedPrompt(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(true);
        $this->registry->register(new Prompt(
            name: 'admin-prompt',
            description: 'Admin',
            messages: [new PromptMessage('user', 'Admin task')],
            requiredRole: 'admin',
        ));

        $result = ($this->handler)(['name' => 'admin-prompt'], self::BEARER);

        self::assertArrayHasKey('messages', $result);
    }
}
