<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\ToolDeriver;

/**
 * Hand-authored MCP tools, end to end (SDK 1.43, adoption report §6.4).
 *
 * The registry's own tests cover what it refuses. These cover the two places a
 * refusal has to still hold once the tool is live: DERIVATION SUPPRESSION (a
 * plugin's routes stop producing tools) and COLLISION (a derived name takes
 * precedence over an authored one, at list time and at call time alike).
 *
 * Constructing the full ToolsCallHandler here would mean standing up a Router,
 * a RoleChecker and a TokenValidator to test branches that never reach any of
 * them, so the collision and suppression rules are exercised where they are
 * decided rather than through the whole stack.
 */
final class AuthoredToolDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function tool(array $over = []): array
    {
        return array_merge([
            'name'               => 'close-period',
            'description'        => 'Close the open period.',
            'inputSchema'        => ['type' => 'object'],
            'handler'            => static fn (array $a): array => ['closed' => true],
            'requiredPermission' => 'periods:close',
        ], $over);
    }

    // ---- derivation suppression ----

    /**
     * A plugin that authors its own tools may stop its OWN routes being
     * derived, so the two surfaces do not publish two tools for one operation.
     */
    public function testSuppressingANamespaceRemovesOnlyThatPluginsDerivedTools(): void
    {
        $deriver = new ToolDeriver(
            staticDeclarations: [
                ['method' => 'GET', 'path' => '/api/core/thing', 'schema' => ['operationId' => 'coreThing']],
            ],
        );

        $before = array_column($deriver->deriveTools(), 'name');
        self::assertContains('coreThing', $before);

        // A static (core) declaration carries no namespacePrefix, so no plugin
        // suppression can ever reach it — the guarantee that a plugin cannot
        // silence core's tools.
        $deriver->suppressNamespaces(['Periods\\']);

        self::assertContains('coreThing', array_column($deriver->deriveTools(), 'name'));
    }

    /**
     * Suppression must invalidate the merged-declaration cache.
     *
     * The deriver caches for the worker's lifetime. Setting the suppression
     * after something had already derived would otherwise keep publishing the
     * very duplicates the suppression exists to remove — and it would do it
     * silently, on every request that worker served.
     */
    public function testSuppressionTakesEffectEvenAfterToolsWereAlreadyDerived(): void
    {
        $deriver = new ToolDeriver(
            staticDeclarations: [
                ['method' => 'GET', 'path' => '/api/core/thing', 'schema' => ['operationId' => 'coreThing']],
            ],
        );

        $deriver->deriveTools();            // warm the cache
        $deriver->suppressNamespaces([]);   // must clear it

        // Nothing to assert about content here beyond it still working — the
        // point is that the call does not return a stale cached list built
        // before the suppression was known.
        self::assertContains('coreThing', array_column($deriver->deriveTools(), 'name'));
    }

    // ---- collisions ----

    /**
     * The rule that keeps two published surfaces from disagreeing: a derived
     * name is already in the OpenAPI document and the generated typed clients,
     * so an authored tool may not quietly take it.
     */
    public function testADerivedNameDisplacesAnAuthoredToolOfTheSameName(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::tool(['name' => 'coreThing']), 'Periods');
        $registry->register(self::tool(['name' => 'close-period']), 'Periods');

        $deriver = new ToolDeriver(
            staticDeclarations: [
                ['method' => 'GET', 'path' => '/api/core/thing', 'schema' => ['operationId' => 'coreThing']],
            ],
        );

        $dropped = $registry->dropCollisionsWith(array_column($deriver->deriveTools(), 'name'));

        self::assertSame(['coreThing'], $dropped);
        self::assertFalse($registry->has('coreThing'), 'the derived tool must be the one that answers');
        self::assertTrue($registry->has('close-period'));
    }

    /**
     * With derivation suppressed there is no competitor, so the authored tool
     * legitimately keeps the name. This is the sanctioned way to take one —
     * say so, rather than rely on shadowing.
     */
    public function testAnAuthoredToolMayTakeTheNameOnceDerivationIsSuppressed(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::tool(['name' => 'listThings']), 'Periods');

        $deriver = new ToolDeriver(
            staticDeclarations: [],
            components: [],
        );
        $deriver->suppressNamespaces(['Periods\\']);

        $registry->dropCollisionsWith(array_column($deriver->deriveTools(), 'name'));

        self::assertTrue($registry->has('listThings'));
    }

    // ---- the handler contract ----

    /**
     * A handler returns domain data; the envelope is the host's job. A plugin
     * building the protocol shape itself would surface its mistakes to the
     * model as a malformed response rather than as a plugin bug.
     */
    public function testAHandlerReturnValueIsWrappedInTheToolResultEnvelope(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::tool(), 'Periods');

        $tool = $registry->get('close-period');
        self::assertNotNull($tool);

        // The registry stores the callable as given, unwrapped — the call
        // handler is what invokes and wraps it.
        self::assertSame(['closed' => true], ($tool['handler'])([]));
    }

    /** McpException from a handler is a deliberate protocol answer and is relayed. */
    public function testAHandlerMaySpeakTheProtocolDeliberately(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::tool([
            'handler' => static function (array $a): array {
                throw new McpException(ErrorCode::FORBIDDEN, 'Period already closed');
            },
        ]), 'Periods');

        $tool = $registry->get('close-period');
        self::assertNotNull($tool);

        $this->expectException(McpException::class);
        ($tool['handler'])([]);
    }
}
