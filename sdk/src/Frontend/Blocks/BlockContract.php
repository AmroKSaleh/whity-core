<?php

declare(strict_types=1);

namespace Whity\Sdk\Frontend\Blocks;

/**
 * The SP3 server-driven plugin-UI block whitelist (SDK 1.8, WC-233).
 *
 * A plugin describes a screen as a platform-NEUTRAL tree of semantic UI
 * "blocks". The host stores and ships that tree verbatim; per-platform
 * renderers (web, mobile, desktop — landing in later slices) translate each
 * block into native widgets. This class is the SINGLE SOURCE OF TRUTH for
 * which block types exist, what props each accepts, and the structural caps —
 * {@see BlockValidator} reads nothing else.
 *
 * Props are SEMANTIC, never presentational: a block says `variant => 'danger'`
 * or `align => 'between'`, never a CSS class, hex/RGB color, or pixel value.
 * Mapping semantics to a platform's visual language is the renderer's job, so
 * the SAME tree renders idiomatically everywhere.
 *
 * The `screen: 'blocks'` frontend feature
 * ---------------------------------------
 * This is a new `screen` value a plugin's
 * {@see \Whity\Sdk\PluginFrontendInterface::getFrontendFeatures()} may return,
 * alongside the existing `'crud' | 'action' | 'custom'`. No interface method
 * changes are needed — `getFrontendFeatures()` already returns arrays. A
 * blocks descriptor has the shape:
 *
 * ```
 * [
 *   'id'                 => 'plugin-dashboard', // kebab-case slug, REQUIRED
 *   'label'              => 'Dashboard',        // menu/screen title, REQUIRED
 *   'screen'             => 'blocks',           // REQUIRED, selects this mode
 *   'blocks'             => [ <block>, ... ],   // REQUIRED, validated here
 *   'requiredPermission' => 'plugin:read',      // REQUIRED, fail-closed gate
 *   'icon'               => 'dashboard',        // optional tabler icon name
 *   'group'              => 'plugins',          // optional nav group
 *   'order'              => 100,                // optional sort order
 * ]
 * ```
 *
 * The host validates `blocks` with {@see BlockValidator::validate()} and drops
 * an invalid descriptor (logging the reason) exactly as it does for the other
 * screen kinds — a bad tree never reaches a renderer.
 *
 * Rule shape returned by {@see rulesFor()}
 * ----------------------------------------
 * Each entry is an array of this shape (see the per-type table below):
 *
 * ```
 * array{
 *   container: bool,                          // may carry a `children` array
 *   props: array<string, array{              // prop name => its rule
 *     type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'itemActionList'|'blockId'|'contextPath'|'ouScopeList'|'ouTypeKey',
 *     required: bool,
 *     values?: list<string|int>,             // allowed set for enum / intEnum
 *   }>,
 * }
 * ```
 *
 * @phpstan-type PropRule array{
 *   type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'itemActionList'|'blockId'|'contextPath'|'ouScopeList'|'ouTypeKey',
 *   required: bool,
 *   values?: list<string|int>,
 * }
 * @phpstan-type BlockRule array{container: bool, props: array<string, PropRule>}
 */
final class BlockContract
{
    /** Maximum nesting depth of the block tree (root nodes are depth 1). */
    public const MAX_DEPTH = 32;

    /** Maximum total number of nodes anywhere in the tree. */
    public const MAX_NODES = 500;

