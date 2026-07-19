<?php

declare(strict_types=1);

namespace UiKitShowcase;

use UiKitShowcase\Migrations\GrantUiKitViewToAdmin;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

/**
 * UiKitShowcasePlugin (WC-228 / WC-232 / WC-236 / WC-240 / WC-241)
 *
 * The capstone example plugin for the SP1 + SP2 + SP3 + SP4 server-driven
 * plugin-UI block system (SDK 1.11, WC-225–WC-241). It is a SANCTIONED
 * example plugin — named for the SDK feature it documents — that proves AND
 * documents the entire pipeline:
 *
 *   SDK BlockContract whitelist (WC-225)
 *     -> host BlockValidator validation of `screen: 'blocks'` features (WC-226)
 *       -> web BlockRenderer at /admin/x/[featureId] (WC-227)
 *         -> THIS plugin's single `ui-kit-reference` feature.
 *
 * As of WC-232, the plugin exposes two read-only GET demo endpoints returning
 * static fixtures (no DB), both gated on `uikit:view`, that the SP2 data-bound
 * block demos (`dataTable`, `dataStat`, `dataList`) bind to. The host (WC-230)
 * verifies each block's `source` is one of the plugin's own registered GET
 * routes, then rewrites it to the versioned URL before serving the descriptor.
 *
 * As of WC-236, the plugin also exposes a write demo endpoint:
 * `POST /api/uikit/demo/echo` (gated on `uikit:view`, DB-free). It reads the
 * JSON body; if the required `name` field is missing or empty it returns a 422
 * with `{issues:[{severity:'error',message,column:'name'}]}`; otherwise it
 * echoes the body back as `{data:{received:…}}`. The Interactive tab demos
 * an SP3 `form` (all 9 input leaf types + a `submitButton`) and a standalone
 * `actionButton`, both targeting this endpoint and gated on `uikit:view`.
 *
 * As of WC-240, the plugin also demos the SP4 `chart` block: a bar chart bound
 * to `GET /api/uikit/demo/chart-rows` (gated on `uikit:view`, DB-free), whose
 * two series each pick one of the five semantic `--chart-1..5` design tokens —
 * never a raw hex/rgb value.
 *
 * As of WC-241, the `dataTable` and `dataList` demos also show inline
 * client-side sort/filter/pagination: sortable/filterable column flags and a
 * `pageSize`, all applied entirely to the rows already fetched from the same
 * `GET /api/uikit/demo/rows` endpoint — no additional route is ever touched.
 *
 * The plugin contributes ONE `screen: 'blocks'` feature whose declarative tree
 * renders a LIVE instance of every one of the 34 block types (21 SP1+SP2 + 12
 * SP3 interactive + 1 SP4 chart) beside the exact PHP snippet that declares it.
 *
 * Props are SEMANTIC throughout (never CSS/hex/pixels), exactly as the
 * contract requires.
 *
 * It lives in its own directory (`plugins/UiKitShowcase/`) so the PluginLoader
 * resolves it under the `UiKitShowcase` namespace prefix (directory name) and
 * auto-discovers it without any manual registration.
 */
