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
 *   container: bool,                          // may carry child blocks (see childSlots())
 *   props: array<string, array{              // prop name => its rule
 *     type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'itemActionList'|'blockId'|'contextPath'|'ouScopeList'|'ouTypeKey'|'recordPath'|'recordFactList'|'accessCheck',
 *     required: bool,
 *     values?: list<string|int>,             // allowed set for enum / intEnum
 *   }>,
 * }
 * ```
 *
 * @phpstan-type PropRule array{
 *   type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'itemActionList'|'blockId'|'contextPath'|'ouScopeList'|'ouTypeKey'|'recordPath'|'recordFactList'|'accessCheck',
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
     * The most graph nodes a `flow` block will ever draw (#950, inheriting #192).
     *
     * This is a READABILITY ceiling, not a payload one, and it is in the
     * contract rather than in a renderer because the two answers differ. #192 is
     * open against the OU and relations graph views for exactly this: a canvas
     * of several hundred boxes — most of them with no edges at all — is not a
     * hard-to-read diagram, it is a diagram that has stopped conveying anything,
     * and every renderer built on the same library inherits that the moment the
     * data grows. Discovering it in a plugin's production deployment is the
     * outcome this constant exists to prevent.
     *
     * So the ceiling is DECLARED and the behaviour above it is DEFINED: a
     * renderer draws the first `maxNodes` nodes in payload order — the plugin's
     * own ordering, so the truncation cuts where the author would cut — together
     * with the edges among them, and SAYS SO on screen. Silently dropping the
     * tail would make a partial graph indistinguishable from a complete one,
     * which is worse than the tangle: a reader cannot see what is missing.
     *
     * A block may LOWER it via `flow.maxNodes` and may not raise it. That
     * direction is deliberate. A plugin knows things this contract does not —
     * that its steps carry long labels, that a phone renders it — and lowering
     * is that knowledge being applied. Raising is a plugin asserting that its
     * 400-node graph is legible, on every platform and screen this tree may
     * render on, which it cannot know; a dataset that large wants `dataTable`
     * beside the diagram, not a bigger diagram.
     */
    public const FLOW_MAX_NODES = 150;

    /**
     * Props EVERY block type carries, merged into each rule by {@see rules()}.
     *
     * `visibleWhen` used to be declared type by type — on `section`, `card` and
     * the eleven input leaves — because WC-532 A3 only ever meant "hide an input
     * when a sibling field says so". #909 makes it a condition over the RECORD
     * and over the CALLER'S ACCESS as well, and at that point a facet only some
     * types may carry stops being a small restriction and becomes a second
     * mechanism: a page that can gate a `card` but not the `dataTable` inside it
     * has to grow a wrapper for every gated leaf, and granular gating is then
     * expressed one way in some places and another way in others.
     *
     * So it is declared ONCE, here, and merged. Adding a block type gets the
     * facet for free, which is the property that keeps the vocabulary uniform as
     * it grows.
     *
     * @var array<string, PropRule>
     */
    public const UNIVERSAL_PROPS = [
        'visibleWhen' => ['type' => 'visibilityRule', 'required' => false],
    ];

    /**
     * The child-list keys a type may carry, for the types that carry more than
     * the usual one.
     *
     * `children` is the default and the only slot almost every container has.
     * `accessGate` has two, because the two renderings of a gated region have to
     * be declared TOGETHER (see the type's own note), and a second slot is the
     * only way to say that in a tree.
     *
     * DECLARED HERE, NOT RESTATED PER WALKER. Several things walk a block tree —
     * the validator, the host loader's ownership check and version rewrite, the
     * showcase's coverage tests — and a walker that does not know about a slot
     * silently skips everything in it. For the loader that is not a cosmetic
     * miss: an unchecked slot is a `source` that never got ownership-checked. So
     * every walker asks {@see childSlots()} instead of restating a list, which
     * is the same lesson #908 drew when it made the source-bearing types derived
     * rather than listed.
     *
     * @var array<string, list<string>>
     */
    private const CHILD_SLOTS = [
        'accessGate' => ['children', 'otherwise'],
    ];

    /**
     * The whitelist: block type => its rule, with {@see UNIVERSAL_PROPS} merged
     * into every entry.
     *
     * @return array<string, BlockRule>
     */
    public static function rules(): array
    {
        $rules = self::declaredRules();

        foreach ($rules as $type => $rule) {
            // Declared props win: the merge ADDS the universal facet and can
            // never quietly redefine a prop a type states for itself.
            $rules[$type]['props'] = $rule['props'] + self::UNIVERSAL_PROPS;
        }

        return $rules;
    }

    /**
     * The whitelist as WRITTEN, before the universal facet is merged in. The
     * ordering here is the canonical documentation order (containers first,
     * then leaves).
     *
     * @return array<string, BlockRule>
     */
    private static function declaredRules(): array
    {
        return [
            // ---- containers (may carry `children`) ----
            'section' => [
                'container' => true,
                'props' => [
                    'title' => ['type' => 'string', 'required' => false],
                ],
            ],
            'card' => [
                'container' => true,
                'props' => [
                    'title' => ['type' => 'string', 'required' => false],
                    'description' => ['type' => 'string', 'required' => false],
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
            // #883: the four literal leaves each carry an optional `…From`
            // twin — a `contextPath` naming a field of a record in the
            // master-detail context (a `dataRecord`'s published facts, or a row
            // an `open` row action published). The LITERAL stays required and
            // is the fallback: a record page that titles itself with the
            // record's own name still has a title while the record loads, and
            // still has one if the reference never resolves. That is why these
            // are additive twins rather than an either/or — "exactly one of"
            // would have made an unresolved reference render as nothing, and a
            // page with no heading is a worse failure than a generic one.
            //
            // These are FACT bindings — the page STATING something about the
            // record — so the validator refuses one that names a field in
            // {@see BlockValidator::CALLER_DECISION_FIELDS}. See #895.
            'heading' => [
                'container' => false,
                'props' => [
                    'level' => ['type' => 'intEnum', 'required' => true, 'values' => [1, 2, 3, 4]],
                    'text' => ['type' => 'string', 'required' => true],
                    'textFrom' => ['type' => 'contextPath', 'required' => false],
                ],
            ],
            'text' => [
                'container' => false,
                'props' => [
                    'value' => ['type' => 'string', 'required' => true],
                    'valueFrom' => ['type' => 'contextPath', 'required' => false],
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
                    'labelFrom' => ['type' => 'contextPath', 'required' => false],
                ],
            ],
            'stat' => [
                'container' => false,
                'props' => [
                    'label' => ['type' => 'string', 'required' => true],
                    'value' => ['type' => 'string', 'required' => true],
                    'valueFrom' => ['type' => 'contextPath', 'required' => false],
                    'hint' => ['type' => 'string', 'required' => false],
                    'hintFrom' => ['type' => 'contextPath', 'required' => false],
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

            // ---- graph block (#950) ----
            // A SET OF NODES AND THE EDGES BETWEEN THEM — the one shape the
            // other 47 types cannot express. `dataTable` lists a process's
            // steps, `form`/`fieldArray` edit them, `timeline` shows what has
            // already happened to one; none of them draws the picture, and for
            // an ordered or branching process the picture is usually the thing
            // that makes it legible to the person who is not going to read a
            // table. So every product with a workflow either shipped a bespoke
            // React screen or shipped no diagram.
            //
            // This exposes the renderer core already runs rather than adding
            // one: `@xyflow/react` is an in-tree dependency with two working
            // consumers (the OU hub's hierarchy view and the relations family
            // graph), and both of those are the same composition — selectable
            // node cards, a per-node action menu, connecting disabled.
            //
            // ONE FETCH, ROWS THAT ARE NODES. Data-bound exactly like
            // `dataTable`: one ownership-checked `source` returning a
            // collection, then per-field mappings. A row IS a node; there is no
            // second endpoint for edges, because a block that needed two
            // sources would need two ownership checks, two loading states and a
            // join the renderer would have to invent. The edges come off the
            // node rows instead, as references to OTHER nodes' ids:
            //
            //   - `edgeFromField` — the field holding the id(s) this node is
            //     reached FROM. A parent pointer: `'edgeFromField' => 'parentId'`
            //     is the OU hierarchy, unchanged.
            //   - `edgeToField` — the field holding the id(s) this node leads
            //     TO. A next pointer: `'edgeToField' => 'nextStepId'` is an
            //     ordered process, and this is the shape #947's routing steps
            //     already have.
            //   - Either field's value may be a LIST, which is how a step
            //     branches to more than one successor without a second source.
            //   - NEITHER declared: the nodes are a LINEAR SEQUENCE in payload
            //     order. The common case — an ordered list of steps — costs the
            //     plugin no edge modelling at all.
            //
            // A reference to an id no row declared is DROPPED rather than
            // materialised as a node. The alternative is a graph containing
            // boxes the plugin never described, labelled with a raw id.
            //
            // READ-ONLY, and read-only by construction: no endpoint, no verb,
            // nothing for a renderer to submit — the same property `timeline`
            // has. Editing is a form opened FROM a node, which is why the
            // affordance is `nodeActions` of the SAME `rowActionList` kind
            // `dataTable.rowActions` uses, resolved by the same validator and
            // rendered through the same code path. A node's actions are a row's
            // actions; declaring them twice in two shapes is how the two
            // spellings drift. Clicking the node itself runs its first `open`
            // action, if it has one — a diagram whose only affordance is a
            // dropdown is a diagram nobody clicks, and "click the node, get the
            // detail" is what both existing consumers already do.
            //
            // `maxNodes` is the #192 inheritance, carried deliberately: see
            // {@see self::FLOW_MAX_NODES} for the ceiling, why it may only be
            // lowered, and what a renderer must do above it.
            'flow' => ['container' => false, 'props' => [
                'source'            => ['type' => 'apiPath',       'required' => true],
                'nodeIdField'       => ['type' => 'string',        'required' => true],
                'nodeLabelField'    => ['type' => 'string',        'required' => true],
                'nodeSubtitleField' => ['type' => 'string',        'required' => false],
                'edgeFromField'     => ['type' => 'string',        'required' => false],
                'edgeToField'       => ['type' => 'string',        'required' => false],
                'orientation'       => ['type' => 'enum',          'required' => false,
                    'values' => ['horizontal', 'vertical']],
                'nodeActions'       => ['type' => 'rowActionList', 'required' => false],
                'maxNodes'          => ['type' => 'int',           'required' => false],
                'emptyText'         => ['type' => 'string',        'required' => false],
                'params'            => ['type' => 'sourceParamList', 'required' => false],
            ]],

            // ---- record blocks (#883) ----
            // The RECORD-BOUND primitive. Every other data-bound leaf in this
            // contract assumes a COLLECTION at `source`: `dataTable` renders
            // rows, `dataStat` reduces a body to one scalar, `keyValue` takes
            // literals baked into the declaration. Nothing fetched ONE resource
            // and rendered its fields, so a record page — the platform's
            // standard for editing a record since #882 — could not be described
            // at all, only hand-written in React.
            //
            // `dataRecord` is a CONTAINER rather than a leaf, and that is the
            // whole design. It does three jobs that a record page needs done in
            // one place:
            //
            //  1. It fetches one resource at `source`.
            //  2. It PUBLISHES that record into the master-detail context under
            //     its `id`, so every block beneath it — and beside it — reads
            //     the record through the SAME `{id}.{field}` addressing an
            //     `open` row action already publishes a row under. That is the
            //     page-level record context #883 asks for, built out of the
            //     mechanism that already exists instead of a second one.
            //  3. It owns the loading and failure states for its whole subtree,
            //     so a half-loaded record is one state on one block rather than
            //     a dozen skeletons that arrive in an arbitrary order.
            //
            // WHICH record. `source` is a `recordPath`: an owned apiPath that
            // may carry `{token}` segments in the SAME addressing as
            // `params.from` / `defaultFrom` / `submit.endpoint`. So a detail
            // pane reads `/api/x/rows/{picker}` from a `selector`, an overlay
            // reads `/api/x/rows/{edit-modal.id}` from the row that opened it,
            // and a record ROUTE reads `/api/x/rows/{record}` — `record` being
            // the reserved name a host seeds with the route's own record id
            // (which is why a `selector` may not be named `record`; see
            // {@see BlockValidator::PAGE_RECORD_BINDING}). The block does not
            // fetch until every token resolves: an unresolved token would
            // otherwise request a DIFFERENT resource than the one meant, and
            // silently render it as the record.
            //
            // `fields` IS THE #895 GUARD, and it is a whitelist rather than a
            // warning. #895: the roles record page derived "is this a global
            // role" from `manageable` — the server's answer to "may YOU write
            // this?" — and for a tenant-0 caller `manageable` is true of every
            // role, so the system tenant, the one caller whose edit reaches
            // every tenant, saw a deployment-wide role labelled "Your tenant's
            // role". #897 made that unwriteable in TypeScript by splitting the
            // payload into `fields` (what the record IS) and `access` (what the
            // caller MAY DO) and refusing a fields type that carries a caller
            // flag. A block tree is runtime data, not a type, so the same split
            // is enforced two ways here:
            //
            //  - The declaration NAMES the record's facts, and ONLY the named
            //    fields are published into context. A payload's `manageable`,
            //    `canEdit` or `mayModify` is not reachable by any binding in
            //    the tree because it was never published — whatever it is
            //    called, and whether or not the author thought about it. That
            //    is the structural half, and it does not depend on a vocabulary.
            //  - A `fields` entry naming a field the contract recognises as a
            //    caller decision ({@see BlockValidator::CALLER_DECISION_FIELDS},
            //    the same list #897 checks in TypeScript) is REFUSED, which
            //    drops the whole feature. That is the half that names the
            //    mistake, so an author who tries to declare `manageable` as a
            //    fact is told why rather than watching it silently vanish.
            //
            // A record's caller-permission flags therefore have exactly one
            // route into a record page: none. What the caller may do decides
            // which CONTROLS exist, and controls are gated by
            // `requiredPermission` on `form`/`submitButton`/`actionButton` and
            // by the host's own per-caller resolution — never by a sentence the
            // page states about the record.
            'dataRecord' => ['container' => true, 'props' => [
                'id'        => ['type' => 'blockId',        'required' => true],
                'source'    => ['type' => 'recordPath',     'required' => true],
                'fields'    => ['type' => 'recordFactList', 'required' => true],
                'emptyText' => ['type' => 'string',         'required' => false],
                'params'    => ['type' => 'sourceParamList', 'required' => false],
            ]],
            // The data-bound `keyValue`: a description list of a record's facts,
            // read from the context a `dataRecord` published rather than from
            // literals baked into the declaration.
            //
            // It carries no `source` and no labels of its own. The labels were
            // declared once beside the facts on `dataRecord.fields`, because a
            // record page shows the same field in more than one place (a card, a
            // side panel, a read-only rendering) and a label restated per
            // placement is a label that drifts per placement. `fields` picks a
            // SUBSET, in the order given; omitted, the block renders every fact
            // the named record declared. A name that is not one of them renders
            // nothing — the same no-op an unresolvable `params.from` already is.
            'recordFields' => ['container' => false, 'props' => [
                'from'      => ['type' => 'blockId',    'required' => true],
                'fields'    => ['type' => 'stringList', 'required' => false],
                'emptyText' => ['type' => 'string',     'required' => false],
            ]],

            // ---- access blocks (#909) ----
            // The CALLER-ACCESS primitive, and the other half of #895's split.
            //
            // #883 gave a record page everything except the half the record-page
            // shell exists to enforce. `RecordPageShell` takes `main: {editor,
            // readOnly}` — TWO renderings, and it picks. A block tree could not
            // pick: `visibleWhen` matched a sibling FORM FIELD and nothing else,
            // so a described record page rendered its editor unconditionally and
            // a caller who may not write got a disabled Save button beside fully
            // editable inputs. That is precisely the greyed-out form #882 was
            // written to make unshippable.
            //
            // WHAT IT IS. A gate declares ONE question about the CALLER — "would
            // you let me make this request?" — publishes the host's answer under
            // its `id`, and renders `children` when the answer is yes and
            // `otherwise` when it is no.
            //
            // WHO ANSWERS IT, which is the whole reason this type lives in the
            // contract rather than in each plugin. `check` names a concrete
            // request, and the host resolves it through
            // `POST /api/v1/me/permitted-actions` — the SAME route lookup feeding
            // the SAME RoleChecker calls RbacMiddleware makes, against the live
            // route table (#868). A plugin therefore does NOT declare the
            // permission the region is gated on: that is not its to restate, and
            // a restated slug is a second answer to a question the route table
            // already answers, drifting the day someone re-gates the route and
            // updates one of the two places. This is the property that made
            // `inbox` correct by construction, applied to a region of a page
            // instead of to a row's buttons.
            //
            // WHY TWO SLOTS RATHER THAN TWO NEGATED CONDITIONS. `children` and
            // `otherwise` could be written as two siblings carrying
            // `visibleWhen` with opposite polarity, and that is exactly what
            // must not be the recommended shape: two conditions that are
            // supposed to be each other's negation drift, and when they drift
            // the editable half is the one that ends up showing. Declared as one
            // node they cannot disagree — the same reason `RecordPageShell`
            // takes both renderings as required props rather than one plus a
            // flag.
            //
            // THE THREE-STATE CASE COMPOSES. hidden / read-only / editable is
            // two nested gates: the outer one on the READ request with no
            // `otherwise` (refused ⇒ the region is absent), the inner one on the
            // WRITE request with both (refused ⇒ the read-only rendering, and
            // its own subtree is where "which gate refused" is said). Nesting
            // also gives #897's "first refusal wins" structurally: an outer gate
            // that refuses never renders the inner one, so exactly one refusal
            // is ever on screen.
            //
            // A GATE MAY CARRY NO CHILDREN AT ALL. Then it is purely a
            // declaration, and `visibleWhen: {access: <id>}` reads it from
            // anywhere on the screen — which is how a single question gates a
            // heading here, a column there, and a whole card somewhere else
            // without wrapping each of them.
            //
            // WHAT A GATE'S ANSWER IS NOT. It is a CONTROL binding, never a
            // fact. The answer is published into a namespace of its own, which
            // `textFrom`/`valueFrom`/`labelFrom`/`hintFrom`/`defaultFrom`/
            // `params.from` do not resolve against — they read records and
            // selections, and always have. There is exactly one prop in this
            // contract that names a gate, `visibleWhen.access`, and it decides
            // whether something renders. So a page can act on what the caller
            // may do and still cannot SAY it about the record, which is #895's
            // rule kept intact while the thing it forbade becomes expressible.
            'accessGate' => ['container' => true, 'props' => [
                'id'    => ['type' => 'blockId',     'required' => true],
                'check' => ['type' => 'accessCheck', 'required' => true],
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
            // WC-532 A3 / #909: `visibleWhen` is no longer declared per type.
            // It is a UNIVERSAL facet ({@see self::UNIVERSAL_PROPS}) merged into
            // every rule, because a condition that only some blocks may carry is
            // a condition granular gating has to route around.
            'textInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'placeholder' => ['type' => 'string',    'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
            ]],
            'textArea' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'rows'        => ['type' => 'int',       'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
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
            ]],
            'select' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName',    'required' => true],
                'label'       => ['type' => 'string',       'required' => true],
                'options'     => ['type' => 'selectOptions', 'required' => true],
                'required'    => ['type' => 'bool',         'required' => false],
                'default'     => ['type' => 'string',       'required' => false],
                'defaultFrom' => ['type' => 'contextPath',  'required' => false],
            ]],
            'checkbox' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'default'     => ['type' => 'bool',      'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
            ]],
            'slider' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'min'         => ['type' => 'int',       'required' => true],
                'max'         => ['type' => 'int',       'required' => true],
                'step'        => ['type' => 'int',       'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
            ]],
            'dateInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
            ]],
            'fileInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'accept'      => ['type' => 'string',    'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
            ]],
            'colorInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'default'     => ['type' => 'string',    'required' => false],
                'defaultFrom' => ['type' => 'contextPath', 'required' => false],
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
     * Whether the type may carry child blocks. Unknown types are not containers.
     */
    public static function isContainer(string $type): bool
    {
        return self::rules()[$type]['container'] ?? false;
    }

    /**
     * The child-list keys this type may carry, in declaration order.
     *
     * `['children']` for every container that has not said otherwise, and the
     * empty list for a leaf or an unknown type — so a caller can iterate the
     * answer unconditionally.
     *
     * EVERY TREE WALKER SHOULD USE THIS rather than reaching for `children`
     * directly: the validator, the host's ownership/version rewrite and the
     * coverage tests all descend through it, and a walker that hard-codes the
     * name skips whatever lives in a slot added later. For the loader the miss
     * is not cosmetic — an unwalked slot is a `source` that never got
     * ownership-checked.
     *
     * @return list<string>
     */
    public static function childSlots(string $type): array
    {
        if (!self::isContainer($type)) {
            return [];
        }

        return self::CHILD_SLOTS[$type] ?? ['children'];
    }

    /**
     * Every child-list key any type in the whitelist may carry.
     *
     * The validator uses it to refuse a slot on a type that does not declare one
     * — an `otherwise` on a `card` is an author who meant something, and what
     * they meant is a subtree that would silently never render.
     *
     * @return list<string>
     */
    public static function knownChildSlots(): array
    {
        $slots = ['children'];
        foreach (self::CHILD_SLOTS as $declared) {
            foreach ($declared as $slot) {
                if (!\in_array($slot, $slots, true)) {
                    $slots[] = $slot;
                }
            }
        }

        return $slots;
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