    /**
     * The whitelist: block type => its rule. The ordering here is the canonical
     * documentation order (containers first, then leaves).
     *
     * @return array<string, BlockRule>
     */
    public static function rules(): array
    {
        return [
            // ---- containers (may carry `children`) ----
            'section' => [
                'container' => true,
                'props' => [
                    'title' => ['type' => 'string', 'required' => false],
                    // WC-532 A3: presentational conditional visibility. When
                    // inside a `form`, the section (and its subtree) is hidden
                    // unless the referenced sibling field matches. Purely a
                    // render-time facet — the server stays authoritative on
                    // validation and never trusts client-side visibility.
                    'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
                ],
            ],
            'card' => [
                'container' => true,
                'props' => [
                    'title' => ['type' => 'string', 'required' => false],
                    'description' => ['type' => 'string', 'required' => false],
                    'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
                ],
            ],
            'grid' => [
                'container' => true,
                'props' => [
                    'columns' => ['type' => 'intEnum', 'required' => true, 'values' => [1, 2, 3, 4]],
                ],
            ],
            'row' => [
                'container' => true,
                'props' => [
                    'align' => ['type' => 'enum', 'required' => false, 'values' => ['start', 'center', 'end', 'between']],
                ],
            ],
            'tabs' => [
                'container' => true,
                'props' => [],
            ],
            'tab' => [
                'container' => true,
                'props' => [
                    'label' => ['type' => 'string', 'required' => true],
                ],
            ],
            'divider' => [
                'container' => false,
                'props' => [],
            ],

            // ---- leaves (no `children`) ----
            'heading' => [
                'container' => false,
                'props' => [
                    'level' => ['type' => 'intEnum', 'required' => true, 'values' => [1, 2, 3, 4]],
                    'text' => ['type' => 'string', 'required' => true],
                ],
            ],
            'text' => [
                'container' => false,
                'props' => [
                    'value' => ['type' => 'string', 'required' => true],
                    'tone' => ['type' => 'enum', 'required' => false, 'values' => ['default', 'muted']],
                ],
            ],
            'alert' => [
                'container' => false,
                'props' => [
                    'variant' => ['type' => 'enum', 'required' => true, 'values' => ['info', 'success', 'warning', 'danger']],
                    'title' => ['type' => 'string', 'required' => false],
                    'body' => ['type' => 'string', 'required' => true],
                ],
            ],
            'badge' => [
                'container' => false,
                'props' => [
                    'variant' => ['type' => 'enum', 'required' => true, 'values' => ['neutral', 'info', 'success', 'warning', 'danger']],
                    'label' => ['type' => 'string', 'required' => true],
                ],
            ],
            'stat' => [
                'container' => false,
                'props' => [
                    'label' => ['type' => 'string', 'required' => true],
                    'value' => ['type' => 'string', 'required' => true],
                    'hint' => ['type' => 'string', 'required' => false],
                    'trend' => ['type' => 'enum', 'required' => false, 'values' => ['up', 'down', 'flat']],
                ],
            ],
            'keyValue' => [
                'container' => false,
                'props' => [
                    'items' => ['type' => 'kvList', 'required' => true],
                ],
            ],
            'list' => [
                'container' => false,
                'props' => [
                    'ordered' => ['type' => 'bool', 'required' => false],
                    'items' => ['type' => 'stringList', 'required' => true],
                ],
            ],
            'table' => [
                'container' => false,
                'props' => [
                    'columns' => ['type' => 'columnList', 'required' => true],
                    'rows' => ['type' => 'rowList', 'required' => true],
                ],
            ],
            'button' => [
                'container' => false,
                'props' => [
                    'label' => ['type' => 'string', 'required' => true],
                    'href' => ['type' => 'relPath', 'required' => true],
                    'variant' => ['type' => 'enum', 'required' => false, 'values' => ['primary', 'secondary', 'outline', 'ghost', 'destructive']],
                ],
            ],
            'icon' => [
                'container' => false,
                'props' => [
                    'name' => ['type' => 'string', 'required' => true],
                    'tone' => ['type' => 'enum', 'required' => false, 'values' => ['default', 'muted']],
                ],
            ],
            'code' => [
                'container' => false,
                'props' => [
                    'language' => ['type' => 'string', 'required' => false],
                    'content' => ['type' => 'string', 'required' => true],
                ],
            ],
            // ---- WC-532 A5: math + markdown display blocks ----
            // A LaTeX expression rendered via KaTeX (trust:false, so it can
            // never inject executable content). `block` selects display mode.
            'math' => [
                'container' => false,
                'props' => [
                    'expression' => ['type' => 'string', 'required' => true],
                    'block'      => ['type' => 'bool',   'required' => false],
                ],
            ],
            // Markdown source rendered by the web's dependency-free, XSS-safe
            // renderer (React elements only, sanitized links, inline $…$ math).
            'markdown' => [
                'container' => false,
                'props' => [
                    'content' => ['type' => 'string', 'required' => true],
                ],
            ],

            // ---- data-bound leaves (SP2, WC-229) ----
            // WC-241: 'columns' upgraded to 'dataColumnList' (adds optional
            // per-column sortable/filterable flags) and a new optional
            // 'pageSize' enables client-side pagination — the row set itself
            // still comes from ONE already-verified fetch of 'source'; sort,
            // filter, and page state are applied entirely client-side over
            // that response and never trigger a second request.
            // WC-532 A7 (master-detail): every data-bound block accepts an
            // optional `params` facet — a list of {param, from} that the web
            // renderer appends to `source` as query params (URL-encoded), the
            // value taken from the named `selector`'s current selection. The
            // base `source` stays a plain owned apiPath (still ownership-checked
            // + version-rewritten); ONLY whitelisted query params interpolate,
            // so the SSRF/ownership gate is never widened. Changing a selection
            // re-fetches the block (usePluginData keys on the effective source).
            'dataTable' => ['container' => false, 'props' => [
                'source'    => ['type' => 'apiPath',        'required' => true],
                'columns'   => ['type' => 'dataColumnList', 'required' => true],
                'pageSize'  => ['type' => 'int',            'required' => false],
                'emptyText' => ['type' => 'string',         'required' => false],
                // WC-532 A1: optional per-row affordances rendered in a trailing
                // "Actions" column. Each is either an internal-nav `href` or a
                // `{method, endpoint}` mutation, both templated with `{field}`
                // placeholders from the row (see rowActionList validation).
                'rowActions' => ['type' => 'rowActionList', 'required' => false],
                'params'     => ['type' => 'sourceParamList', 'required' => false],
            ]],
            'dataStat' => ['container' => false, 'props' => [
                'source'     => ['type' => 'apiPath', 'required' => true],
                'label'      => ['type' => 'string',  'required' => true],
                'valueField' => ['type' => 'string',  'required' => true],
                'hintField'  => ['type' => 'string',  'required' => false],
                'trendField' => ['type' => 'string',  'required' => false],
                'emptyText'  => ['type' => 'string',  'required' => false],
                'params'     => ['type' => 'sourceParamList', 'required' => false],
            ]],
            // WC-241: 'sortable' (alphabetical toggle) / 'filterable' (a
            // search box over itemField) / 'pageSize' (client pagination) —
            // same client-side-only invariant as dataTable above.
            'dataList' => ['container' => false, 'props' => [
                'source'     => ['type' => 'apiPath',    'required' => true],
                'itemField'  => ['type' => 'string',     'required' => true],
                'ordered'    => ['type' => 'bool',       'required' => false],
                'sortable'   => ['type' => 'bool',       'required' => false],
                'filterable' => ['type' => 'bool',       'required' => false],
                'pageSize'   => ['type' => 'int',        'required' => false],
                'emptyText'  => ['type' => 'string',     'required' => false],
                'params'     => ['type' => 'sourceParamList', 'required' => false],
            ]],
            // ---- SP4 chart block (WC-240) ----
            'chart' => ['container' => false, 'props' => [
                'source'    => ['type' => 'apiPath',         'required' => true],
                'chartType' => ['type' => 'enum',            'required' => true,
                    'values' => ['bar', 'line', 'area', 'pie']],
                'series'    => ['type' => 'chartSeriesList', 'required' => true],
                'xField'    => ['type' => 'string',          'required' => false],
                'emptyText' => ['type' => 'string',          'required' => false],
                'params'    => ['type' => 'sourceParamList', 'required' => false],
            ]],
            // WC-532 A7: the MASTER control. Populates a dropdown from an
            // owned collection `source` (ownership-checked like dataTable) and
            // publishes the chosen `valueField` under `name` into a shared
            // master-detail context; sibling data-bound blocks read it via
            // their `params` facet. Not a form input — it drives fetches, not
            // form submission.
            'selector' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'source'      => ['type' => 'apiPath',    'required' => true],
                'valueField'  => ['type' => 'string',     'required' => true],
                'labelField'  => ['type' => 'string',     'required' => true],
                'placeholder' => ['type' => 'string',     'required' => false],
            ]],

