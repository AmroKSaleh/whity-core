<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Tools\AuthoredToolRegistry;

/**
 * Hand-authored MCP tools contributed by plugins (SDK 1.43, WC-adoption 6.4).
 *
 * The registry is where a plugin's descriptor stops being plugin data and
 * becomes something the dispatcher will call, so everything it refuses is a
 * thing that would otherwise surface on a live request instead of at load.
 *
 * The rule these tests exist for is FAIL CLOSED on audience. A derived tool
 * inherits its route's RBAC gate, and a route with no permission is visibly
 * open — in the route table and in the route-catalogue CI check. An authored
 * tool has no route, so an omitted permission is visible NOWHERE: callable by
 * every authenticated principal with nothing saying so.
 */
final class AuthoredToolRegistryTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function descriptor(array $over = []): array
    {
        return array_merge([
            'name'               => 'close-period',
            'description'        => 'Close the open period and report what it refused.',
            'inputSchema'        => ['type' => 'object', 'properties' => []],
            'handler'            => static fn (array $args): array => ['ok' => true],
            'requiredPermission' => 'periods:close',
        ], $over);
    }

    public function testAWellFormedToolRegisters(): void
    {
        $registry = new AuthoredToolRegistry();

        self::assertNull($registry->register(self::descriptor(), 'Periods'));
        self::assertTrue($registry->has('close-period'));
    }

    // ---- fail closed on audience ----

    public function testAToolDeclaringNoAudienceIsRefused(): void
    {
        $registry = new AuthoredToolRegistry();

        $reason = $registry->register(
            self::descriptor(['requiredPermission' => null]),
            'Periods'
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('requiredRole', $reason);
        self::assertStringContainsString('open: true', $reason);
        self::assertFalse($registry->has('close-period'), 'a tool with no declared audience must not be callable');
    }

    /**
     * The escape has to exist. Without it an author who genuinely wants a
     * public tool mints a dummy permission to satisfy the rule — and a
     * permission that exists only to be granted to everybody is a lie the
     * catalogue then carries forever.
     */
    public function testAToolMayDeclareItselfOpenDeliberately(): void
    {
        $registry = new AuthoredToolRegistry();

        $reason = $registry->register(
            self::descriptor(['requiredPermission' => null, 'open' => true]),
            'Periods'
        );

        self::assertNull($reason);
        self::assertSame(
            ['requiredRole' => null, 'requiredPermission' => null],
            $registry->accessMap()['close-period']
        );
    }

    /** `open` must be the boolean true — not a truthy string a template produced. */
    public function testATruthyOpenValueDoesNotCountAsDeclaringOpen(): void
    {
        $registry = new AuthoredToolRegistry();

        $reason = $registry->register(
            self::descriptor(['requiredPermission' => null, 'open' => 'yes']),
            'Periods'
        );

        self::assertNotNull($reason);
        self::assertFalse($registry->has('close-period'));
    }

    public function testARoleAloneIsEnoughToDeclareAnAudience(): void
    {
        $registry = new AuthoredToolRegistry();

        self::assertNull($registry->register(
            self::descriptor(['requiredPermission' => null, 'requiredRole' => 'auditor']),
            'Periods'
        ));
    }

    // ---- shape ----

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformed(): iterable
    {
        yield 'no name'            => [['name' => ''], 'name'];
        yield 'no description'     => [['description' => ''], 'description'];
        yield 'schema not array'   => [['inputSchema' => 'object'], 'inputSchema'];
        yield 'handler not callable' => [['handler' => 'not-a-function'], 'handler'];
    }

    /**
     * @dataProvider malformed
     * @param array<string, mixed> $over
     */
    public function testAMalformedDescriptorIsRefusedWithASpecificReason(array $over, string $expected): void
    {
        $registry = new AuthoredToolRegistry();

        $reason = $registry->register(self::descriptor($over), 'Periods');

        self::assertNotNull($reason);
        self::assertStringContainsString($expected, $reason);
    }

    /**
     * `handler` is the one field whose validity cannot wait. The others produce
     * a bad answer; a non-callable handler produces a crash inside the
     * dispatcher, on a request, long after the plugin that shipped it loaded.
     */
    public function testANonCallableHandlerIsRefusedAtRegistrationNotAtCallTime(): void
    {
        $registry = new AuthoredToolRegistry();

        $registry->register(self::descriptor(['handler' => ['NotAClass', 'nope']]), 'Periods');

        self::assertFalse($registry->has('close-period'));
    }

    // ---- collisions ----

    public function testTheFirstRegistrationOfANameWins(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::descriptor(['description' => 'First.']), 'Periods');

        $reason = $registry->register(self::descriptor(['description' => 'Second.']), 'Ledger');

        self::assertNotNull($reason);
        self::assertStringContainsString('Periods', $reason, 'the reason must name the plugin that already holds it');
        self::assertSame('First.', $registry->toolObjects()[0]['description']);
    }

    /**
     * A DERIVED tool wins against an authored one, and the authored one is
     * dropped rather than shadowing it.
     *
     * A derived name is already published twice — in the OpenAPI document and
     * in the generated typed clients — so an authored tool quietly taking it
     * would leave two descriptions of one name disagreeing, with nothing
     * reporting the divergence.
     */
    public function testADerivedToolTakesTheNameBackFromAnAuthoredOne(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::descriptor(), 'Periods');
        $registry->register(self::descriptor(['name' => 'summarise-ledger']), 'Periods');

        $dropped = $registry->dropCollisionsWith(['close-period', 'unrelated-derived-tool']);

        self::assertSame(['close-period'], $dropped);
        self::assertFalse($registry->has('close-period'));
        self::assertTrue($registry->has('summarise-ledger'), 'a non-colliding authored tool must survive');
    }

    // ---- what leaves the registry ----

    /**
     * The handler must never appear in a tool object. It is not JSON-encodable,
     * and it has no business crossing the wire even if it were.
     */
    public function testToolObjectsCarrySchemaAndCopyButNeverTheHandler(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::descriptor(), 'Periods');

        $object = $registry->toolObjects()[0];

        self::assertSame(['name', 'description', 'inputSchema'], array_keys($object));
    }

    public function testTheAccessMapMatchesTheShapeTheDeriverProduces(): void
    {
        $registry = new AuthoredToolRegistry();
        $registry->register(self::descriptor(['requiredRole' => 'auditor']), 'Periods');

        // Same keys the ToolDeriver's map uses, so the list and call handlers
        // can filter both kinds of tool through one code path.
        self::assertSame(
            ['requiredRole' => 'auditor', 'requiredPermission' => 'periods:close'],
            $registry->accessMap()['close-period']
        );
    }

    public function testSuppressionIsRecordedPerPlugin(): void
    {
        $registry = new AuthoredToolRegistry();

        $registry->suppressDerivationFor('Periods');
        $registry->suppressDerivationFor('Periods');

        self::assertSame(['Periods'], $registry->suppressedPlugins());
    }
}
