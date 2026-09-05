<?php

declare(strict_types=1);

namespace Whity\Sdk\Frontend\Blocks;

/**
 * Validates a platform-neutral block tree against {@see BlockContract} (WC-225, WC-229, WC-233).
 *
 * {@see validate()} is the single gate every server-driven plugin-UI screen
 * passes before any renderer sees it. It is PURE and worker-safe — no static
 * state is retained across calls — and it NEVER throws: malformed input (a
 * scalar where a node is expected, a missing `type`, a prop of the wrong PHP
 * type, an over-deep or over-large tree) is reported as a path-qualified error,
 * not an exception. The contract is therefore safe to run directly on untrusted
 * plugin-supplied data inside a long-lived worker.
 *
 * Every error message is qualified with the JSON-ish path to the offending
 * node/prop, e.g. `blocks[0].children[2]: unknown block type 'wormhole'`.
 *
 * SP3 (WC-233): input leaves and submitButton are only valid inside a `form`
 * ancestor; this is enforced by threading `$inForm` (an ancestor flag, not just
 * a direct-parent check) and `$formNames` (a per-form name registry for
 * duplicate-name detection) through `validateList` and `validateNode`.
 */
final class BlockValidator
{
    /**
     * The three scope kinds an `ouScopePicker` value may carry, and the order
     * they are offered in when the block declares no `scopes` of its own.
     *
     * Public because it is the vocabulary, not an implementation detail: a
     * consumer resolving a stored rule switches on exactly these three strings,
     * and a renderer offers exactly these three controls.
     *
     * @var list<string>
     */
    public const OU_SCOPES = ['unit', 'subtree', 'children'];

    /**
     * The reserved master-detail binding a host seeds with the record its ROUTE
     * is about (#883 gap 2).
     *
     * A record page is `/admin/<resource>/[id]`, and nothing in a block tree
     * could name that id: `defaultFrom` and `params.from` resolve only against a
     * `selector` choice or a `dataTable` row action, and both of those are
     * list-anchored. So the host publishes the route's record id under this one
     * reserved name, and a `dataRecord` reads it the ordinary way —
     * `source => '/api/x/rows/{record}'`.
     *
     * Public because it is the vocabulary a host implements and a plugin author
     * types, not an implementation detail. A `selector` may not claim the name:
     * a selection published under it would shadow the page's own record for
     * every block on the screen, and the failure — a record page quietly showing
     * a different record — is invisible in exactly the deployments where the
     * selector happens to be empty.
     */
    public const PAGE_RECORD_BINDING = 'record';

    /**
     * Field names that state a decision about the CALLER rather than a property
     * of the RECORD (#895), refused wherever a declaration states a FACT.
     *
     * This list is #897's `CallerDecisionKey` verbatim — the same eleven names,
     * in the same order — and that is deliberate rather than incidental. #897
     * made the mistake unwriteable in TypeScript for hand-built record pages;
     * this file has to make the same mistake unwriteable in a tree that is
     * validated at runtime. Two lists that were "kept in sync" would be one list
     * and one stale copy, so the pairing is stated here, pinned by a test on
     * both sides, and any addition is made to both in the same change.
     *
     * WHAT WENT WRONG, since a list of words does not carry it: the roles record
     * page derived "is this a global base role" from `manageable`, the server's
     * answer to "may YOU write this?". For a tenant-0 caller `manageable` is
     * true of every role, so the system tenant — the one caller whose edit
     * reaches every tenant — saw a deployment-wide base role labelled "Your
     * tenant's role". The inference reads as correct in the common case and is
     * wrong precisely for the caller who can act on it.
     *
     * Note what this list is NOT. It is not the guard; it is the guard's error
     * message. The guard is that a `dataRecord` publishes ONLY the fields its
     * declaration names as facts, so a payload's caller flag is unreachable from
     * the tree whatever it is called. This list catches the author who names one
     * ANYWAY, and names it back to them.
     *
     * @var list<string>
     */
    public const CALLER_DECISION_FIELDS = [
        'manageable',
        'editable',
        'writable',
        'deletable',
        'canEdit',
        'canDelete',
        'canManage',
        'canWrite',
        'allowed',
        'permitted',
        'readOnly',
    ];

    /**
     * The HTTP methods an `accessGate.check` may name (#909).
     *
     * GET is here and the write verbs are here, and the pair is the difference
     * between two of the three states a gated region has. "May I CHANGE this?"
     * is a write verb and selects between the editable and the read-only
     * rendering; "may I SEE this at all?" is a GET and selects between rendering
     * the region and omitting it. Without GET the hidden state has no authority
     * to ask, and an author would have to fake it by gating a read-only panel on
     * a write request — which answers a different question and answers it wrong
     * for every reader who may look but not touch.
     *
     * `POST /api/v1/me/permitted-actions` accepts the same set; that endpoint's
     * identity ("allowed implies the middleware would admit exactly this
     * request") is method-agnostic, so widening it costs nothing it was
     * promising.
     *
     * @var list<string>
     */
    public const ACCESS_CHECK_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * The props whose value is a FACT the page states about a record, and which
     * therefore may not name a caller decision.
     *
     * `defaultFrom` and `params.from` are deliberately absent. They are
     * PLUMBING, not statements: one seeds a form control whose value the server
     * re-validates and is authoritative over, the other narrows a fetch. #897
     * draws the line in the same place — its `RecordAccess` half is read freely
     * to decide which controls exist, and only the FACTS projection is checked.
     * A guard that also refused the plumbing would be refusing a correct
     * program, and a guard that refuses correct programs gets removed.
     *
     * @var list<string>
     */
    private const FACT_BINDING_PROPS = ['textFrom', 'valueFrom', 'labelFrom', 'hintFrom'];

    /**
     * Longest organizational-unit type key accepted (#822's `ou_types.type_key`
     * column width). Restated rather than imported for the same reason the key
     * grammar is: the SDK may not reference a core symbol.
     */
    private const OU_TYPE_KEY_MAX_LENGTH = 128;

    /**
     * The input leaf types that are only valid inside a `form` ancestor.
     */
    private const INPUT_LEAF_TYPES = [
        'textInput', 'textArea', 'numberInput', 'select',
        'checkbox', 'slider', 'dateInput', 'fileInput', 'colorInput',
        'bilingualText', 'referenceSelect', 'richTextInput', 'ouScopePicker',
    ];

    /**
     * All interactive block types (input leaves + submitButton) that require a
     * `form` ancestor.
     */
    private const FORM_ONLY_TYPES = [
        'textInput', 'textArea', 'numberInput', 'select',
        'checkbox', 'slider', 'dateInput', 'fileInput', 'colorInput',
        'bilingualText', 'referenceSelect', 'richTextInput', 'ouScopePicker',
        'submitButton', 'fieldArray',
        // WC-532 item 3. `variant` selects on a sibling input's value, so a
        // form is the only place its discriminator can exist. `variantCase` is
        // listed too rather than relying on its parent check alone: the two
        // rules answer different questions ("is there a form?" and "is the
        // parent a variant?"), and a case that somehow reached the tree without
        // a form ancestor should say so in its own terms.
        'variant', 'variantCase',
    ];

