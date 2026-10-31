<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * Budgets on core's MCP surface (WHIT-751, docs/design/MCP-TOOL-SURFACE.md §5.6).
 *
 * What a model reads before it does anything is a cost every client pays on
 * every connection, so it is held to numbers here rather than left to drift.
 * Each failure names the operation and the budget it breaks; the fix is to
 * shorten the text (or split the schema), not to raise the number.
 *
 * Measured on 2026-10-31: 313 exposed tools, longest name 55 characters,
 * no description over 500, median tool 400 bytes, largest 1,923.
 */
final class McpToolBudgetTest extends TestCase
{
    /** Several model APIs accept only these characters in a function name, and some cap it at 64. */
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private const MAX_DESCRIPTION_CHARS = 500;
    private const MAX_INPUT_SCHEMA_BYTES = 8192;

    /** The five always-on tools together; one of them is a list with filters. */
    private const MAX_ALWAYS_ON_BYTES = 4096;

    /** The discovery tools' own definitions. */
    private const MAX_DISCOVERY_BYTES = 2048;

    /** Everything a bare connection lists before plugins: always-on plus discovery. */
    private const MAX_DEFAULT_LIST_BYTES = 10240;

    /** @var list<array<string, mixed>> */
    private array $exposed = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null);
        foreach ($deriver->deriveTools() as $tool) {
            if ($deriver->isExposed((string) $tool['name'])) {
                $this->exposed[] = $tool;
            }
        }
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    public function testNamesAreSafeForEveryModelApi(): void
    {
        $bad = [];
        foreach ($this->exposed as $tool) {
            if (preg_match(self::NAME_PATTERN, (string) $tool['name']) !== 1) {
                $bad[] = $tool['name'];
            }
        }
        self::assertSame([], $bad, 'Tool names must match ' . self::NAME_PATTERN . ' (set an operationId)');
    }

    public function testDescriptionsFitTheBudget(): void
    {
        $over = [];
        foreach ($this->exposed as $tool) {
            $length = mb_strlen((string) ($tool['description'] ?? ''));
            if ($length > self::MAX_DESCRIPTION_CHARS) {
                $over[] = "{$tool['name']} ({$length} characters)";
            }
        }
        self::assertSame([], $over, 'Descriptions over ' . self::MAX_DESCRIPTION_CHARS . ' characters');
    }

    public function testInputSchemasFitTheBudget(): void
    {
        $over = [];
        foreach ($this->exposed as $tool) {
            $bytes = strlen((string) json_encode($tool['inputSchema'] ?? []));
            if ($bytes > self::MAX_INPUT_SCHEMA_BYTES) {
                $over[] = "{$tool['name']} ({$bytes} bytes)";
            }
        }
        self::assertSame([], $over, 'inputSchema over ' . self::MAX_INPUT_SCHEMA_BYTES . ' bytes');
    }

    public function testTheAlwaysOnSetIsFiveSmallReadOnlyTools(): void
    {
        $byName = [];
        foreach ($this->exposed as $tool) {
            $byName[(string) $tool['name']] = $tool;
        }

        $alwaysOn = [];
        foreach (CoreToolsets::ALWAYS_ON as $name) {
            self::assertArrayHasKey($name, $byName, "always-on {$name} is not an exposed core operation");
            self::assertTrue($byName[$name]['annotations']['readOnlyHint'] ?? false, "always-on {$name} must only read");
            $alwaysOn[] = $byName[$name];
        }

        // Four derived operations plus init_session (WHIT-794): five always-on.
        self::assertCount(4, $alwaysOn);
        $alwaysOn[] = DiscoveryTools::initDefinition();
        $bytes = strlen((string) json_encode($alwaysOn));
        self::assertLessThanOrEqual(self::MAX_ALWAYS_ON_BYTES, $bytes, "The always-on tools are {$bytes} bytes");
    }

    public function testTheDefaultListStaysSmall(): void
    {
        $discovery = strlen((string) json_encode(DiscoveryTools::definitions()));
        self::assertLessThanOrEqual(self::MAX_DISCOVERY_BYTES, $discovery, "The discovery tools are {$discovery} bytes");

        $alwaysOn = array_values(array_filter($this->exposed, static fn (array $t): bool => CoreToolsets::isAlwaysOn((string) $t['name'])));
        $total    = strlen((string) json_encode([DiscoveryTools::initDefinition(), ...$alwaysOn, ...DiscoveryTools::definitions()]));
        self::assertLessThanOrEqual(self::MAX_DEFAULT_LIST_BYTES, $total, "A bare connection's list is {$total} bytes");
    }
}
