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
     * The demo endpoints (WC-232 + WC-236 + WC-240 + #909). All are DB-free
     * fixtures, and all but one are gated on `uikit:view`.
     *
     * GET  /api/uikit/demo/rows        — static fixture collection (SP2 data-bound demos)
     * GET  /api/uikit/demo/rows/{name} — one record (#883 dataRecord demo)
     * PUT  /api/uikit/demo/rows/{name} — the WRITE route behind the #909 accessGate demo,
     *                                    gated on `uikit:manage`, which is declared and
     *                                    never granted so the gate refuses for real
     * GET  /api/uikit/demo/metric      — static fixture metric (SP2 data-bound stat demo)
     * GET  /api/uikit/demo/chart-rows  — static fixture series (SP4 chart demo)
     * POST /api/uikit/demo/echo        — interactive echo for SP3 form + actionButton demos
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
            // #883: the RECORD endpoint behind the `dataRecord` demo — one
            // resource, not a collection. Its payload deliberately carries
            // caller-permission flags (`manageable`, `canEdit`) alongside the
            // record's own facts, because that is what a real endpoint returns
            // and it is the exact shape #895 went wrong on. The block
            // declaration names neither of them, and cannot: they are refused
            // as facts by the validator and never published by the renderer.
            [
                'method' => 'GET',
                'path' => '/api/uikit/demo/rows/{name}',
                'handler' => [$this, 'demoRecord'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo single record for the record-bound block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoRecordResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            // #909: the WRITE route behind the `accessGate` demo. Gated on
            // `uikit:manage`, which the plugin DECLARES and deliberately never
            // GRANTS — so on a stock install the gate refuses for everybody,
            // including the platform admin, and the Record tab renders its
            // read-only branch live rather than describing one. Grant
            // `uikit:manage` to a role and the same tree renders the editor
            // instead, with nothing in the declaration changing.
            [
                'method' => 'PUT',
                'path' => '/api/uikit/demo/rows/{name}',
                'handler' => [$this, 'demoRecordUpdate'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:manage',
                'schema' => [
                    'summary' => 'Demo single-record write, behind a permission nothing grants',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoRecordResponse',
                        403 => ['description' => 'Missing uikit:manage permission'],
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
            // #868: fixture endpoint for the workflow `timeline` block demo.
            [
                'method' => 'GET',
                'path' => '/api/uikit/demo/events',
                'handler' => [$this, 'demoEvents'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo event history for the timeline block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoEventsResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            // #950: the node set behind the `flow` block demo. ONE endpoint —
            // a row is a node, and its successors are a field on that row — so
            // there is no second route here for the edges.
            [
                'method' => 'GET',
                'path' => '/api/uikit/demo/flow-steps',
                'handler' => [$this, 'demoFlowSteps'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo process steps for the flow block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoFlowStepsResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            // #868: the queue behind the `inbox` block demo, plus the two write
            // routes its actions call. Each write route declares its OWN
            // requiredPermission — the single source of truth the host resolves
            // per caller when it decides whether to render that action.
            [
                'method' => 'GET',
                'path' => '/api/uikit/demo/tasks',
                'handler' => [$this, 'demoTasks'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo task queue for the inbox block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoTasksResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/uikit/demo/tasks/{id}/approve',
                'handler' => [$this, 'demoTaskAction'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo approve action for the inbox block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoTaskActionResponse',
                        403 => ['description' => 'Missing uikit:view permission'],
                    ],
                    'components' => self::demoComponents(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/uikit/demo/tasks/{id}/reject',
                'handler' => [$this, 'demoTaskAction'],
                'requiredRole' => null,
                'requiredPermission' => 'uikit:view',
                'schema' => [
                    'summary' => 'Demo reject action for the inbox block example',
                    'tags' => ['uikit-showcase'],
                    'responses' => [
                        200 => 'UiKitDemoTaskActionResponse',
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
     * Handle GET /api/uikit/demo/rows/{name} (requires uikit:view).
     *
     * Returns ONE static fixture record for the `dataRecord` demo. No PDO, no
     * side effects.
     *
     * The payload carries `manageable` and `canEdit` ON PURPOSE. A real record
     * endpoint answers both questions at once — what the record IS, and what
     * THIS caller may do to it — and #895 is what happens when a page reads the
     * second as the first. The showcase declaration names four facts and
     * neither flag, so this fixture is also the live proof that a caller flag
     * present in the payload is unreachable from the tree.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response One static demo record.
     */
    public function demoRecord(Request $request, array $params = []): Response
    {
        $name = $params['name'] ?? 'Anika Patel';

        $records = [
            'Anika Patel' => ['role' => 'Administrator', 'status' => 'Active', 'joined' => '2024-03-11'],
            'Bjorn Larsen' => ['role' => 'Editor', 'status' => 'Active', 'joined' => '2024-07-02'],
            'Camille Dupont' => ['role' => 'Viewer', 'status' => 'Invited', 'joined' => '2025-01-19'],
        ];

        $record = $records[$name] ?? $records['Anika Patel'];

        return Response::json([
            'data' => [
                'name' => \array_key_exists($name, $records) ? $name : 'Anika Patel',
                'role' => $record['role'],
                'status' => $record['status'],
                'joined' => $record['joined'],
                // Caller decisions, not record facts. Deliberately present.
                'manageable' => true,
                'canEdit' => true,
            ],
        ]);
    }

    /**
     * Handle PUT /api/uikit/demo/rows/{name} (requires uikit:manage).
     *
     * The write half of the record demo, and DB-free like every other fixture
     * here — it echoes the record back. What matters is not what it does but
     * that it EXISTS as a registered route with a gate of its own, because that
     * is what an `accessGate` asks the host about: the permitted-actions
     * resolver looks this route up in the live table and evaluates its
     * `uikit:manage` requirement for the caller. Nothing in the block tree
     * restates that slug, which is the whole point — re-gate this route and the
     * page follows without an edit.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response The record, echoed.
     */
    public function demoRecordUpdate(Request $request, array $params = []): Response
    {
        return $this->demoRecord($request, $params);
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
     * Handle GET /api/uikit/demo/events (requires uikit:view).
     *
     * A static fixture history for the `timeline` block demo, newest first.
     * No PDO, no side effects.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response Static demo events.
     */
    public function demoEvents(Request $request, array $params = []): Response
    {
        return Response::json([
            'data' => [
                [
                    'actor' => 'Anika Patel',
                    'action' => 'approved the request',
                    'at' => '2026-08-17 09:12',
                    'note' => 'Within the delegated limit.',
                    'from' => 'in review',
                    'to' => 'approved',
                ],
                [
                    'actor' => 'Bjorn Larsen',
                    'action' => 'moved it to review',
                    'at' => '2026-08-16 17:40',
                    'note' => '',
                    'from' => 'submitted',
                    'to' => 'in review',
                ],
                [
                    'actor' => 'Camille Dupont',
                    'action' => 'submitted the request',
                    'at' => '2026-08-16 14:03',
                    'note' => 'Conference travel, pre-approved by finance.',
                    'from' => '',
                    'to' => 'submitted',
                ],
            ],
        ]);
    }

    /**
     * Handle GET /api/uikit/demo/flow-steps (requires uikit:view).
     *
     * A static fixture process for the `flow` block demo: an expense-approval
     * route that BRANCHES at the review step. One endpoint, and a row is a node
     * — `next` holds the ids this step leads to, and holding a LIST is how the
     * branch is expressed without a second source for the edges. A terminal step
     * carries an empty list rather than being omitted, so "this step ends the
     * process" is stated rather than inferred from a missing key. No PDO, no
     * side effects.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response Static demo process steps.
     */
    public function demoFlowSteps(Request $request, array $params = []): Response
    {
        return Response::json([
            'data' => [
                [
                    'id' => 'submitted',
                    'name' => 'Submitted',
                    'owner' => 'Requester',
                    'next' => ['review'],
                ],
                [
                    'id' => 'review',
                    'name' => 'Manager review',
                    'owner' => 'Line manager',
                    'next' => ['finance', 'rejected'],
                ],
                [
                    'id' => 'finance',
                    'name' => 'Finance approval',
                    'owner' => 'Finance team',
                    'next' => ['paid'],
                ],
                [
                    'id' => 'paid',
                    'name' => 'Paid',
                    'owner' => 'Payroll',
                    'next' => [],
                ],
                [
                    'id' => 'rejected',
                    'name' => 'Rejected',
                    'owner' => 'Requester',
                    'next' => [],
                ],
            ],
        ]);
    }

    /**
     * Handle GET /api/uikit/demo/tasks (requires uikit:view).
     *
     * A static fixture queue for the `inbox` block demo. Core has no notion of a
     * task queue — this is exactly the half a plugin supplies. No PDO, no side
     * effects.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response Static demo tasks.
     */
    public function demoTasks(Request $request, array $params = []): Response
    {
        return Response::json([
            'data' => [
                [
                    'id' => 1,
                    'title' => 'Expense claim #4821',
                    'requester' => 'Bjorn Larsen',
                    'submitted' => '2026-08-16 14:03',
                    'status' => 'pending',
                ],
                [
                    'id' => 2,
                    'title' => 'Access request — Reporting',
                    'requester' => 'Camille Dupont',
                    'submitted' => '2026-08-15 08:55',
                    'status' => 'pending',
                ],
            ],
        ]);
    }

    /**
     * Handle POST /api/uikit/demo/tasks/{id}/approve and .../reject
     * (both require uikit:view).
     *
     * The demo's write side. It does nothing durable — the point of the demo is
     * that the button EXISTS only because the host resolved this route's own
     * `requiredPermission` for this caller, not what the handler then does.
     *
     * @param Request               $request The incoming HTTP request.
     * @param array<string, string> $params  Captured path parameters (`id`).
     * @return Response 200 echoing the addressed task id.
     */
    public function demoTaskAction(Request $request, array $params = []): Response
    {
        return Response::json([
            'data' => [
                'id' => (string) ($params['id'] ?? ''),
                'accepted' => true,
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
        // `uikit:manage` is declared and NEVER granted, on purpose (#909). The
        // Record tab's `accessGate` asks the host whether this caller may PUT
        // the demo record; with nothing holding `uikit:manage` the honest answer
        // is no, so the showcase renders a REAL read-only state rather than a
        // screenshot of one. Grant it to a role and the same tree renders the
        // editor. A permission with no grant is also the ordinary shape of
        // "some parts have permissions, not always everything is allowed".
        return ['uikit:view', 'uikit:manage'];
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
     * into Containers / Content / Data / Interactive / Overlays / Workflow /
     * Record — each tab pairing a live block with the PHP that declares it (via
     * {@see self::demo()}). Every one of the block types in
     * {@see \Whity\Sdk\Frontend\Blocks\BlockContract::types()} appears at least
     * once — that is a CI gate, not an aspiration — and the result passes
     * {@see \Whity\Sdk\Frontend\Blocks\BlockValidator::validate()}.
     *
     * The tab list is named rather than counted, and the type total is not
     * restated at all: the number here had drifted 17 types out of date, which
     * is worse than saying nothing because it reads as though somebody checked.
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
                    [
                        'type' => 'tab',
                        'label' => 'Overlays',
                        'children' => $this->overlaysTab(),
                    ],
                    [
                        'type' => 'tab',
                        'label' => 'Workflow',
                        'children' => $this->workflowTab(),
                    ],
                    [
                        'type' => 'tab',
                        'label' => 'Record',
                        'children' => $this->recordTab(),
                    ],
                ],
            ],
        ];
    }

    /**
     * The "Overlays" tab: `modal` (→ Dialog) and `drawer` (→ Sheet). Each holds
     * overlay content — a modal wraps a `form`, a drawer a data-bound detail.
     * Both are opened either by their own `trigger` button or by a `dataTable`
     * row action of kind `open`, which publishes the clicked row into the
     * master-detail context; the overlay's content then reads that row — a form
     * input via `defaultFrom`, a data-bound child via a dotted `params.from`
     * (`{targetId}.{field}`). This is the in-place edit/detail pattern.
     *
     * @return list<array<string, mixed>>
     */
    private function overlaysTab(): array
    {
        return [
            [
                'type' => 'heading',
                'level' => 2,
                'text' => 'Overlays — in-place edit & detail',
            ],
            [
                'type' => 'text',
                'value' => 'A modal (Dialog) and a drawer (Sheet) hold overlay content. Open them from '
                    . 'their own trigger button, or from a dataTable row action of kind `open`, which '
                    . 'publishes the clicked row into the master-detail context. Overlay content reads '
                    . 'that row: a form input via `defaultFrom`, a data-bound child via a dotted '
                    . '`params.from` (`{targetId}.{field}`). A form inside an overlay closes it on '
                    . 'submit-success.',
                'tone' => 'muted',
            ],
            // The master: a dataTable whose row actions OPEN an overlay, publishing
            // the row under the target's id for the overlay to read.
            [
                'type' => 'dataTable',
                'source' => '/api/uikit/demo/rows',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                    ['key' => 'role', 'label' => 'Role', 'sortable' => true],
                ],
                'rowActions' => [
                    ['label' => 'Edit', 'open' => 'demo-edit-modal'],
                    ['label' => 'Details', 'open' => 'demo-detail-drawer'],
                ],
            ],
            // A modal wrapping a form. Its `trigger` also opens it standalone (a
            // blank "New row"); when opened from a row action, `defaultFrom` seeds
            // each input from the published row (`{id}.{field}`).
            [
                'type' => 'modal',
                'id' => 'demo-edit-modal',
                'title' => 'Edit row',
                'trigger' => 'New row',
                'variant' => 'primary',
                'size' => 'md',
                'children' => [
                    [
                        'type' => 'form',
                        'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                        'requiredPermission' => 'uikit:view',
                        'children' => [
                            [
                                'type' => 'textInput',
                                'name' => 'name',
                                'label' => 'Name',
                                'required' => true,
                                'defaultFrom' => 'demo-edit-modal.name',
                            ],
                            [
                                'type' => 'textInput',
                                'name' => 'role',
                                'label' => 'Role',
                                'defaultFrom' => 'demo-edit-modal.role',
                            ],
                            ['type' => 'submitButton', 'label' => 'Save'],
                        ],
                    ],
                ],
            ],
            // A drawer showing the opened row's detail. Its data-bound child binds
            // a query param to the published row via a dotted `params.from`.
            [
                'type' => 'drawer',
                'id' => 'demo-detail-drawer',
                'title' => 'Row detail',
                'trigger' => 'Open detail',
                'side' => 'right',
                'children' => [
                    [
                        'type' => 'dataStat',
                        'source' => '/api/uikit/demo/metric',
                        'label' => 'Metric for the selected row',
                        'valueField' => 'value',
                        'params' => [
                            ['param' => 'name', 'from' => 'demo-detail-drawer.name'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The "Record" tab (#883): `dataRecord`, `recordFields`, and the `...From`
     * twins that let a literal leaf bind to a record field.
     *
     * This tab is a RECORD PAGE, assembled from nothing but the block
     * vocabulary — the shape #882 made the platform standard and which, until
     * this release, could only be hand-written in React. Read top to bottom it
     * is the whole answer to "can a record page be described?": a master
     * control names the record, a `dataRecord` fetches it and publishes it, the
     * header states facts about it, a description list shows its fields, a
     * related collection hangs beside it, and an edit form seeded from the
     * record sits underneath.
     *
     * The `selector` here stands in for the ROUTE. On a record page the record
     * is named by the URL and the host publishes it under `record`, so the
     * `dataRecord` would read `/api/uikit/demo/rows/{record}` instead — the
     * same source template, the same resolver, a different publisher. A
     * showcase has no record route to be mounted at, so it drives the same
     * binding from a dropdown, which also demonstrates the master-detail form
     * of the block.
     *
     * WHAT IS DELIBERATELY ABSENT. The endpoint returns `manageable` and
     * `canEdit`; the declaration names neither, and could not — the validator
     * refuses either word as a fact, and the renderer publishes only the four
     * fields named below. That is #895 made unwriteable rather than documented.
     *
     * @return list<array<string, mixed>>
     */
    private function recordTab(): array
    {
        return [
            [
                'type' => 'heading',
                'level' => 2,
                'text' => 'Record — one resource, and everything that says something about it',
            ],
            [
                'type' => 'text',
                'value' => 'Every other data-bound block assumes a COLLECTION at `source`. `dataRecord` '
                    . 'fetches ONE resource, publishes the fields it names into the master-detail '
                    . 'context under its `id`, and owns the loading and failure states for everything '
                    . 'beneath it. Siblings then read the record through the same `{id}.{field}` '
                    . 'addressing a row action already publishes with — `recordFields` for a '
                    . 'description list, and `textFrom` / `valueFrom` / `labelFrom` / `hintFrom` to '
                    . 'bind a heading, a paragraph, a badge or a stat to the record\'s own values.',
                'tone' => 'muted',
            ],
            [
                'type' => 'alert',
                'variant' => 'warning',
                'title' => 'A record page states facts about the RECORD, never about the CALLER',
                'body' => 'This demo\'s endpoint returns `manageable` and `canEdit` beside the record\'s '
                    . 'own fields, exactly as a real one does. Neither can be named in `fields` and '
                    . 'neither can be bound by a `...From` twin: the contract refuses those words, and '
                    . 'the renderer publishes only the fields the declaration asked for, so a flag it '
                    . 'never named is unreachable whatever it is called. #895 is the incident this '
                    . 'prevents — for a tenant-0 caller `manageable` is true of every record, so a '
                    . 'page inferring "this is yours" from it was wrong for exactly the one caller who '
                    . 'could act on it. What the caller may do gates CONTROLS, through a form or '
                    . 'button `requiredPermission`.',
            ],
            [
                'type' => 'selector',
                'name' => 'demo-record-pick',
                'label' => 'Which record',
                'source' => '/api/uikit/demo/rows',
                'valueField' => 'name',
                'labelField' => 'name',
                'placeholder' => 'Pick a record...',
            ],
            [
                'type' => 'dataRecord',
                'id' => 'demo-record',
                'source' => '/api/uikit/demo/rows/{demo-record-pick}',
                'fields' => [
                    ['field' => 'name', 'label' => 'Name'],
                    ['field' => 'role', 'label' => 'Role'],
                    ['field' => 'status', 'label' => 'Status'],
                    ['field' => 'joined', 'label' => 'Joined'],
                ],
                'emptyText' => 'Pick a record above to see it here.',
                'children' => [
                    // The header: a title, a badge and a stat, all bound to the
                    // record rather than to literals. Each keeps its literal as
                    // the fallback, which is what renders before the fetch
                    // settles.
                    [
                        'type' => 'heading',
                        'level' => 3,
                        'text' => 'This record',
                        'textFrom' => 'demo-record.name',
                    ],
                    [
                        'type' => 'row',
                        'align' => 'start',
                        'children' => [
                            [
                                'type' => 'badge',
                                'variant' => 'info',
                                'label' => 'Role',
                                'labelFrom' => 'demo-record.role',
                            ],
                            [
                                'type' => 'badge',
                                'variant' => 'neutral',
                                'label' => 'Status',
                                'labelFrom' => 'demo-record.status',
                            ],
                        ],
                    ],
                    [
                        'type' => 'grid',
                        'columns' => 2,
                        'children' => [
                            [
                                'type' => 'stat',
                                'label' => 'Status',
                                'value' => 'Unknown',
                                'valueFrom' => 'demo-record.status',
                                'hint' => 'since joining',
                                'hintFrom' => 'demo-record.joined',
                            ],
                            [
                                'type' => 'card',
                                'title' => 'recordFields',
                                'description' => 'Every fact the record declared, under the labels declared beside them.',
                                'children' => [
                                    ['type' => 'recordFields', 'from' => 'demo-record'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'card',
                        'title' => 'recordFields (a subset)',
                        'description' => 'The same record, two of its fields, in the order asked for.',
                        'children' => [
                            [
                                'type' => 'recordFields',
                                'from' => 'demo-record',
                                'fields' => ['status', 'role'],
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'value' => 'No role resolved yet.',
                        'valueFrom' => 'demo-record.role',
                        'tone' => 'muted',
                    ],
                    // The edit affordance a record page carries, seeded from the
                    // record the page is about. `defaultFrom` is PLUMBING rather
                    // than a statement, so it is not fact-guarded — the server
                    // re-validates whatever is submitted.
                    [
                        'type' => 'card',
                        'title' => 'The edit form, seeded from the record',
                        'description' => 'A record page is a form WITH context, which is the half a modal cannot carry.',
                        'children' => [
                            [
                                'type' => 'form',
                                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                                'requiredPermission' => 'uikit:view',
                                'children' => [
                                    [
                                        'type' => 'textInput',
                                        'name' => 'name',
                                        'label' => 'Name',
                                        'required' => true,
                                        'defaultFrom' => 'demo-record.name',
                                    ],
                                    [
                                        'type' => 'textInput',
                                        'name' => 'role',
                                        'label' => 'Role',
                                        'defaultFrom' => 'demo-record.role',
                                    ],
                                    ['type' => 'submitButton', 'label' => 'Save'],
                                ],
                            ],
                        ],
                    ],
                    // ---- #909: the READ-ONLY STATE, and the three-state shape ----
                    // Everything above states facts about the record. This
                    // states what the CALLER may do to it, which is the half
                    // #883 could not express and the reason a described record
                    // page was not allowed to replace the hand-built one.
                    //
                    // Read the nesting outside in and it is the three states:
                    //
                    //   HIDDEN     the outer gate asks whether the caller may
                    //              GET the record at all. It declares no
                    //              `otherwise`, so a caller who may not read it
                    //              sees nothing here — not an error, not an
                    //              empty panel.
                    //   READ-ONLY  the inner gate's `otherwise`: a description
                    //              list plus a notice naming the gate that
                    //              refused. This is what renders on a stock
                    //              install, because `uikit:manage` is declared
                    //              and never granted.
                    //   EDITABLE   the inner gate's `children`: the form. Grant
                    //              `uikit:manage` and it appears, with nothing
                    //              in this declaration changing.
                    //
                    // Nesting is also how "which gate refused" stays singular:
                    // an outer gate that refuses never renders the inner one, so
                    // exactly one refusal is ever on screen — #897's "first
                    // refusal wins", structurally rather than by convention.
                    [
                        'type' => 'accessGate',
                        'id' => 'demo-record-readable',
                        // A GET check: "may I see this at all?". The endpoint is
                        // the same templated record path the `dataRecord` above
                        // fetches, so the gate and the fetch ask about the same
                        // resource by construction.
                        'check' => ['method' => 'GET', 'endpoint' => '/api/uikit/demo/rows/{demo-record-pick}'],
                        'children' => [
                            [
                                'type' => 'accessGate',
                                'id' => 'demo-record-writable',
                                // A WRITE check. Note what is NOT here: the
                                // permission slug. The host reads `uikit:manage`
                                // off the ROUTE this names and evaluates it with
                                // the same RoleChecker calls RbacMiddleware
                                // makes, so what the page shows and what the
                                // middleware admits are one computation.
                                'check' => ['method' => 'PUT', 'endpoint' => '/api/uikit/demo/rows/{demo-record-pick}'],
                                'children' => [
                                    [
                                        'type' => 'card',
                                        'title' => 'Editable — the caller may write this record',
                                        'description' => 'Rendered only when the host says PUT would be admitted.',
                                        'children' => [
                                            [
                                                'type' => 'form',
                                                'submit' => ['method' => 'POST', 'endpoint' => '/api/uikit/demo/echo'],
                                                'requiredPermission' => 'uikit:view',
                                                'children' => [
                                                    [
                                                        'type' => 'textInput',
                                                        'name' => 'role',
                                                        'label' => 'Role',
                                                        'defaultFrom' => 'demo-record.role',
                                                    ],
                                                    ['type' => 'submitButton', 'label' => 'Save'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'otherwise' => [
                                    [
                                        'type' => 'card',
                                        'title' => 'Read-only — a different rendering, not a disabled form',
                                        'description' => 'The record\'s own values as a description list.',
                                        'children' => [
                                            [
                                                'type' => 'alert',
                                                'variant' => 'info',
                                                'title' => 'Which gate refused',
                                                'body' => 'Changing this record needs the `uikit:manage` permission, '
                                                    . 'which this installation declares and never grants. The page is '
                                                    . 'not guessing: it asked the host whether PUT '
                                                    . '/api/uikit/demo/rows/... would be admitted for you, and the host '
                                                    . 'answered from the route table. Grant `uikit:manage` to a role and '
                                                    . 'the editor above appears in place of this panel, with nothing in '
                                                    . 'the block declaration changing.',
                                            ],
                                            ['type' => 'recordFields', 'from' => 'demo-record'],
                                        ],
                                    ],
                                ],
                            ],
                            // A condition on an ORDINARY block, not on a gate's
                            // slot. `visibleWhen` is carried by every block type
                            // now, which is what lets a single gate declared once
                            // decide a badge here and a column there without
                            // wrapping each of them in a container.
                            [
                                'type' => 'badge',
                                'variant' => 'warning',
                                'label' => 'Read-only for you',
                                'visibleWhen' => ['access' => 'demo-record-writable', 'equals' => false],
                            ],
                            // A CONDITIONAL NOTICE keyed on the record itself —
                            // the third shell property #883 could not express.
                            // `from` reads the published record through the same
                            // `{id}.{field}` addressing every other binding uses.
                            [
                                'type' => 'alert',
                                'variant' => 'warning',
                                'title' => 'This person has not accepted their invitation',
                                'body' => 'Shown only while the record\'s own `status` is `Invited` — a notice '
                                    . 'conditioned on a FACT, beside a rendering conditioned on ACCESS. They are '
                                    . 'different subjects of the same facet and neither can be mistaken for the other.',
                                'visibleWhen' => ['from' => 'demo-record.status', 'equals' => 'Invited'],
                            ],
                        ],
                    ],
                    // A related collection, which is the other half a record
                    // page carries and a modal has nowhere to put.
                    [
                        'type' => 'card',
                        'title' => 'A related collection',
                        'description' => 'Record pages hang related data beside the record; overlays have nowhere to put it.',
                        'children' => [
                            [
                                'type' => 'dataTable',
                                'source' => '/api/uikit/demo/rows',
                                'columns' => [
                                    ['key' => 'name', 'label' => 'Name'],
                                    ['key' => 'role', 'label' => 'Role'],
                                ],
                                'emptyText' => 'Nobody else here.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'code',
                'language' => 'php',
                'content' => "['type' => 'dataRecord',\n"
                    . "    'id' => 'demo-record',\n"
                    . "    // {token} resolves from a selector, an opened row, or {record} —\n"
                    . "    // the name a host seeds with the record its ROUTE is about.\n"
                    . "    'source' => '/api/uikit/demo/rows/{demo-record-pick}',\n"
                    . "    // The facts the record STATES. Only these are published into\n"
                    . "    // context; 'manageable' and 'canEdit' are in the payload and are\n"
                    . "    // unreachable from the tree. Naming either here is REFUSED (#895).\n"
                    . "    'fields' => [\n"
                    . "        ['field' => 'name',   'label' => 'Name'],\n"
                    . "        ['field' => 'role',   'label' => 'Role'],\n"
                    . "        ['field' => 'status', 'label' => 'Status'],\n"
                    . "        ['field' => 'joined', 'label' => 'Joined'],\n"
                    . "    ],\n"
                    . "    'children' => [\n"
                    . "        ['type' => 'heading', 'level' => 3, 'text' => 'This record',\n"
                    . "            'textFrom' => 'demo-record.name'],\n"
                    . "        ['type' => 'recordFields', 'from' => 'demo-record'],\n"
                    . "        ['type' => 'recordFields', 'from' => 'demo-record',\n"
                    . "            'fields' => ['status', 'role']],\n"
                    . "    ],\n"
                    . "]",
            ],
            [
                'type' => 'code',
                'language' => 'php',
                'content' => "// #909: hidden / read-only / editable, as two nested gates.\n"
                    . "['type' => 'accessGate',\n"
                    . "    'id'    => 'demo-record-readable',\n"
                    . "    // A CONCRETE REQUEST, never a permission slug: the host reads the\n"
                    . "    // gate off the ROUTE this names, so the page cannot disagree with\n"
                    . "    // the middleware and there is no second slug to re-gate.\n"
                    . "    'check' => ['method' => 'GET', 'endpoint' => '/api/uikit/demo/rows/{demo-record-pick}'],\n"
                    . "    // No 'otherwise' => a caller who may not read it sees NOTHING.\n"
                    . "    'children' => [\n"
                    . "        ['type' => 'accessGate',\n"
                    . "            'id'    => 'demo-record-writable',\n"
                    . "            'check' => ['method' => 'PUT', 'endpoint' => '/api/uikit/demo/rows/{demo-record-pick}'],\n"
                    . "            // The two renderings, declared TOGETHER so they cannot drift.\n"
                    . "            'children'  => [/* the form */],\n"
                    . "            'otherwise' => [/* a <dl> and the reason */]],\n"
                    . "        // Every block carries the facet, so one gate decides many things.\n"
                    . "        ['type' => 'badge', 'variant' => 'warning', 'label' => 'Read-only for you',\n"
                    . "            'visibleWhen' => ['access' => 'demo-record-writable', 'equals' => false]],\n"
                    . "        // ...and a notice conditioned on a FACT rather than on access.\n"
                    . "        ['type' => 'alert', 'variant' => 'warning', 'title' => 'Not accepted yet',\n"
                    . "            'body' => '...',\n"
                    . "            'visibleWhen' => ['from' => 'demo-record.status', 'equals' => 'Invited']],\n"
                    . "    ],\n"
                    . "]",
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
            // WC-532 A5: math + markdown display blocks.
            $this->demo(
                'math',
                'A LaTeX expression rendered with KaTeX (trust:false — never executes).',
                [
                    'type' => 'math',
                    'expression' => 'e^{i\\pi} + 1 = 0',
                    'block' => true,
                ],
                <<<'PHP'
                    ['type' => 'math', 'expression' => 'e^{i\\pi}+1=0', 'block' => true]
                    PHP,
            ),
            $this->demo(
                'markdown',
                'Markdown rendered by the XSS-safe renderer, with inline $…$ math.',
                [
                    'type' => 'markdown',
                    'content' => "## Notes\n\n**Bold**, _italic_, `code`, a [link](/plugins), and inline math \$a^2+b^2=c^2\$.\n\n- first\n- second",
                ],
                <<<'PHP'
                    ['type' => 'markdown', 'content' => "## Notes\n\n**Bold** and math \$a^2\$"]
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
                    // WC-532 A7: master-detail — a selector drives a sibling
                    // dataTable via its `params` facet. Labels avoid "name".
                    $this->demo(
                        'selector + master-detail',
                        'A selector publishes its choice into the shared context; a sibling dataTable '
                            . 'appends it to its source as a query param via the `params` facet (WC-532 A7). '
                            . 'The base source stays a plain owned route — only the whitelisted param interpolates.',
                        [
                            'type' => 'section',
                            'title' => 'Filter members by role',
                            'children' => [
                                [
                                    'type' => 'selector',
                                    'name' => 'roleFilter',
                                    'label' => 'Filter by role',
                                    'source' => '/api/uikit/demo/rows',
                                    'valueField' => 'role',
                                    'labelField' => 'role',
                                ],
                                // Detail: a dataStat that re-fetches with the
                                // selected role appended (?role=…) — its base
                                // source stays a plain owned route.
                                [
                                    'type' => 'dataStat',
                                    'source' => '/api/uikit/demo/metric',
                                    'label' => 'Users in role',
                                    'valueField' => 'value',
                                    'params' => [['param' => 'role', 'from' => 'roleFilter']],
                                ],
                            ],
                        ],
                        <<<'PHP'
                            ['type' => 'selector', 'name' => 'roleFilter', 'source' => '/api/uikit/demo/rows',
                             'valueField' => 'role', 'labelField' => 'role'],
                            ['type' => 'dataStat', 'source' => '/api/uikit/demo/metric', 'valueField' => 'value',
                             'params' => [['param' => 'role', 'from' => 'roleFilter']]]
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
                                // WC-532 A4: bilingual AR/EN paired-text input.
                                // Labels deliberately avoid the substring "name"
                                // so they never collide with the e2e's
                                // getByLabel('Name') on the textInput above.
                                [
                                    'type' => 'bilingualText',
                                    'name' => 'bilingualTitle',
                                    'label' => 'Bilingual title',
                                    'arLabel' => 'العنوان بالعربية',
                                    'enLabel' => 'Title (English)',
                                ],
                                // WC-532 A6: foreign-key select populated from a
                                // plugin-owned collection (GET /api/uikit/demo/rows).
                                // Label avoids "name" to steer clear of the e2e's
                                // getByLabel('Name') on the textInput above.
                                [
                                    'type' => 'referenceSelect',
                                    'name' => 'assignedRole',
                                    'label' => 'Assigned role',
                                    'source' => '/api/uikit/demo/rows',
                                    'valueField' => 'role',
                                    'labelField' => 'role',
                                ],
                                // #868: the organizational-unit SCOPE picker.
                                // The live instance the every-type coverage gate
                                // requires, and deliberately inside this form:
                                // it is a form input, and its whole output is
                                // one value in the submitted payload. Note the
                                // absence of a `source` — the units and the type
                                // vocabulary come from CORE's own OU endpoints
                                // under the caller's `ous:read` gate, and there
                                // is no prop with which to point it elsewhere.
                                // Label avoids "name" (the e2e's getByLabel).
                                [
                                    'type' => 'ouScopePicker',
                                    'name' => 'appliesTo',
                                    'label' => 'Applies to',
                                ],
                                // WC-532 A5: Markdown-aware input with a live
                                // preview. Label avoids "name" (e2e getByLabel).
                                [
                                    'type' => 'richTextInput',
                                    'name' => 'notes',
                                    'label' => 'Notes (Markdown)',
                                    'rows' => 4,
                                ],
                                // WC-532 A2: a repeatable field-group. Labels avoid
                                // "name" (the e2e's getByLabel('Name')); starts with
                                // zero rows (min unset) so it adds no required fields.
                                [
                                    'type' => 'fieldArray',
                                    'name' => 'lineItems',
                                    'label' => 'Line items',
                                    'itemLabel' => 'Line',
                                    'max' => 5,
                                    'children' => [
                                        ['type' => 'textInput', 'name' => 'description', 'label' => 'Description'],
                                        ['type' => 'numberInput', 'name' => 'qty', 'label' => 'Quantity', 'min' => 0],
                                    ],
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
                                 ['type' => 'ouScopePicker','name' => 'appliesTo', 'label' => 'Applies to'],
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
                    // #868: the OU scope picker's own card. The LIVE instance is
                    // in the form above (it is a form input and has to be), so
                    // this one documents the half a plugin author actually has
                    // to get right: the shape of the value they will persist and
                    // the rule for resolving it.
                    [
                        'type' => 'card',
                        'title' => 'ouScopePicker',
                        'description' => 'Choose a SCOPE over the organizational-unit tree — '
                            . 'a rule resolved at execution time, not a pinned list of unit ids. '
                            . 'The live instance is the "Applies to" control in the form above.',
                        'children' => [
                            [
                                'type' => 'text',
                                'value' => 'The block declares no `source`. Every other data-bound '
                                    . 'block names a route the plugin registered, and the host '
                                    . 'ownership-checks it — so a plugin cannot name core\'s OU '
                                    . 'endpoint at all, and republishing the hierarchy through a '
                                    . 'route of its own is exactly the drift #822 exists to delete. '
                                    . 'The renderer reads the units and the type vocabulary from '
                                    . 'core, under the caller\'s own ous:read gate.',
                                'tone' => 'muted',
                            ],
                            [
                                'type' => 'code',
                                'language' => 'php',
                                'content' => <<<'PHP'
                                    ['type'       => 'ouScopePicker',
                                     'name'       => 'appliesTo',
                                     'label'      => 'Applies to',
                                     // optional — which scopes are offered, in order.
                                     // The FIRST is the opening state. Default: all three.
                                     'scopes'     => ['subtree', 'children', 'unit'],
                                     // optional — restrict which units may ANCHOR the rule
                                     // (`?type=` on the picker's own unit fetch).
                                     'anchorType' => 'faculty',
                                     // optional — PIN the kind filter and hide the control.
                                     // Omit it and the user chooses (including "any kind").
                                     'memberType' => 'department',
                                     // optional — drop the tenant-wide option, so the rule
                                     // must be anchored at a unit.
                                     'required'   => true]
                                    PHP,
                            ],
                            [
                                'type' => 'text',
                                'value' => 'The value submitted under `name` is one object. `scope` '
                                    . 'is ALWAYS written: "this unit" and "this unit\'s subtree" are '
                                    . 'different answers, and nothing else in the object tells them '
                                    . 'apart, so the discriminator is never inferred.',
                                'tone' => 'muted',
                            ],
                            [
                                'type' => 'code',
                                'language' => 'json',
                                'content' => <<<'JSON'
                                    { "appliesTo": { "unit": 42, "scope": "subtree", "type": "department" } }
                                    JSON,
                            ],
                            [
                                'type' => 'table',
                                'columns' => [
                                    ['key' => 'unit', 'label' => 'unit'],
                                    ['key' => 'scope', 'label' => 'scope'],
                                    ['key' => 'resolves', 'label' => 'resolves to'],
                                ],
                                'rows' => [
                                    ['unit' => 'id', 'scope' => 'unit', 'resolves' => 'exactly that unit'],
                                    ['unit' => 'id', 'scope' => 'children', 'resolves' => 'its direct children (?parent_id=<id>)'],
                                    ['unit' => 'id', 'scope' => 'subtree', 'resolves' => 'it AND every descendant (inclusive)'],
                                    ['unit' => 'null', 'scope' => 'children', 'resolves' => 'the root units (?parent_id=0)'],
                                    ['unit' => 'null', 'scope' => 'subtree', 'resolves' => 'every unit in the tenant'],
                                    ['unit' => 'null', 'scope' => 'unit', 'resolves' => 'never produced — the nothing-selected state'],
                                ],
                            ],
                            [
                                'type' => 'text',
                                'value' => '`type`, when non-null, narrows whatever that produced to '
                                    . 'units of that kind (`?type=<key>`) — applied AFTER the scope '
                                    . 'expands, never instead of it. It is meaningless for a `unit` '
                                    . 'scope, so the renderer hides the control and writes null there.',
                                'tone' => 'muted',
                            ],
                            [
                                'type' => 'code',
                                'language' => 'php',
                                'content' => <<<'PHP'
                                    // Resolving a stored rule, in the plugin's own handler.
                                    // `scope` is the switch; nothing is guessed from the other fields.
                                    $query = match ($rule['scope']) {
                                        'unit'     => ['id' => $rule['unit']],
                                        'children' => ['parent_id' => $rule['unit'] ?? 0],
                                        'subtree'  => ['descendants_of' => $rule['unit']], // walk, inclusive
                                    };
                                    if ($rule['type'] !== null) {
                                        $query['type'] = $rule['type']; // narrows the set, never replaces it
                                    }
                                    PHP,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The "Workflow" tab (#868, #950): `timeline`, `inbox` and `flow` — what has
     * happened, what is waiting for you, and the shape of the process itself.
     *
     * `timeline` is the audit-trail shape — actor, action, timestamp, an optional
     * note, an optional from/to. Read-only: the contract carries no endpoint and
     * no verb, so there is nothing for a renderer to submit.
     *
     * `inbox` is the task list, and the demo exists to make its SEAM concrete.
     * The plugin supplies the items from its own endpoint; the HOST resolves
     * which of the declared `actions` the caller may take on each, from the
     * route each action calls, through `POST /api/v1/me/permitted-actions`.
     * Note what the declaration below does NOT contain: the permission each
     * endpoint is gated on. That is read off the route, so what a reader sees
     * here cannot drift from what the middleware enforces on click.
     *
     * `flow` (#950) is the third face of the same subject: the DIAGRAM. A
     * process can already be listed with `dataTable` and edited with `form`, and
     * neither of those is what makes it legible to the person who has to follow
     * it. The demo below is deliberately a BRANCHING route rather than a
     * straight line, because a straight line is the case the contract handles
     * with no edge modelling at all and so demonstrates nothing about the edges.
     *
     * @return list<array<string, mixed>>
     */
    private function workflowTab(): array
    {
        return [
            [
                'type' => 'heading',
                'level' => 2,
                'text' => 'Workflow — history, work awaiting you, and the process itself',
            ],
            [
                'type' => 'text',
                'value' => 'Three blocks every product with an approval step would otherwise '
                    . 're-implement, differently each time. The interesting one is `inbox`: '
                    . 'the plugin supplies the items, and CORE resolves which actions the caller may '
                    . 'take on each — from the route the action calls, with the same checks the RBAC '
                    . 'middleware makes. An action is rendered only when the host answered that this '
                    . 'caller may make that exact request. `flow` is the other half of the same '
                    . 'story: the picture of the route those items travel.',
                'tone' => 'muted',
            ],
            $this->dataBoundDemo(
                'timeline',
                'An ordered, append-only event list: actor, action, timestamp, optional note, optional from/to. Read-only.',
                [
                    'type' => 'timeline',
                    'source' => '/api/uikit/demo/events',
                    'actorField' => 'actor',
                    'actionField' => 'action',
                    'timestampField' => 'at',
                    'noteField' => 'note',
                    'fromField' => 'from',
                    'toField' => 'to',
                    'emptyText' => 'No events recorded yet.',
                ],
                <<<'PHP'
                [
                    'type'           => 'timeline',
                    'source'         => '/api/uikit/demo/events', // ownership-checked + version-rewritten
                    'actorField'     => 'actor',
                    'actionField'    => 'action',
                    'timestampField' => 'at',
                    'noteField'      => 'note',   // optional
                    'fromField'      => 'from',   // optional — a state change
                    'toField'        => 'to',     // optional
                    'pageSize'       => 10,       // optional, client-side over the fetched rows
                    'emptyText'      => 'No events recorded yet.',
                ]
                PHP,
                <<<'PHP'
                // The endpoint returns the events newest-first. Nothing about the
                // block is writable, so the route is a plain GET.
                public function demoEvents(Request $r, array $p = []): Response {
                    return Response::json(['data' => [
                        ['actor' => 'Anika Patel', 'action' => 'approved the request',
                         'at' => '2026-08-17 09:12', 'note' => '', 'from' => 'pending', 'to' => 'approved'],
                        // ...
                    ]]);
                }
                PHP,
            ),
            $this->dataBoundDemo(
                'inbox',
                'Items awaiting the current user. The plugin supplies the items; core resolves which actions this caller may take on each.',
                [
                    'type' => 'inbox',
                    'source' => '/api/uikit/demo/tasks',
                    'idField' => 'id',
                    'titleField' => 'title',
                    'subtitleField' => 'requester',
                    'timestampField' => 'submitted',
                    'statusField' => 'status',
                    'actions' => [
                        [
                            'key' => 'approve',
                            'label' => 'Approve',
                            'method' => 'POST',
                            'endpoint' => '/api/uikit/demo/tasks/{id}/approve',
                            'variant' => 'primary',
                        ],
                        [
                            'key' => 'reject',
                            'label' => 'Reject',
                            'method' => 'POST',
                            'endpoint' => '/api/uikit/demo/tasks/{id}/reject',
                            'confirm' => 'Reject this request? The requester is notified.',
                            'variant' => 'destructive',
                        ],
                    ],
                    'emptyText' => 'Nothing awaiting you.',
                ],
                <<<'PHP'
                [
                    'type'           => 'inbox',
                    'source'         => '/api/uikit/demo/tasks', // the PLUGIN supplies the items
                    'idField'        => 'id',
                    'titleField'     => 'title',
                    'subtitleField'  => 'requester',  // optional
                    'timestampField' => 'submitted',  // optional
                    'statusField'    => 'status',     // optional — rendered as a badge
                    // 'resourceType' => 'task',      // optional — required by scopedPermission
                    'actions'        => [
                        [
                            'key'      => 'approve',
                            'label'    => 'Approve',
                            'method'   => 'POST',
                            'endpoint' => '/api/uikit/demo/tasks/{id}/approve',
                            'variant'  => 'primary',
                            // No permission here. CORE reads the route's own gate.
                            // 'scopedPermission' => 'tasks:approve', // per-RECORD, needs resourceType
                        ],
                        [
                            'key'      => 'reject',
                            'label'    => 'Reject',
                            'method'   => 'POST',
                            'endpoint' => '/api/uikit/demo/tasks/{id}/reject',
                            'confirm'  => 'Reject this request? The requester is notified.',
                            'variant'  => 'destructive',
                        ],
                    ],
                    'emptyText' => 'Nothing awaiting you.',
                ]
                PHP,
                <<<'PHP'
                // Three routes: the queue, and one per action. Each action route
                // declares its OWN requiredPermission, and that declaration is the
                // single source of truth — the block never restates it, and the
                // host resolves it per caller when it renders the item.
                //   GET  /api/uikit/demo/tasks               requiredPermission: uikit:view
                //   POST /api/uikit/demo/tasks/{id}/approve  requiredPermission: uikit:view
                //   POST /api/uikit/demo/tasks/{id}/reject   requiredPermission: uikit:view
                public function demoTasks(Request $r, array $p = []): Response {
                    return Response::json(['data' => [
                        ['id' => 1, 'title' => 'Expense claim #4821', 'requester' => 'Bjorn Larsen',
                         'submitted' => '2026-08-16 14:03', 'status' => 'pending'],
                        // ...
                    ]]);
                }
                PHP,
            ),
            $this->dataBoundDemo(
                'flow',
                'A set of nodes and the edges between them. One source, a row is a node, '
                    . 'and its successors are a field on that row.',
                [
                    // The live example is TWO blocks: the graph, and the drawer a
                    // node click opens. The drawer declares no `trigger`, so the
                    // only way in is the node — which is the point being shown.
                    'type' => 'section',
                    'children' => [
                        [
                            'type' => 'flow',
                            'source' => '/api/uikit/demo/flow-steps',
                            'nodeIdField' => 'id',
                            'nodeLabelField' => 'name',
                            'nodeSubtitleField' => 'owner',
                            'edgeToField' => 'next',
                            'orientation' => 'horizontal',
                            'nodeActions' => [
                                ['label' => 'Details', 'open' => 'demo-step-drawer'],
                            ],
                            'emptyText' => 'No steps configured yet.',
                        ],
                        [
                            'type' => 'drawer',
                            'id' => 'demo-step-drawer',
                            'title' => 'Step detail',
                            'side' => 'right',
                            'children' => [
                                [
                                    'type' => 'heading',
                                    'level' => 3,
                                    'text' => 'Step',
                                    'textFrom' => 'demo-step-drawer.name',
                                ],
                                [
                                    'type' => 'text',
                                    'value' => 'Owned by whoever this step is assigned to.',
                                    'valueFrom' => 'demo-step-drawer.owner',
                                    'tone' => 'muted',
                                ],
                            ],
                        ],
                    ],
                ],
                <<<'PHP'
                [
                    'type'              => 'flow',
                    'source'            => '/api/uikit/demo/flow-steps', // ownership-checked + versioned
                    'nodeIdField'       => 'id',
                    'nodeLabelField'    => 'name',
                    'nodeSubtitleField' => 'owner',   // optional, second line on the node
                    // How the edges are read off the node rows. Omit BOTH and the
                    // nodes are a linear sequence in payload order — the common
                    // case, modelled for free.
                    'edgeToField'       => 'next',    // ids this node leads TO; a LIST branches
                 // 'edgeFromField'     => 'parentId', // ids this node is reached FROM
                    'orientation'       => 'horizontal', // or 'vertical'
                    // The SAME shape as dataTable's rowActions, resolved by the
                    // same validator. Clicking the node runs the first `open`.
                    'nodeActions'       => [
                        ['label' => 'Details', 'open' => 'demo-step-drawer'],
                    ],
                 // 'maxNodes'          => 40,  // optional: LOWER the readability
                                                // ceiling (BlockContract::FLOW_MAX_NODES).
                                                // Above it a renderer draws the first
                                                // maxNodes and says the graph was cut.
                    'emptyText'         => 'No steps configured yet.',
                ],
                // The drawer the node click opens. No `trigger`, so the graph is
                // the only way in; the clicked node's row is published under the
                // drawer's id for `textFrom` / `valueFrom` / `params.from` to read.
                [
                    'type' => 'drawer', 'id' => 'demo-step-drawer', 'title' => 'Step detail',
                    'children' => [
                        ['type' => 'heading', 'level' => 3, 'text' => 'Step',
                         'textFrom' => 'demo-step-drawer.name'],
                    ],
                ]
                PHP,
                <<<'PHP'
                // ONE endpoint. A row is a node, and `next` holds the ids it leads
                // to — so the edges need no second route, no second ownership
                // check and no join in the renderer. A terminal step carries an
                // empty list rather than omitting the key: "this ends the process"
                // is worth stating.
                public function demoFlowSteps(Request $r, array $p = []): Response {
                    return Response::json(['data' => [
                        ['id' => 'submitted', 'name' => 'Submitted',
                         'owner' => 'Requester',    'next' => ['review']],
                        ['id' => 'review',    'name' => 'Manager review',
                         'owner' => 'Line manager', 'next' => ['finance', 'rejected']],
                        ['id' => 'paid',      'name' => 'Paid',
                         'owner' => 'Payroll',      'next' => []],
                        // ...
                    ]]);
                }
                PHP,
            ),
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
            'UiKitDemoRecordResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['name', 'role', 'status', 'joined', 'manageable', 'canEdit'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'role' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'joined' => ['type' => 'string'],
                            'manageable' => ['type' => 'boolean'],
                            'canEdit' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
            'UiKitDemoEvent' => [
                'type' => 'object',
                'required' => ['actor', 'action', 'at', 'note', 'from', 'to'],
                'properties' => [
                    'actor' => ['type' => 'string'],
                    'action' => ['type' => 'string'],
                    'at' => ['type' => 'string'],
                    'note' => ['type' => 'string'],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                ],
            ],
            'UiKitDemoEventsResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/UiKitDemoEvent'],
                    ],
                ],
            ],
            'UiKitDemoFlowStep' => [
                'type' => 'object',
                'required' => ['id', 'name', 'owner', 'next'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    // A LIST, even for the single-successor and terminal steps:
                    // the branch is the interesting case, and a field that is
                    // sometimes a scalar and sometimes an array is a field every
                    // consumer has to normalise.
                    'next' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'UiKitDemoFlowStepsResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/UiKitDemoFlowStep'],
                    ],
                ],
            ],
            'UiKitDemoTask' => [
                'type' => 'object',
                'required' => ['id', 'title', 'requester', 'submitted', 'status'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'requester' => ['type' => 'string'],
                    'submitted' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                ],
            ],
            'UiKitDemoTasksResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/UiKitDemoTask'],
                    ],
                ],
            ],
            'UiKitDemoTaskActionResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['id', 'accepted'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'accepted' => ['type' => 'boolean'],
                        ],
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
