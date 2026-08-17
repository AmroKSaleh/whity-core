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
     * The input leaf types that are only valid inside a `form` ancestor.
     */
    private const INPUT_LEAF_TYPES = [
        'textInput', 'textArea', 'numberInput', 'select',
        'checkbox', 'slider', 'dateInput', 'fileInput', 'colorInput',
        'bilingualText', 'referenceSelect', 'richTextInput',
    ];

    /**
     * All interactive block types (input leaves + submitButton) that require a
     * `form` ancestor.
     */
    private const FORM_ONLY_TYPES = [
        'textInput', 'textArea', 'numberInput', 'select',
        'checkbox', 'slider', 'dateInput', 'fileInput', 'colorInput',
        'bilingualText', 'referenceSelect', 'richTextInput', 'submitButton',
        'fieldArray',
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

        $isContainer = $rule['container'];
        $hasChildren = \array_key_exists('children', $node);

        if ($hasChildren && !$isContainer) {
            $errors[] = "{$path}: '{$type}' is a leaf block and cannot have 'children'";

            return;
        }

        if ($hasChildren) {
            $children = $node['children'];
            if (!\is_array($children)) {
                $errors[] = "{$path}.children: 'children' must be a list of blocks";

                return;
            }

            if ($depth + 1 > BlockContract::MAX_DEPTH) {
                $errors[] = "{$path}.children: nesting too deep — the tree exceeds the maximum depth of "
                    . BlockContract::MAX_DEPTH;

                return;
            }

            /** @var array<mixed> $children */
            if ($type === 'form' || $type === 'fieldArray') {
                // A `form` starts a fresh name registry for its subtree; a
                // `fieldArray` (WC-532 A2) likewise scopes its template's input
                // names PER ROW, so they neither collide with the outer form nor
                // with a sibling fieldArray. Its children still require a form
                // ancestor, so `$inForm` stays true down this branch.
                $childFormNames = [];
                self::validateList($children, "{$path}.children", $depth + 1, $count, $errors, $type, true, $childFormNames);
            } else {
                self::validateList($children, "{$path}.children", $depth + 1, $count, $errors, $type, $inForm, $formNames);
            }
        }
    }

    /**
     * Validate every declared prop of a node against the type's prop rules.
     *
     * @param array<mixed>  $node
     * @param array<string, array{type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'blockId'|'contextPath', required: bool, values?: list<string|int>}> $propRules
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
     * @param array{type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'dataColumnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec'|'visibilityRule'|'rowActionList'|'sourceParamList'|'blockId'|'contextPath', values?: list<string|int>, required: bool} $rule
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

            case 'contextPath':
                // WC-relations-ui: a master-detail context reference. Either a
                // bare selector name (resolves against the selection scalars) or
                // a dotted `{targetId}.{field}` (resolves against a row published
                // by an `open` row action). Shape only — like `from`, an
                // unresolvable reference is a runtime no-op, never validated
                // against the tree.
                self::validateContextPath($value, $type, $prop, $path, $errors);

                break;
        }
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
     * an array with `method` ∈ ['POST','PUT'] and `endpoint` satisfying
     * the existing apiPath predicate (/api/ prefix, no ///../\whitespace).
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

        if (!\is_string($method) || !\in_array($method, ['POST', 'PUT'], true)) {
            $errors[] = "{$path}.method: '{$type}.{$prop}.method' must be 'POST' or 'PUT', got "
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
     * `visibleWhen` (WC-532 A3): a presentational conditional-visibility rule.
     * An object `{field: non-empty string}` carrying EXACTLY ONE of:
     *   - `equals`: a scalar the referenced field's value must equal, or
     *   - `in`: a non-empty list of scalars the value must be one of.
     *
     * This is render-time only — the web renderer hides the block when the
     * predicate is unmet. It carries NO endpoint or path, so unlike `apiPath`
     * props there is nothing to ownership-check; it can never widen data access
     * or bypass server-side validation (the server never trusts it).
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
            $errors[] = "{$path}: '{$type}.{$prop}' must be a {field, equals|in} object, got "
                . get_debug_type($value);

            return;
        }

        $field = $value['field'] ?? null;
        if (!\is_string($field) || $field === '') {
            $errors[] = "{$path}.field: '{$type}.{$prop}.field' must be a non-empty string, got "
                . self::describeScalar($field);
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
