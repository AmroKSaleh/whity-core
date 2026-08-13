<?php

declare(strict_types=1);

namespace Whity\Sdk\Schema;

/**
 * Every relationship a plugin has TOLD core about — the set
 * {@see UndeclaredReferenceLinter} checks a schema against.
 *
 * Core learns about a relationship in exactly two ways, and both are data a
 * plugin already writes:
 *
 *  - **`blocks_delete`** — "rows in that table point at this record, so deleting
 *    it would orphan them; refuse the delete." Declared since SDK 1.20.
 *  - **`cascade_delete`** — "rows in that table are PART OF this record, so
 *    delete them with it." The composition half of the same graph.
 *
 * Opposite answers to one question — what happens to the children — so for the
 * purpose of "does core know this edge exists?" they count identically. An edge
 * in EITHER list is known. An edge in neither is invisible: deleting the parent
 * neither refuses nor cascades, it just succeeds and leaves rows pointing at an
 * id that no longer resolves, in a state no screen lists and no guard protects.
 *
 * That is the whole signal. This class is only the vocabulary for it.
 *
 * Reading a declaration
 * ---------------------
 * {@see fromDataTypes()} takes the array a plugin returns from
 * `PluginDataTypesInterface::getDataTypes()` and reads both lists out of it.
 * Anything malformed is skipped rather than throwing — a linter that dies on a
 * declaration the host would merely log is a linter people stop running, and
 * the host's own registry is where malformed declarations get rejected properly.
 *
 * A note on `cascade_delete` availability
 * -----------------------------------------
 * `blocks_delete` has been part of the data-type declaration since SDK 1.20.
 * `cascade_delete` is the composition half; this class reads it wherever it is
 * present and simply finds nothing when it is not, so a plugin declaring only
 * guards is handled correctly and one declaring both is handled correctly the
 * moment it does.
 */
final class ReferenceDeclarations
{
    /**
     * Declared edges, as `<table>.<column>` => the declaring type key.
     *
     * The value is carried for the violation message: when an edge IS declared
     * the linter says nothing, but when a NEARBY one is, naming the type that
     * declared it is what turns "add a declaration" into "add it here".
     *
     * @var array<string, string>
     */
    private array $edges;

    /**
     * @param array<string, string> $edges `<table>.<column>` => declaring type key.
     */
    public function __construct(array $edges = [])
    {
        $normalised = [];
        foreach ($edges as $edge => $declaredBy) {
            $normalised[strtolower($edge)] = $declaredBy;
        }
        $this->edges = $normalised;
    }

    /**
     * Read the declared reference graph out of a `getDataTypes()` return value.
     *
     * @param array<string, array<string, mixed>> $dataTypes Slug => declaration.
     * @param string $source Optional plugin name, used only to make the type key
     *        in a violation message match what `GET /api/data-types` publishes.
     */
    public static function fromDataTypes(array $dataTypes, string $source = ''): self
    {
        $edges = [];

        foreach ($dataTypes as $slug => $declaration) {
            if (!is_array($declaration)) {
                continue;
            }

            $typeKey = $source === '' ? (string) $slug : strtolower($source) . ':' . $slug;

            foreach (['blocks_delete', 'cascade_delete'] as $listKey) {
                $list = $declaration[$listKey] ?? [];
                if (!is_array($list)) {
                    continue;
                }

                foreach ($list as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $table = $entry['table'] ?? null;
                    $column = $entry['column'] ?? null;
                    if (!is_string($table) || !is_string($column) || $table === '' || $column === '') {
                        continue;
                    }

                    $edges[strtolower($table) . '.' . strtolower($column)] = $typeKey;
                }
            }
        }

        return new self($edges);
    }

    /**
     * Return a copy that also knows every edge the other set knows.
     *
     * One plugin's guard can legitimately cover another plugin's column when
     * both are loaded, and the CI runner merges every loaded plugin's
     * declarations before linting any of them for exactly that reason.
     */
    public function merge(self $other): self
    {
        return new self([...$this->edges, ...$other->edges]);
    }

    /**
     * Return a copy with one more declared edge.
     *
     * The seam for a relationship declared somewhere other than a data type —
     * or for a legitimate exception a maintainer would rather record in code
     * than as an annotation in a migration.
     */
    public function with(string $table, string $column, string $declaredBy = 'explicit'): self
    {
        $edges = $this->edges;
        $edges[strtolower($table) . '.' . strtolower($column)] = $declaredBy;

        return new self($edges);
    }

    /**
     * Whether core has been told that `<table>.<column>` is a reference.
     */
    public function declares(string $table, string $column): bool
    {
        return array_key_exists(strtolower($table) . '.' . strtolower($column), $this->edges);
    }

    /**
     * The declared edges, as `<table>.<column>` => declaring type key.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->edges;
    }
}