    /**
     * Validate a top-level list of blocks.
     *
     * @param array<mixed> $tree the candidate tree: a list of block nodes
     *
     * @return array{ok: bool, errors: list<string>} `ok` is true only when
     *                                                `errors` is empty
     */
    public static function validate(array $tree): array
    {
        $errors = [];
        $count = 0;
        $formNames = [];

        self::validateList($tree, 'blocks', 1, $count, $errors, null, false, $formNames);

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Validate a list of blocks at the given path and nesting depth.
     *
     * @param array<mixed>      $list
     * @param int               $depth      1-based depth of the nodes in this list
     * @param int               $count      running total node count (by reference)
     * @param list<string>      $errors     accumulated errors (by reference)
     * @param string|null       $parentType the container type whose children
     *                                      this list is, or null at the root
     * @param bool              $inForm     true when inside a `form` ancestor
     * @param array<string,bool> $formNames  per-form input name registry (by reference)
     */
    private static function validateList(
        array $list,
        string $path,
        int $depth,
        int &$count,
        array &$errors,
        ?string $parentType = null,
        bool $inForm = false,
        array &$formNames = [],
    ): void {
        if (!array_is_list($list)) {
            $errors[] = "{$path}: expected a list of blocks";

            return;
        }

        foreach ($list as $index => $node) {
            self::validateNode($node, "{$path}[{$index}]", $depth, $count, $errors, $parentType, $inForm, $formNames);
        }
    }

    /**
     * Validate a single node (and recurse into its children).
     *
     * @param mixed              $node
     * @param int                $count      running total node count (by reference)
     * @param list<string>       $errors     accumulated errors (by reference)
     * @param bool               $inForm     true when inside a `form` ancestor
     * @param array<string,bool> $formNames  per-form input name registry (by reference)
     */
    private static function validateNode(
        mixed $node,
        string $path,
        int $depth,
        int &$count,
        array &$errors,
        ?string $parentType,
        bool $inForm,
        array &$formNames,
    ): void {
        if (!\is_array($node)) {
            $errors[] = "{$path}: expected a block object (array), got " . get_debug_type($node);

            return;
        }

        $count++;
        if ($count > BlockContract::MAX_NODES) {
            $errors[] = "{$path}: too many nodes — the tree exceeds the maximum of "
                . BlockContract::MAX_NODES . ' nodes';

            return;
        }

        if (!isset($node['type']) || !\is_string($node['type'])) {
            $errors[] = "{$path}: block is missing a string 'type'";

            return;
        }

        $type = $node['type'];
        $rule = BlockContract::rulesFor($type);
        if ($rule === null) {
            $errors[] = "{$path}: unknown block type '{$type}'";

            return;
        }

        // `tab` is only legal as a direct child of `tabs`.
        if ($type === 'tab' && $parentType !== 'tabs') {
            $errors[] = "{$path}: 'tab' is only valid as a direct child of 'tabs'";

            return;
        }
        // `tabs` children must all be `tab` blocks (enforced where the child's
        // own type is wrong).
        if ($parentType === 'tabs' && $type !== 'tab') {
            $errors[] = "{$path}: children of 'tabs' must be 'tab' blocks, got '{$type}'";

            return;
        }

        // WC-532 item 3: `variant`/`variantCase` pair the same way, and for a
        // sharper reason than tabs do. A case is the unit the renderer includes
        // in or excludes from the SUBMIT PAYLOAD; an input sitting directly
        // under a `variant`, in no case at all, has no answer to "which
        // discriminator value does this belong to". Rejecting it here is the
        // difference between a declaration error and a field that silently
        // never submits.
        if ($type === 'variantCase' && $parentType !== 'variant') {
            $errors[] = "{$path}: 'variantCase' is only valid as a direct child of 'variant'";

            return;
        }
        if ($parentType === 'variant' && $type !== 'variantCase') {
            $errors[] = "{$path}: children of 'variant' must be 'variantCase' blocks, got '{$type}'";

            return;
        }

        // SP3 (WC-233): input leaves and submitButton require a `form` ancestor.
        if (\in_array($type, self::FORM_ONLY_TYPES, true) && !$inForm) {
            $errors[] = "{$path}: '{$type}' is only valid inside a 'form'";

            return;
        }

        // SP3 (WC-233): track input names within their enclosing form for
        // duplicate detection. Applies to input leaves (not submitButton) and
        // to `fieldArray` (WC-532 A2), whose own `name` is the payload key for
        // its row array and must not collide with a sibling input.
        if ($inForm && (\in_array($type, self::INPUT_LEAF_TYPES, true) || $type === 'fieldArray')) {
            $nameValue = $node['name'] ?? null;
            if (\is_string($nameValue) && $nameValue !== '') {
                if (isset($formNames[$nameValue])) {
                    $errors[] = "{$path}: duplicate input name '{$nameValue}' within the form";
                } else {
                    $formNames[$nameValue] = true;
                }
            }
            // Missing / invalid `name` prop is caught by validateProps below.
        }

        self::validateProps($node, $type, $rule['props'], $path, $errors);

        // #868: an `inbox` action's `scopedPermission` is a PER-RECORD predicate,
        // so the block must say which kind of record its items are. Without a
        // `resourceType` the host would have no resource to scope the question
        // to and would silently answer the tenant-wide one instead — a check
        // that reads as per-record, is not, and is wrong in the permissive
        // direction relative to the author's intent. Refused at validation
        // rather than degraded at runtime.
        if ($type === 'inbox') {
            self::validateInboxScoping($node, $path, $errors);
        }

        // #868: a `memberType` on an `ouScopePicker` whose only permitted scope
        // is 'unit' is a filter that can never do anything but subtract the one
        // unit the user just chose by hand. Cross-prop, so it cannot live in the
        // per-prop rules. Refused rather than quietly ignored: an author who
        // wrote it meant something, and what they meant is not what it does.
        if ($type === 'ouScopePicker') {
            self::validateOuScopePicker($node, $path, $errors);
        }

        // #950: the graph block's two cross-prop rules — a node ceiling that may
        // only be lowered, and edge fields that must name different fields.
        if ($type === 'flow') {
            self::validateFlow($node, $path, $errors);
        }

        // #883: `record` is the host's binding for the record a ROUTE is about
        // (see self::PAGE_RECORD_BINDING). A selector publishing under that name
        // would shadow it for every block on the screen, and the symptom — a
        // record page showing a different record — appears only once the
        // selector has a value, so it is invisible on first paint and in every
        // test that never touches the dropdown.
        if ($type === 'selector' && ($node['name'] ?? null) === self::PAGE_RECORD_BINDING) {
            $errors[] = "{$path}.name: 'selector.name' may not be '" . self::PAGE_RECORD_BINDING
                . "' — that name is reserved for the record a record-page route is about, "
                . 'and a selection published under it would shadow the page\'s own record';
        }

        // The child lists this TYPE declares (#909). Almost always `['children']`;
        // `accessGate` declares two, and asking the contract rather than reaching
        // for `children` is what keeps a slot added later from being silently
        // skipped here and in every other walker.
        $slots = BlockContract::childSlots($type);

        foreach (BlockContract::knownChildSlots() as $slot) {
            if (!\array_key_exists($slot, $node) || \in_array($slot, $slots, true)) {
                continue;
            }

            $errors[] = $rule['container']
                ? "{$path}: '{$type}' has no '{$slot}' list — it takes " . self::describeSlots($slots)
                : "{$path}: '{$type}' is a leaf block and cannot have '{$slot}'";

            return;
        }

        // The two branches of an `accessGate` are MUTUALLY EXCLUSIVE renderings,
        // so an input name used in one is not a duplicate of the same name in the
        // other — only one of them is ever on screen. Each slot therefore starts
        // from a snapshot of the names declared BEFORE the node, and the names
        // each slot introduces are merged back afterwards so a later sibling
        // still collides with them. For a single-slot container this is exactly
        // the by-reference behaviour it replaces.
        $outerNames = $formNames;

        foreach ($slots as $slot) {
            if (!\array_key_exists($slot, $node)) {
                continue;
            }

            $children = $node[$slot];
            if (!\is_array($children)) {
                $errors[] = "{$path}.{$slot}: '{$slot}' must be a list of blocks";

                return;
            }

            if ($depth + 1 > BlockContract::MAX_DEPTH) {
                $errors[] = "{$path}.{$slot}: nesting too deep — the tree exceeds the maximum depth of "
                    . BlockContract::MAX_DEPTH;

                return;
            }

            /** @var array<mixed> $children */
            if ($type === 'form' || $type === 'fieldArray') {
                // A `form` starts a fresh name registry for its subtree; a
                // `fieldArray` (WC-532 A2) likewise scopes its template's input
                // names PER ROW, so they neither collide with the outer form nor
                // with a sibling fieldArray. Its children still require a form
                // ancestor, so `$inForm` stays true down this branch. Nothing
                // merges back out: that scoping is the point.
                $childFormNames = [];
                self::validateList($children, "{$path}.{$slot}", $depth + 1, $count, $errors, $type, true, $childFormNames);

                continue;
            }

            // WC-532 item 3: a `variantCase` INHERITS the enclosing form's
            // names but does not export its own.
            //
            // Inheriting is what stops a case from redefining a name the form
            // already uses outside the variant: both would be in the payload
            // together, and one would win silently.
            //
            // Not exporting is what makes the union work. Sibling cases are
            // mutually exclusive by construction — at most one is ever
            // submitted — so two cases may both declare `value`, which is
            // exactly what a discriminated union looks like from the server's
            // side: {type:'numeric', value: 5} and {type:'text', value: 'x'}.
            // Merging their names back out would report that as a duplicate and
            // force every branch to prefix its fields, which would make the
            // payload shape depend on the declaration style rather than on the
            // union being modelled.
            //
            // A duplicate WITHIN one case is still caught, because the case's
            // own registry is shared across its subtree.
            if ($type === 'variantCase') {
                $caseNames = $outerNames;
                self::validateList($children, "{$path}.{$slot}", $depth + 1, $count, $errors, $type, $inForm, $caseNames);

                continue;
            }

            $slotNames = $outerNames;
            self::validateList($children, "{$path}.{$slot}", $depth + 1, $count, $errors, $type, $inForm, $slotNames);
            $formNames += $slotNames;
        }
    }

    /**
     * Render a type's child-slot names for an error message.
     *
     * @param list<string> $slots
     */
    private static function describeSlots(array $slots): string
    {
        return $slots === []
            ? 'no child lists'
            : "'" . implode("' and '", $slots) . "'";
    }

    /**
     * Validate every declared prop of a node against the type's prop rules.
     *
     * @param array<mixed>  $node
     * @param array<string, array{type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'blockId'|'contextPath'|'itemActionList'|'ouScopeList'|'ouTypeKey'|'recordPath'|'recordFactList'|'accessCheck'|'preloadSpec', required: bool, values?: list<string|int>}> $propRules
     * @param list<string>  $errors by reference
     */
    private static function validateProps(
        array $node,
        string $type,
        array $propRules,
        string $path,
        array &$errors,
    ): void {
        foreach ($propRules as $prop => $rule) {
            $present = \array_key_exists($prop, $node);

            if (!$present) {
                if ($rule['required']) {
                    $errors[] = "{$path}: '{$type}' is missing required prop '{$prop}'";
                }

                continue;
            }

            self::validatePropValue($node[$prop], $rule, $type, $prop, "{$path}.{$prop}", $errors);
        }
    }

    /**
     * Validate a single present prop value against its rule.
     *
     * @param mixed $value
     * @param array{type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'blockId'|'contextPath'|'itemActionList'|'ouScopeList'|'ouTypeKey'|'recordPath'|'recordFactList'|'accessCheck'|'preloadSpec', values?: list<string|int>, required: bool} $rule
     * @param list<string> $errors by reference
     */
    private static function validatePropValue(
        mixed $value,
        array $rule,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        switch ($rule['type']) {
            case 'string':
                if (!\is_string($value)) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be a string, got " . get_debug_type($value);
                }

                break;

            case 'int':
                if (!\is_int($value)) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be an integer, got " . get_debug_type($value);
                }

                break;

            case 'bool':
                if (!\is_bool($value)) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be a boolean, got " . get_debug_type($value);
                }

