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
 *     type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec',
 *     required: bool,
 *     values?: list<string|int>,             // allowed set for enum / intEnum
 *   }>,
 * }
 * ```
 *
 * @phpstan-type PropRule array{
 *   type: 'string'|'int'|'bool'|'enum'|'intEnum'|'kvList'|'stringList'|'columnList'|'rowList'|'chartSeriesList'|'relPath'|'apiPath'|'inputName'|'selectOptions'|'submitSpec',
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

            // ---- data-bound leaves (SP2, WC-229) ----
            'dataTable' => ['container' => false, 'props' => [
                'source'    => ['type' => 'apiPath',    'required' => true],
                'columns'   => ['type' => 'columnList', 'required' => true],
                'emptyText' => ['type' => 'string',     'required' => false],
            ]],
            'dataStat' => ['container' => false, 'props' => [
                'source'     => ['type' => 'apiPath', 'required' => true],
                'label'      => ['type' => 'string',  'required' => true],
                'valueField' => ['type' => 'string',  'required' => true],
                'hintField'  => ['type' => 'string',  'required' => false],
                'trendField' => ['type' => 'string',  'required' => false],
                'emptyText'  => ['type' => 'string',  'required' => false],
            ]],
            'dataList' => ['container' => false, 'props' => [
                'source'    => ['type' => 'apiPath',    'required' => true],
                'itemField' => ['type' => 'string',     'required' => true],
                'ordered'   => ['type' => 'bool',       'required' => false],
                'emptyText' => ['type' => 'string',     'required' => false],
            ]],
            // ---- SP4 chart block (WC-240) ----
            'chart' => ['container' => false, 'props' => [
                'source'    => ['type' => 'apiPath',         'required' => true],
                'chartType' => ['type' => 'enum',            'required' => true,
                    'values' => ['bar', 'line', 'area', 'pie']],
                'series'    => ['type' => 'chartSeriesList', 'required' => true],
                'xField'    => ['type' => 'string',          'required' => false],
                'emptyText' => ['type' => 'string',          'required' => false],
            ]],

            // ---- interactive blocks (SP3, WC-233) ----
            'form' => ['container' => true, 'props' => [
                'submit'             => ['type' => 'submitSpec', 'required' => true],
                'requiredPermission' => ['type' => 'string',     'required' => false],
            ]],
            'textInput' => ['container' => false, 'props' => [
                'name'        => ['type' => 'inputName', 'required' => true],
                'label'       => ['type' => 'string',    'required' => true],
                'placeholder' => ['type' => 'string',    'required' => false],
                'required'    => ['type' => 'bool',      'required' => false],
                'default'     => ['type' => 'string',    'required' => false],
            ]],
            'textArea' => ['container' => false, 'props' => [
                'name'     => ['type' => 'inputName', 'required' => true],
                'label'    => ['type' => 'string',    'required' => true],
                'rows'     => ['type' => 'int',       'required' => false],
                'required' => ['type' => 'bool',      'required' => false],
                'default'  => ['type' => 'string',    'required' => false],
            ]],
            'numberInput' => ['container' => false, 'props' => [
                'name'     => ['type' => 'inputName', 'required' => true],
                'label'    => ['type' => 'string',    'required' => true],
                'min'      => ['type' => 'int',       'required' => false],
                'max'      => ['type' => 'int',       'required' => false],
                'step'     => ['type' => 'int',       'required' => false],
                'required' => ['type' => 'bool',      'required' => false],
                'default'  => ['type' => 'string',    'required' => false],
            ]],
            'select' => ['container' => false, 'props' => [
                'name'     => ['type' => 'inputName',    'required' => true],
                'label'    => ['type' => 'string',       'required' => true],
                'options'  => ['type' => 'selectOptions', 'required' => true],
                'required' => ['type' => 'bool',         'required' => false],
                'default'  => ['type' => 'string',       'required' => false],
            ]],
            'checkbox' => ['container' => false, 'props' => [
                'name'    => ['type' => 'inputName', 'required' => true],
                'label'   => ['type' => 'string',    'required' => true],
                'default' => ['type' => 'bool',      'required' => false],
            ]],
            'slider' => ['container' => false, 'props' => [
                'name'    => ['type' => 'inputName', 'required' => true],
                'label'   => ['type' => 'string',    'required' => true],
                'min'     => ['type' => 'int',       'required' => true],
                'max'     => ['type' => 'int',       'required' => true],
                'step'    => ['type' => 'int',       'required' => false],
                'default' => ['type' => 'string',    'required' => false],
            ]],
            'dateInput' => ['container' => false, 'props' => [
                'name'     => ['type' => 'inputName', 'required' => true],
                'label'    => ['type' => 'string',    'required' => true],
                'required' => ['type' => 'bool',      'required' => false],
                'default'  => ['type' => 'string',    'required' => false],
            ]],
            'fileInput' => ['container' => false, 'props' => [
                'name'     => ['type' => 'inputName', 'required' => true],
                'label'    => ['type' => 'string',    'required' => true],
                'accept'   => ['type' => 'string',    'required' => false],
                'required' => ['type' => 'bool',      'required' => false],
            ]],
            'colorInput' => ['container' => false, 'props' => [
                'name'    => ['type' => 'inputName', 'required' => true],
                'label'   => ['type' => 'string',    'required' => true],
                'default' => ['type' => 'string',    'required' => false],
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
