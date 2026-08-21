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

    // ==================== workflow blocks (#868) ====================

    /**
     * @return array<string, mixed>
     */
    private static function validInbox(): array
    {
        return [
            'type' => 'inbox',
            'source' => '/api/tasks/mine',
            'idField' => 'id',
            'titleField' => 'title',
            'actions' => [
                ['key' => 'approve', 'label' => 'Approve', 'method' => 'POST', 'endpoint' => '/api/tasks/{id}/approve'],
            ],
        ];
    }

    public function testTimelineAcceptsItsFullPropSet(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'timeline',
            'source' => '/api/tasks/1/events',
            'actorField' => 'actor',
            'actionField' => 'action',
            'timestampField' => 'at',
            'noteField' => 'note',
            'fromField' => 'from',
            'toField' => 'to',
            'pageSize' => 10,
            'emptyText' => 'Nothing yet.',
        ]]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    public function testTimelineRequiresItsThreeMandatoryFieldMappings(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'timeline',
            'source' => '/api/tasks/1/events',
        ]]);

        $joined = implode(' | ', $result['errors']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('actorField', $joined);
        $this->assertStringContainsString('actionField', $joined);
        $this->assertStringContainsString('timestampField', $joined);
    }

    public function testTimelineRejectsAnAbsoluteSource(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'timeline',
            'source' => 'https://evil.example/events',
            'actorField' => 'actor',
            'actionField' => 'action',
            'timestampField' => 'at',
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('source', implode(' | ', $result['errors']));
    }

    /**
     * `timeline` is read-only BY CONSTRUCTION: the contract gives it no verb and
     * no endpoint, so a tree cannot express a writable one. Pinned here because
     * "read-only" is the type's whole promise — a later prop that quietly
     * carried an endpoint would break it silently.
     */
    public function testTimelineDeclaresNoWritableProp(): void
    {
        $rule = BlockContract::rulesFor('timeline');
        $this->assertNotNull($rule);
        $props = $rule['props'];

        $this->assertArrayNotHasKey('actions', $props);
        $this->assertArrayNotHasKey('rowActions', $props);
        foreach ($props as $name => $rule) {
            $this->assertNotSame(
                'submitSpec',
                $rule['type'],
                "timeline.{$name} must not be a submit spec — the type is read-only"
            );
        }
    }

    public function testInboxAcceptsItsFullPropSet(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'inbox',
            'source' => '/api/tasks/mine',
            'idField' => 'id',
            'titleField' => 'title',
            'subtitleField' => 'requester',
            'timestampField' => 'submitted',
            'statusField' => 'status',
            'resourceType' => 'task',
            'pageSize' => 20,
            'emptyText' => 'Nothing awaiting you.',
            'actions' => [
                [
                    'key' => 'approve',
                    'label' => 'Approve',
                    'method' => 'POST',
                    'endpoint' => '/api/tasks/{id}/approve',
                    'scopedPermission' => 'tasks:approve',
                    'confirm' => 'Approve this?',
                    'variant' => 'primary',
                ],
                [
                    'key' => 'reject',
                    'label' => 'Reject',
                    'method' => 'DELETE',
                    'endpoint' => '/api/tasks/{id}',
                ],
            ],
        ]]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    public function testInboxRequiresANonEmptyActionList(): void
    {
        $block = self::validInbox();
        $block['actions'] = [];

        $result = BlockValidator::validate([$block]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('actions', implode(' | ', $result['errors']));
    }

    public function testInboxRejectsADuplicateActionKey(): void
    {
        $block = self::validInbox();
        $block['actions'][] = [
            'key' => 'approve',
            'label' => 'Approve again',
            'method' => 'POST',
            'endpoint' => '/api/tasks/{id}/approve-again',
        ];

        $result = BlockValidator::validate([$block]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("duplicate 'inbox.actions' key 'approve'", implode(' | ', $result['errors']));
    }

    public function testInboxRejectsAnUnsupportedActionMethod(): void
    {
        $block = self::validInbox();
        $block['actions'][0]['method'] = 'GET';

        $result = BlockValidator::validate([$block]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('method', implode(' | ', $result['errors']));
    }

    public function testInboxRejectsAnAbsoluteActionEndpoint(): void
    {
        $block = self::validInbox();
        $block['actions'][0]['endpoint'] = 'https://evil.example/approve';

        $result = BlockValidator::validate([$block]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('endpoint', implode(' | ', $result['errors']));
    }

    /**
     * A `scopedPermission` is a PER-RECORD predicate, so the block must say what
     * kind of record its items are. Without `resourceType` the host would fall
     * back to the tenant-wide question — a check that reads as per-record, is
     * not, and is wrong in the permissive direction relative to the author's
     * intent. Refused at validation rather than degraded at runtime.
     */
    public function testInboxRejectsScopedPermissionWithoutAResourceType(): void
    {
        $block = self::validInbox();
        $block['actions'][0]['scopedPermission'] = 'tasks:approve';

        $result = BlockValidator::validate([$block]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('resourceType', implode(' | ', $result['errors']));
    }

    public function testInboxAcceptsScopedPermissionWithAResourceType(): void
    {
        $block = self::validInbox();
        $block['resourceType'] = 'task';
        $block['actions'][0]['scopedPermission'] = 'tasks:approve';

        $result = BlockValidator::validate([$block]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    /**
     * The permission an action's ENDPOINT is gated on is not the plugin's to
     * restate — the host reads it off the route. Pinned so nobody adds a
     * `permission` prop back and reintroduces the second source of truth.
     */
    public function testInboxActionsCannotDeclareTheEndpointsOwnPermission(): void
    {
        $block = self::validInbox();
        $block['permission'] = 'tasks:approve';

        // An unknown prop is IGNORED by the contract (props are a whitelist, and
        // only declared ones are read) — so the guarantee is not that this is
        // rejected, but that no such prop exists to be honoured.
        $inboxRule = BlockContract::rulesFor('inbox');
        $this->assertNotNull($inboxRule);
        $this->assertArrayNotHasKey('permission', $inboxRule['props']);
        $this->assertTrue(BlockValidator::validate([$block])['ok']);
    }

    public function testInboxIsALeafAndRejectsChildren(): void
    {
        $block = self::validInbox();
        $block['children'] = [['type' => 'text', 'value' => 'x']];

        $result = BlockValidator::validate([$block]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('leaf', implode(' | ', $result['errors']));
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
        // (WC-233) + SP4 chart type (WC-240) + workflow types and the OU scope
        // picker (#868) + the record blocks (#883)
        $expected = [
            'actionButton', 'alert', 'badge', 'bilingualText', 'button', 'card', 'chart', 'checkbox', 'code',
            'colorInput', 'dataList', 'dataRecord', 'dataStat', 'dataTable', 'dateInput', 'divider', 'drawer',
            'fieldArray', 'fileInput', 'form', 'grid', 'heading', 'icon', 'inbox', 'keyValue', 'list', 'markdown', 'math',
            'modal', 'numberInput', 'ouScopePicker', 'recordFields', 'referenceSelect', 'richTextInput', 'row', 'section', 'select', 'selector', 'slider', 'stat', 'submitButton',
            'tab', 'table', 'tabs', 'text', 'textArea', 'textInput', 'timeline',
        ];
        sort($expected);

        $this->assertSame($expected, $types);
    }

    // ==================== record blocks (#883) ====================

    /**
     * A record page, expressed entirely in the block vocabulary: a record-bound
     * container that publishes its facts, a header bound to those facts, a
     * description list, and the edit form seeded from the record.
     *
     * This is the acceptance test for #883 — if this tree stops validating, a
     * record page has stopped being describable.
     */
    public function testARecordPageIsExpressibleAsABlockTree(): void
    {
        $tree = [[
            'type' => 'dataRecord',
            'id' => 'role',
            'source' => '/api/roles/{record}',
            'fields' => [
                ['field' => 'name', 'label' => 'Name'],
                ['field' => 'scope', 'label' => 'Scope'],
                ['field' => 'created', 'label' => 'Created'],
            ],
            'children' => [
                ['type' => 'heading', 'level' => 1, 'text' => 'Role', 'textFrom' => 'role.name'],
                ['type' => 'badge', 'variant' => 'info', 'label' => 'Scope', 'labelFrom' => 'role.scope'],
                ['type' => 'stat', 'label' => 'Created', 'value' => '-', 'valueFrom' => 'role.created'],
                ['type' => 'text', 'value' => '-', 'valueFrom' => 'role.scope'],
                ['type' => 'recordFields', 'from' => 'role'],
                ['type' => 'recordFields', 'from' => 'role', 'fields' => ['scope', 'name']],
                ['type' => 'dataTable', 'source' => '/api/roles/holders', 'columns' => [
                    ['key' => 'name', 'label' => 'Name'],
                ], 'params' => [['param' => 'role', 'from' => 'role.name']]],
                ['type' => 'timeline', 'source' => '/api/roles/history',
                    'actorField' => 'actor', 'actionField' => 'action', 'timestampField' => 'at'],
                ['type' => 'form', 'submit' => ['method' => 'PATCH', 'endpoint' => '/api/roles/{record}'],
                    'requiredPermission' => 'roles:write', 'children' => [
                        ['type' => 'textInput', 'name' => 'name', 'label' => 'Name', 'defaultFrom' => 'role.name'],
                        ['type' => 'submitButton', 'label' => 'Save'],
                    ]],
            ],
        ]];

        $result = BlockValidator::validate($tree);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    /**
     * THE #895 GUARD, on the declaration side.
     *
     * `manageable` is the server's answer to "may YOU write this?". A record
     * page that names it as a fact is the exact defect #895 found, and naming it
     * is refused rather than discouraged — the whole feature drops.
     */
    public function testACallerPermissionFieldCannotBeDeclaredAsARecordFact(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataRecord',
            'id' => 'role',
            'source' => '/api/roles/{record}',
            'fields' => [
                ['field' => 'name', 'label' => 'Name'],
                ['field' => 'manageable', 'label' => "Your tenant's role"],
            ],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("may not bind 'manageable'", $result['errors'][0]);
        $this->assertStringContainsString('#895', $result['errors'][0]);
    }

    /**
     * THE #895 GUARD, on the binding side.
     *
     * The declaration half only covers a `dataRecord`'s own payload. A row
     * published by an `open` row action carries whatever the collection endpoint
     * returned, with no `fields` declaration in front of it, so the `...From`
     * twins are guarded independently — otherwise the same sentence would be
     * refused in one place and permitted three lines away.
     *
     * @dataProvider callerFlagBindingProvider
     *
     * @param array<string, mixed> $node
     */
    public function testACallerPermissionFieldCannotBeBoundAsAFact(array $node, string $expectedField): void
    {
        $result = BlockValidator::validate([$node]);

        $this->assertFalse($result['ok'], 'expected a refusal for ' . $expectedField);
        $this->assertStringContainsString("may not bind '{$expectedField}'", implode('; ', $result['errors']));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function callerFlagBindingProvider(): array
    {
        return [
            'heading.textFrom' => [
                ['type' => 'heading', 'level' => 1, 'text' => 'x', 'textFrom' => 'rec.manageable'],
                'manageable',
            ],
            'text.valueFrom' => [
                ['type' => 'text', 'value' => 'x', 'valueFrom' => 'rec.editable'],
                'editable',
            ],
            'badge.labelFrom' => [
                ['type' => 'badge', 'variant' => 'info', 'label' => 'x', 'labelFrom' => 'rec.canEdit'],
                'canEdit',
            ],
            'stat.valueFrom' => [
                ['type' => 'stat', 'label' => 'x', 'value' => 'y', 'valueFrom' => 'rec.readOnly'],
                'readOnly',
            ],
            'stat.hintFrom' => [
                ['type' => 'stat', 'label' => 'x', 'value' => 'y', 'hintFrom' => 'rec.permitted'],
                'permitted',
            ],
            // A bare reference addresses a selector's value rather than a record
            // field, and is checked too: the same sentence about the same
            // subject, reached by a different route.
            'a bare selector reference' => [
                ['type' => 'badge', 'variant' => 'info', 'label' => 'x', 'labelFrom' => 'deletable'],
                'deletable',
            ],
        ];
    }

    /**
     * The same flag arrives spelled differently from different serializers, and
     * a guard that only knows one spelling fails on the payload shape it was not
     * written against.
     *
     * @dataProvider callerFlagSpellingProvider
     */
    public function testCallerPermissionFieldsAreMatchedAcrossSpellings(string $spelling): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataRecord',
            'id' => 'rec',
            'source' => '/api/x/{record}',
            'fields' => [['field' => $spelling, 'label' => 'L']],
        ]]);

        $this->assertFalse($result['ok'], "expected '{$spelling}' to be refused");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function callerFlagSpellingProvider(): array
    {
        return [
            'camelCase' => ['canEdit'],
            'snake_case' => ['can_edit'],
            'kebab-case' => ['can-edit'],
            'SCREAMING_SNAKE' => ['READ_ONLY'],
            'is-prefixed' => ['is_editable'],
            'has-prefixed' => ['hasWritable'],
            'mixed case' => ['Manageable'],
        ];
    }

    /**
     * The guard must not refuse correct programs, or it gets removed.
     *
     * `issued` is not `sued` — the `is`/`has` prefix is tried as a SECOND
     * candidate rather than stripped unconditionally — and a field whose name
     * merely CONTAINS a reserved word is a different field.
     *
     * @dataProvider honestFieldProvider
     */
    public function testHonestFieldNamesAreNotRefused(string $field): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataRecord',
            'id' => 'rec',
            'source' => '/api/x/{record}',
            'fields' => [['field' => $field, 'label' => 'L']],
        ]]);

        $this->assertTrue($result['ok'], "'{$field}' should be a permitted fact: " . implode('; ', $result['errors']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function honestFieldProvider(): array
    {
        return [
            'issued' => ['issued'],
            'allowedList' => ['allowedList'],
            'permittedDomains' => ['permittedDomains'],
            'editableRegions' => ['editableRegions'],
            'island' => ['island'],
            'hash' => ['hash'],
        ];
    }

    /**
     * PLUMBING IS NOT A STATEMENT.
     *
     * `defaultFrom` seeds a control the server re-validates and is authoritative
     * over; `params.from` narrows a fetch. #897 draws the line in exactly the
     * same place — its `RecordAccess` half is read freely to decide which
     * controls exist, and only the FACTS projection is checked. Pinned as a
     * test because the tempting "guard every binding" change looks stricter and
     * is wrong.
     */
    public function testPlumbingBindingsMayStillReferenceACallerFlag(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => '/api/x/save'],
            'children' => [
                ['type' => 'checkbox', 'name' => 'm', 'label' => 'M', 'defaultFrom' => 'rec.manageable'],
            ],
        ], [
            'type' => 'dataTable',
            'source' => '/api/x/rows',
            'columns' => [['key' => 'a', 'label' => 'A']],
            'params' => [['param' => 'editable', 'from' => 'rec.editable']],
        ]]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    /**
     * The SDK's caller-decision vocabulary is #897's `CallerDecisionKey`
     * verbatim.
     *
     * Two lists that are "kept in sync" are one list and one stale copy, so the
     * pairing is asserted rather than remembered. When this fails, the
     * TypeScript union in `packages/features/src/record/types.ts` and this
     * constant have diverged, and the fix is to change BOTH.
     */
    public function testTheCallerDecisionVocabularyMatchesTheTypeScriptGuard(): void
    {
        $this->assertSame(
            [
                'manageable', 'editable', 'writable', 'deletable',
                'canEdit', 'canDelete', 'canManage', 'canWrite',
                'allowed', 'permitted', 'readOnly',
            ],
            BlockValidator::CALLER_DECISION_FIELDS,
            'BlockValidator::CALLER_DECISION_FIELDS must stay identical to CallerDecisionKey in '
            . 'packages/features/src/record/types.ts — one vocabulary, two media (#895/#897).'
        );
    }

    /**
     * `record` is the host's binding for the record a ROUTE is about. A selector
     * publishing under that name would shadow it for every block on the screen,
     * and the symptom — a record page showing a different record — appears only
     * once the selector has a value.
     */
    public function testASelectorMayNotClaimTheReservedRecordBinding(): void
    {
        $result = BlockValidator::validate([[
            'type' => 'selector',
            'name' => 'record',
            'label' => 'Pick',
            'source' => '/api/x/rows',
            'valueField' => 'id',
            'labelField' => 'name',
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("may not be 'record'", $result['errors'][0]);
        $this->assertSame('record', BlockValidator::PAGE_RECORD_BINDING);
    }

    /**
     * A `recordPath` accepts an ordinary owned API path, with or without context
     * tokens — a singleton record source is judged exactly as a collection
     * source is.
     *
     * @dataProvider recordPathProvider
     */
    public function testRecordPathShapes(string $source, bool $valid): void
    {
        $result = BlockValidator::validate([[
            'type' => 'dataRecord',
            'id' => 'rec',
            'source' => $source,
            'fields' => [['field' => 'name', 'label' => 'Name']],
        ]]);

        $this->assertSame($valid, $result['ok'], $source . ': ' . implode('; ', $result['errors']));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function recordPathProvider(): array
    {
        return [
            'a singleton, no tokens' => ['/api/x/settings', true],
            'the page record binding' => ['/api/x/rows/{record}', true],
            'a selector name' => ['/api/x/rows/{picker}', true],
            'a dotted row reference' => ['/api/x/rows/{edit-modal.id}', true],
            'two tokens' => ['/api/x/{tenant}/rows/{record}', true],
            'a token mid-segment' => ['/api/x/rows/{record}/detail', true],
            'unbalanced open brace' => ['/api/x/rows/{record', false],
            'unbalanced close brace' => ['/api/x/rows/record}', false],
            'a token with two dots' => ['/api/x/rows/{a.b.c}', false],
            'an empty token' => ['/api/x/rows/{}', false],
            'a token with whitespace' => ['/api/x/rows/{a b}', false],
            'traversal' => ['/api/x/../y/{record}', false],
            'an absolute URL' => ['https://evil.example/api/x', false],
            'a double slash' => ['/api//x/{record}', false],
            'not under /api/' => ['/admin/x/{record}', false],
        ];
    }

    /**
     * `dataRecord.fields` is the record's fact whitelist, so its shape is
     * enforced: non-empty, duplicate-free, and each `field` a plain name with no
     * dot (which would collide with `{id}.{field}` addressing).
     */
    public function testRecordFactListShape(): void
    {
        $cases = [
            'not a list' => 'nope',
            'empty' => [],
            'missing label' => [['field' => 'name']],
            'missing field' => [['label' => 'Name']],
            'empty field' => [['field' => '', 'label' => 'Name']],
            'dotted field' => [['field' => 'a.b', 'label' => 'A']],
            'whitespace field' => [['field' => 'a b', 'label' => 'A']],
            'duplicate field' => [['field' => 'a', 'label' => 'A'], ['field' => 'a', 'label' => 'B']],
        ];

        foreach ($cases as $label => $fields) {
            $result = BlockValidator::validate([[
                'type' => 'dataRecord',
                'id' => 'rec',
                'source' => '/api/x/rows/{record}',
                'fields' => $fields,
            ]]);
            $this->assertFalse($result['ok'], "expected '{$label}' to be refused");
        }
    }

    /**
     * `dataRecord` is a container (it wraps the page); `recordFields` is a leaf
     * (it renders a list). Getting these the wrong way round is a common first
     * mistake, and the contract answers it rather than rendering something odd.
     */
    public function testRecordBlockContainerClassification(): void
    {
        $this->assertTrue(BlockContract::isContainer('dataRecord'));
        $this->assertFalse(BlockContract::isContainer('recordFields'));

        $result = BlockValidator::validate([[
            'type' => 'recordFields',
            'from' => 'rec',
            'children' => [],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('leaf block and cannot have', $result['errors'][0]);
    }

    /**
     * Every `...From` twin is OPTIONAL and its literal stays REQUIRED, so every
     * tree that validated before this release still validates. The fallback is
     * the point: a record page needs a title before its record arrives.
     */
    public function testLiteralLeavesStillValidateWithoutTheirBindings(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'heading', 'level' => 1, 'text' => 'Plain'],
            ['type' => 'text', 'value' => 'Plain'],
            ['type' => 'badge', 'variant' => 'info', 'label' => 'Plain'],
            ['type' => 'stat', 'label' => 'Plain', 'value' => '1'],
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));

        $missingLiteral = BlockValidator::validate([
            ['type' => 'heading', 'level' => 1, 'textFrom' => 'rec.name'],
        ]);
        $this->assertFalse($missingLiteral['ok']);
        $this->assertStringContainsString("missing required prop 'text'", $missingLiteral['errors'][0]);
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
    // ==================== #868: ouScopePicker ====================

    /**
     * Wrap a candidate input in the minimal valid form the contract requires,
     * so each test below asserts the picker's own rules rather than re-proving
     * the form-ancestor one.
     *
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private static function inForm(array $input): array
    {
        return [[
            'type'     => 'form',
            'submit'   => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [$input, ['type' => 'submitButton', 'label' => 'Save']],
        ]];
    }

    public function testOuScopePickerIsInTheWhitelistAsALeaf(): void
    {
        $this->assertTrue(BlockContract::isKnown('ouScopePicker'));
        $this->assertFalse(BlockContract::isContainer('ouScopePicker'));
    }

    /**
     * The structural property the whole design rests on: this is the only
     * fetching leaf with NO `source`. The loader's ownership walk keys off
     * `props.source.type === 'apiPath'`, so a `source` here would put the picker
     * behind a plugin-owned route — the exact republishing of core's hierarchy
     * the block exists to avoid.
     */
    public function testOuScopePickerDeclaresNoSourceProp(): void
    {
        $rule = BlockContract::rulesFor('ouScopePicker');

        $this->assertIsArray($rule);
        $this->assertArrayNotHasKey(
            'source',
            $rule['props'],
            'ouScopePicker must declare no source: its units come from core, not from a plugin route'
        );
    }

    public function testMinimalOuScopePickerInsideAFormIsValid(): void
    {
        $tree = self::inForm(['type' => 'ouScopePicker', 'name' => 'appliesTo', 'label' => 'Applies to']);

        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testFullyDeclaredOuScopePickerIsValid(): void
    {
        $tree = self::inForm([
            'type'        => 'ouScopePicker',
            'name'        => 'appliesTo',
            'label'       => 'Applies to',
            'scopes'      => ['subtree', 'children'],
            'anchorType'  => 'faculty',
            'memberType'  => 'acme:department',
            'required'    => true,
            'placeholder' => 'Choose a faculty',
        ]);

        $this->assertSame(['ok' => true, 'errors' => []], BlockValidator::validate($tree));
    }

    public function testOuScopePickerAtTopLevelIsRejected(): void
    {
        $result = BlockValidator::validate([
            ['type' => 'ouScopePicker', 'name' => 'x', 'label' => 'X'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            "'ouScopePicker' is only valid inside a 'form'",
            implode(' | ', $result['errors'])
        );
    }

    public function testOuScopePickerMissingNameOrLabelIsRejected(): void
    {
        $result = BlockValidator::validate(self::inForm(['type' => 'ouScopePicker']));

        $this->assertFalse($result['ok']);
        $joined = implode(' | ', $result['errors']);
        $this->assertStringContainsString("missing required prop 'name'", $joined);
        $this->assertStringContainsString("missing required prop 'label'", $joined);
    }

    /**
     * The picker's `name` participates in the per-form duplicate registry like
     * any other input leaf: its value occupies one payload key, so a collision
     * would silently overwrite a sibling's.
     */
    public function testOuScopePickerNameCollidesWithASiblingInput(): void
    {
        $result = BlockValidator::validate([[
            'type'     => 'form',
            'submit'   => ['method' => 'POST', 'endpoint' => '/api/x/y'],
            'children' => [
                ['type' => 'textInput', 'name' => 'scope', 'label' => 'Scope'],
                ['type' => 'ouScopePicker', 'name' => 'scope', 'label' => 'Applies to'],
                ['type' => 'submitButton', 'label' => 'Save'],
            ],
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("duplicate input name 'scope'", implode(' | ', $result['errors']));
    }

    // ---- scopes (the `ouScopeList` prop-rule kind) ----

    public function testEachScopeKindIsAcceptedOnItsOwn(): void
    {
        foreach (BlockValidator::OU_SCOPES as $scope) {
            $result = BlockValidator::validate(self::inForm([
                'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'scopes' => [$scope],
            ]));

            $this->assertTrue($result['ok'], "scope '{$scope}' must be accepted: " . implode(' | ', $result['errors']));
        }
    }

    public function testUnknownScopeIsRejected(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'scopes' => ['ancestors'],
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be one of [unit, subtree, children]', implode(' | ', $result['errors']));
    }

    public function testEmptyScopeListIsRejected(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'scopes' => [],
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be a non-empty list of [unit, subtree, children]', implode(' | ', $result['errors']));
    }

    /**
     * Order is meaningful (the first entry is the control's opening state), so a
     * repeated entry is an author error rather than a set operation to absorb.
     */
    public function testDuplicateScopeIsRejected(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'scopes' => ['subtree', 'subtree'],
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("duplicate 'ouScopePicker.scopes' entry 'subtree'", implode(' | ', $result['errors']));
    }

    public function testScopesMustBeAListNotAMap(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'scopes' => ['first' => 'unit'],
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be a non-empty list of', implode(' | ', $result['errors']));
    }

    // ---- anchorType / memberType (the `ouTypeKey` prop-rule kind) ----

    public function testBareAndNamespacedOuTypeKeysAreAccepted(): void
    {
        foreach (['faculty', 'sub_unit', 'acme:clinic', 'none'] as $key) {
            $result = BlockValidator::validate(self::inForm([
                'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'anchorType' => $key,
            ]));

            $this->assertTrue($result['ok'], "type key '{$key}' must be accepted: " . implode(' | ', $result['errors']));
        }
    }

    /**
     * The same grammar `GET /api/ous?type=` enforces — a key this accepts is a
     * key that endpoint accepts, so a picker cannot be configured with something
     * the filter would 422 on.
     */
    public function testMalformedOuTypeKeysAreRejected(): void
    {
        foreach (['Faculty', 'faculty:', ':clinic', 'a:b:c', '9lives', 'has space', 'kebab-case', ''] as $key) {
            $result = BlockValidator::validate(self::inForm([
                'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'memberType' => $key,
            ]));

            $this->assertFalse($result['ok'], "type key '{$key}' must be rejected");
            $this->assertStringContainsString(
                'must be a lowercase organizational-unit type key',
                implode(' | ', $result['errors'])
            );
        }
    }

    public function testOverlongOuTypeKeyIsRejected(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A',
            'anchorType' => str_repeat('a', 129),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            'must be a lowercase organizational-unit type key',
            implode(' | ', $result['errors'])
        );
    }

    public function testNonStringOuTypeKeyIsRejected(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'anchorType' => 42,
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            'must be a lowercase organizational-unit type key',
            implode(' | ', $result['errors'])
        );
    }

    // ---- the cross-prop rule ----

    /**
     * A kind filter over a scope that resolves to exactly the unit the user
     * picked can only ever remove it. Refused rather than ignored: the author
     * meant something, and it is not what the declaration does.
     */
    public function testMemberTypeWithUnitOnlyScopesIsRejected(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A',
            'scopes' => ['unit'], 'memberType' => 'department',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString(
            "'ouScopePicker.memberType' cannot apply when 'scopes' is exactly ['unit']",
            implode(' | ', $result['errors'])
        );
    }

    public function testMemberTypeWithAWiderScopeListIsAccepted(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A',
            'scopes' => ['unit', 'subtree'], 'memberType' => 'department',
        ]));

        $this->assertTrue($result['ok'], implode(' | ', $result['errors']));
    }

    /**
     * `scopes` omitted means all three, which includes scopes a kind filter
     * applies to — so the cross-prop rule must NOT fire on the default.
     */
    public function testMemberTypeWithDefaultScopesIsAccepted(): void
    {
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A', 'memberType' => 'department',
        ]));

        $this->assertTrue($result['ok'], implode(' | ', $result['errors']));
    }

    public function testUnknownPropOnOuScopePickerIsIgnoredButKnownOnesAreChecked(): void
    {
        // The whitelist checks DECLARED props; an undeclared one is not a prop
        // rule the contract knows, and `source` is precisely the one an author
        // might reach for out of habit. It must not turn into a fetch.
        $result = BlockValidator::validate(self::inForm([
            'type' => 'ouScopePicker', 'name' => 'a', 'label' => 'A',
            'source' => '/api/v1/ous',
        ]));

        $this->assertTrue($result['ok'], implode(' | ', $result['errors']));

        $rule = BlockContract::rulesFor('ouScopePicker');
        $this->assertIsArray($rule);
        $this->assertArrayNotHasKey('source', $rule['props']);
    }
}
