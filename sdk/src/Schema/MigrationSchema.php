<?php

declare(strict_types=1);

namespace Whity\Sdk\Schema;

/**
 * The schema predicates, on `$this`, inside a migration.
 *
 *     final class AddArchivedAtToAcmeItems implements MigrationInterface
 *     {
 *         use MigrationSchema;
 *
 *         public function up(\PDO $pdo): void
 *         {
 *             $this->addColumnIfMissing($pdo, 'acme_items', 'archived_at', 'TIMESTAMP NULL');
 *         }
 *
 *         public function down(\PDO $pdo): void
 *         {
 *             $this->dropColumnIfExists($pdo, 'acme_items', 'archived_at');
 *         }
 *     }
 *
 * Why a trait and not a base class
 * --------------------------------
 * {@see \Whity\Sdk\MigrationInterface} is an interface precisely so a plugin
 * can shape its own migration hierarchy — several plugins already have one, and
 * PHP grants a single parent. A trait adds the helpers without spending that
 * one inheritance slot, and a plugin that would rather call
 * {@see SchemaInspector} statically loses nothing by not using it.
 *
 * The method signatures match, deliberately, the private helpers plugin authors
 * have been writing by hand — `(\PDO $pdo, string $table, …)` — so adopting
 * this is deleting a private method and adding a `use` line, with no call-site
 * edits. The `$pdo` argument stays explicit rather than becoming trait state
 * because the interface hands the connection to `up()`/`down()` per call; a
 * migration that stashed it in a property would be holding a connection the
 * host owns.
 *
 * Every method here is a thin forward to {@see SchemaInspector}, which carries
 * the reasoning about search paths, privilege filtering and case folding. A
 * caller that is not a migration instance — a host migration handed a
 * connection wrapper, a test, a repair command — calls that class statically
 * with the underlying PDO and gets exactly the same answers.
 */
trait MigrationSchema
{
    /**
     * Whether a base table of this name is visible to the connection.
     *
     * @see SchemaInspector::tableExists()
     */
    protected function tableExists(\PDO $pdo, string $table): bool
    {
        return SchemaInspector::tableExists($pdo, $table);
    }

    /**
     * Whether the table has this column. False when the table itself is absent.
     *
     * @see SchemaInspector::columnExists()
     */
    protected function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        return SchemaInspector::columnExists($pdo, $table, $column);
    }

    /**
     * Whether an index of this name exists.
     *
     * @see SchemaInspector::indexExists()
     */
    protected function indexExists(\PDO $pdo, string $index): bool
    {
        return SchemaInspector::indexExists($pdo, $index);
    }

    /**
     * Declare that a column should exist. Returns true when it was added.
     *
     * @see SchemaInspector::addColumnIfMissing()
     */
    protected function addColumnIfMissing(
        \PDO $pdo,
        string $table,
        string $column,
        string $definition
    ): bool {
        return SchemaInspector::addColumnIfMissing($pdo, $table, $column, $definition);
    }

    /**
     * Declare that a column should not exist. Returns true when it was dropped.
     *
     * @see SchemaInspector::dropColumnIfExists()
     */
    protected function dropColumnIfExists(\PDO $pdo, string $table, string $column): bool
    {
        return SchemaInspector::dropColumnIfExists($pdo, $table, $column);
    }

    /**
     * The table's columns, lowercased, in declaration order.
     *
     * @return list<string>
     * @see SchemaInspector::columns()
     */
    protected function tableColumns(\PDO $pdo, string $table): array
    {
        return SchemaInspector::columns($pdo, $table);
    }
}
