<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Mcp\Auth\McpScope;

/**
 * What a token's scope grants (WHIT-695): capabilities open method families,
 * permission names narrow RBAC, and neither ever widens it.
 */
final class McpScopeTest extends TestCase
{
    // ── Capabilities ──────────────────────────────────────────────────────────

    /** @return iterable<string, array{list<string>, string, bool}> */
    public static function methods(): iterable
    {
        yield 'tools token lists tools'           => [['tools:call'], 'tools/list', true];
        yield 'tools token calls tools'           => [['tools:call'], 'tools/call', true];
        yield 'tools token cannot read resources' => [['tools:call'], 'resources/read', false];
        yield 'tools token cannot list resources' => [['tools:call'], 'resources/list', false];
        yield 'tools token cannot get prompts'    => [['tools:call'], 'prompts/get', false];
        yield 'resources token cannot call tools' => [['resources:read'], 'tools/call', false];
        yield 'resources token reads resources'   => [['resources:read'], 'resources/read', true];
        yield 'resources token lists templates'   => [['resources:read'], 'resources/templates/list', true];
        yield 'prompts token lists prompts'       => [['prompts:read'], 'prompts/list', true];
        yield 'any token may initialize'          => [['prompts:read'], 'initialize', true];
        yield 'any token may ping'                => [['resources:read'], 'ping', true];
        yield 'legacy tools:list means tools'     => [['tools:list'], 'tools/call', true];
        yield 'legacy prompts:list means prompts' => [['prompts:list'], 'prompts/get', true];
        yield 'legacy prompts:list is not tools'  => [['prompts:list'], 'tools/list', false];
        yield 'any tools: verb means tools'       => [['tools:get'], 'tools/call', true];
        yield 'any resources: verb means reads'   => [['resources:list'], 'resources/read', true];
        yield 'no capability keeps every family'  => [['users:read'], 'resources/read', true];
        yield 'empty scope keeps every family'    => [[], 'tools/call', true];
    }

    /** @param list<string> $scope */
    #[DataProvider('methods')]
    public function testAllowsMethod(array $scope, string $method, bool $allowed): void
    {
        self::assertSame($allowed, (new McpScope($scope))->allowsMethod($method));
    }

    // ── Permission narrowing ──────────────────────────────────────────────────

    /** @return iterable<string, array{list<string>, ?string, bool}> */
    public static function permissions(): iterable
    {
        yield 'no narrowing reaches gated'          => [['tools:call'], 'users:delete', true];
        yield 'no narrowing reaches ungated'        => [['tools:call'], null, true];
        yield 'session capabilities do not narrow'  => [McpScope::CAPABILITIES, 'users:delete', true];
        yield 'legacy aliases do not narrow'        => [['tools:list', 'prompts:list'], 'users:delete', true];
        yield 'any family verb does not narrow'     => [['prompts:get', 'resources:list'], 'users:delete', true];
        yield 'named permission is reachable'       => [['tools:call', 'users:read'], 'users:read', true];
        yield 'other permission is not'             => [['tools:call', 'users:read'], 'users:delete', false];
        yield 'narrowed token loses ungated'        => [['tools:call', 'users:read'], null, false];
        yield 'narrowed token loses empty gate'     => [['tools:call', 'users:read'], '', false];
        yield 'resource wildcard matches'           => [['tools:call', 'users:*'], 'users:delete', true];
        yield 'resource wildcard stays in resource' => [['tools:call', 'users:*'], 'roles:read', false];
        yield 'wildcard needs the colon boundary'   => [['tools:call', 'user:*'], 'users:read', false];
    }

    /** @param list<string> $scope */
    #[DataProvider('permissions')]
    public function testAllowsPermission(array $scope, ?string $required, bool $allowed): void
    {
        self::assertSame($allowed, (new McpScope($scope))->allowsPermission($required));
    }

    // ── Issuance ──────────────────────────────────────────────────────────────

    private const KNOWN = ['users:read', 'users:write', 'roles:read'];

    public function testIssuanceAcceptsCapabilitiesAndKnownPermissions(): void
    {
        self::assertSame([], McpScope::issuanceErrors(['tools:call'], self::KNOWN));
        self::assertSame([], McpScope::issuanceErrors(['tools:call', 'resources:read', 'prompts:read'], self::KNOWN));
        self::assertSame([], McpScope::issuanceErrors(['tools:call', 'users:read', 'roles:*'], self::KNOWN));
    }

    public function testIssuanceRequiresACapability(): void
    {
        $errors = McpScope::issuanceErrors(['users:read'], self::KNOWN);

        self::assertCount(1, $errors);
        self::assertStringContainsString('at least one of: tools:call, resources:read, prompts:read', $errors[0]);
    }

    public function testIssuanceRejectsUnknownNames(): void
    {
        self::assertSame(
            ['unknown scope entry: users:raed', 'unknown permission resource: widgets:*', 'unknown permission resource: :*'],
            McpScope::issuanceErrors(['tools:call', 'users:raed', 'widgets:*', ':*'], self::KNOWN),
        );
    }

    public function testIssuanceAcceptsLegacyAliasesAsCapabilities(): void
    {
        self::assertSame([], McpScope::issuanceErrors(['tools:list'], self::KNOWN));
    }
}