final class UiKitShowcasePlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'UiKitShowcase';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Interactive block types landed in SDK 1.8 ({@see \Whity\Sdk\Sdk::VERSION});
     * the showcase requires that range as of WC-236.
     *
     * @inheritDoc
     */
    public function getSdkConstraint(): string
    {
        return '^1.8';
    }

    /**
     * No host core-version constraint: the showcase runs against any core that
     * ships the SDK range it requires.
     *
     * @inheritDoc
     */
    public function getCoreConstraint(): string
    {
        return '';
    }

    /**
     * The showcase depends on no other plugin.
     *
     * @inheritDoc
     */
    public function getPluginDependencies(): array
    {
        return [];
    }

    /**
     * Four demo endpoints (WC-232 + WC-236 + WC-240). All are gated on `uikit:view`
     * (the single existing permission — no new permission or migration).
     *
     * GET /api/uikit/demo/rows        — static fixture collection (SP2 data-bound demos)
     * GET /api/uikit/demo/metric      — static fixture metric (SP2 data-bound stat demo)
     * GET /api/uikit/demo/chart-rows  — static fixture series (SP4 chart demo)
     * POST /api/uikit/demo/echo       — interactive echo for SP3 form + actionButton demos
     *
     * @inheritDoc
     */
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/uikit/demo/rows',
                'handler' => [$this, 'demoRows'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo collection for data-bound block examples',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoRowsResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/uikit/demo/metric',
                'handler' => [$this, 'demoMetric'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo metric for data-bound stat block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoMetricResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            // WC-240: fixture endpoint for the SP4 chart block demo.
            [
                'method' => 'GET',
                'path' => '/api/uikit/demo/chart-rows',
                'handler' => [$this, 'demoChartRows'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo series for the chart block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoChartRowsResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            // WC-236: write endpoint for SP3 interactive block demos.
            [
                'method' => 'POST',
                'path' => '/api/uikit/demo/echo',
                'handler' => [$this, 'demoEcho'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo echo endpoint for interactive block examples',
                    'tags' => ['uikit-showcase'],
                    'request' => 'UiKitDemoEchoRequest',
                    'responses' => [
                        200 => 'UiKitDemoEchoResponse',
                        422 => 'UiKitDemoEchoIssues',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
        ];
    }

    /**
     * Handle GET /api/uikit/demo/rows (requires uikit:view).
     *
     * Returns a static fixture collection used by the data-bound block demos
     * (dataTable and dataList). No PDO, no side effects.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response Static demo rows.
     */
    public function demoRows(Request $request, array $params = []): Response
    {
        return Response::json([
            'data' => [
                ['name' => 'Anika Patel', 'role' => 'Administrator'],
                ['name' => 'Bjorn Larsen', 'role' => 'Editor'],
                ['name' => 'Camille Dupont', 'role' => 'Viewer'],
            ],
        ]);
    }

    /**
     * Handle GET /api/uikit/demo/metric (requires uikit:view).
     *
     * Returns a static fixture metric used by the data-bound stat block demo.
     * No PDO, no side effects.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response Static demo metric.
     */
    public function demoMetric(Request $request, array $params = []): Response
    {
        return Response::json([
            'data' => [
                'label' => 'Active users',
                'value' => '1,284',
                'trend' => 'up',
                'hint' => '+12% this week',
            ],
        ]);
    }

    /**
     * Handle GET /api/uikit/demo/chart-rows (requires uikit:view).
     *
     * Returns a static fixture series used by the chart block demo. No PDO,
     * no side effects.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response Static demo chart series.
     */
    public function demoChartRows(Request $request, array $params = []): Response
    {
        return Response::json([
            'data' => [
                ['role' => 'Administrator', 'count' => 3, 'lastMonth' => 2],
                ['role' => 'Editor', 'count' => 7, 'lastMonth' => 5],
                ['role' => 'Viewer', 'count' => 12, 'lastMonth' => 9],
            ],
        ]);
    }

    /**
     * Handle POST /api/uikit/demo/echo (requires uikit:view).
     *
     * Reads the JSON body. If the required `name` field is missing or empty,
     * returns a 422 with `{issues:[{severity:'error',message,column:'name'}]}`.
     * Otherwise echoes the body back as `{data:{received:…}}` (200).
     *
     * DB-free, FrankenPHP-safe (no static state).
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response 200 with echo data or 422 with validation issues.
     */
    public function demoEcho(Request $request, array $params = []): Response
    {
        $raw = $request->getBody();
        /** @var mixed $body */
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            $body = [];
        }

        // Validate `name` only when the body is non-empty (i.e. at least one
        // field was submitted — the form always includes fields; the actionButton
        // sends `{}` and should succeed without providing form data).
        if (count($body) > 0) {
            $name = $body['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                return Response::json(
                    [
                        'issues' => [
                            [
                                'severity' => 'error',
                                'message' => 'Name is required',
                                'column' => 'name',
                            ],
                        ],
                    ],
                    422
                );
            }
        }

        return Response::json([
            'data' => [
                'received' => $body,
            ],
        ]);
    }

    /**
     * One permission, in the mandated `resource:action` colon notation, that
     * the `ui-kit-reference` feature and demo endpoints are gated on.
     *
     * @inheritDoc
     */
    public function getPermissions(): array
    {
        return ['uikit:view'];
    }

    /**
     * No hooks — the showcase observes no platform events.
     *
     * @inheritDoc
     */
    public function getHooks(): array
    {
        return [];
    }

    /**
     * Seed and grant `uikit:view` so admins see the reference screen on a fresh
     * install.
     *
     * @inheritDoc
     */
    public function getMigrations(): array
    {
        return [GrantUiKitViewToAdmin::class];
    }

    /**
     * Declare the single `screen: 'blocks'` reference feature (SDK 1.8).
     *
     * UI metadata only — the descriptor grants nothing; the host validates the
     * `blocks` tree against {@see \Whity\Sdk\Frontend\Blocks\BlockValidator} and
     * filters the descriptor per caller against `uikit:view`.
     *
     * @inheritDoc
     */
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'ui-kit-reference',
                'label' => 'UI-Kit Reference',
                'icon' => 'components',
                'group' => 'plugins',
                'order' => 20,
                'screen' => 'blocks',
                'blocks' => $this->blocks(),
                'requiredPermission' => 'uikit:view',
            ],
        ];
    }

    /**
     * Build the reference block tree.
     *
     * Top level: an intro section, then a `tabs` set splitting the catalogue
     * into Containers / Content / Data / Interactive — each tab pairing a live
     * block with the PHP that declares it (via {@see self::demo()}). Every one
     * of the 33 block types (21 SP1+SP2 + 12 SP3 interactive) appears at least
     * once, and the result passes
     * {@see \Whity\Sdk\Frontend\Blocks\BlockValidator::validate()}.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(): array
    {
        return [
            [
                'type' => 'section',
                'title' => 'Block catalogue',
                'children' => [
                    [
                        'type' => 'heading',
                        'level' => 1,
                        'text' => 'SP1 UI Blocks',
                    ],
                    [
                        'type' => 'text',
                        'value' => 'A live reference for every server-driven plugin-UI block. '
                            . 'Each example renders the real block beside the exact PHP that declares it — '
                            . 'copy any snippet straight into your plugin\'s getFrontendFeatures().',
                        'tone' => 'muted',
                    ],
                    [
                        'type' => 'alert',
                        'variant' => 'info',
                        'title' => 'Platform-neutral by design',
                        'body' => 'Props are SEMANTIC, never presentational — say variant => \'danger\', '
                            . 'never a color or pixel value. The same tree renders idiomatically on web, '
                            . 'mobile, and desktop.',
                    ],
                ],
            ],
            [
                'type' => 'tabs',
                'children' => [
                    [
                        'type' => 'tab',
                        'label' => 'Containers',
                        'children' => $this->containersTab(),
                    ],
                    [
                        'type' => 'tab',
                        'label' => 'Content',
                        'children' => $this->contentTab(),
                    ],
                    [
                        'type' => 'tab',
                        'label' => 'Data',
                        'children' => $this->dataTab(),
                    ],
                    [
                        'type' => 'tab',
                        'label' => 'Interactive',
                        'children' => $this->interactiveTab(),
                    ],
                ],
            ],
        ];
    }

    /**
     * The "Containers" tab: section, card, grid, row, tabs (this very tab set),
     * tab, and divider.
     *
     * @return list<array<string, mixed>>
     */
    private function containersTab(): array
    {
        return [
            [
                'type' => 'heading',
                'level' => 2,
                'text' => 'Layout containers',
            ],
            [
                'type' => 'text',
                'value' => 'Containers carry a children array; leaves do not. tabs / tab build '
                    . 'this very tab set, and grid / row arrange their children.',
            ],
            $this->demo(
                'section',
                'A labelled vertical grouping of blocks.',
                [
                    'type' => 'section',
                    'title' => 'A nested section',
                    'children' => [
                        [
                            'type' => 'text',
                            'value' => 'Sections stack their children vertically under an optional title.',
                        ],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'section', 'title' => 'A nested section', 'children' => [
                        ['type' => 'text', 'value' => '...'],
                    ]]
                    PHP,
            ),
            $this->demo(
                'card',
                'A surface with an optional title/description and a body.',
                [
                    'type' => 'card',
                    'title' => 'Card title',
                    'description' => 'An optional supporting description.',
                    'children' => [
                        [
                            'type' => 'text',
                            'value' => 'Card bodies hold any blocks.',
                        ],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'card', 'title' => 'Card title',
                     'description' => 'An optional supporting description.', 'children' => [
                        ['type' => 'text', 'value' => 'Card bodies hold any blocks.'],
                    ]]
                    PHP,
            ),
            $this->demo(
                'grid',
                'An N-column responsive grid (columns: 1 | 2 | 3 | 4).',
                [
                    'type' => 'grid',
                    'columns' => 3,
                    'children' => [
                        ['type' => 'badge', 'variant' => 'info', 'label' => 'One'],
                        ['type' => 'badge', 'variant' => 'success', 'label' => 'Two'],
                        ['type' => 'badge', 'variant' => 'warning', 'label' => 'Three'],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'grid', 'columns' => 3, 'children' => [
                        ['type' => 'badge', 'variant' => 'info', 'label' => 'One'],
                        ['type' => 'badge', 'variant' => 'success', 'label' => 'Two'],
                        ['type' => 'badge', 'variant' => 'warning', 'label' => 'Three'],
                    ]]
                    PHP,
            ),
            $this->demo(
                'row',
                'A horizontal row with an optional align (start | center | end | between).',
                [
                    'type' => 'row',
                    'align' => 'between',
                    'children' => [
                        ['type' => 'badge', 'variant' => 'neutral', 'label' => 'Left'],
                        ['type' => 'badge', 'variant' => 'neutral', 'label' => 'Right'],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'row', 'align' => 'between', 'children' => [
                        ['type' => 'badge', 'variant' => 'neutral', 'label' => 'Left'],
                        ['type' => 'badge', 'variant' => 'neutral', 'label' => 'Right'],
                    ]]
                    PHP,
            ),
            [
                'type' => 'card',
                'title' => 'tabs + tab',
                'description' => 'A tab set whose children are tab blocks — the tabs above this page '
                    . 'demonstrate them. A tab is only valid as a direct child of tabs.',
                'children' => [
                    [
                        'type' => 'code',
                        'language' => 'php',
                        'content' => <<<'PHP'
                            ['type' => 'tabs', 'children' => [
                                ['type' => 'tab', 'label' => 'First', 'children' => [ /* ... */ ]],
                                ['type' => 'tab', 'label' => 'Second', 'children' => [ /* ... */ ]],
                            ]]
                            PHP,
                    ],
                ],
            ],
            $this->demo(
                'divider',
                'A horizontal separator between blocks.',
                ['type' => 'divider'],
                "['type' => 'divider']",
            ),
        ];
    }

    /**
     * The "Content" tab: heading, text, alert, badge, icon, code.
     *
     * @return list<array<string, mixed>>
     */
    private function contentTab(): array
    {
        return [
            [
                'type' => 'heading',
                'level' => 2,
                'text' => 'Content blocks',
            ],
            $this->demo(
                'heading',
                'A semantic heading at one of four levels (level: 1 | 2 | 3 | 4).',
                ['type' => 'heading', 'level' => 3, 'text' => 'A level-3 heading'],
                "['type' => 'heading', 'level' => 3, 'text' => 'A level-3 heading']",
            ),
            $this->demo(
                'text',
                'A paragraph, optionally muted (tone: default | muted).',
                ['type' => 'text', 'value' => 'Body copy, optionally muted.', 'tone' => 'muted'],
                "['type' => 'text', 'value' => 'Body copy, optionally muted.', 'tone' => 'muted']",
            ),
            $this->demo(
                'alert',
                'A callout banner (variant: info | success | warning | danger).',
                [
                    'type' => 'alert',
                    'variant' => 'warning',
                    'title' => 'Heads up',
                    'body' => 'Use alerts for state the reader must notice.',
                ],
                <<<'PHP'
                    ['type' => 'alert', 'variant' => 'warning', 'title' => 'Heads up',
                     'body' => 'Use alerts for state the reader must notice.']
                    PHP,
            ),
            $this->demo(
                'badge',
                'A small status pill (variant: neutral | info | success | warning | danger).',
                [
                    'type' => 'row',
                    'children' => [
                        ['type' => 'badge', 'variant' => 'neutral', 'label' => 'neutral'],
                        ['type' => 'badge', 'variant' => 'info', 'label' => 'info'],
                        ['type' => 'badge', 'variant' => 'success', 'label' => 'success'],
                        ['type' => 'badge', 'variant' => 'warning', 'label' => 'warning'],
                        ['type' => 'badge', 'variant' => 'danger', 'label' => 'danger'],
                    ],
                ],
                "['type' => 'badge', 'variant' => 'success', 'label' => 'active']",
            ),
            $this->demo(
                'icon',
                'A Tabler icon by name (tone: default | muted).',
                [
                    'type' => 'row',
                    'children' => [
                        ['type' => 'icon', 'name' => 'rocket'],
                        ['type' => 'icon', 'name' => 'bell', 'tone' => 'muted'],
                        ['type' => 'icon', 'name' => 'check'],
                    ],
                ],
                "['type' => 'icon', 'name' => 'rocket', 'tone' => 'default']",
            ),
            $this->demo(
                'code',
                'A monospaced code sample, rendered as literal text (never executed).',
                [
                    'type' => 'code',
                    'language' => 'json',
                    'content' => '{ "screen": "blocks", "requiredPermission": "uikit:view" }',
                ],
                <<<'PHP'
                    ['type' => 'code', 'language' => 'json',
                     'content' => '{ "screen": "blocks" }']
                    PHP,
            ),
        ];
    }

    /**
     * The "Data" tab: stat, keyValue, list, table (SP1 static) plus the
     * data-bound demos in a "Live data" section: dataTable, dataStat, dataList
     * (SP2) and chart (SP4).
     *
     * @return list<array<string, mixed>>
     */
    private function dataTab(): array
    {
        return [
            [
                'type' => 'heading',
                'level' => 2,
                'text' => 'Data display',
            ],
            $this->demo(
                'stat',
                'A metric tile with an optional hint and trend (up | down | flat).',
                [
                    'type' => 'grid',
                    'columns' => 3,
                    'children' => [
                        ['type' => 'stat', 'label' => 'Active users', 'value' => '1,284', 'trend' => 'up', 'hint' => '+12% this week'],
                        ['type' => 'stat', 'label' => 'Errors', 'value' => '3', 'trend' => 'down'],
                        ['type' => 'stat', 'label' => 'Uptime', 'value' => '99.9%', 'trend' => 'flat'],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'stat', 'label' => 'Active users', 'value' => '1,284',
                     'trend' => 'up', 'hint' => '+12% this week']
                    PHP,
            ),
            $this->demo(
                'keyValue',
                'A definition list of label/value pairs.',
                [
                    'type' => 'keyValue',
                    'items' => [
                        ['label' => 'Plugin', 'value' => 'UiKitShowcase'],
                        ['label' => 'SDK', 'value' => '^1.8'],
                        ['label' => 'Screen', 'value' => 'blocks'],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'keyValue', 'items' => [
                        ['label' => 'Plugin', 'value' => 'UiKitShowcase'],
                        ['label' => 'SDK', 'value' => '^1.8'],
                    ]]
                    PHP,
            ),
            $this->demo(
                'list',
                'An ordered or unordered list of plain strings.',
                [
                    'type' => 'list',
                    'ordered' => true,
                    'items' => [
                        'Declare the feature with screen => \'blocks\'.',
                        'Build the tree from whitelisted blocks.',
                        'The host validates and ships it verbatim.',
                    ],
                ],
                <<<'PHP'
                    ['type' => 'list', 'ordered' => true, 'items' => [
                        'Declare the feature.', 'Build the tree.', 'Ship it.',
                    ]]
                    PHP,
            ),
            $this->demo(
                'table',
                'A static table of string cells keyed by column.',
                [
                    'type' => 'table',
                    'columns' => [
                        ['key' => 'block', 'label' => 'Block'],
                        ['key' => 'kind', 'label' => 'Kind'],
                    ],
                    'rows' => [
                        ['block' => 'section', 'kind' => 'container'],
                        ['block' => 'heading', 'kind' => 'leaf'],
                        ['block' => 'table', 'kind' => 'leaf'],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'table',
                     'columns' => [['key' => 'block', 'label' => 'Block'],
                                   ['key' => 'kind', 'label' => 'Kind']],
                     'rows' => [['block' => 'section', 'kind' => 'container'],
                                ['block' => 'heading', 'kind' => 'leaf']]]
                    PHP,
            ),
            // ---- SP2 data-bound demos (WC-232) ----
            [
                'type' => 'section',
                'title' => 'Live data',
                'children' => [
                    [
                        'type' => 'text',
                        'value' => 'Data-bound blocks fetch their content from one of the plugin\'s '
                            . 'own RBAC-gated endpoints at render time. '
                            . 'Declare a `source` (an unversioned `/api/...` path the plugin itself registers); '
                            . 'the host verifies ownership and rewrites it to the versioned URL.',
                        'tone' => 'muted',
                    ],
                    $this->dataBoundDemo(
                        'dataTable',
                        'A table whose rows are fetched from a plugin endpoint at render time. '
                            . 'The Name column is sortable + filterable, and pageSize turns on '
                            . 'inline client-side pagination (WC-241) — all three operate over the '
                            . 'rows already fetched from this one endpoint, no second request.',
                        [
                            'type' => 'dataTable',
                            'source' => '/api/uikit/demo/rows',
                            'columns' => [
                                ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                                ['key' => 'role', 'label' => 'Role', 'sortable' => true],
                            ],
                            'pageSize' => 2,
                        ],
                        <<<'PHP'
                            ['type' => 'dataTable',
                             'source' => '/api/uikit/demo/rows',
                             'columns' => [
                                 ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                                 ['key' => 'role', 'label' => 'Role', 'sortable' => true],
                             ],
                             'pageSize' => 2]
                            PHP,
                        <<<'PHP'
                            // GET /api/uikit/demo/rows — returns:
                            // { "data": [{"name":"Anika Patel","role":"..."},] }
                            public function demoRows(Request $r, array $p = []): Response {
                                return Response::json(['data' => [
                                    ['name' => 'Anika Patel',   'role' => 'Administrator'],
                                    ['name' => 'Bjorn Larsen',  'role' => 'Editor'],
                                    ['name' => 'Camille Dupont','role' => 'Viewer'],
                                ]]);
                            }
                            PHP,
                    ),
                    $this->dataBoundDemo(
                        'dataStat',
                        'A metric tile whose value, trend, and hint are fetched from a plugin endpoint.',
                        [
                            'type' => 'dataStat',
                            'source' => '/api/uikit/demo/metric',
                            'label' => 'Active users',
                            'valueField' => 'value',
                            'trendField' => 'trend',
                            'hintField' => 'hint',
                        ],
                        <<<'PHP'
                            ['type' => 'dataStat',
                             'source' => '/api/uikit/demo/metric',
                             'label' => 'Active users',
                             'valueField' => 'value',
                             'trendField' => 'trend',
                             'hintField' => 'hint']
                            PHP,
                        <<<'PHP'
                            // GET /api/uikit/demo/metric — returns:
                            // { "data": {"label":"Active users","value":"1,284","trend":"up","hint":"..."} }
                            public function demoMetric(Request $r, array $p = []): Response {
                                return Response::json(['data' => [
                                    'label' => 'Active users', 'value' => '1,284',
                                    'trend' => 'up', 'hint' => '+12% this week',
                                ]]);
                            }
                            PHP,
                    ),
                    $this->dataBoundDemo(
                        'dataList',
                        'An unordered list whose items are fetched from a plugin endpoint. '
                            . 'sortable/filterable/pageSize (WC-241) add an alphabetical sort '
                            . 'toggle, a search box, and inline pagination over the same fetch.',
                        [
                            'type' => 'dataList',
                            'source' => '/api/uikit/demo/rows',
                            'itemField' => 'name',
                            'sortable' => true,
                            'filterable' => true,
                            'pageSize' => 2,
                        ],
                        <<<'PHP'
                            ['type' => 'dataList',
                             'source' => '/api/uikit/demo/rows',
                             'itemField' => 'name',
                             'sortable' => true,
                             'filterable' => true,
                             'pageSize' => 2]
                            PHP,
                        <<<'PHP'
                            // Same GET /api/uikit/demo/rows endpoint — `itemField`
                            // picks the column to render as list items.
                            PHP,
                    ),
                    $this->dataBoundDemo(
                        'chart',
                        'A bar/line/area/pie chart whose rows are fetched from a plugin endpoint; '
                            . 'each series picks a --chart-1..5 design token, never a raw color.',
                        [
                            'type' => 'chart',
                            'source' => '/api/uikit/demo/chart-rows',
                            'chartType' => 'bar',
                            'xField' => 'role',
                            'series' => [
                                ['key' => 'count', 'label' => 'This month', 'color' => 1],
                                ['key' => 'lastMonth', 'label' => 'Last month', 'color' => 2],
                            ],
                        ],
                        <<<'PHP'
                            ['type' => 'chart',
                             'source' => '/api/uikit/demo/chart-rows',
                             'chartType' => 'bar',
                             'xField' => 'role',
                             'series' => [
                                 ['key' => 'count', 'label' => 'This month', 'color' => 1],
                                 ['key' => 'lastMonth', 'label' => 'Last month', 'color' => 2],
                             ]]
                            PHP,
                        <<<'PHP'
                            // GET /api/uikit/demo/chart-rows — returns:
                            // { "data": [{"role":"Administrator","count":3,"lastMonth":2},] }
                            public function demoChartRows(Request $r, array $p = []): Response {
                                return Response::json(['data' => [
                                    ['role' => 'Administrator', 'count' => 3, 'lastMonth' => 2],
                                    ['role' => 'Editor',        'count' => 7, 'lastMonth' => 5],
                                    ['role' => 'Viewer',        'count' => 12, 'lastMonth' => 9],
                                ]]);
                            }
                            PHP,
                    ),
                ],
            ],
        ];
    }

    /**
     * The "Interactive" tab: button (every variant) plus the SP3 interactive
     * blocks — a form with all 9 input leaf types and a submitButton, and a
     * standalone actionButton. Both the form and actionButton target the plugin's
     * own `POST /api/uikit/demo/echo` endpoint and declare
     * `requiredPermission: 'uikit:view'` so the host (WC-234) accepts them and
     * the web (WC-235) gates the trigger accordingly.
     *
     * @return list<array<string, mixed>>
     */
    private function interactiveTab(): array
    {
        return [
            [
                'type' => 'heading',
                'level' => 2,
                'text' => 'Actions',
            ],
            [
                'type' => 'text',
                'value' => 'A button links to an INTERNAL route only — its href must be a relative '
                    . 'path starting with "/". The renderer makes any non-internal href inert.',
                'tone' => 'muted',
            ],
            $this->demo(
                'button',
                'A labelled link to an internal route (variant: primary | secondary | outline | ghost | destructive).',
                [
                    'type' => 'row',
                    'children' => [
                        ['type' => 'button', 'label' => 'Primary', 'href' => '/admin', 'variant' => 'primary'],
                        ['type' => 'button', 'label' => 'Secondary', 'href' => '/admin', 'variant' => 'secondary'],
                        ['type' => 'button', 'label' => 'Outline', 'href' => '/admin', 'variant' => 'outline'],
                        ['type' => 'button', 'label' => 'Ghost', 'href' => '/admin', 'variant' => 'ghost'],
                        ['type' => 'button', 'label' => 'Destructive', 'href' => '/admin', 'variant' => 'destructive'],
                    ],
                ],
                <<<'PHP'
                    ['type' => 'button', 'label' => 'Open dashboard',
                     'href' => '/admin', 'variant' => 'primary']
                    PHP,
            ),
            // ---- SP3 interactive demos (WC-236) ----
            [
                'type' => 'section',
                'title' => 'Interactive blocks',
                'children' => [
                    [
                        'type' => 'text',
                        'value' => 'Interactive blocks POST/PUT to a plugin-owned, RBAC-gated endpoint. '
                            . 'Declare `requiredPermission` on both the block and the route — the host '
                            . 'verifies ownership + permission match and rewrites the endpoint to the '
                            . 'versioned URL. The web renderer gates the trigger via PermissionButton.',
                        'tone' => 'muted',
                    ],
                    $this->dataBoundDemo(
                        'form',
                        'A form container with input leaves and a submitButton. '
                            . 'Submits the collected JSON to the plugin\'s own POST/PUT endpoint.',
                        [
                            'type' => 'form',
                            'submit' => [
                                'method' => 'POST',
                                'endpoint' => '/api/uikit/demo/echo',
                            ],
                            'requiredPermission' => 'uikit:view',
                            'children' => [
                                [
                                    'type' => 'textInput',
                                    'name' => 'name',
                                    'label' => 'Name',
                                    'placeholder' => 'Enter your name',
                                    'required' => true,
                                ],
                                [
                                    'type' => 'textArea',
                                    'name' => 'bio',
                                    'label' => 'Bio',
                                    'rows' => 3,
                                ],
                                [
                                    'type' => 'numberInput',
                                    'name' => 'age',
                                    'label' => 'Age',
                                    'min' => 0,
                                    'max' => 120,
                                ],
                                [
                                    'type' => 'select',
                                    'name' => 'role',
                                    'label' => 'Role',
                                    'options' => [
                                        ['value' => 'viewer', 'label' => 'Viewer'],
                                        ['value' => 'editor', 'label' => 'Editor'],
                                        ['value' => 'admin', 'label' => 'Administrator'],
                                    ],
                                ],
                                [
                                    'type' => 'checkbox',
                                    'name' => 'active',
                                    'label' => 'Active',
                                    'default' => true,
                                ],
                                [
                                    'type' => 'slider',
                                    'name' => 'level',
                                    'label' => 'Experience level',
                                    'min' => 1,
                                    'max' => 10,
                                ],
                                [
                                    'type' => 'dateInput',
                                    'name' => 'since',
                                    'label' => 'Member since',
                                ],
                                [
                                    'type' => 'fileInput',
                                    'name' => 'avatar',
                                    'label' => 'Avatar',
                                    'accept' => 'image/*',
                                ],
                                [
                                    'type' => 'colorInput',
                                    'name' => 'accent',
                                    'label' => 'Accent colour',
                                    'default' => '#6366f1',
                                ],
                                [
                                    'type' => 'submitButton',
                                    'label' => 'Submit',
                                    'requiredPermission' => 'uikit:view',
                                    'variant' => 'primary',
                                ],
                            ],
                        ],
                        <<<'PHP'
                            ['type' => 'form',
                             'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                             'requiredPermission' => 'uikit:view',
                             'children' => [
                                 ['type' => 'textInput',   'name' => 'name',  'label' => 'Name', 'required' => true],
                                 ['type' => 'textArea',    'name' => 'bio',   'label' => 'Bio',  'rows' => 3],
                                 ['type' => 'numberInput', 'name' => 'age',   'label' => 'Age',  'min' => 0, 'max' => 120],
                                 ['type' => 'select',      'name' => 'role',  'label' => 'Role',
                                  'options' => [['value' => 'viewer', 'label' => 'Viewer'],
                                                ['value' => 'editor', 'label' => 'Editor']]],
                                 ['type' => 'checkbox',    'name' => 'active', 'label' => 'Active', 'default' => true],
                                 ['type' => 'slider',      'name' => 'level', 'label' => 'Experience level', 'min' => 1, 'max' => 10],
                                 ['type' => 'dateInput',   'name' => 'since', 'label' => 'Member since'],
                                 ['type' => 'fileInput',   'name' => 'avatar','label' => 'Avatar', 'accept' => 'image/*'],
                                 ['type' => 'colorInput',  'name' => 'accent','label' => 'Accent colour', 'default' => '#6366f1'],
                                 ['type' => 'submitButton','label' => 'Submit','requiredPermission' => 'uikit:view','variant' => 'primary'],
                             ]]
                            PHP,
                        <<<'PHP'
                            // POST /api/uikit/demo/echo — reads JSON body; when the body
                            // is non-empty, validates that `name` is present and non-blank;
                            // returns 200 {data:{received:…}} or 422 {issues:[…]}.
                            // An empty {} (actionButton payload) bypasses validation.
                            public function demoEcho(Request $r, array $p = []): Response {
                                $body = json_decode($r->getBody(), true) ?? [];
                                if (count($body) > 0) {
                                    $name = $body['name'] ?? null;
                                    if (!is_string($name) || trim($name) === '') {
                                        return Response::json(['issues' => [[
                                            'severity' => 'error',
                                            'message'  => 'Name is required',
                                            'column'   => 'name',
                                        ]]], 422);
                                    }
                                }
                                return Response::json(['data' => ['received' => $body]]);
                            }
                            PHP,
                    ),
                    $this->dataBoundDemo(
                        'actionButton',
                        'A standalone one-click mutation button that POSTs to the plugin\'s own endpoint. '
                            . 'An optional `confirm` shows a confirmation dialog before firing.',
                        [
                            'type' => 'actionButton',
                            'label' => 'Run action',
                            'action' => [
                                'method' => 'POST',
                                'endpoint' => '/api/uikit/demo/echo',
                            ],
                            'requiredPermission' => 'uikit:view',
                            'confirm' => 'Run the demo action?',
                            'variant' => 'secondary',
                        ],
                        <<<'PHP'
                            ['type' => 'actionButton',
                             'label' => 'Run action',
                             'action' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                             'requiredPermission' => 'uikit:view',
                             'confirm' => 'Run the demo action?',
                             'variant' => 'secondary']
                            PHP,
                        <<<'PHP'
                            // Same POST /api/uikit/demo/echo endpoint — the actionButton sends an
                            // empty body {}; the handler returns 200 {data:{received:{}}} because
                            // the form's required `name` check is skipped for an empty payload
                            // (the echo route exists to demonstrate the feedback paths — the form
                            // enforces the `name` field; the actionButton sends whatever {} it likes).
                            // In a real plugin, use a dedicated endpoint per action.
                            PHP,
                    ),
                ],
            ],
        ];
    }

    /**
     * Emit one documented demo: a `card` titled by the block name, holding the
     * LIVE example block above a `code` block carrying the exact PHP that
     * declares it. Keeps the tree readable and uniform across every type.
     *
     * @param string               $blockType   the block type being documented (the card title)
     * @param string               $description one-line description of the block
     * @param array<string, mixed> $live        the live example node rendered to the reader
     * @param string               $snippet     the PHP source that declares the block
     *
     * @return array<string, mixed> a `card` node
     */
    private function demo(string $blockType, string $description, array $live, string $snippet): array
    {
        return [
            'type' => 'card',
            'title' => $blockType,
            'description' => $description,
            'children' => [
                $live,
                [
                    'type' => 'code',
                    'language' => 'php',
                    'content' => $snippet,
                ],
            ],
        ];
    }

    /**
     * Emit a data-bound demo card: a `card` holding the LIVE block,
     * the PHP block declaration snippet, and the endpoint handler snippet.
     *
     * Kept separate from {@see self::demo()} to make the three-child card shape
     * explicit and avoid overloading the generic helper's type annotations.
     * Also reused for SP3 interactive demos that pair a block snippet with an
     * endpoint snippet.
     *
     * @param string               $blockType        the block type (card title)
     * @param string               $description      one-line description
     * @param array<string, mixed> $live             the live block node
     * @param string               $blockSnippet     PHP for the block declaration
     * @param string               $endpointSnippet  PHP for the endpoint handler
     *
     * @return array<string, mixed> a `card` node
     */
    private function dataBoundDemo(
        string $blockType,
        string $description,
        array $live,
        string $blockSnippet,
        string $endpointSnippet
    ): array {
        return [
            'type' => 'card',
            'title' => $blockType,
            'description' => $description,
            'children' => [
                $live,
                [
                    'type' => 'code',
                    'language' => 'php',
                    'content' => $blockSnippet,
                ],
                [
                    'type' => 'code',
                    'language' => 'php',
                    'content' => $endpointSnippet,
                ],
            ],
        ];
    }

    /**
     * The OpenAPI component schemas for the demo endpoints.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function demoComponents(): array
    {
        return [
            'UiKitDemoRow' => [
                'type' => 'object',
                'required' => ['name', 'role'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'role' => ['type' => 'string'],
                ],
            ],
            'UiKitDemoRowsResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/UiKitDemoRow'],
                    ],
                ],
            ],
            'UiKitDemoMetricResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['label', 'value', 'trend', 'hint'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'trend' => ['type' => 'string'],
                            'hint' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'UiKitDemoChartRow' => [
                'type' => 'object',
                'required' => ['role', 'count', 'lastMonth'],
                'properties' => [
                    'role' => ['type' => 'string'],
                    'count' => ['type' => 'integer'],
                    'lastMonth' => ['type' => 'integer'],
                ],
            ],
            'UiKitDemoChartRowsResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/UiKitDemoChartRow'],
                    ],
                ],
            ],
            'UiKitDemoEchoRequest' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1],
                ],
            ],
            'UiKitDemoEchoResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['received'],
                        'properties' => [
                            'received' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
            'UiKitDemoEchoIssues' => [
                'type' => 'object',
                'required' => ['issues'],
                'properties' => [
                    'issues' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['severity', 'message', 'column'],
                            'properties' => [
                                'severity' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'column' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
