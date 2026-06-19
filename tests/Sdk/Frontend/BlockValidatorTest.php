<?php

declare(strict_types=1);

namespace Tests\Sdk\Frontend;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Frontend\Blocks\BlockContract;
use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * WC-225: SDK plugin-UI block contract + validator (SP1 slice 1).
 *
 * A plugin declares a screen as a platform-neutral tree of semantic UI
 * "blocks"; {@see BlockValidator::validate()} is the single gate that proves a
 * tree is well-formed against {@see BlockContract} before any per-platform
 * renderer ever sees it. These tests pin both the happy path and every
 * documented failure mode (each must be path-qualified and never throw).
 */
final class BlockValidatorTest extends TestCase
{
    // ==================== happy path ====================

    public function testRepresentativeValidTreePasses(): void
    {
        $tree = [
            [
                'type' => 'section',
                'title' => 'Overview',
                'children' => [
                    [
                        'type' => 'card',
                        'title' => 'Everything',
                        'description' => 'one of each leaf',
                        'children' => [
                            ['type' => 'heading', 'level' => 2, 'text' => 'Hello'],
                            ['type' => 'text', 'value' => 'Body copy', 'tone' => 'muted'],
                            ['type' => 'alert', 'variant' => 'info', 'title' => 'Heads up', 'body' => 'Note'],
                            ['type' => 'badge', 'variant' => 'success', 'label' => 'OK'],
                            ['type' => 'stat', 'label' => 'Users', 'value' => '42', 'hint' => 'active', 'trend' => 'up'],
                            [
                                'type' => 'keyValue',
                                'items' => [
                                    ['label' => 'Plan', 'value' => 'Pro'],
                                    ['label' => 'Seats', 'value' => '5'],
                                ],
                            ],
                            ['type' => 'list', 'ordered' => true, 'items' => ['one', 'two', 'three']],
                            [
                                'type' => 'table',
                                'columns' => [
                                    ['key' => 'name', 'label' => 'Name'],
                                    ['key' => 'role', 'label' => 'Role'],
                                ],
                                'rows' => [
                                    ['name' => 'Ada', 'role' => 'admin'],
                                    ['name' => 'Linus', 'role' => 'user'],
                                ],
                            ],
                            ['type' => 'button', 'label' => 'Go', 'href' => '/users', 'variant' => 'primary'],
                            ['type' => 'icon', 'name' => 'user', 'tone' => 'default'],
                            ['type' => 'code', 'language' => 'php', 'content' => '<?php echo 1;'],
                        ],
                    ],
                    [
                        'type' => 'grid',
                        'columns' => 2,
                        'children' => [
                            ['type' => 'divider'],
                            ['type' => 'text', 'value' => 'cell'],
                        ],
                    ],
                    [
                        'type' => 'row',
                        'align' => 'between',
                        'children' => [
                            ['type' => 'text', 'value' => 'left'],
                            ['type' => 'text', 'value' => 'right'],
                        ],
                    ],
                    [
                        'type' => 'tabs',
                        'children' => [
                            ['type' => 'tab', 'label' => 'First', 'children' => [
                                ['type' => 'text', 'value' => 'tab one'],
                            ]],
                            ['type' => 'tab', 'label' => 'Second', 'children' => []],
                        ],
                    ],
                ],
            ],
        ];

        $result = BlockValidator::validate($tree);

        $this->assertSame(['ok' => true, 'errors' => []], $result);
    }