                break;

            case 'enum':
                $allowed = $rule['values'] ?? [];
                if (!\is_string($value) || !\in_array($value, $allowed, true)) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be one of ["
                        . implode(', ', array_map(static fn ($v): string => (string) $v, $allowed))
                        . '], got ' . self::describeScalar($value);
                }

                break;

            case 'intEnum':
                $allowed = $rule['values'] ?? [];
                if (!\is_int($value) || !\in_array($value, $allowed, true)) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be one of ["
                        . implode(', ', array_map(static fn ($v): string => (string) $v, $allowed))
                        . '], got ' . self::describeScalar($value);
                }

                break;

            case 'stringList':
                if (!self::isStringList($value)) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be a list of strings";
                }

                break;

            case 'kvList':
                self::validateKvList($value, $type, $prop, $path, $errors);

                break;

            case 'columnList':
                self::validateColumnList($value, $type, $prop, $path, $errors);

                break;

            case 'dataColumnList':
                self::validateDataColumnList($value, $type, $prop, $path, $errors);

                break;

            case 'rowList':
                self::validateRowList($value, $type, $prop, $path, $errors);

                break;

            case 'chartSeriesList':
                self::validateChartSeriesList($value, $type, $prop, $path, $errors);

                break;

            case 'relPath':
                if (!\is_string($value) || $value === '' || $value[0] !== '/' || str_starts_with($value, '//')) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be a relative path starting with '/' "
                        . '(absolute and protocol-relative URLs are rejected), got ' . self::describeScalar($value);
                }

                break;

            case 'apiPath':
                if (
                    !\is_string($value)
                    || !str_starts_with($value, '/api/')
                    || str_contains($value, '//')
                    || str_contains($value, '..')
                    || str_contains($value, '\\')
                    || preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1
                ) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be a relative API path starting with '/api/' "
                        . '(no scheme, host, "..", backslash, or whitespace), got ' . self::describeScalar($value);
                }

                break;

            case 'inputName':
                // SP3 (WC-233): a non-empty string identifier for an input field.
                if (!\is_string($value) || $value === '') {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be a non-empty string, got "
                        . self::describeScalar($value);
                }

                break;

            case 'selectOptions':
                // SP3 (WC-233): a list of {value: string, label: string} objects.
                self::validateSelectOptions($value, $type, $prop, $path, $errors);

                break;

            case 'submitSpec':
                // SP3 (WC-233): an array with method ∈ ['POST','PUT'] and a valid apiPath endpoint.
                self::validateSubmitSpec($value, $type, $prop, $path, $errors);

                break;

            case 'visibilityRule':
                // WC-532 A3: a presentational `{field, equals|in}` predicate.
                self::validateVisibilityRule($value, $type, $prop, $path, $errors);

                break;

            case 'rowActionList':
                // WC-532 A1: per-row dataTable actions.
                self::validateRowActionList($value, $type, $prop, $path, $errors);

                break;

            case 'sourceParamList':
                // WC-532 A7: master-detail query-param bindings.
                self::validateSourceParamList($value, $type, $prop, $path, $errors);

                break;

            case 'blockId':
                // WC-relations-ui: a modal/drawer identifier a row action can
                // target and `{id}.{field}` addressing can key on. A non-empty
                // string with NO dot (the `{id}.{field}` separator) and no
                // whitespace, so the addressing stays unambiguous.
                if (
                    !\is_string($value) || $value === ''
                    || str_contains($value, '.')
                    || preg_match('/\s/', $value) === 1
                ) {
                    $errors[] = "{$path}: '{$type}.{$prop}' must be a non-empty string with no '.' or whitespace, got "
                        . self::describeScalar($value);
                }

                break;

            case 'itemActionList':
                // #868: `inbox.actions` — the candidate actions per item.
                self::validateItemActionList($value, $type, $prop, $path, $errors);

                break;

            case 'ouScopeList':
                // #868: `ouScopePicker.scopes` — which of the three scope kinds
                // the author permits, in offer order.
                self::validateOuScopeList($value, $type, $prop, $path, $errors);

                break;

            case 'ouTypeKey':
                // #868: an organizational-unit TYPE key (#822) — bare for core
                // and tenant vocabulary, `plugin:slug` for a plugin's. The
                // grammar is restated here rather than imported because the SDK
                // may not reference a core symbol (SdkPackageContractTest pins
                // that); it is a KEY GRAMMAR, not a value, and it is the same
                // grammar `GET /api/ous?type=` validates against, so a key this
                // accepts is one that endpoint accepts.
                self::validateOuTypeKey($value, $type, $prop, $path, $errors);

                break;

            case 'contextPath':
                // WC-relations-ui: a master-detail context reference. Either a
                // bare selector name (resolves against the selection scalars) or
                // a dotted `{targetId}.{field}` (resolves against a row published
                // by an `open` row action). Shape only — like `from`, an
                // unresolvable reference is a runtime no-op, never validated
                // against the tree.
                self::validateContextPath($value, $type, $prop, $path, $errors);

                // #883/#895: a FACT binding may not name a caller decision. The
                // shape check above ran first, so a malformed reference is
                // reported as malformed rather than as a permission mistake.
                if (\in_array($prop, self::FACT_BINDING_PROPS, true) && \is_string($value)) {
                    self::rejectCallerDecisionField(
                        self::fieldOfContextPath($value),
                        "'{$type}.{$prop}'",
                        $path,
                        $errors,
                    );
                }

                break;

            case 'recordPath':
                // #883: `dataRecord.source` — an owned apiPath that may carry
                // `{token}` segments in the master-detail addressing.
                self::validateRecordPath($value, $type, $prop, $path, $errors);

                break;

            case 'preloadSpec':
                // `form.dataSource` — the GET a form issues on mount to
                // pre-populate its fields.
                self::validatePreloadSpec($value, $type, $prop, $path, $errors);

                break;

            case 'recordFactList':
                // #883: `dataRecord.fields` — the record's FACTS, named and
                // labelled, and the only fields published into context.
                self::validateRecordFactList($value, $type, $prop, $path, $errors);

                break;

            case 'accessCheck':
                // #909: `accessGate.check` — the one concrete request whose
                // permission the host resolves for this caller.
                self::validateAccessCheck($value, $type, $prop, $path, $errors);

                break;
        }
    }

    /**
     * `accessGate.check` (#909): the CONCRETE REQUEST a gate asks the host about
     * — `{method, endpoint}`, where `endpoint` is an owned API path that may
     * carry `{token}` segments in the same master-detail addressing a
     * `dataRecord.source` uses.
     *
     * A request rather than a permission slug, and that is the design decision
     * rather than a shorthand. The host answers it by looking the method+path up
     * in the LIVE ROUTE TABLE and evaluating that route's own gate with the same
     * RoleChecker calls RbacMiddleware makes. So the plugin never states which
     * permission governs the region, and there is no second copy of that answer
     * to fall out of step with the route — the property #868 established for
     * `inbox`, which is the closest existing thing to this question.
     *
     * A slug WOULD have been the shorter spelling, and it is the one deliberately
     * not offered: `'permission' => 'acme:write'` beside a route gated on
     * `acme:manage` is a page that hides a control the caller could have used, or
     * shows one they cannot, and nothing detects the disagreement because nothing
     * compares them.
     *
     * Token substitution happens in the renderer, exactly as it does for
     * `dataRecord.source`, and an unresolved token means the gate is NOT asked —
     * a half-substituted path names a different resource, and being told whether
     * you may write some other record is worse than not being told.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateAccessCheck(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a {method, endpoint} object, got "
                . get_debug_type($value);

            return;
        }

        $method = $value['method'] ?? null;
        if (!\is_string($method) || !\in_array($method, self::ACCESS_CHECK_METHODS, true)) {
            $errors[] = "{$path}.method: '{$type}.{$prop}.method' must be one of ["
                . implode(', ', self::ACCESS_CHECK_METHODS) . '], got ' . self::describeScalar($method);
        }

        if (!\array_key_exists('endpoint', $value)) {
            $errors[] = "{$path}.endpoint: '{$type}.{$prop}' is missing 'endpoint'";

            return;
        }

        // Same predicate as `dataRecord.source`: an owned API path that may
        // carry balanced `{token}` segments in the master-detail addressing.
        self::validateRecordPath($value['endpoint'], $type, "{$prop}.endpoint", "{$path}.endpoint", $errors);
    }

    /**
     * `dataRecord.source` (#883): an owned API path that may carry `{token}`
     * segments, each one a master-detail reference in the SAME addressing as
     * `params.from` / `defaultFrom` / `submit.endpoint` — a bare name (a
     * `selector`'s value, or the host-seeded {@see self::PAGE_RECORD_BINDING})
     * or a dotted `{targetId}.{field}`.
     *
     * The path around the tokens is the ordinary `apiPath` predicate, unchanged:
     * this widens what a source may SAY, never where it may point. The loader
     * still ownership-checks it against the routes the declaring plugin actually
     * registered, comparing with route parameters normalized, so a templated
     * source can only ever name a route the plugin already owns.
     *
     * A brace that never closes is refused rather than treated as a literal.
     * `/api/x/{id` is a path that requests a resource named `{id`, which no
     * route serves — so the block would 404 forever with nothing saying why, and
     * the author's intent is not in doubt.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateRecordPath(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (
            !\is_string($value)
            || !str_starts_with($value, '/api/')
            || str_contains($value, '//')
            || str_contains($value, '..')
            || str_contains($value, '\\')
            || preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1
        ) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a relative API path starting with '/api/' "
                . '(no scheme, host, "..", backslash, or whitespace), got ' . self::describeScalar($value);

            return;
        }

        // Balanced, non-nested braces. `preg_match_all` finds the well-formed
        // tokens; comparing the counts catches a stray brace on either side.
        $tokenCount = preg_match_all('/\{([^{}]*)\}/', $value, $matches);
        if ($tokenCount === false
            || $tokenCount !== substr_count($value, '{')
            || $tokenCount !== substr_count($value, '}')
        ) {
            $errors[] = "{$path}: '{$type}.{$prop}' has unbalanced '{'/'}' — every context token must be "
                . "a complete '{reference}', got " . self::describeScalar($value);

            return;
        }

        foreach ($matches[1] as $i => $reference) {
            if (self::isMalformedContextPath($reference)) {
                $errors[] = "{$path}: '{$type}.{$prop}' token #{$i} must be a selector name or a dotted "
                    . "'{targetId}.{field}' reference, got " . self::describeScalar('{' . $reference . '}');
            }
        }
    }

    /**
     * `dataRecord.fields` (#883): the record's FACTS — a non-empty, duplicate-
     * free list of `{field: non-empty string, label: string}`.
     *
     * This list is a WHITELIST with two jobs at once. It names the labels a
     * `recordFields` renders, and it is the complete set of the fetched
     * payload's keys the renderer publishes into the master-detail context —
     * everything else the endpoint returned is dropped before any binding can
     * see it. That is the structural half of the #895 guard
     * ({@see self::CALLER_DECISION_FIELDS} explains the incident): a caller flag
     * riding along in the payload is unreachable from the tree because it was
     * never published, whatever the plugin chose to call it.
     *
     * A `field` carries no dot, for the same reason a `blockId` carries none —
     * `{id}.{field}` addressing has to stay unambiguous about where the split
     * falls. Duplicates are refused rather than collapsed: two entries for one
     * field are two labels for one value, and whichever the renderer picked
     * would be arbitrary.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateRecordFactList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a non-empty list of {field, label} objects";

            return;
        }

        $seen = [];

        foreach ($value as $i => $item) {
            $at = "{$path}[{$i}]";

            if (
                !\is_array($item)
                || !isset($item['field']) || !\is_string($item['field']) || $item['field'] === ''
                || str_contains($item['field'], '.')
                || preg_match('/\s/', $item['field']) === 1
                || !isset($item['label']) || !\is_string($item['label'])
            ) {
                $errors[] = "{$at}: each '{$type}.{$prop}' entry must be a "
                    . '{field: non-empty string with no "." or whitespace, label: string} object';

                continue;
            }

            /** @var string $field */
            $field = $item['field'];

            if (isset($seen[$field])) {
                $errors[] = "{$at}.field: duplicate '{$type}.{$prop}' field '{$field}'";

                continue;
            }
            $seen[$field] = true;

            self::rejectCallerDecisionField($field, "'{$type}.{$prop}'", "{$at}.field", $errors);
        }
    }

    /**
     * The FIELD half of a context reference: everything after the dot in a
     * dotted `{targetId}.{field}`, or the whole thing when it is a bare name.
     *
     * A bare name addresses a `selector`'s current value rather than a record's
     * field, and it is checked too. A selector named `manageable` publishing
     * into a heading is the same sentence about the same subject, reached by a
     * different route.
     */
    private static function fieldOfContextPath(string $reference): string
    {
        $dot = strpos($reference, '.');

        return $dot === false ? $reference : substr($reference, $dot + 1);
    }

    /**
     * Refuse a field name that states what the CALLER may do where the
     * declaration states what the RECORD is (#895).
     *
     * Matching is on a NORMALIZED form — case-folded with `_`/`-` removed, and
     * again with a leading `is`/`has` removed — because the same flag arrives as
     * `canEdit` from one serializer and `can_edit` or `is_editable` from
     * another, and a guard that only knows one spelling is a guard that fails on
     * the payload shape it was not written against. The `is`/`has` form is
     * checked as a SECOND candidate rather than by stripping the prefix
     * unconditionally, so an honest field like `issued` is never mistaken for
     * `sued`.
     *
     * @param list<string> $errors by reference
     */
    private static function rejectCallerDecisionField(
        string $field,
        string $what,
        string $path,
        array &$errors,
    ): void {
        if ($field === '') {
            return;
        }

        $normalized = self::normalizeFieldName($field);
        $withoutPrefix = preg_replace('/^(?:is|has)(?=.)/', '', $normalized) ?? $normalized;

        foreach (self::CALLER_DECISION_FIELDS as $reserved) {
            $target = self::normalizeFieldName($reserved);
            if ($normalized !== $target && $withoutPrefix !== $target) {
                continue;
            }

            $errors[] = "{$path}: {$what} may not bind '{$field}' — it says what the CALLER may do, "
                . 'not what the record IS, and a record page states facts about the record (#895). '
                . 'A caller-permission flag belongs to the controls the page offers '
                . "(a form/button 'requiredPermission'), never to a field, heading, badge, or stat";

            return;
        }
    }

    /**
     * Case-fold a field name and drop the separators serializers disagree about,
     * so `canEdit`, `can_edit` and `Can-Edit` are one name.
     */
    private static function normalizeFieldName(string $field): string
    {
        return str_replace(['_', '-'], '', strtolower($field));
    }

    /**
     * `keyValue.items`: a list of `{label: string, value: string}`.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateKvList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a list of {label, value} objects";

            return;
        }

        foreach ($value as $i => $item) {
            if (
                !\is_array($item)
                || !isset($item['label']) || !\is_string($item['label'])
                || !isset($item['value']) || !\is_string($item['value'])
            ) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be a {label: string, value: string} object";
            }
        }
    }

    /**
     * `table.columns` / `dataTable.columns`: a list of `{key: string, label: string}`.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateColumnList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a list of {key, label} objects";

            return;
        }

        foreach ($value as $i => $item) {
            if (
                !\is_array($item)
                || !isset($item['key']) || !\is_string($item['key'])
                || !isset($item['label']) || !\is_string($item['label'])
            ) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be a {key: string, label: string} object";
            }
        }
    }

    /**
     * `dataTable.columns` (WC-241): a list of `{key, label}` objects, each
     * optionally carrying `sortable`/`filterable` booleans that turn on the
     * web renderer's inline client-side sort/filter for that column. These
     * are semantic on/off flags only — never an expression, class, or style.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateDataColumnList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a list of {key, label} objects";

            return;
        }

        foreach ($value as $i => $item) {
            if (
                !\is_array($item)
                || !isset($item['key']) || !\is_string($item['key'])
                || !isset($item['label']) || !\is_string($item['label'])
                || (isset($item['sortable']) && !\is_bool($item['sortable']))
                || (isset($item['filterable']) && !\is_bool($item['filterable']))
            ) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be a "
                    . '{key: string, label: string, sortable?: bool, filterable?: bool} object';
            }
        }
    }

    /**
     * `table.rows`: a list of objects mapping string => string.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateRowList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a list of row objects";

            return;
        }

        foreach ($value as $i => $row) {
            if (!\is_array($row) || array_is_list($row)) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be a string => string object";

                continue;
            }

            foreach ($row as $key => $cell) {
                if (!\is_string($key) || !\is_string($cell)) {
                    $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' cell must be a string keyed by a string column";

                    break;
                }
            }
        }
    }

    /**
     * `chart.series`: a list of `{key: string, label: string, color: 1|2|3|4|5}`.
     * `color` selects one of the five semantic `--chart-1..5` design tokens —
     * never a raw hex/rgb value, so a plugin cannot smuggle CSS via this prop.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateChartSeriesList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a non-empty list of {key, label, color} objects";

            return;
        }

        foreach ($value as $i => $item) {
            if (
                !\is_array($item)
                || !isset($item['key']) || !\is_string($item['key']) || $item['key'] === ''
                || !isset($item['label']) || !\is_string($item['label'])
                || !isset($item['color']) || !\is_int($item['color']) || !\in_array($item['color'], [1, 2, 3, 4, 5], true)
            ) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be a "
                    . "{key: non-empty string, label: string, color: one of 1..5} object";
            }
        }
    }

    /**
     * `select.options` (SP3, WC-233): a list of `{value: string, label: string}`.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateSelectOptions(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a list of {value, label} objects";

            return;
        }

        foreach ($value as $i => $item) {
            if (
                !\is_array($item)
                || !isset($item['value']) || !\is_string($item['value'])
                || !isset($item['label']) || !\is_string($item['label'])
            ) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be a {value: string, label: string} object";
            }
        }
    }

    /**
     * `form.submit` / `actionButton.action` (SP3, WC-233):
     * an array with `method` ∈ ['POST','PUT','PATCH'] and `endpoint` satisfying
     * the existing apiPath predicate (/api/ prefix, no ///../\whitespace).
     *
     * WC-block-submit-templating: PATCH is accepted (the sync update verb), and
     * the `endpoint` may carry `{targetId.field}` / `{selector}` context tokens —
     * the SAME addressing as `params.from` / `defaultFrom` — which the renderer
     * interpolates from the master-detail context at submit time (e.g. a modal
     * edit form PATCHing `/api/persons/{edit-person.id}` for the opened row). The
     * `{`/`}`/`.` in a single-dot token pass the path predicate; an unresolved
     * token is a runtime no-op, consistent with the contract's no-cross-reference stance.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateSubmitSpec(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be an object with 'method' and 'endpoint', got "
                . get_debug_type($value);

            return;
        }

        $method   = $value['method']   ?? null;
        $endpoint = $value['endpoint'] ?? null;

        if (!\is_string($method) || !\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $errors[] = "{$path}.method: '{$type}.{$prop}.method' must be 'POST', 'PUT', or 'PATCH', got "
                . self::describeScalar($method);
        }

        if (
            !\is_string($endpoint)
            || !str_starts_with($endpoint, '/api/')
            || str_contains($endpoint, '//')
            || str_contains($endpoint, '..')
            || str_contains($endpoint, '\\')
            || preg_match('/[\s\x00-\x1f\x7f]/', $endpoint) === 1
        ) {
            $errors[] = "{$path}.endpoint: '{$type}.{$prop}.endpoint' must be a relative API path starting with '/api/' "
                . '(no scheme, host, "..", backslash, or whitespace), got ' . self::describeScalar($endpoint);
        }
    }

    /**
     * `form.dataSource`: the GET a form issues on mount to pre-populate itself.
     *
     * WHY IT IS DECLARED AT ALL. It was not, and that is the defect this closes.
     * `web/lib/plugin-features.ts` has carried `dataSource?: { method: 'GET';
     * path: string }` on `FormBlock` all along, and the renderer fetches it —
     * but nothing in the contract mentioned it, so nothing validated its shape
     * and, worse, the host's plugin loader never ownership-checked the path.
     * (Named in prose rather than with a `@see`: the SDK is standalone and may
     * not reference core namespaces — `SdkPackageContractTest` enforces it, and
     * caught this docblock doing exactly that.)
     *
     * That gap is a property of how validation works here rather than an
     * oversight in one rule: {@see validateProps} iterates the DECLARED prop
     * rules, never the node's own keys, and the loader's walk returns the node
     * it was handed. So an undeclared prop is neither rejected nor stripped —
     * it travels to the client untouched. `submit.endpoint`, every `source`,
     * `inbox.actions` and every `rowActionList` are checked against the routes
     * the plugin registered; this one was not, and it is the only endpoint a
     * block can name that was not.
     *
     * `method` is GET and only GET. A preload that could POST would be a write
     * performed by rendering a screen.
     *
     * The path is validated as a {@see validateRecordPath} — an `/api/` path
     * that may carry `{token}` segments — because #949 established that
     * `dataSource.path` takes the same master-detail tokens a `dataRecord`
     * source does, and the renderer substitutes them the same way.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validatePreloadSpec(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be an object with 'method' and 'path', got "
                . get_debug_type($value);

            return;
        }

        $method = $value['method'] ?? null;
        if ($method !== 'GET') {
            $errors[] = "{$path}.method: '{$type}.{$prop}.method' must be 'GET', got "
                . self::describeScalar($method);
        }

        self::validateRecordPath($value['path'] ?? null, $type, "{$prop}.path", "{$path}.path", $errors);
    }

    /**
     * `<dataBound>.params` (WC-532 A7): master-detail query-param bindings.
     * A list of `{param: non-empty string, from: non-empty string}` where
     * `param` is the query-param name appended to the block's `source` and
     * `from` is the name of the `selector` whose current value supplies it.
     * The base `source` is unchanged (still ownership-checked) — only these
     * whitelisted params interpolate, URL-encoded, at fetch time on the web.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateSourceParamList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a list of {param, from} objects";

            return;
        }

        foreach ($value as $i => $item) {
            if (
                !\is_array($item)
                || !isset($item['param']) || !\is_string($item['param']) || $item['param'] === ''
                || !isset($item['from']) || self::isMalformedContextPath($item['from'])
            ) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be a "
                    . '{param: non-empty string, from: a selector name or "{targetId}.{field}"} object';
            }
        }
    }

    /**
     * `<input>.defaultFrom` (WC-relations-ui): seed an input's initial value from
     * the master-detail context — the same addressing as `params.from` (a bare
     * selector name, or a dotted `{targetId}.{field}` published by an `open` row
     * action). Shape only; an unresolvable reference is a runtime no-op.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateContextPath(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (self::isMalformedContextPath($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a selector name or a dotted '{targetId}.{field}' reference, got "
                . self::describeScalar($value);
        }
    }

    /**
     * A master-detail context reference is a bare selector name OR a dotted
     * `{targetId}.{field}` (exactly one dot, both sides non-empty). No whitespace
     * either way — the dot is the sole discriminator, and `blockId` forbids dots,
     * so `{targetId}.{field}` can never be mistaken for a bare name.
     */
    private static function isMalformedContextPath(mixed $value): bool
    {
        if (!\is_string($value) || $value === '' || \preg_match('/\s/', $value) === 1) {
            return true;
        }

        if (\substr_count($value, '.') > 1) {
            return true;
        }

        if (\str_contains($value, '.')) {
            [$id, $field] = \explode('.', $value, 2);
            if ($id === '' || $field === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * `dataTable.rowActions` (WC-532 A1): a list of per-row affordances. Each
     * entry is `{label: non-empty string}` PLUS exactly one of:
     *   - `href`: an internal relative path (may carry `{field}` placeholders
     *     the renderer substitutes from the row) — an internal-nav link, OR
     *   - `endpoint` (apiPath, `{field}`-templatable) + `method` ∈
     *     POST|PUT|DELETE — a mutation, with an optional `confirm` prompt, OR
     *   - `open` (WC-relations-ui): a modal/drawer block id to open, publishing
     *     this row into the master-detail context for the overlay's content to
     *     read (form `defaultFrom`, data-bound `params.from`).
     *
     * Placeholders are validated loosely (the path predicates already permit
     * `{`/`}`); the renderer URL-encodes each substituted row value. A `{field}`
     * endpoint that resolves to another plugin's route is a runtime concern the
     * host's route dispatch still gates — this contract only fixes the shape.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateRowActionList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a non-empty list of row-action objects";

            return;
        }

        foreach ($value as $i => $item) {
            $at = "{$path}[{$i}]";
            if (!\is_array($item)) {
                $errors[] = "{$at}: each '{$type}.{$prop}' entry must be an object";

                continue;
            }

            $label = $item['label'] ?? null;
            if (!\is_string($label) || $label === '') {
                $errors[] = "{$at}.label: each '{$type}.{$prop}' entry must carry a non-empty 'label'";
            }

            $hasHref     = \array_key_exists('href', $item);
            $hasEndpoint = \array_key_exists('endpoint', $item);
            $hasOpen     = \array_key_exists('open', $item);

            if ((int) $hasHref + (int) $hasEndpoint + (int) $hasOpen !== 1) {
                $errors[] = "{$at}: each '{$type}.{$prop}' entry must carry exactly one of 'href', 'endpoint', or 'open'";

                continue;
            }

            if ($hasHref) {
                $href = $item['href'];
                if (!\is_string($href) || $href === '' || $href[0] !== '/' || str_starts_with($href, '//')) {
                    $errors[] = "{$at}.href: '{$type}.{$prop}' href must be an internal path starting with '/' "
                        . '(absolute and protocol-relative URLs are rejected), got ' . self::describeScalar($href);
                }

                continue;
            }

            if ($hasOpen) {
                // WC-relations-ui: open a modal/drawer, publishing this row into
                // the master-detail context under the target's id. `open` is that
                // target's blockId (no dot/whitespace, matching the `blockId` rule).
                $open = $item['open'];
                if (
                    !\is_string($open) || $open === ''
                    || str_contains($open, '.')
                    || preg_match('/\s/', $open) === 1
                ) {
                    $errors[] = "{$at}.open: '{$type}.{$prop}' open must be a modal/drawer block id "
                        . '(non-empty, no "." or whitespace), got ' . self::describeScalar($open);
                }

                continue;
            }

            // endpoint + method (+ optional confirm)
            $endpoint = $item['endpoint'];
            if (
                !\is_string($endpoint)
                || !str_starts_with($endpoint, '/api/')
                || str_contains($endpoint, '//')
                || str_contains($endpoint, '..')
                || str_contains($endpoint, '\\')
                || preg_match('/[\s\x00-\x1f\x7f]/', $endpoint) === 1
            ) {
                $errors[] = "{$at}.endpoint: '{$type}.{$prop}' endpoint must be a relative API path starting with '/api/' "
                    . '(no scheme, host, "..", backslash, or whitespace), got ' . self::describeScalar($endpoint);
            }

            $method = $item['method'] ?? null;
            if (!\is_string($method) || !\in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                $errors[] = "{$at}.method: '{$type}.{$prop}' endpoint action must carry method POST, PUT, or DELETE, got "
                    . self::describeScalar($method);
            }

            if (\array_key_exists('confirm', $item) && !\is_string($item['confirm'])) {
                $errors[] = "{$at}.confirm: '{$type}.{$prop}' confirm must be a string";
            }
        }
    }

    /**
     * `inbox` (#868): a `scopedPermission` anywhere in `actions` requires the
     * block to declare `resourceType`. Cross-prop, so it cannot live in the
     * per-prop rules — `validateItemActionList` sees the list, not its siblings.
     *
     * @param array<mixed>  $node
     * @param list<string>  $errors by reference
     */
    private static function validateInboxScoping(array $node, string $path, array &$errors): void
    {
        $resourceType = $node['resourceType'] ?? null;
        if (\is_string($resourceType) && $resourceType !== '') {
            return;
        }

        $actions = $node['actions'] ?? null;
        if (!\is_array($actions)) {
            return; // shape errors already reported by validateItemActionList
        }

        foreach ($actions as $i => $action) {
            if (\is_array($action) && \array_key_exists('scopedPermission', $action)) {
                $errors[] = "{$path}.actions[{$i}]: 'inbox.actions' scopedPermission requires the block to declare "
                    . "a 'resourceType' — a per-record permission check needs a record kind to scope to";
            }
        }
    }

    /**
     * `inbox.actions` (#868): the candidate actions an item may carry. Each entry is
     *
     *     {
     *       key:               non-empty string, unique within the list,
     *       label:             non-empty string,
     *       method:            'POST' | 'PUT' | 'PATCH' | 'DELETE',
     *       endpoint:          an apiPath, `{field}`-templatable from the item,
     *       scopedPermission?: a `resource:action` slug, resolved AT the item,
     *       confirm?:          string,
     *       prompt?:           {field, label, required?, placeholder?},
     *       variant?:          primary|secondary|outline|ghost|destructive,
     *     }
     *
     * `prompt` (WC-532 item 5) COLLECTS A REASON AND SENDS IT. `confirm` asks
     * a yes/no question and posts an empty body, which covers "approve" and
     * cannot express "return this, and say why" — the shape a review queue is
     * mostly made of. Without it a plugin has to leave the inbox and hand-build
     * the screen, which is what item 5 was raised about.
     *
     * The value is sent as `{[field]: text}` in the request body. `required`
     * means the action cannot be dispatched with it blank — enforced in the
     * dialog, and by the plugin's own handler, which is the authority. A
     * required comment that the client alone enforced would be a convention,
     * not a rule.
     *
     * There is deliberately no `permission` prop for the endpoint's own gate.
     * The host reads that off the route the endpoint dispatches to; a plugin
     * restating it would create a second answer to a question the route table
     * already answers, and the renderer would then show what the plugin
     * believes rather than what the middleware enforces.
     *
     * `scopedPermission` is a different question and is therefore askable: the
     * per-record predicate a plugin's handler applies INSIDE the request, which
     * no route table can express. The host resolves it at the item's
     * (`resourceType`, id) through resource-scoped grants and treats it as an
     * ADDITIONAL conjunct — it can only remove an action from the set the route
     * gate already permitted, never add one, so a wrong declaration costs the
     * user an affordance and can never widen access.
     *
     * `key` is the renderer's per-item identity for the resolved answer, so it
     * must be unique: two actions sharing a key are one action to the resolver
     * and whichever resolved last would decide both.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateItemActionList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a non-empty list of item-action objects";

            return;
        }

        $seenKeys = [];

        foreach ($value as $i => $item) {
            $at = "{$path}[{$i}]";
            if (!\is_array($item)) {
                $errors[] = "{$at}: each '{$type}.{$prop}' entry must be an object";

                continue;
            }

            $key = $item['key'] ?? null;
            if (!\is_string($key) || $key === '' || \preg_match('/\s/', $key) === 1) {
                $errors[] = "{$at}.key: each '{$type}.{$prop}' entry must carry a non-empty 'key' with no whitespace, got "
                    . self::describeScalar($key);
            } elseif (isset($seenKeys[$key])) {
                $errors[] = "{$at}.key: duplicate '{$type}.{$prop}' key '{$key}'";
            } else {
                $seenKeys[$key] = true;
            }

            $label = $item['label'] ?? null;
            if (!\is_string($label) || $label === '') {
                $errors[] = "{$at}.label: each '{$type}.{$prop}' entry must carry a non-empty 'label'";
            }

            $method = $item['method'] ?? null;
            if (!\is_string($method) || !\in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $errors[] = "{$at}.method: '{$type}.{$prop}' method must be 'POST', 'PUT', 'PATCH', or 'DELETE', got "
                    . self::describeScalar($method);
            }

            $endpoint = $item['endpoint'] ?? null;
            if (
                !\is_string($endpoint)
                || !str_starts_with($endpoint, '/api/')
                || str_contains($endpoint, '//')
                || str_contains($endpoint, '..')
                || str_contains($endpoint, '\\')
                || preg_match('/[\s\x00-\x1f\x7f]/', $endpoint) === 1
            ) {
                $errors[] = "{$at}.endpoint: '{$type}.{$prop}' endpoint must be a relative API path starting with '/api/' "
                    . '(no scheme, host, "..", backslash, or whitespace), got ' . self::describeScalar($endpoint);
            }

            if (\array_key_exists('scopedPermission', $item)) {
                $scoped = $item['scopedPermission'];
                if (!\is_string($scoped) || $scoped === '' || \preg_match('/\s/', $scoped) === 1) {
                    $errors[] = "{$at}.scopedPermission: '{$type}.{$prop}' scopedPermission must be a non-empty "
                        . 'permission slug with no whitespace, got ' . self::describeScalar($scoped);
                }
            }

            if (\array_key_exists('confirm', $item) && !\is_string($item['confirm'])) {
                $errors[] = "{$at}.confirm: '{$type}.{$prop}' confirm must be a string";
            }

            // WC-532 item 5: the reason-collecting prompt. `field` is the body
            // key the text is sent under, so it is validated as an input name —
            // the same rule a form field's `name` follows, for the same reason:
            // it becomes a key in a JSON payload a handler reads by name.
            if (\array_key_exists('prompt', $item)) {
                $prompt = $item['prompt'];
                if (!\is_array($prompt)) {
                    $errors[] = "{$at}.prompt: '{$type}.{$prop}' prompt must be an object";
                } else {
                    foreach (['field', 'label'] as $req) {
                        $v = $prompt[$req] ?? null;
                        if (!\is_string($v) || trim($v) === '') {
                            $errors[] = "{$at}.prompt.{$req}: '{$type}.{$prop}' prompt {$req} must be a non-empty string";
                        }
                    }
                    if (\is_string($prompt['field'] ?? null)
                        && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prompt['field']) !== 1
                    ) {
                        $errors[] = "{$at}.prompt.field: '{$type}.{$prop}' prompt field must be a valid input name";
                    }
                    if (\array_key_exists('required', $prompt) && !\is_bool($prompt['required'])) {
                        $errors[] = "{$at}.prompt.required: '{$type}.{$prop}' prompt required must be a boolean";
                    }
                    if (\array_key_exists('placeholder', $prompt) && !\is_string($prompt['placeholder'])) {
                        $errors[] = "{$at}.prompt.placeholder: '{$type}.{$prop}' prompt placeholder must be a string";
                    }
                }
            }

            if (
                \array_key_exists('variant', $item)
                && (!\is_string($item['variant'])
                    || !\in_array($item['variant'], ['primary', 'secondary', 'outline', 'ghost', 'destructive'], true))
            ) {
                $errors[] = "{$at}.variant: '{$type}.{$prop}' variant must be one of "
                    . '[primary, secondary, outline, ghost, destructive], got ' . self::describeScalar($item['variant']);
            }
        }
    }

    /**
     * `ouScopePicker.scopes` (#868): a non-empty, duplicate-free list drawn from
     * {@see self::OU_SCOPES}, in the order the author wants them offered.
     *
     * Order is meaningful — the first entry is the control's opening state — so
     * this is a list rather than a set, and a duplicate is an error rather than
     * a no-op: two identical options in a dropdown is a mistake, and silently
     * collapsing them would hide it.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateOuScopeList(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a non-empty list of ["
                . implode(', ', self::OU_SCOPES) . ']';

            return;
        }

        $seen = [];
        foreach ($value as $i => $scope) {
            if (!\is_string($scope) || !\in_array($scope, self::OU_SCOPES, true)) {
                $errors[] = "{$path}[{$i}]: each '{$type}.{$prop}' entry must be one of ["
                    . implode(', ', self::OU_SCOPES) . '], got ' . self::describeScalar($scope);

                continue;
            }
            if (isset($seen[$scope])) {
                $errors[] = "{$path}[{$i}]: duplicate '{$type}.{$prop}' entry '{$scope}'";

                continue;
            }
            $seen[$scope] = true;
        }
    }

    /**
     * An organizational-unit TYPE key (#822): lowercase, letter-initial,
     * `[a-z0-9_]` thereafter, optionally namespaced once as `plugin:slug`, and
     * no longer than the column holds.
     *
     * Shape only. Whether a tenant has actually defined the key is a question
     * about tenant DATA, and this validator runs at plugin load against a tree
     * that will be served to every tenant on the installation — a key one tenant
     * has adopted and another has not is correct for the first and merely
     * matches nothing for the second, which is the same answer
     * `GET /api/ous?type=` gives.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateOuTypeKey(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (
            !\is_string($value)
            || \strlen($value) > self::OU_TYPE_KEY_MAX_LENGTH
            || \preg_match('/^[a-z][a-z0-9_]*(?::[a-z][a-z0-9_]*)?$/', $value) !== 1
        ) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a lowercase organizational-unit type key, "
                . "optionally namespaced once as 'plugin:slug', got " . self::describeScalar($value);
        }
    }

    /**
     * `ouScopePicker` (#868): a `memberType` is a filter over the units a scope
     * RESOLVES to, so it needs a scope that can resolve to more than the anchor
     * itself. A block whose only permitted scope is `unit` has no such scope,
     * and the declaration is refused rather than ignored.
     *
     * Cross-prop, so it cannot live in the per-prop rules — `validateOuScopeList`
     * sees the list, not its siblings.
     *
     * @param array<mixed> $node
     * @param list<string> $errors by reference
     */
    private static function validateOuScopePicker(array $node, string $path, array &$errors): void
    {
        if (!\array_key_exists('memberType', $node)) {
            return;
        }

        $scopes = $node['scopes'] ?? null;
        if (!\is_array($scopes) || $scopes !== ['unit']) {
            return; // absent (all three permitted), malformed (already reported), or wider than ['unit']
        }

        $errors[] = "{$path}: 'ouScopePicker.memberType' cannot apply when 'scopes' is exactly ['unit'] "
            . '— a kind filter over a scope that resolves to the single unit the user picked can only '
            . 'ever remove it';
    }

    /**
     * `flow` (#950): the two rules that read more than one prop at a time.
     *
     * 1. `maxNodes` may only LOWER {@see BlockContract::FLOW_MAX_NODES}. The
     *    ceiling is a readability limit the contract owns (see that constant for
     *    why it exists and why the direction is one-way), so a declaration above
     *    it is refused here rather than clamped at render time. Clamping would
     *    leave the author believing a number the renderer is quietly ignoring —
     *    and believing it on every platform at once, since each renderer would
     *    have to remember to clamp identically.
     *
     * 2. `edgeFromField` and `edgeToField` must name DIFFERENT fields. One field
     *    read as both the predecessor and the successor draws, for every row, an
     *    edge in each direction between the same two nodes: a mutual pair, never
     *    a sequence. There is no graph that spelling describes, so it is an
     *    author error with a plausible-looking result, which is the kind worth
     *    refusing rather than rendering.
     *
     * Both are cross-prop, so neither can live in the per-prop rules.
     *
     * @param array<mixed> $node
     * @param list<string> $errors by reference
     */
    private static function validateFlow(array $node, string $path, array &$errors): void
    {
        $maxNodes = $node['maxNodes'] ?? null;
        if (\is_int($maxNodes) && ($maxNodes < 1 || $maxNodes > BlockContract::FLOW_MAX_NODES)) {
            $errors[] = "{$path}.maxNodes: 'flow.maxNodes' must be between 1 and "
                . BlockContract::FLOW_MAX_NODES . ' — a block may lower the readability ceiling for its '
                . 'own graph but not raise it, and a dataset larger than the ceiling wants a dataTable '
                . 'beside the diagram rather than a bigger diagram, got ' . self::describeScalar($maxNodes);
        }

        $from = $node['edgeFromField'] ?? null;
        $to = $node['edgeToField'] ?? null;
        if (\is_string($from) && $from !== '' && $from === $to) {
            $errors[] = "{$path}: 'flow.edgeFromField' and 'flow.edgeToField' name the same field "
                . "('{$from}') — read as both predecessor and successor it draws an edge in each "
                . 'direction between the same two nodes for every row, which is a mutual pair rather '
                . 'than a sequence';
        }
    }

    /**
     * The three things a `visibleWhen` may ask about, exactly one per rule.
     *
     * @var list<string>
     */
    private const VISIBILITY_SUBJECTS = ['field', 'from', 'access'];

    /**
     * `visibleWhen` (WC-532 A3, widened by #909): a presentational
     * conditional-visibility rule, carried by EVERY block type
     * ({@see BlockContract::UNIVERSAL_PROPS}).
     *
     * The rule names exactly one SUBJECT — what it asks about — and then how to
     * test it:
     *
     *   - `field`  a sibling input in the same `form`. WC-532 A3's original and
     *              only subject, unchanged.
     *   - `from`   a master-detail context reference in the ordinary addressing
     *              (`{recordId}.{field}`, or a bare `selector` name): the RECORD
     *              the page is about, or a row an overlay was opened with.
     *   - `access` the `id` of an `accessGate` — the host's answer to "may this
     *              caller make that request?".
     *
     * plus exactly one of `equals` / `in`, except for `access`, which takes a
     * BOOLEAN `equals` and nothing else: a yes/no answer has no set to be a
     * member of, and `equals: false` is how the read-only half of a pair says so
     * out loud rather than by omission.
     *
     * WHY `from` IS NOT FACT-GUARDED, while `textFrom` and its twins are. #908
     * drew this line and it is the same line: a FACT binding is the page STATING
     * something about the record, and stating "you may edit this" as a property
     * of the record is what produced #895. A CONTROL binding decides what exists
     * on screen. `defaultFrom` and `params.from` are unguarded for this reason
     * and so is this. The structural guard applies regardless — a `dataRecord`
     * publishes only the fields it named, so `from` cannot reach a caller flag
     * riding along in the payload whatever the author calls it.
     *
     * AND WHY READING `access` HERE DOES NOT RE-OPEN #895. The subject is a gate
     * id, not a record field, and a gate's answer lives in a namespace of its own
     * that no `...From` prop resolves against. `visibleWhen.access` is the only
     * prop in the whole contract whose value names a gate, and all it can do is
     * decide whether a subtree renders. There is no declaration anywhere that
     * turns the answer into text on the page.
     *
     * Render-time only, either way. The rule carries no endpoint of its own, so
     * unlike `apiPath` props there is nothing here to ownership-check, and the
     * server never trusts client-side visibility.
     *
     * @param mixed        $value
     * @param list<string> $errors by reference
     */
    private static function validateVisibilityRule(
        mixed $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        if (!\is_array($value) || array_is_list($value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' must be a {field|from|access, equals|in} object, got "
                . get_debug_type($value);

            return;
        }

        $subjects = array_values(array_filter(
            self::VISIBILITY_SUBJECTS,
            static fn (string $key): bool => \array_key_exists($key, $value),
        ));

        if (\count($subjects) !== 1) {
            $errors[] = "{$path}: '{$type}.{$prop}' must name exactly one of 'field' (a sibling form input), "
                . "'from' (a record in the master-detail context) or 'access' (an 'accessGate' id), got "
                . ($subjects === [] ? 'none' : implode(' + ', $subjects));

            return;
        }

        if ($subjects[0] === 'access') {
            self::validateAccessCondition($value, $type, $prop, $path, $errors);

            return;
        }

        if ($subjects[0] === 'field') {
            if (!\is_string($value['field']) || $value['field'] === '') {
                $errors[] = "{$path}.field: '{$type}.{$prop}.field' must be a non-empty string, got "
                    . self::describeScalar($value['field']);
            }
        } elseif (self::isMalformedContextPath($value['from'])) {
            $errors[] = "{$path}.from: '{$type}.{$prop}.from' must be a selector name or a dotted "
                . "'{targetId}.{field}' reference, got " . self::describeScalar($value['from']);
        }

        $hasEquals = \array_key_exists('equals', $value);
        $hasIn     = \array_key_exists('in', $value);

        if ($hasEquals === $hasIn) {
            // both or neither
            $errors[] = "{$path}: '{$type}.{$prop}' must carry exactly one of 'equals' or 'in'";

            return;
        }

        if ($hasEquals && !\is_scalar($value['equals'])) {
            $errors[] = "{$path}.equals: '{$type}.{$prop}.equals' must be a string, number, or boolean, got "
                . get_debug_type($value['equals']);
        }

        if ($hasIn) {
            $in = $value['in'];
            if (!\is_array($in) || !array_is_list($in) || $in === []) {
                $errors[] = "{$path}.in: '{$type}.{$prop}.in' must be a non-empty list of scalars";
            } else {
                foreach ($in as $i => $item) {
                    if (!\is_scalar($item)) {
                        $errors[] = "{$path}.in[{$i}]: each '{$type}.{$prop}.in' entry must be a string, number, or boolean, got "
                            . get_debug_type($item);
                    }
                }
            }
        }
    }

    /**
     * The `access` form of a visibility rule (#909):
     * `{access: <gate id>, equals: bool}`.
     *
     * `equals` is REQUIRED and must be a boolean, and `in` is refused. A gate
     * answers yes or no, so a membership test over it means nothing, and an
     * omitted `equals` would have to default to one polarity — which is the one
     * place in this rule where guessing wrong renders a control to somebody who
     * may not use it. Written out, the read-only half of a pair says
     * `equals: false` and reads as the negation it is.
     *
     * @param array<mixed> $value
     * @param list<string> $errors by reference
     */
    private static function validateAccessCondition(
        array $value,
        string $type,
        string $prop,
        string $path,
        array &$errors,
    ): void {
        $gate = $value['access'];
        if (
            !\is_string($gate) || $gate === ''
            || str_contains($gate, '.')
            || preg_match('/\s/', $gate) === 1
        ) {
            $errors[] = "{$path}.access: '{$type}.{$prop}.access' must be an 'accessGate' id — a non-empty "
                . "string with no '.' or whitespace, got " . self::describeScalar($gate);
        }

        if (\array_key_exists('in', $value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' may not carry 'in' beside 'access' — a gate answers "
                . "yes or no, so state 'equals: true' or 'equals: false'";
        }

        if (!\array_key_exists('equals', $value)) {
            $errors[] = "{$path}: '{$type}.{$prop}' with 'access' must state 'equals: true' or "
                . "'equals: false' — the polarity is never inferred, because inferring it the wrong way "
                . 'renders a control to a caller who may not use it';

            return;
        }

        if (!\is_bool($value['equals'])) {
            $errors[] = "{$path}.equals: '{$type}.{$prop}.equals' must be a boolean when the subject is "
                . "'access', got " . get_debug_type($value['equals']);
        }
    }

    /**
     * Whether the value is a list of strings.
     *
     * @param mixed $value
     *
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render a scalar (or non-scalar) value for an error message.
     *
     * @param mixed $value
     */
    private static function describeScalar(mixed $value): string
    {
        if (\is_string($value)) {
            return "'{$value}'";
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return get_debug_type($value);
    }
}
