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

        // SP1 display types + SP2 data-bound types + SP3 interactive types
        // (WC-233) + SP4 chart type (WC-240)
        $expected = [
            'actionButton', 'alert', 'badge', 'bilingualText', 'button', 'card', 'chart', 'checkbox', 'code',
            'colorInput', 'dataList', 'dataStat', 'dataTable', 'dateInput', 'divider', 'drawer',
            'fieldArray', 'fileInput', 'form', 'grid', 'heading', 'icon', 'keyValue', 'list', 'markdown', 'math',
            'modal', 'numberInput', 'referenceSelect', 'richTextInput', 'row', 'section', 'select', 'selector', 'slider', 'stat', 'submitButton',
            'tab', 'table', 'tabs', 'text', 'textArea', 'textInput',
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

    public function testDataTableSourceWithBackslashIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/x\\y',
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

    // ==================== WC-241: dataTable/dataList inline sort/filter/pagination ====================

    public function testDataTableWithSortableFilterableColumnsAndPageSizeIsValid(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                    ['key' => 'role', 'label' => 'Role', 'sortable' => false],
                ],
                'pageSize' => 10,
            ],
        ]);

        $this->assertSame(['ok' => true, 'errors' => []], $result);
    }

    public function testDataTableColumnWithNonBoolSortableIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name', 'sortable' => 'yes'],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('columns', $joined);
    }

    public function testDataTableColumnWithNonBoolFilterableIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name', 'filterable' => 1],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('columns', $joined);
    }

    public function testDataTablePageSizeMustBeAnInteger(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [['key' => 'name', 'label' => 'Name']],
                'pageSize' => '10',
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('pageSize', $joined);
    }

    public function testDataListWithSortableFilterableAndPageSizeIsValid(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataList',
                'source' => '/api/uikit/demo/rows',
                'itemField' => 'name',
                'sortable' => true,
                'filterable' => true,
                'pageSize' => 5,
            ],
        ]);

        $this->assertSame(['ok' => true, 'errors' => []], $result);
    }

    public function testDataListSortableMustBeABool(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataList',
                'source' => '/api/uikit/demo/rows',
                'itemField' => 'name',
                'sortable' => 'true',
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('sortable', $joined);
    }

    public function testDataListFilterableMustBeABool(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataList',
                'source' => '/api/uikit/demo/rows',
                'itemField' => 'name',
                'filterable' => 'true',
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('filterable', $joined);
    }

    /**
     * A dataTable/dataList declared exactly as before WC-241 (no sortable,
     * filterable, or pageSize props) must still validate — this is a purely
     * additive, backward-compatible upgrade.
     */
    public function testPreExistingDataTableAndDataListShapesStillValidate(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'role', 'label' => 'Role'],
                ],
            ],
            [
                'type' => 'dataList',
                'source' => '/api/uikit/demo/rows',
                'itemField' => 'name',
            ],
        ]);

        $this->assertSame(['ok' => true, 'errors' => []], $result);
    }

    // ==================== SP4 chart block type (WC-240) ====================

    public function testChartWithAllFourTypesIsValid(): void
    {
        foreach (['bar', 'line', 'area', 'pie'] as $chartType) {
            $result = BlockValidator::validate([
                [
                    'type' => 'chart',
                    'source' => '/api/uikit/demo/rows',
                    'chartType' => $chartType,
                    'xField' => 'label',
                    'series' => [
                        ['key' => 'revenue', 'label' => 'Revenue', 'color' => 1],
                        ['key' => 'cost', 'label' => 'Cost', 'color' => 2],
                    ],
                ],
            ]);

            $this->assertSame(['ok' => true, 'errors' => []], $result, "chartType={$chartType}");
        }
    }

    public function testChartMissingSourceIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'chartType' => 'bar',
                'series' => [['key' => 'revenue', 'label' => 'Revenue', 'color' => 1]],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('source', $joined);
    }

    public function testChartSourceOwnershipUsesTheSameApiPathRule(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => 'http://evil/api/x',
                'chartType' => 'bar',
                'series' => [['key' => 'revenue', 'label' => 'Revenue', 'color' => 1]],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('source', $joined);
    }

    public function testChartUnknownChartTypeIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => '/api/uikit/demo/rows',
                'chartType' => 'scatter',
                'series' => [['key' => 'revenue', 'label' => 'Revenue', 'color' => 1]],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('chartType', $joined);
    }

    public function testChartMissingSeriesIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => '/api/uikit/demo/rows',
                'chartType' => 'bar',
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('series', $joined);
    }

    public function testChartEmptySeriesListIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => '/api/uikit/demo/rows',
                'chartType' => 'bar',
                'series' => [],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('series', $joined);
    }

    public function testChartSeriesEntryMissingKeyIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => '/api/uikit/demo/rows',
                'chartType' => 'bar',
                'series' => [['label' => 'Revenue', 'color' => 1]],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('series', $joined);
    }

    /**
     * `color` selects one of the five semantic `--chart-1..5` tokens; it is an
     * int enum, never a raw hex/rgb string a plugin could smuggle CSS through.
     */
    public function testChartSeriesEntryWithOutOfRangeColorIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => '/api/uikit/demo/rows',
                'chartType' => 'bar',
                'series' => [['key' => 'revenue', 'label' => 'Revenue', 'color' => 6]],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('series', $joined);
    }

    public function testChartSeriesEntryWithHexColorStringIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => '/api/uikit/demo/rows',
                'chartType' => 'bar',
                'series' => [['key' => 'revenue', 'label' => 'Revenue', 'color' => '#ff0000']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('series', $joined);
    }

    public function testChartWithChildrenIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type' => 'chart',
                'source' => '/api/uikit/demo/rows',
                'chartType' => 'bar',
                'series' => [['key' => 'revenue', 'label' => 'Revenue', 'color' => 1]],
                'children' => [
                    ['type' => 'text', 'value' => 'nope'],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('children', $joined);
    }

    public function testChartIsInTheWhitelistAndIsALeaf(): void
    {
        $this->assertContains('chart', BlockContract::types());
        $this->assertFalse(BlockContract::isContainer('chart'));
    }

    // ==================== SP3 interactive block types (WC-233) ====================

    /**
     * A full interactive tree: a form with one of each input kind + a
     * submitButton, plus a standalone actionButton. Must pass validation.
     */
    public function testFullInteractiveTreeIsValid(): void
    {
        $tree = [
            [
                'type'               => 'form',
                'submit'             => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'requiredPermission' => 'uikit:view',
                'children'           => [
                    ['type' => 'textInput',   'name' => 'myText',   'label' => 'Text field'],
                    ['type' => 'textArea',     'name' => 'myArea',   'label' => 'Text area', 'rows' => 4],
                    ['type' => 'numberInput',  'name' => 'myNumber', 'label' => 'Number', 'min' => 0, 'max' => 100],
                    ['type' => 'select',       'name' => 'mySelect', 'label' => 'Select',
                        'options' => [
                            ['value' => 'a', 'label' => 'Option A'],
                            ['value' => 'b', 'label' => 'Option B'],
                        ],
                    ],
                    ['type' => 'checkbox',    'name' => 'myCheck',  'label' => 'Enable'],
                    ['type' => 'slider',      'name' => 'mySlider', 'label' => 'Volume', 'min' => 0, 'max' => 100],
                    ['type' => 'dateInput',   'name' => 'myDate',   'label' => 'Date'],
                    ['type' => 'fileInput',   'name' => 'myFile',   'label' => 'File', 'accept' => '.csv'],
                    ['type' => 'colorInput',  'name' => 'myColor',  'label' => 'Color'],
                    ['type' => 'submitButton', 'label' => 'Submit'],
                ],
            ],
            [
                'type'               => 'actionButton',
                'label'              => 'Run Action',
                'action'             => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'requiredPermission' => 'uikit:view',
                'confirm'            => 'Are you sure?',
            ],
        ];

        $result = BlockValidator::validate($tree);

        $this->assertSame(['ok' => true, 'errors' => []], $result);
    }

    /**
     * An input nested form→grid→textInput is valid (inForm is an ancestor flag,
     * not just direct-parent).
     */
    public function testFormGridNestedInputIsValid(): void
    {
        $tree = [
            [
                'type'   => 'form',
                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'children' => [
                    [
                        'type'     => 'grid',
                        'columns'  => 2,
                        'children' => [
                            ['type' => 'textInput', 'name' => 'nested', 'label' => 'Nested input'],
                        ],
                    ],
                    ['type' => 'submitButton', 'label' => 'Go'],
                ],
            ],
        ];

        $result = BlockValidator::validate($tree);

        $this->assertSame(['ok' => true, 'errors' => []], $result);
    }

    public function testTextInputAtTopLevelIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'textInput', 'name' => 'field', 'label' => 'Top-level input'],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString("'textInput' is only valid inside a 'form'", $joined);
    }

    public function testSubmitButtonOutsideFormIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'submitButton', 'label' => 'Submit'],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString("'submitButton' is only valid inside a 'form'", $joined);
    }

    public function testDuplicateInputNameWithinFormIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type'   => 'form',
                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'children' => [
                    ['type' => 'textInput', 'name' => 'dup', 'label' => 'First'],
                    ['type' => 'textInput', 'name' => 'dup', 'label' => 'Second'],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString("duplicate input name 'dup' within the form", $joined);
    }

    public function testFormWithInvalidSubmitMethodIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type'   => 'form',
                'submit' => ['method' => 'DELETE', 'endpoint' => '/api/uikit/demo/echo'],
                'children' => [],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('submit', $joined);
    }

    // WC-block-submit-templating: PATCH (the sync update verb) is accepted, and
    // the endpoint may carry a {targetId.field} context token the renderer
    // interpolates at submit — e.g. a modal edit form for the opened row.
    public function testFormAcceptsPatchWithAContextTemplatedEndpoint(): void
    {
        $result = BlockValidator::validate([
            [
                'type'   => 'form',
                'submit' => ['method' => 'PATCH', 'endpoint' => '/api/persons/{edit-person.id}'],
                'children' => [],
            ],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['ok']);
    }

    public function testFormWithEndpointMissingApiPrefixIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type'   => 'form',
                'submit' => ['method' => 'POST', 'endpoint' => '/users'],
                'children' => [],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('submit', $joined);
    }

    public function testSelectWithNoOptionsIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type'   => 'form',
                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'children' => [
                    ['type' => 'select', 'name' => 'mySelect', 'label' => 'Select'],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('options', $joined);
    }

    public function testSelectWithBadOptionEntryIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type'   => 'form',
                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'children' => [
                    [
                        'type'    => 'select',
                        'name'    => 'mySelect',
                        'label'   => 'Select',
                        'options' => [
                            ['value' => 'a', 'label' => 'Valid'],
                            ['value' => 123], // missing label + value is int
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('options', $joined);
    }

    public function testInputMissingNameIsRejected(): void
    {
        $result = BlockValidator::validate([
            [
                'type'   => 'form',
                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'children' => [
                    ['type' => 'textInput', 'label' => 'Missing name'],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('name', $joined);
    }

    public function testActionButtonMissingActionIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'actionButton', 'label' => 'Go'],
        ]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString('blocks[0]', $joined);
        $this->assertStringContainsString('action', $joined);
    }

    public function testInteractiveTypesAreInTheWhitelist(): void
    {
        $types = BlockContract::types();
        foreach ([
            'form', 'textInput', 'textArea', 'numberInput', 'select',
            'checkbox', 'slider', 'dateInput', 'fileInput', 'colorInput',
            'bilingualText', 'referenceSelect', 'richTextInput', 'submitButton', 'actionButton',
        ] as $expectedType) {
            $this->assertContains($expectedType, $types, "'{$expectedType}' must be in BlockContract::types()");
        }
    }

    public function testFormIsAContainerInteractiveInputsAreLeaves(): void
    {
        $this->assertTrue(BlockContract::isContainer('form'));
        foreach (['textInput', 'textArea', 'numberInput', 'select', 'checkbox',
                  'slider', 'dateInput', 'fileInput', 'colorInput',
                  'bilingualText', 'referenceSelect', 'richTextInput',
                  'submitButton', 'actionButton'] as $leaf) {
            $this->assertFalse(BlockContract::isContainer($leaf), "'{$leaf}' must be a leaf");
        }
    }

    // ==================== WC-532 A3: visibleWhen conditional visibility ====================

    /**
     * An input with `visibleWhen: {field, equals}` and a section with
     * `visibleWhen: {field, in}` both validate inside a form.
     */
    public function testVisibleWhenEqualsAndInAreValid(): void
    {
        $tree = [
            [
                'type'   => 'form',
                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                'children' => [
                    ['type' => 'select', 'name' => 'kind', 'label' => 'Kind', 'options' => [
                        ['value' => 'person', 'label' => 'Person'],
                        ['value' => 'org', 'label' => 'Organisation'],
                    ]],
                    // shown only when kind === 'org'
                    [
                        'type'        => 'textInput',
                        'name'        => 'orgName',
                        'label'       => 'Organisation name',
                        'visibleWhen' => ['field' => 'kind', 'equals' => 'org'],
                    ],
                    // a whole section shown when kind ∈ {person, org}
                    [
                        'type'        => 'section',
                        'title'       => 'Details',
                        'visibleWhen' => ['field' => 'kind', 'in' => ['person', 'org']],
                        'children'    => [
                            ['type' => 'textInput', 'name' => 'note', 'label' => 'Note'],
                        ],
                    ],
                    ['type' => 'submitButton', 'label' => 'Save'],
                ],
            ],
        ];

        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    /**
     * `visibleWhen.equals` accepts a boolean (checkbox-driven visibility).
     */
    public function testVisibleWhenEqualsAcceptsBoolean(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
            'children' => [
                ['type' => 'checkbox', 'name' => 'advanced', 'label' => 'Advanced'],
                [
                    'type'        => 'textInput',
                    'name'        => 'tuning',
                    'label'       => 'Tuning',
                    'visibleWhen' => ['field' => 'advanced', 'equals' => true],
                ],
                ['type' => 'submitButton', 'label' => 'Save'],
            ],
        ]];

        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testVisibleWhenMissingFieldIsRejected(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'textInput', 'name' => 'a', 'label' => 'A', 'visibleWhen' => ['equals' => 'x']],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];

        $result = BlockValidator::validate($tree);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('visibleWhen.field', implode(' | ', $result['errors']));
    }

    public function testVisibleWhenWithBothEqualsAndInIsRejected(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                [
                    'type'        => 'textInput',
                    'name'        => 'a',
                    'label'       => 'A',
                    'visibleWhen' => ['field' => 'k', 'equals' => 'x', 'in' => ['x', 'y']],
                ],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];

        $result = BlockValidator::validate($tree);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("exactly one of 'equals' or 'in'", implode(' | ', $result['errors']));
    }

    public function testVisibleWhenWithNeitherEqualsNorInIsRejected(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'textInput', 'name' => 'a', 'label' => 'A', 'visibleWhen' => ['field' => 'k']],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];

        $result = BlockValidator::validate($tree);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("exactly one of 'equals' or 'in'", implode(' | ', $result['errors']));
    }

    public function testVisibleWhenInMustBeNonEmptyListOfScalars(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'textInput', 'name' => 'a', 'label' => 'A', 'visibleWhen' => ['field' => 'k', 'in' => []]],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];

        $result = BlockValidator::validate($tree);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('visibleWhen.in', implode(' | ', $result['errors']));
    }

    public function testVisibleWhenEqualsMustBeScalar(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                [
                    'type'        => 'textInput',
                    'name'        => 'a',
                    'label'       => 'A',
                    'visibleWhen' => ['field' => 'k', 'equals' => ['not', 'a', 'scalar']],
                ],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];

        $result = BlockValidator::validate($tree);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('visibleWhen.equals', implode(' | ', $result['errors']));
    }

    public function testVisibleWhenAsAListNotObjectIsRejected(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'textInput', 'name' => 'a', 'label' => 'A', 'visibleWhen' => ['field', 'k']],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];

        $result = BlockValidator::validate($tree);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('{field, equals|in} object', implode(' | ', $result['errors']));
    }

    // ==================== WC-532 A4: bilingualText input ====================

    public function testBilingualTextInsideFormIsValid(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
            'children' => [
                [
                    'type'     => 'bilingualText',
                    'name'     => 'displayName',
                    'label'    => 'Display name',
                    'required' => true,
                    'arLabel'  => 'الاسم',
                    'enLabel'  => 'Name',
                ],
                ['type' => 'submitButton', 'label' => 'Save'],
            ],
        ]];

        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testBilingualTextAtTopLevelIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'bilingualText', 'name' => 'x', 'label' => 'X'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("'bilingualText' is only valid inside a 'form'", implode(' | ', $result['errors']));
    }

    public function testBilingualTextMissingNameIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'bilingualText', 'label' => 'X'],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("missing required prop 'name'", implode(' | ', $result['errors']));
    }

    public function testDuplicateBilingualTextNameWithinFormIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'bilingualText', 'name' => 'dup', 'label' => 'A'],
                ['type' => 'textInput', 'name' => 'dup', 'label' => 'B'],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("duplicate input name 'dup'", implode(' | ', $result['errors']));
    }

    // ==================== WC-532 A6: referenceSelect input ====================

    public function testReferenceSelectInsideFormIsValid(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
            'children' => [
                [
                    'type'       => 'referenceSelect',
                    'name'       => 'ownerId',
                    'label'      => 'Owner',
                    'source'     => '/api/uikit/people',
                    'valueField' => 'id',
                    'labelField' => 'name',
                    'required'   => true,
                ],
                ['type' => 'submitButton', 'label' => 'Save'],
            ],
        ]];

        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testReferenceSelectAtTopLevelIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'referenceSelect', 'name' => 'x', 'label' => 'X',
             'source' => '/api/x/rows', 'valueField' => 'id', 'labelField' => 'name'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("'referenceSelect' is only valid inside a 'form'", implode(' | ', $result['errors']));
    }

    public function testReferenceSelectMissingSourceIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'referenceSelect', 'name' => 'x', 'label' => 'X',
                 'valueField' => 'id', 'labelField' => 'name'],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("missing required prop 'source'", implode(' | ', $result['errors']));
    }

    public function testReferenceSelectSourceUsesTheApiPathRule(): void
    {
        $result = BlockValidator::validate([[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'referenceSelect', 'name' => 'x', 'label' => 'X',
                 'source' => 'https://evil.example/api/x', 'valueField' => 'id', 'labelField' => 'name'],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be a relative API path', implode(' | ', $result['errors']));
    }

    public function testReferenceSelectMissingValueOrLabelFieldIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'referenceSelect', 'name' => 'x', 'label' => 'X', 'source' => '/api/x/rows'],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]]);

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString("missing required prop 'valueField'", $joined);
        $this->assertStringContainsString("missing required prop 'labelField'", $joined);
    }

    // ==================== WC-532 A1: dataTable rowActions ====================

    public function testDataTableWithHrefAndEndpointRowActionsIsValid(): void
    {
        $tree = [[
            'type'    => 'dataTable',
            'source'  => '/api/uikit/demo/rows',
            'columns' => [['key' => 'name', 'label' => 'Name']],
            'rowActions' => [
                ['label' => 'View', 'href' => '/plugins/uikit/{name}'],
                ['label' => 'Archive', 'method' => 'POST', 'endpoint' => '/api/uikit/items/{name}/archive', 'confirm' => 'Archive this row?'],
                ['label' => 'Delete', 'method' => 'DELETE', 'endpoint' => '/api/uikit/items/{name}'],
            ],
        ]];

        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testRowActionMissingLabelIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataTable', 'source' => '/api/x/rows',
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rowActions' => [['href' => '/x/1']],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('non-empty \'label\'', implode(' | ', $result['errors']));
    }

    public function testRowActionWithBothHrefAndEndpointIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataTable', 'source' => '/api/x/rows',
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rowActions' => [['label' => 'X', 'href' => '/x', 'endpoint' => '/api/x', 'method' => 'POST']],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("exactly one of 'href', 'endpoint', or 'open'", implode(' | ', $result['errors']));
    }

    public function testRowActionWithNeitherHrefNorEndpointIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataTable', 'source' => '/api/x/rows',
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rowActions' => [['label' => 'X']],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("exactly one of 'href', 'endpoint', or 'open'", implode(' | ', $result['errors']));
    }

    public function testRowActionEndpointWithBadMethodIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataTable', 'source' => '/api/x/rows',
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rowActions' => [['label' => 'X', 'endpoint' => '/api/x/1', 'method' => 'GET']],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('method POST, PUT, or DELETE', implode(' | ', $result['errors']));
    }

    public function testRowActionEndpointMustBeApiPath(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataTable', 'source' => '/api/x/rows',
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rowActions' => [['label' => 'X', 'endpoint' => 'https://evil.example/api/x', 'method' => 'POST']],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be a relative API path', implode(' | ', $result['errors']));
    }

    public function testRowActionsMustBeNonEmptyList(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataTable', 'source' => '/api/x/rows',
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rowActions' => [],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('non-empty list of row-action objects', implode(' | ', $result['errors']));
    }

    // ==================== WC-532 A5: math / markdown / richTextInput ====================

    public function testMathAndMarkdownDisplayBlocksAreValid(): void
    {
        $tree = [
            ['type' => 'math', 'expression' => 'e^{i\\pi}+1=0', 'block' => true],
            ['type' => 'markdown', 'content' => "## Title\n\n**bold** and \$a^2\$"],
        ];
        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testMathMissingExpressionIsRejected(): void
    {
        $result = BlockValidator::validate([['type' => 'math', 'block' => true]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("missing required prop 'expression'", implode(' | ', $result['errors']));
    }

    public function testMarkdownMissingContentIsRejected(): void
    {
        $result = BlockValidator::validate([['type' => 'markdown']]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("missing required prop 'content'", implode(' | ', $result['errors']));
    }

    public function testMathAndMarkdownAreLeafDisplayBlocks(): void
    {
        // Display blocks: valid at the top level (NOT form-only) and leaves.
        $this->assertFalse(BlockContract::isContainer('math'));
        $this->assertFalse(BlockContract::isContainer('markdown'));
        $this->assertContains('math', BlockContract::types());
        $this->assertContains('markdown', BlockContract::types());
    }

    public function testRichTextInputInsideFormIsValid(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
            'children' => [
                ['type' => 'richTextInput', 'name' => 'notes', 'label' => 'Notes', 'rows' => 4, 'required' => true],
                ['type' => 'submitButton', 'label' => 'Save'],
            ],
        ]];
        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testRichTextInputAtTopLevelIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'richTextInput', 'name' => 'x', 'label' => 'X'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("'richTextInput' is only valid inside a 'form'", implode(' | ', $result['errors']));
    }

    public function testRichTextInputMissingNameIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'richTextInput', 'label' => 'X'],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("missing required prop 'name'", implode(' | ', $result['errors']));
    }

    // ==================== WC-532 A2: fieldArray ====================

    public function testFieldArrayWithTemplateInsideFormIsValid(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
            'children' => [
                [
                    'type'  => 'fieldArray',
                    'name'  => 'lineItems',
                    'label' => 'Line items',
                    'itemLabel' => 'Line',
                    'max'   => 5,
                    'children' => [
                        ['type' => 'textInput', 'name' => 'description', 'label' => 'Description'],
                        ['type' => 'numberInput', 'name' => 'qty', 'label' => 'Quantity'],
                    ],
                ],
                ['type' => 'submitButton', 'label' => 'Save'],
            ],
        ]];
        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testFieldArrayIsAContainerAndInTheWhitelist(): void
    {
        $this->assertTrue(BlockContract::isContainer('fieldArray'));
        $this->assertContains('fieldArray', BlockContract::types());
    }

    public function testFieldArrayAtTopLevelIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'fieldArray', 'name' => 'x', 'label' => 'X', 'children' => [
                ['type' => 'textInput', 'name' => 'a', 'label' => 'A'],
            ]],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("'fieldArray' is only valid inside a 'form'", implode(' | ', $result['errors']));
    }

    /**
     * A fieldArray SCOPES its template names per row: a child named 'label' may
     * reuse a name that also appears in the OUTER form (or another fieldArray)
     * without a duplicate-name error — the name is row-local.
     */
    public function testFieldArrayTemplateNamesAreScopedNotGlobal(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'textInput', 'name' => 'title', 'label' => 'Title'],
                ['type' => 'fieldArray', 'name' => 'rows', 'label' => 'Rows', 'children' => [
                    ['type' => 'textInput', 'name' => 'title', 'label' => 'Row title'],
                ]],
                ['type' => 'fieldArray', 'name' => 'more', 'label' => 'More', 'children' => [
                    ['type' => 'textInput', 'name' => 'title', 'label' => 'More title'],
                ]],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];
        // 'title' appears in the outer form and inside BOTH fieldArrays — all fine.
        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testFieldArrayNameCollidingWithSiblingInputIsRejected(): void
    {
        $tree = [[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'textInput', 'name' => 'dup', 'label' => 'Dup'],
                ['type' => 'fieldArray', 'name' => 'dup', 'label' => 'Dup array', 'children' => [
                    ['type' => 'textInput', 'name' => 'a', 'label' => 'A'],
                ]],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]];
        $result = BlockValidator::validate($tree);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("duplicate input name 'dup'", implode(' | ', $result['errors']));
    }

    public function testFieldArrayMissingNameIsRejected(): void
    {
        $result = BlockValidator::validate([[
            'type'   => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'fieldArray', 'label' => 'X', 'children' => [
                    ['type' => 'textInput', 'name' => 'a', 'label' => 'A'],
                ]],
                ['type' => 'submitButton', 'label' => 'Go'],
            ],
        ]]);
        $result2 = $result;
        $this->assertFalse($result2['ok']);
        $this->assertStringContainsString("missing required prop 'name'", implode(' | ', $result2['errors']));
    }

    // ==================== WC-532 A7: selector + data-bound params ====================

    public function testSelectorAndDataTableParamsMasterDetailIsValid(): void
    {
        $tree = [
            ['type' => 'selector', 'name' => 'team', 'label' => 'Team',
             'source' => '/api/x/teams', 'valueField' => 'id', 'labelField' => 'name'],
            ['type' => 'dataTable', 'source' => '/api/x/members',
             'columns' => [['key' => 'n', 'label' => 'N']],
             'params' => [['param' => 'teamId', 'from' => 'team']]],
        ];
        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testSelectorIsALeafInTheWhitelistWithOwnedSourceRule(): void
    {
        $this->assertFalse(BlockContract::isContainer('selector'));
        $this->assertContains('selector', BlockContract::types());
        // Its source uses the shared apiPath rule (ownership-checked at load).
        $result = BlockValidator::validate([
            ['type' => 'selector', 'name' => 's', 'label' => 'S',
             'source' => 'https://evil.example/api/x', 'valueField' => 'id', 'labelField' => 'name'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be a relative API path', implode(' | ', $result['errors']));
    }

    public function testSelectorMissingValueOrLabelFieldIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'selector', 'name' => 's', 'label' => 'S', 'source' => '/api/x/rows'],
        ]);
        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString("missing required prop 'valueField'", $joined);
        $this->assertStringContainsString("missing required prop 'labelField'", $joined);
    }

    public function testDataBoundParamsEntryMustHaveParamAndFrom(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'dataTable', 'source' => '/api/x/rows',
             'columns' => [['key' => 'a', 'label' => 'A']],
             'params' => [['param' => 'x']]],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('{param: non-empty string, from: a selector name or "{targetId}.{field}"}', implode(' | ', $result['errors']));
    }

    public function testDataStatChartAndDataListAllAcceptParams(): void
    {
        $tree = [
            ['type' => 'dataStat', 'source' => '/api/x/m', 'label' => 'M', 'valueField' => 'v',
             'params' => [['param' => 'p', 'from' => 'sel']]],
            ['type' => 'dataList', 'source' => '/api/x/l', 'itemField' => 'name',
             'params' => [['param' => 'p', 'from' => 'sel']]],
            ['type' => 'chart', 'source' => '/api/x/c', 'chartType' => 'bar',
             'series' => [['key' => 'k', 'label' => 'K', 'color' => 1]],
             'params' => [['param' => 'p', 'from' => 'sel']]],
        ];
        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }
}