    public function testEmptyTreeIsValid(): void
    {
        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate([]));
    }

    // ==================== never throws on garbage ====================

    /**
     * Each provided value is a single (malformed) NODE; we wrap it in a
     * one-element list so the validator is exercised on a real tree shape.
     *
     * @dataProvider malformedNodes
     *
     * @param mixed $node
     */
    public function testNeverThrowsOnMalformedInput(mixed $node): void
    {
        $result = BlockValidator::validate([$node]);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function malformedNodes(): iterable
    {
        yield 'scalar node' => ['just a string'];
        yield 'null node' => [null];
        yield 'int node' => [7];
        yield 'node missing type' => [['title' => 'no type here']];
        yield 'node with non-string type' => [['type' => 123]];
    }

    public function testTopLevelMustBeAList(): void
    {
        // An assoc array (not a list of blocks) at the top level is rejected.
        $result = BlockValidator::validate(['type' => 'text', 'value' => 'x']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocks', $result['errors'][0]);
        $this->assertStringContainsString('list', $result['errors'][0]);
    }

    // ==================== individual failure modes ====================

    public function testUnknownTypeIsRejectedWithPath(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'text', 'value' => 'ok'],
            ['type' => 'wormhole'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            "blocks[1]: unknown block type 'wormhole'",
            $result['errors']
        );
    }

    public function testMissingRequiredPropIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'heading', 'level' => 2],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocks[0]', $result['errors'][0]);
        $this->assertStringContainsString('text', $result['errors'][0]);
    }

    public function testWrongPrimitiveTypeIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'heading', 'level' => 'two', 'text' => 'hi'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocks[0]', $result['errors'][0]);
        $this->assertStringContainsString('level', $result['errors'][0]);
    }

    public function testInvalidEnumIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'alert', 'variant' => 'purple', 'body' => 'x'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocks[0]', $result['errors'][0]);
        $this->assertStringContainsString('variant', $result['errors'][0]);
    }

    public function testChildrenOnALeafIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'badge', 'variant' => 'info', 'label' => 'x', 'children' => [
                ['type' => 'text', 'value' => 'nope'],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocks[0]', $result['errors'][0]);
        $this->assertStringContainsString('children', $result['errors'][0]);
    }

    public function testTabOutsideTabsIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'tab', 'label' => 'orphan'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocks[0]', $result['errors'][0]);
        $this->assertStringContainsString('tab', $result['errors'][0]);
    }

    public function testTabsWithNonTabChildIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'tabs', 'children' => [
                ['type' => 'tab', 'label' => 'good', 'children' => []],
                ['type' => 'text', 'value' => 'not a tab'],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $found = false;
        foreach ($result['errors'] as $error) {
            if (str_contains($error, 'blocks[0].children[1]') && str_contains($error, 'tab')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'expected a path-qualified error for the non-tab child of tabs: ' . implode(' | ', $result['errors']));
    }

    public function testNonRelativeButtonHrefIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'button', 'label' => 'Evil', 'href' => 'https://evil'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocks[0]', $result['errors'][0]);
        $this->assertStringContainsString('href', $result['errors'][0]);
    }

    public function testProtocolRelativeButtonHrefIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'button', 'label' => 'Evil', 'href' => '//evil.example'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('href', $result['errors'][0]);
    }

    public function testOverDepthIsRejected(): void
    {
        // Build a chain of nested sections 33 levels deep (MAX_DEPTH = 32).
        $depth = BlockContract::MAX_DEPTH + 1;
        $node = ['type' => 'text', 'value' => 'leaf'];
        for ($i = 0; $i < $depth; $i++) {
            $node = ['type' => 'section', 'children' => [$node]];
        }

        $result = BlockValidator::validate([$node]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('depth', $joined);
    }

    public function testOverNodesIsRejected(): void
    {
        // One section whose children are MAX_NODES leaves → total > MAX_NODES.
        $children = [];
        for ($i = 0; $i < BlockContract::MAX_NODES + 5; $i++) {
            $children[] = ['type' => 'text', 'value' => 'x'];
        }
        $tree = [['type' => 'section', 'children' => $children]];

        $result = BlockValidator::validate($tree);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('node', $joined);
    }

    // ==================== contract surface ====================

    public function testContractCapsAreExposed(): void
    {
        $this->assertSame(32, BlockContract::MAX_DEPTH);
        $this->assertSame(500, BlockContract::MAX_NODES);
    }

    public function testContractTypesCoverTheWholeWhitelist(): void
    {
        $types = BlockContract::types();
        sort($types);

        $expected = [
            'alert', 'badge', 'button', 'card', 'code', 'dataList', 'dataStat',
            'dataTable', 'divider', 'grid', 'heading', 'icon', 'keyValue', 'list',
            'row', 'section', 'stat', 'tab', 'table', 'tabs', 'text',
        ];
        sort($expected);

        $this->assertSame($expected, $types);
    }

    public function testContainerClassification(): void
    {
        $this->assertTrue(BlockContract::isContainer('section'));
        $this->assertTrue(BlockContract::isContainer('tabs'));
        $this->assertTrue(BlockContract::isContainer('tab'));
        $this->assertFalse(BlockContract::isContainer('divider'));
        $this->assertFalse(BlockContract::isContainer('badge'));
        $this->assertFalse(BlockContract::isContainer('unknown-type'));
    }

    public function testRulesForUnknownTypeIsNull(): void
    {
        $this->assertNull(BlockContract::rulesFor('wormhole'));
    }

    // ==================== SP2 data-bound block types (WC-229) ====================

    /**
     * A representative tree with all three data-bound leaf types passes
     * validation when every required prop is present and well-formed.
     */
    public function testDataBoundTreeWithAllThreeTypesIsValid(): void
    {
        $tree = [
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'role', 'label' => 'Role'],
                ],
            ],
            [
                'type' => 'dataStat',
                'source' => '/api/uikit/demo/metric',
                'label' => 'Active users',
                'valueField' => 'value',
            ],
            [
                'type' => 'dataList',
                'source' => '/api/uikit/demo/rows',
                'itemField' => 'name',
            ],
        ];

        $result = BlockValidator::validate($tree);

        $this->assertSame(['ok' => true, 'errors' => []], $result);
    }

    public function testDataTableMissingSourceIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'columns' => [['key' => 'id', 'label' => 'ID']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('source', $joined);
    }

    public function testDataTableSourceWithNoApiPrefixIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => 'hello/greetings',
                'columns' => [['key' => 'id', 'label' => 'ID']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('source', $joined);
    }

    public function testDataTableSourceWithDoubleSlashIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api//x',
                'columns' => [['key' => 'id', 'label' => 'ID']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('source', $joined);
    }

    public function testDataTableSourceWithDotDotIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/../secret',
                'columns' => [['key' => 'id', 'label' => 'ID']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('source', $joined);
    }

    public function testDataTableSourceWithSchemeIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => 'http://evil/api/x',
                'columns' => [['key' => 'id', 'label' => 'ID']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('source', $joined);
    }

    public function testDataTableSourceWithWhitespaceIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/x y',
                'columns' => [['key' => 'id', 'label' => 'ID']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('source', $joined);
    }

    public function testDataTableMissingColumnsIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('columns', $joined);
    }

    public function testDataStatMissingValueFieldIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataStat',
                'source' => '/api/uikit/demo/metric',
                'label' => 'Stat label',
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('valueField', $joined);
    }

    public function testDataListMissingItemFieldIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataList',
                'source' => '/api/uikit/demo/rows',
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('itemField', $joined);
    }

    public function testDataTableWithChildrenIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [['key' => 'id', 'label' => 'ID']],
                'children' => [
                    ['type' => 'text', 'value' => 'nope'],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('children', $joined);
    }

    public function testDataBoundTypesAreInTheWhitelist(): void
    {
        $types = BlockContract::types();
        $this->assertContains('dataTable', $types);
        $this->assertContains('dataStat', $types);
        $this->assertContains('dataList', $types);
    }

    public function testDataBoundTypesAreLeafBlocks(): void
    {
        $this->assertFalse(BlockContract::isContainer('dataTable'));
        $this->assertFalse(BlockContract::isContainer('dataStat'));
        $this->assertFalse(BlockContract::isContainer('dataList'));
    }
}
