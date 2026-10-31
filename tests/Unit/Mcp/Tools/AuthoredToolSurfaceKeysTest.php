<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Tools\AuthoredToolRegistry;

/**
 * SDK 1.49 descriptor keys and budgets for authored MCP tools (WHIT-752,
 * docs/design/MCP-TOOL-SURFACE.md §5.7).
 */
final class AuthoredToolSurfaceKeysTest extends TestCase
{
    private const PLUGIN = 'Plugins\\PeriodClose\\PeriodClosePlugin';

    public function testDefaultsAreTheRootToolsetDiscoverableAndAllow(): void
    {
        $registry = new AuthoredToolRegistry();
        self::assertNull($registry->register(self::tool('period_close_list'), self::PLUGIN));

        self::assertSame([
            'dev.whity/toolset'     => 'period-close',
            'dev.whity/visibility'  => 'discoverable',
            'dev.whity/agentPolicy' => 'allow',
        ], $registry->toolObjects()[0]['_meta']);
        self::assertSame([], $registry->drainWarnings());
    }

    public function testDeclaredKeysAreKeptAndToolsetsAreRelativeOrAbsolute(): void
    {
        $registry = new AuthoredToolRegistry();
        self::assertNull($registry->register(self::tool('period_close_lock', ['toolset' => 'locks', 'agentPolicy' => 'propose', 'undo' => ['windowSeconds' => 1800, 'operation' => 'period_close_unlock']]), self::PLUGIN));
        self::assertNull($registry->register(self::tool('period_close_unlock', ['toolset' => 'period-close.locks']), self::PLUGIN));

        $meta = array_column($registry->toolObjects(), '_meta', 'name');
        self::assertSame('period-close.locks', $meta['period_close_lock']['dev.whity/toolset']);
        self::assertSame('propose', $meta['period_close_lock']['dev.whity/agentPolicy']);
        self::assertSame('period-close.locks', $meta['period_close_unlock']['dev.whity/toolset']);
        self::assertSame(['windowSeconds' => 1800, 'operation' => 'period_close_unlock', 'handler' => null], $registry->get('period_close_lock')['undo'] ?? null);
    }

    public function testRefusals(): void
    {
        $cases = [
            'a toolset outside the plugin' => self::tool('period_close_a', ['toolset' => 'core.documents']),
            'another plugin\'s toolset'    => self::tool('period_close_b', ['toolset' => 'acme.things']),
            'an unknown visibility'        => self::tool('period_close_c', ['visibility' => 'default']),
            'an unknown agent policy'      => self::tool('period_close_d', ['agentPolicy' => 'maybe']),
            'a malformed undo'             => self::tool('period_close_e', ['undo' => ['windowSeconds' => 0, 'operation' => 'x']]),
            'an undo with no way back'     => self::tool('period_close_f', ['undo' => ['windowSeconds' => 60]]),
            'a dot in the name'            => self::tool('period_close.g'),
            'a name over 64 characters'    => self::tool('period_close_' . str_repeat('x', 60)),
            'a long description'           => self::tool('period_close_h', ['description' => str_repeat('a', 501)]),
            'a writing always-on tool'     => self::tool('period_close_i', ['visibility' => 'always-on']),
        ];
        foreach ($cases as $case => $descriptor) {
            self::assertNotNull((new AuthoredToolRegistry())->register($descriptor, self::PLUGIN), "accepted {$case}");
        }
    }

    public function testOneAlwaysOnToolPerPluginTheRestAreDemoted(): void
    {
        $registry = new AuthoredToolRegistry();
        $reading  = ['visibility' => 'always-on', 'annotations' => ['readOnlyHint' => true]];
        self::assertNull($registry->register(self::tool('period_close_status', $reading), self::PLUGIN));
        self::assertNull($registry->register(self::tool('period_close_summary', $reading), self::PLUGIN));

        $meta = array_column($registry->toolObjects(), '_meta', 'name');
        self::assertSame('always-on', $meta['period_close_status']['dev.whity/visibility']);
        self::assertSame('discoverable', $meta['period_close_summary']['dev.whity/visibility']);
        self::assertCount(1, $registry->drainWarnings());
        self::assertSame([], $registry->drainWarnings(), 'warnings are taken once');
    }

    public function testUnprefixedNamesLoadWithADeprecationWarning(): void
    {
        $registry = new AuthoredToolRegistry();
        self::assertNull($registry->register(self::tool('lock_period'), self::PLUGIN));

        $warnings = $registry->drainWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString("start with 'period_close_'", $warnings[0]);
    }

    public function testToolsetDeclarationsArePrefixedAndChecked(): void
    {
        $registry = new AuthoredToolRegistry();
        $refused  = $registry->registerToolsets([
            ['id' => '', 'title' => 'Period close', 'titleAr' => 'إقفال الفترة', 'description' => 'Close accounting periods and lock them.'],
            ['id' => 'locks', 'title' => 'Locks', 'description' => 'Lock and unlock periods.', 'split' => true, 'requiresEntitlement' => 'accounting.close'],
            ['id' => 'Bad Id', 'title' => 'x', 'description' => 'x'],
            ['id' => 'nodesc', 'title' => 'No description'],
            'not an array',
        ], self::PLUGIN);

        self::assertCount(3, $refused);
        $toolsets = $registry->toolsets();
        self::assertSame(['period-close', 'period-close.locks'], array_keys($toolsets));
        self::assertTrue($toolsets['period-close']['split']);
        self::assertSame('Locks', $toolsets['period-close.locks']['titleAr'], 'a missing Arabic title falls back to the English');
        self::assertSame('accounting.close', $toolsets['period-close.locks']['requiresEntitlement']);

        $registry->reset();
        self::assertSame([], $registry->toolsets());
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function tool(string $name, array $extra = []): array
    {
        return $extra + [
            'name'               => $name,
            'description'        => 'Does a period-close thing.',
            'inputSchema'        => ['type' => 'object'],
            'handler'            => static fn (): array => [],
            'requiredPermission' => 'periods:manage',
        ];
    }
}
