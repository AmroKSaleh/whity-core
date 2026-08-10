<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

use Whity\Core\Container\HostWiredService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;
use Whity\Core\Tenant\TableOwnershipRegistry;

/**
 * The catalogue of registered DATA TYPES — Door 2 of WC-723,
 * `registerDataType`: the plugin owns the table, core owns the lifecycle and
 * the referential guards.
 *
 * What registration buys
 * ----------------------
 * A declaration turns three things core cannot infer into three things it can
 * enforce: where a record lives, what its lifecycle states mean, and which rows
 * still point at it. From that, and nothing else, core can refuse a delete that
 * would orphan data, say WHY in the plugin's own words, and offer trash /
 * restore / retire with no plugin-authored UI.
 *
 * Attribution, again from the loader
 * ----------------------------------
 * `$source` is the plugin NAME the loader supplies from `$plugin->getName()`,
 * never anything the plugin returned. Keys are namespaced under it, so two
 * plugins may both declare `record` and neither can shadow a core type — the
 * same rule {@see ResourceTypeRegistry} applies, reused deliberately so that a
 * data type and a resource-scoped role grant address the SAME key. `acme:record`
 * means one thing across the install, not two.
 *
 * Ownership is the gate
 * ---------------------
 * Every table a declaration names — its own AND every referencing table in
 * `blocks_delete` — must be one {@see TableOwnershipRegistry} says this source
 * owns, and must be tenant-scoped. This is the entire reason the ownership
 * registry exists: without it "which rows still reference this?" is a way to
 * count rows in another plugin's data by declaration alone, and tenant
 * isolation would rest on a plugin's own say-so.
 *
 * Instance, not static
 * --------------------
 * Process-level statics are per FrankenPHP worker, so a registration made while
 * serving one request is invisible to the other seven (PR #701). An instance
 * resolved from the container is rebuilt per boot from the same plugin
 * bootstrap every worker runs.
 */
class DataTypeRegistry implements HostWiredService
{
    /**
     * Registered definitions, keyed by canonical namespaced key.
     *
     * @var array<string, DataTypeDefinition>
     */
    private array $types = [];

    private TableOwnershipRegistry $tables;

    private ?HookManager $hookManager;

    /**
     * @param TableOwnershipRegistry $tables      The loader-stamped ownership map.
     * @param HookManager|null       $hookManager Announces registrations on the durable spine.
     */
    public function __construct(TableOwnershipRegistry $tables, ?HookManager $hookManager = null)
    {
        $this->tables = $tables;
        $this->hookManager = $hookManager;
    }

    /**
     * Register a source's data types.
     *
     * Each type is validated and stored INDEPENDENTLY: one malformed
     * declaration is rejected on its own and does not discard the source's
     * other types. A type is never partially stored.
     *
     * @param string                              $source       Plugin name supplied by the loader.
     * @param array<string, array<string, mixed>> $declarations Bare slug => declaration.
     * @return list<string> The canonical keys actually registered.
     *
     * @throws InvalidDataTypeException On the FIRST invalid declaration, so the
     *                                 loader can log it against the plugin. Types
     *                                 validated before it are already stored.
     */
    public function register(string $source, array $declarations): array
    {
        $prefix = SourceSlug::from($source);
        if ($prefix === null) {
            throw InvalidDataTypeException::forSource($source);
        }

        $registered = [];
        foreach ($declarations as $slug => $declaration) {
            $definition = $this->build($source, $prefix, (string) $slug, $declaration);
            $this->types[$definition->key()] = $definition;
            $registered[] = $definition->key();

            $this->hookManager?->dispatch('datatype.registered', [
                'source' => $source,
                'data_type' => $definition->key(),
                'table' => $definition->table(),
            ]);
        }

        return $registered;
    }

    /**
     * A registered definition, or null when the key is unknown.
     *
     * @param string $key The canonical namespaced key.
     */
    public function get(string $key): ?DataTypeDefinition
    {
        return $this->types[$key] ?? null;
    }

    /**
     * Whether a canonical key is registered.
     *
     * @param string $key The canonical namespaced key.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->types);
    }

    /**
     * Every registered definition, keyed by canonical key.
     *
     * @return array<string, DataTypeDefinition>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The canonical key a source's bare slug resolves to.
     *
     * Callers use this rather than concatenating by hand, so the namespacing
     * rule lives in one place and a change to it cannot silently orphan every
     * key a plugin has written.
     *
     * @param string $source The declaring plugin name.
     * @param string $slug   The bare slug.
     */
    public static function canonicalKey(string $source, string $slug): string
    {
        return ResourceTypeRegistry::canonicalKey($source, $slug);
    }