            // ---- workflow blocks (#868) ----
            // An ordered, append-only EVENT LIST — the audit-trail shape every
            // product on the platform grows and, until now, hand-rolled: actor,
            // action, timestamp, an optional note, and an optional from/to pair
            // for a state change. Read-only by construction: it declares no
            // action, no endpoint and no mutation verb, so there is nothing for
            // a renderer to submit. Data-bound exactly like `dataStat` — one
            // ownership-checked `source`, then per-field mappings — because an
            // audit trail is never a literal the plugin can inline.
            'timeline' => ['container' => false, 'props' => [
                'source'         => ['type' => 'apiPath', 'required' => true],
                'actorField'     => ['type' => 'string',  'required' => true],
                'actionField'    => ['type' => 'string',  'required' => true],
                'timestampField' => ['type' => 'string',  'required' => true],
                'noteField'      => ['type' => 'string',  'required' => false],
                'fromField'      => ['type' => 'string',  'required' => false],
                'toField'        => ['type' => 'string',  'required' => false],
                'pageSize'       => ['type' => 'int',     'required' => false],
                'emptyText'      => ['type' => 'string',  'required' => false],
                'params'         => ['type' => 'sourceParamList', 'required' => false],
            ]],
            // A TASK LIST: the items awaiting the current user, each carrying
            // the actions that user may actually take on it.
            //
            // The seam. Core has no notion of a task queue, so the ITEMS come
            // from the plugin's own `source` — an ordinary ownership-checked
            // apiPath, fetched exactly like a `dataTable`'s. What core owns is
            // the other half: WHICH of the declared `actions` this caller may
            // take on each item. That half is the entire reason the type lives
            // here. A plugin that answered it itself would be re-deriving
            // authorization beside the host's, and the two would drift.
            //
            // Note what an action does NOT declare: the permission its endpoint
            // is gated on. That is not the plugin's to restate — the host reads
            // it off the ROUTE the action actually calls and evaluates it with
            // the same RoleChecker calls RbacMiddleware makes, so what the user
            // is shown cannot disagree with what the middleware enforces. A
            // restated slug would be a second source of truth for a question
            // that already has an authoritative one.
            //
            // `scopedPermission` is the one thing a plugin CAN add, and it is
            // not a restatement either: it is an ADDITIONAL per-record predicate
            // the host resolves at (`resourceType`, the item's `idField` value)
            // through the resource-scoped grants of SDK 1.17/1.22 — the check a
            // plugin's own handler makes INSIDE the request, which no route
            // table can expose. It can only ever hide an action, never reveal
            // one: the route gate is evaluated regardless. Declaring it requires
            // `resourceType`, since a per-record predicate with no record is a
            // tenant-wide check wearing the wrong name.
            'inbox' => ['container' => false, 'props' => [
                'source'         => ['type' => 'apiPath',        'required' => true],
                'idField'        => ['type' => 'string',         'required' => true],
                'titleField'     => ['type' => 'string',         'required' => true],
                'subtitleField'  => ['type' => 'string',         'required' => false],
                'timestampField' => ['type' => 'string',         'required' => false],
                'statusField'    => ['type' => 'string',         'required' => false],
                'resourceType'   => ['type' => 'string',         'required' => false],
                'actions'        => ['type' => 'itemActionList', 'required' => true],
                'pageSize'       => ['type' => 'int',            'required' => false],
                'emptyText'      => ['type' => 'string',         'required' => false],
                'params'         => ['type' => 'sourceParamList', 'required' => false],
            ]],

