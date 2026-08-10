<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

/**
 * One registered data type: a plugin-owned table core can drive a lifecycle
 * and a set of referential guards over (WC-723, Door 2 — `registerDataType`).
 *
 * The plugin owns the storage. Core owns three things and nothing else: where
 * the record lives, what its lifecycle states mean, and which rows still point
 * at it. That is enough to enforce the guard, phrase a refusal, and generate
 * trash / restore / retire — without core ever learning what the record IS.
 *
 * Honest degradation
 * ------------------
 * {@see self::offers()} is the single rule the whole generated surface hangs
 * off: an action exists only when BOTH its lifecycle support and its permission
 * are declared. A type that never declared `permissions['retire']` gets no
 * Retire button AND a retire endpoint that refuses — the affordance is omitted
 * rather than rendered as something that silently does the wrong thing, and the
 * endpoint fails closed rather than running ungated. One predicate, consulted
 * by both the renderer and the enforcer, so the two can never disagree.
 */
final class DataTypeDefinition
{
    /**
     * The canonical, namespaced key: `democatalog:item`.
     */
    private string $key;

    /**
     * The source that declared it — the plugin NAME the loader supplied.
     */
    private string $source;

    /**
     * The plugin-owned table holding the records.
     */
    private string $table;

    /**
     * The primary-key column.
     */
    private string $keyColumn;

    /**
     * The tenant column. Never null: a data type must live on a tenant-scoped
     * table, so every generated statement can bind a tenant predicate.
     */
    private string $tenantColumn;

    /**
     * Display labels by locale.
     *
     * @var array<string, string>
     */
    private array $label;

    private Lifecycle $lifecycle;

    /**
     * The declared reference graph.
     *
     * @var list<ReferenceGuard>
     */
    private array $guards;

    /**
     * Permission slug per action.
     *
     * @var array<string, string>
     */
    private array $permissions;

    /**
     * @param string                $key         Canonical namespaced key.
     * @param string                $source      Declaring plugin name.
     * @param string                $table       Plugin-owned table.
     * @param string                $keyColumn   Primary-key column.
     * @param string                $tenantColumn Tenant column.
     * @param array<string, string> $label       Locale => display label.
     * @param Lifecycle             $lifecycle   Declared lifecycle.
     * @param list<ReferenceGuard>  $guards      Declared reference graph.
     * @param array<string, string> $permissions Action => permission slug.
     */
    public function __construct(
        string $key,
        string $source,
        string $table,
        string $keyColumn,
        string $tenantColumn,
        array $label,
        Lifecycle $lifecycle,
        array $guards,
        array $permissions
    ) {
        $this->key = $key;
        $this->source = $source;
        $this->table = $table;
        $this->keyColumn = $keyColumn;
        $this->tenantColumn = $tenantColumn;
        $this->label = $label;
        $this->lifecycle = $lifecycle;
        $this->guards = $guards;
        $this->permissions = $permissions;
    }

    /**
     * The canonical, namespaced type key.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * The plugin that declared this type.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * The table holding the records.
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * The primary-key column.
     */
    public function keyColumn(): string
    {
        return $this->keyColumn;
    }

    /**
     * The tenant column bound by every generated statement.
     */
    public function tenantColumn(): string
    {
        return $this->tenantColumn;
    }

    /**
     * Display labels by locale.
     *
     * @return array<string, string>
     */
    public function label(): array
    {
        return $this->label;
    }

    /**
     * The declared lifecycle.
     */
    public function lifecycle(): Lifecycle
    {
        return $this->lifecycle;
    }

    /**
     * The declared reference graph.
     *
     * @return list<ReferenceGuard>
     */
    public function guards(): array
    {
        return $this->guards;
    }

    /**
     * Whether the type's declaration STRUCTURALLY supports an action.
     *
     * Read and delete always apply; trash and restore need a trashable
     * lifecycle; retire needs a retirable one. Permissions are a separate
     * question — see {@see self::offers()}.
     *
     * @param string $action A {@see LifecycleAction} constant.
     */
    public function supports(string $action): bool
    {
        return match ($action) {
            LifecycleAction::READ, LifecycleAction::DELETE => true,
            LifecycleAction::TRASH, LifecycleAction::RESTORE => $this->lifecycle->isTrashable(),
            LifecycleAction::RETIRE => $this->lifecycle->isRetirable(),
            default => false,
        };
    }

    /**
     * The permission slug gating an action, or null when none was declared.
     *
     * @param string $action A {@see LifecycleAction} constant.
     */
    public function permissionFor(string $action): ?string
    {
        return $this->permissions[$action] ?? null;
    }

    /**
     * Whether core will generate AND enforce this action.
     *
     * The honest-degradation rule: an undeclared permission is not "no
     * permission required", it is "this action was not offered". Rendering a
     * control for it would promise something the endpoint refuses; running it
     * ungated would be worse.
     *
     * @param string $action A {@see LifecycleAction} constant.
     */
    public function offers(string $action): bool
    {
        return $this->supports($action) && $this->permissionFor($action) !== null;
    }

    /**
     * The actions this type offers, in presentation order.
     *
     * @return list<string>
     */
    public function offeredActions(): array
    {
        $offered = [];
        foreach (LifecycleAction::all() as $action) {
            if ($this->offers($action)) {
                $offered[] = $action;
            }
        }

        return $offered;
    }

    /**
     * The type as data, for the generated-UI contract.
     *
     * Deliberately publishes the reference graph's LABELS but never a row
     * count: what a type is declared to be guarded by is descriptive metadata,
     * whereas how many rows currently reference a given record is tenant data
     * and is answered per record, per caller, through the guarded read.
     *
     * Within that boundary the entry ROUND-TRIPS the declaration: every field a
     * plugin declared is echoed back as the host accepted it, so a declarer can
     * diff what they wrote against what took effect instead of reading core's
     * source to find out whether a field was honoured or quietly dropped.
     *
     * @return array{key: string, source: string, label: array<string, string>, lifecycle: array<string, mixed>, blocks_delete: list<array{table: string, column: string, label: string, ignore_when: array<string, list<string>>}>, actions: list<string>, permissions: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'source' => $this->source,
            'label' => $this->label,
            'lifecycle' => $this->lifecycle->toArray(),
            'blocks_delete' => array_map(
                static fn (ReferenceGuard $guard): array => $guard->toArray(),
                $this->guards
            ),
            'actions' => $this->offeredActions(),
            'permissions' => $this->permissions,
        ];
    }
}