    /**
     * Validate one declaration and build its definition.
     *
     * @param string $source      The raw plugin name (kept for messages/attribution).
     * @param string $prefix      Its normalised namespace prefix.
     * @param string $slug        The bare slug declared.
     * @param mixed  $declaration The raw declaration.
     *
     * @throws InvalidDataTypeException
     */
    private function build(string $source, string $prefix, string $slug, mixed $declaration): DataTypeDefinition
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $slug) !== 1) {
            throw InvalidDataTypeException::forSlug($slug);
        }
        $key = $prefix . ResourceTypeRegistry::NAMESPACE_SEPARATOR . $slug;

        if ($this->has($key)) {
            throw InvalidDataTypeException::forDuplicateKey($key);
        }
        if (!is_array($declaration)) {
            throw InvalidDataTypeException::forMalformedDeclaration($key);
        }

        $table = self::identifier($key, 'table', $declaration['table'] ?? null);
        $keyColumn = self::identifier($key, 'key', $declaration['key'] ?? 'id');
        $tenantColumn = self::identifier($key, 'tenant_column', $declaration['tenant_column'] ?? 'tenant_id');

        $this->assertOwnedTenantTable($key, $table, $source);

        return new DataTypeDefinition(
            $key,
            $source,
            $table,
            $keyColumn,
            $tenantColumn,
            self::labels($declaration['label'] ?? [], $slug),
            self::lifecycle($key, $declaration['lifecycle'] ?? null),
            $this->guards($key, $source, $declaration['blocks_delete'] ?? []),
            self::permissions($key, $declaration['permissions'] ?? [])
        );
    }

    /**
     * Assert that a source owns a table AND that the table is tenant-scoped.
     *
     * @throws InvalidDataTypeException
     */
    private function assertOwnedTenantTable(string $key, string $table, string $source): void
    {
        if (!$this->tables->isOwnedBy($table, $source)) {
            throw InvalidDataTypeException::forUnownedTable(
                $key,
                $table,
                $source,
                $this->tables->ownerOf($table)
            );
        }
        if (!$this->tables->isTenantScoped($table)) {
            throw InvalidDataTypeException::forNonTenantTable($key, $table);
        }
    }

    /**
     * Validate the reference graph.
     *
     * @param string $key    The type key.
     * @param string $source The declaring source.
     * @param mixed  $raw    The raw `blocks_delete` value.
     * @return list<ReferenceGuard>
     *
     * @throws InvalidDataTypeException
     */
    private function guards(string $key, string $source, mixed $raw): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw InvalidDataTypeException::forGuard($key, 'blocks_delete must be a list of reference declarations');
        }

        $guards = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw InvalidDataTypeException::forGuard($key, 'each entry must be an array');
            }

            $table = self::identifier($key, 'blocks_delete.table', $entry['table'] ?? null);
            $column = self::identifier($key, 'blocks_delete.column', $entry['column'] ?? null);
            $tenantColumn = self::identifier(
                $key,
                'blocks_delete.tenant_column',
                $entry['tenant_column'] ?? 'tenant_id'
            );

            // The gate Piece 1 exists for: a guard is an aggregate over the
            // referencing table, so declaring one over a table the plugin does
            // not own would read data it cannot otherwise reach.
            $this->assertOwnedTenantTable($key, $table, $source);

            $label = $entry['label'] ?? null;
            if (!is_string($label) || trim($label) === '') {
                throw InvalidDataTypeException::forGuard(
                    $key,
                    "a non-empty 'label' is required for table '{$table}' — it is what the refusal message says"
                );
            }

            $guards[] = new ReferenceGuard(
                $table,
                $column,
                trim($label),
                $tenantColumn,
                self::ignoreWhen($key, $entry['ignore_when'] ?? [])
            );
        }

        return $guards;
    }

    /**
     * Validate the optional "these referencing rows do not block" filter.
     *
     * @param string $key The type key.
     * @param mixed  $raw The raw `ignore_when` value.
     * @return array<string, list<string>>
     *
     * @throws InvalidDataTypeException
     */
    private static function ignoreWhen(string $key, mixed $raw): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw InvalidDataTypeException::forGuard($key, 'ignore_when must be a map of column => values');
        }

        $out = [];
        foreach ($raw as $column => $values) {
            $name = self::identifier($key, 'blocks_delete.ignore_when', is_string($column) ? $column : '');
            if (!is_array($values) || $values === []) {
                throw InvalidDataTypeException::forGuard(
                    $key,
                    "ignore_when['{$name}'] must be a non-empty list of values"
                );
            }
            $list = [];
            foreach ($values as $value) {
                if (!is_scalar($value)) {
                    throw InvalidDataTypeException::forGuard(
                        $key,
                        "ignore_when['{$name}'] values must be scalars"
                    );
                }
                $list[] = (string) $value;
            }
            $out[$name] = $list;
        }

        return $out;
    }

    /**
     * Validate the lifecycle block.
     *
     * An ABSENT lifecycle is legitimate — the type simply has no trash or
     * retire affordance. A lifecycle that CLAIMS a capability without the state
     * expressing it is rejected loudly: quietly dropping a requested
     * `trashable` would leave the plugin believing deletes are recoverable.
     *
     * @param string $key The type key.
     * @param mixed  $raw The raw `lifecycle` value.
     *
     * @throws InvalidDataTypeException
     */
    private static function lifecycle(string $key, mixed $raw): Lifecycle
    {
        if ($raw === null || $raw === []) {
            return Lifecycle::none();
        }
        if (!is_array($raw)) {
            throw InvalidDataTypeException::forLifecycle($key, 'lifecycle must be an array');
        }

        $trashable = (bool) ($raw['trashable'] ?? false);
        $retirable = (bool) ($raw['retirable'] ?? false);

        $column = $raw['column'] ?? null;
        if ($column === null) {
            if ($trashable || $retirable) {
                throw InvalidDataTypeException::forLifecycle(
                    $key,
                    "'column' is required when trashable or retirable is declared"
                );
            }

            return Lifecycle::none();
        }
        $column = self::identifier($key, 'lifecycle.column', $column);

        $states = [];
        foreach ((array) ($raw['states'] ?? []) as $state) {
            if (!is_string($state) || trim($state) === '') {
                throw InvalidDataTypeException::forLifecycle($key, 'every state must be a non-empty string');
            }
            $states[] = $state;
        }
        if ($states === []) {
            throw InvalidDataTypeException::forLifecycle($key, "'states' must list at least one state");
        }

        $defaultState = $raw['default_state'] ?? $states[0];
        if (!is_string($defaultState) || !in_array($defaultState, $states, true)) {
            throw InvalidDataTypeException::forLifecycle(
                $key,
                "'default_state' must be one of the declared states"
            );
        }

        $trashedState = null;
        if ($trashable) {
            $trashedState = $raw['trashed_state'] ?? 'trashed';
            if (!is_string($trashedState) || !in_array($trashedState, $states, true)) {
                throw InvalidDataTypeException::forLifecycle(
                    $key,
                    "'trashed_state' must be one of the declared states when trashable is true"
                );
            }
        }

        $retiredState = null;
        if ($retirable) {
            $retiredState = $raw['retired_state'] ?? 'retired';
            if (!is_string($retiredState) || !in_array($retiredState, $states, true)) {
                throw InvalidDataTypeException::forLifecycle(
                    $key,
                    "'retired_state' must be one of the declared states when retirable is true"
                );
            }
        }

        if ($trashedState !== null && $trashedState === $retiredState) {
            throw InvalidDataTypeException::forLifecycle(
                $key,
                'the trashed and retired states must differ — a record pending removal is not a '
                . 'record that served its purpose, and collapsing them loses the only distinction '
                . 'this lifecycle exists to express'
            );
        }
        if ($defaultState === $trashedState || $defaultState === $retiredState) {
            throw InvalidDataTypeException::forLifecycle(
                $key,
                "'default_state' must not be the trashed or retired state — a restore would be a no-op"
            );
        }

        return new Lifecycle($column, $states, $defaultState, $trashedState, $retiredState);
    }

    /**
     * Validate the per-action permission map.
     *
     * @param string $key The type key.
     * @param mixed  $raw The raw `permissions` value.
     * @return array<string, string>
     *
     * @throws InvalidDataTypeException
     */
    private static function permissions(string $key, mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            throw InvalidDataTypeException::forPermission($key, '*', 'permissions must be a map of action => slug');
        }

        $out = [];
        foreach ($raw as $action => $slug) {
            $action = is_string($action) ? $action : '';
            if (
                !LifecycleAction::isValid($action)
                || !is_string($slug)
                || preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/', $slug) !== 1
            ) {
                throw InvalidDataTypeException::forPermission($key, $action, is_string($slug) ? $slug : '');
            }
            $out[$action] = $slug;
        }

        return $out;
    }

    /**
     * Normalise the label map, falling back to the slug so a type always has a
     * name to render.
     *
     * @param mixed  $raw  The raw `label` value.
     * @param string $slug The bare slug, used as the fallback label.
     * @return array<string, string>
     */
    private static function labels(mixed $raw, string $slug): array
    {
        if (!is_array($raw)) {
            return ['en' => $slug];
        }

        $out = [];
        foreach ($raw as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $out[strtolower($locale)] = $text;
            }
        }

        return $out === [] ? ['en' => $slug] : $out;
    }

    /**
     * Validate a SQL identifier the host will interpolate into generated SQL.
     *
     * Strict by design: every table and column name reaching the query builder
     * passes through here, which is what makes interpolating them safe. Values
     * are never quoted-and-hoped-for; a name that does not match is refused.
     *
     * @param string $key   The type key (for the message).
     * @param string $field The declaration field (for the message).
     * @param mixed  $value The raw value.
     *
     * @throws InvalidDataTypeException
     */
    private static function identifier(string $key, string $field, mixed $value): string
    {
        $name = is_string($value) ? strtolower(trim($value)) : '';
        if (!TableOwnershipRegistry::isValidTableName($name)) {
            throw InvalidDataTypeException::forIdentifier($key, $field, is_string($value) ? $value : '');
        }

        return $name;
    }
}