            // ---- interactive blocks (SP3, WC-233) ----
            'form' => ['container' => true, 'props' => [
                'submit'             => ['type' => 'submitSpec', 'required' => true],
                'requiredPermission' => ['type' => 'string',     'required' => false],
            ]],
            // WC-532 A2: a repeatable field-group. Its `children` are the
            // per-row sub-form template (input leaves); the web renderer lets
            // the user add / remove / reorder rows and submits the collected
            // rows as a JSON array under `name`. Form-only (needs a `form`
            // ancestor) and, like `form`, scopes its template input names per
            // row. `min`/`max` bound the row count; `itemLabel` names each row.
            'fieldArray' => ['container' => true, 'props' => [
                'name'      => ['type' => 'inputName', 'required' => true],
                'label'     => ['type' => 'string',    'required' => true],
                'itemLabel' => ['type' => 'string',    'required' => false],
                'min'       => ['type' => 'int',       'required' => false],
                'max'       => ['type' => 'int',       'required' => false],
            ]],
            // WC-532 A3: every input carries an optional `visibleWhen`
            // presentational facet — the web renderer hides the input unless a
            // sibling field in the same form matches (equals / in). It never
            // affects submission or server validation.
            'textInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'placeholder' => ['type' => 'string',    'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'textArea' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'rows'        => ['type' => 'int',       'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            // WC-532 A5: a Markdown-aware multi-line input. Submits Markdown
            // SOURCE (a plain string) like textArea; the web renderer shows a
            // live preview via the same XSS-safe renderer as the markdown block.
            'richTextInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'rows'        => ['type' => 'int',       'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'numberInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'min'         => ['type' => 'int',       'required' => false],
                'max'         => ['type' => 'int',       'required' => false],
                'step'        => ['type' => 'int',       'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'select' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName',    'required' => true],
                'label'       => ['type' => 'string',       'required' => true],
                'options'     => ['type' => 'selectOptions', 'required' => true],
                'required'    => ['type' => 'bool',         'required' => false],
                'default'     => ['type' => 'string',       'required' => false],
                'defaultFrom' => ['type' => 'contextPath',  'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'checkbox' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'default'     => ['type' => 'bool',      'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'slider' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'min'         => ['type' => 'int',       'required' => true],
                'max'         => ['type' => 'int',       'required' => true],
                'step'        => ['type' => 'int',       'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'dateInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'fileInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'accept'      => ['type' => 'string',    'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            'colorInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
                'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
            ]],
            // WC-532 A4: a paired Arabic/English bilingual text input. Submits a
            // `{ar?, en?}` LocalizedText object under `name` (matching the
            // schema-driven CRUD screen's localized-field convention), rendered
            // via the shared BilingualInput (RTL/LTR synced). `arLabel`/`enLabel`
            // override the per-field sub-labels.
            'bilingualText' => ['container' => false, 'props' => [
                'name'     => ['type' => 'inputName', 'required' => true],
                'label'    => ['type' => 'string',    'required' => true],
                'required' => ['type' => 'bool',      'required' => false],
                'arLabel'  => ['type' => 'string',    'required' => false],
                'enLabel'  => ['type' => 'string',    'required' => false],
            ]],
            // WC-532 A6: a foreign-key / reference select. Unlike `select`
            // (static `options`), it populates its dropdown from a resource
            // COLLECTION at `source` — an apiPath, so it is ownership-checked
            // and version-rewritten by the PluginLoader exactly like a
            // `dataTable.source` (a plugin can only reference its OWN routes).
            // Each row's `valueField` becomes the submitted value; `labelField`
            // is the display text. Also the reusable primitive behind the
            // Part-B tag-picker.
            'referenceSelect' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'source'      => ['type' => 'apiPath',    'required' => true],
                'valueField'  => ['type' => 'string',     'required' => true],
                'labelField'  => ['type' => 'string',     'required' => true],
                'required'    => ['type' => 'bool',       'required' => false],
                'placeholder' => ['type' => 'string',     'required' => false],
                'default'     => ['type' => 'string',     'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
            ]],
            // ---- organizational-unit SCOPE picker (#868) ----
            // A form input whose value is a RULE over the organizational-unit
            // tree — "every unit of kind X under this parent" — rather than a
            // pinned list of unit ids.
            //
            // Why this is not `referenceSelect` pointed at the OU endpoint.
            // Two reasons, and the second is structural:
            //
            //  1. A reference select submits ONE id. A rule that says "the
            //     departments under this faculty" cannot be expressed as an id,
            //     and pinning the ids it resolves to today is exactly the
            //     parallel unit-id → kind map #822 exists to delete: it goes
            //     stale the first time a unit is added, removed or reparented,
            //     silently, with nothing to tell the consumer it happened.
            //  2. Every `source` prop in this contract is an apiPath the LOADER
            //     ownership-checks against the routes the declaring plugin
            //     actually registered. `/api/ous` is core's, so a plugin cannot
            //     name it — a `referenceSelect` aimed there drops the whole
            //     feature. The only way to satisfy that gate is for the plugin
            //     to republish core's hierarchy through a route of its own,
            //     which is the drift this block exists to prevent.
            //
            // So this type declares NO `source`, deliberately, and it is the
            // only leaf in the whitelist that fetches without one: the host
            // renderer reads the units and the type vocabulary from CORE's own
            // endpoints (`GET /api/ous`, `GET /api/ou-types`), under the
            // caller's own session and the `ous:read` gate those routes already
            // carry. A caller who may not read the org chart cannot build a rule
            // over it, and a plugin cannot point the control anywhere else —
            // there is no prop with which to say where.
            //
            // THE VALUE. One object, submitted under `name`:
            //
            //     ['unit' => 42|null, 'scope' => 'unit'|'subtree'|'children', 'type' => 'faculty'|null]
            //
            //  - `unit`  the anchor unit's id, or null for the whole tenant.
            //  - `scope` ALWAYS present, never inferred. This is the whole point
            //            of the shape: "this unit" and "this unit's subtree" are
            //            different answers and a reader must never have to guess
            //            which one was meant from the presence of another field.
            //  - `type`  an OU type KEY (#822) filtering the RESOLVED set, or
            //            null for any kind. Applied AFTER the scope expands,
            //            never instead of it.
            //
            // How a consumer resolves it, exhaustively:
            //
            //     unit  scope       resolves to
            //     ----  ----------  -------------------------------------------
            //     id    unit        exactly that unit
            //     id    children    its DIRECT children      (?parent_id=<id>)
            //     id    subtree     it AND every descendant   (inclusive)
            //     null  children    the root units            (?parent_id=0)
            //     null  subtree     every unit in the tenant
            //     null  unit        never produced — the renderer cannot offer
            //                       "this unit" with no unit chosen; it is the
            //                       nothing-selected state and is not submitted
            //
            // and `type`, when non-null, narrows whatever that produced —
            // `?type=<key>` on the same core endpoint.
            //
            // THE PROPS.
            //  - `scopes`     the subset of the three the author permits, in the
            //                 order they are offered; the FIRST is the opening
            //                 state. Omitted means all three in the canonical
            //                 order. An author who only ever means subtrees says
            //                 so here and removes the ambiguity at the source.
            //  - `anchorType` restricts which units may be the ANCHOR, by kind
            //                 (`?type=` on the picker's own unit fetch). Design
            //                 time, and a different question from the value's
            //                 `type`, which restricts the resolved SET: "every
            //                 department under a faculty" is `anchorType` =>
            //                 'faculty' with the user choosing 'department'.
            //  - `memberType` PINS the value's `type` to one kind and hides the
            //                 control. Absent, the user chooses (including "any
            //                 kind"). Declaring it alongside a `scopes` list of
            //                 exactly ['unit'] is refused: a kind filter over a
            //                 set the user just picked by hand can only ever
            //                 subtract the one thing in it.
            //  - `required`   removes the tenant-wide option, so the rule must be
            //                 anchored at a unit. Presentational, like every
            //                 other `required` here — the server stays
            //                 authoritative over what it accepts.
            'ouScopePicker' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName',   'required' => true],
                'label'       => ['type' => 'string',      'required' => true],
                'scopes'      => ['type' => 'ouScopeList', 'required' => false],
                'anchorType'  => ['type' => 'ouTypeKey',   'required' => false],
                'memberType'  => ['type' => 'ouTypeKey',   'required' => false],
                'required'    => ['type' => 'bool',        'required' => false],
                'placeholder' => ['type' => 'string',      'required' => false],
            ]],
            'submitButton' => ['container' => false, 'props' => [
                'label'              => ['type' => 'string', 'required' => true],
                'requiredPermission' => ['type' => 'string', 'required' => false],
                'variant'            => ['type' => 'enum',   'required' => false,
                    'values' => ['primary', 'secondary', 'outline', 'ghost', 'destructive']],
            ]],
            'actionButton' => ['container' => false, 'props' => [
                'label'              => ['type' => 'string',     'required' => true],
                'action'             => ['type' => 'submitSpec',  'required' => true],
                'requiredPermission' => ['type' => 'string',     'required' => false],
                'confirm'            => ['type' => 'string',     'required' => false],
                'variant'            => ['type' => 'enum',       'required' => false,
                    'values' => ['primary', 'secondary', 'outline', 'ghost', 'destructive']],
            ]],

            // ---- overlay containers (WC-relations-ui): in-place edit/detail ----
            // A `modal` (→ Dialog) or `drawer` (→ Sheet) wraps its `children` as
            // overlay content — typically a `form`. Opened two ways: its own
            // `trigger` button (present → a top-level "Add …" affordance), or,
            // when `trigger` is omitted, ONLY programmatically by a dataTable
            // `rowActions` entry of kind `open` that targets this block's `id` and
            // publishes the clicked row into the master-detail context. Content
            // then reads that row: a form input via `defaultFrom`, a data-bound
            // child via a dotted `params.from` (`{id}.{field}`). `id` is REQUIRED
            // (the row action's target) and carries no dot so the `{id}.{field}`
            // addressing stays unambiguous. A form nested inside closes the
            // overlay on submit-success (renderer convention — no prop).
            'modal' => ['container' => true, 'props' => [
                'id'      => ['type' => 'blockId', 'required' => true],
                'title'   => ['type' => 'string',  'required' => true],
                'trigger' => ['type' => 'string',  'required' => false],
                'variant' => ['type' => 'enum',    'required' => false,
                    'values' => ['primary', 'secondary', 'outline', 'ghost', 'destructive']],
                'size'    => ['type' => 'enum',     'required' => false, 'values' => ['sm', 'md', 'lg']],
            ]],
            'drawer' => ['container' => true, 'props' => [
                'id'      => ['type' => 'blockId', 'required' => true],
                'title'   => ['type' => 'string',  'required' => true],
                'trigger' => ['type' => 'string',  'required' => false],
                'side'    => ['type' => 'enum',     'required' => false, 'values' => ['left', 'right']],
            ]],
        ];
    }

    /**
     * The block-type whitelist.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::rules());
    }

    /**
     * Whether the type exists in the whitelist.
     */
    public static function isKnown(string $type): bool
    {
        return \array_key_exists($type, self::rules());
    }

    /**
     * Whether the type may carry a `children` array. Unknown types are not
     * containers.
     */
    public static function isContainer(string $type): bool
    {
        return self::rules()[$type]['container'] ?? false;
    }

    /**
     * The rule for a type, or null when the type is not in the whitelist.
     *
     * @return BlockRule|null
     */
    public static function rulesFor(string $type): ?array
    {
        return self::rules()[$type] ?? null;
    }

    /**
     * Static contract only — never instantiated.
     */
    private function __construct()
    {
    }
}
