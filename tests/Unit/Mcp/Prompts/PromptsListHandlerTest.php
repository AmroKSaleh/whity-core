<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Prompts;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptArgument;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Prompts\PromptsListHandler;

/**
 * TDD tests for PromptsListHandler (WC-7755fc38).
 *
 * Verifies permission-aware filtering: open prompts are returned to everyone;
 * role/permission-protected prompts are filtered by the caller's grants.
 * Unauthenticated callers receive only open prompts (soft-auth, no exception).
 */
final class PromptsListHandlerTest extends TestCase
{
    private const BEARER    = 'test.bearer.token';
    private const USER_ID   = 5;
    private const TENANT_ID = 2;

    private PromptRegistry $registry;
    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    private PromptsListHandler $handler;

    protected function setUp(): void
    {
        $this->registry       = new PromptRegistry();
        $this->roleChecker    = $this->createMock(RoleChecker::class);
        $this->tokenValidator = $this->createMock(TokenValidator::class);

        $principal = new McpPrincipal(self::USER_ID, self::TENANT_ID, 'user', ['prompts:list'], 'jti-list');
        $this->tokenValidator->method('validateMcpToken')->willReturn($principal);

        $this->handler = new PromptsListHandler(
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

    // ── Response structure ────────────────────────────────────────────────────

    public function testInvoke_returnsPromptsKey_inResponse(): void
    {
        $result = ($this->handler)(null, self::BEARER);

        self::assertIsArray($result);
        self::assertArrayHasKey('prompts', $result);
        self::assertIsArray($result['prompts']);
    }

    public function testInvoke_returnsEmptyList_whenNoPromptsRegistered(): void
    {
        $result = ($this->handler)(null, self::BEARER);

        self::assertSame([], $result['prompts']);
    }

    // ── Open prompts (no RBAC) ────────────────────────────────────────────────

    public function testInvoke_includesOpenPrompt_forAuthenticatedCaller(): void
    {
        $this->registry->register(new Prompt('open-prompt', 'An open prompt'));

        $result = ($this->handler)(null, self::BEARER);

        self::assertCount(1, $result['prompts']);
        self::assertSame('open-prompt', $result['prompts'][0]['name']);
    }

    public function testInvoke_includesOpenPrompt_forUnauthenticatedCaller(): void
    {
        $this->registry->register(new Prompt('open-prompt', 'An open prompt'));

        $result = ($this->handler)(null, null); // no bearer token

        self::assertCount(1, $result['prompts']);
    }

    public function testInvoke_includesOpenPrompt_whenBearerTokenInvalid(): void
    {
        $this->tokenValidator = $this->createMock(TokenValidator::class);
        $this->tokenValidator->method('validateMcpToken')->willReturn(null);
        $this->handler = new PromptsListHandler($this->registry, $this->roleChecker, $this->tokenValidator);

        $this->registry->register(new Prompt('open-prompt', 'An open prompt'));

        $result = ($this->handler)(null, 'invalid-token');

        self::assertCount(1, $result['prompts']);
    }

    // ── Role-protected prompts ────────────────────────────────────────────────

    public function testInvoke_excludesRoleProtectedPrompt_whenRoleNotGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(false);
        $this->registry->register(new Prompt('admin-prompt', 'Admin only', requiredRole: 'admin'));

        $result = ($this->handler)(null, self::BEARER);

        self::assertSame([], $result['prompts']);
    }

    public function testInvoke_includesRoleProtectedPrompt_whenRoleGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(true);
        $this->registry->register(new Prompt('admin-prompt', 'Admin only', requiredRole: 'admin'));

        $result = ($this->handler)(null, self::BEARER);

        self::assertCount(1, $result['prompts']);
        self::assertSame('admin-prompt', $result['prompts'][0]['name']);
    }

    public function testInvoke_excludesRoleProtectedPrompt_forUnauthenticatedCaller(): void
    {
        $this->registry->register(new Prompt('admin-prompt', 'Admin only', requiredRole: 'admin'));

        $result = ($this->handler)(null, null);

        self::assertSame([], $result['prompts']);
    }

    // ── Permission-protected prompts ──────────────────────────────────────────

    public function testInvoke_excludesPermissionProtectedPrompt_whenPermissionNotGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(false);
        $this->registry->register(new Prompt('perm-prompt', 'Needs perm', requiredPermission: 'things:read'));

        $result = ($this->handler)(null, self::BEARER);

        self::assertSame([], $result['prompts']);
    }

    public function testInvoke_includesPermissionProtectedPrompt_whenPermissionGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);
        $this->registry->register(new Prompt('perm-prompt', 'Needs perm', requiredPermission: 'things:read'));

        $result = ($this->handler)(null, self::BEARER);

        self::assertCount(1, $result['prompts']);
    }

    // ── Mixed open + protected ────────────────────────────────────────────────

    public function testInvoke_returnsOnlyOpenPrompts_forUnauthenticatedCaller_whenMixed(): void
    {
        $this->registry->register(new Prompt('open', 'Open'));
        $this->registry->register(new Prompt('admin', 'Admin', requiredRole: 'admin'));
        $this->registry->register(new Prompt('perm', 'Perm', requiredPermission: 'x:read'));

        $result = ($this->handler)(null, null);

        self::assertCount(1, $result['prompts']);
        self::assertSame('open', $result['prompts'][0]['name']);
    }

    // ── Prompt structure ──────────────────────────────────────────────────────

    public function testInvoke_includesNameDescriptionArguments_inPromptEntry(): void
    {
        $this->registry->register(new Prompt(
            name: 'my-prompt',
            description: 'Does something',
            arguments: [
                new PromptArgument('user_id', 'The user ID', true),
                new PromptArgument('format', 'Output format', false),
            ],
        ));

        $result  = ($this->handler)(null, self::BEARER);
        $entry   = $result['prompts'][0];

        self::assertSame('my-prompt', $entry['name']);
        self::assertSame('Does something', $entry['description']);
        self::assertCount(2, $entry['arguments']);
        self::assertSame('user_id', $entry['arguments'][0]['name']);
        self::assertSame('The user ID', $entry['arguments'][0]['description']);
        self::assertTrue($entry['arguments'][0]['required']);
        self::assertFalse($entry['arguments'][1]['required']);
    }

    public function testInvoke_returnsEmptyArguments_whenPromptHasNone(): void
    {
        $this->registry->register(new Prompt('simple', 'Simple prompt'));

        $result = ($this->handler)(null, self::BEARER);

        self::assertSame([], $result['prompts'][0]['arguments']);
    }
}
