<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

use PDO;

/**
 * {@see SchemaPresence} backed by the live connection's own catalogue.
 *
 * ONE QUERY, CACHED ON THE INSTANCE
 * ---------------------------------
 * The whole table/column map is read once on first use and answered from memory
 * afterwards. The alternative — a `SELECT … WHERE table_name = ?` per question —
 * is a round trip per substrate per request, and a page that renders a rail of
 * views asks the same handful of questions every time. The map for a schema this
 * size is a few hundred rows.
 *
 * Instance-scoped rather than static: see the interface docblock for why a
 * worker-lifetime cache is the wrong shape for a schema answer.
 *
 * TWO DIALECTS, BECAUSE THE TEST ENGINE IS A REAL ENGINE
 * ------------------------------------------------------
 * PostgreSQL is production; SQLite is what the unit suite builds the schema on
 * ({@see \Tests\Support\SchemaFromMigrations}). A probe that only understood
 * `information_schema` would report EVERY substrate absent under SQLite, so
 * every organizer test would pass by rendering nothing — the precise failure
 * this class exists to prevent, arriving as green CI. Both dialects are
 * therefore implemented, and the fallback for an unrecognised driver is to
 * report absence rather than presence: a view that is wrongly hidden is a
 * missing feature, a view that is wrongly shown is a lie.
 *
 * These two statements read the driver's own catalogue, not a tenant-owned
 * table, so they carry no tenant predicate and none is meaningful — schema is
 * not tenant data.
 */
final class PdoSchemaPresence implements SchemaPresence
{
    /**
     * table => (column => true), lower-cased on both levels. Null until read.
     *
     * @var array<string, array<string, true>>|null
     */
    private ?array $map = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function hasTable(string $table): bool
    {
        return isset($this->map()[strtolower($table)]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->map()[strtolower($table)][strtolower($column)]);
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];
        foreach ($this->readCatalogue() as $row) {
            $table = strtolower((string) ($row['table_name'] ?? ''));
            $column = strtolower((string) ($row['column_name'] ?? ''));
            if ($table !== '' && $column !== '') {
                $map[$table][$column] = true;
            }
        }

        return $this->map = $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCatalogue(): array
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        // A catalogue read that FAILS must not be mistaken for an empty
        // catalogue by anything other than this method — but there is nothing
        // more truthful to return, and "absent" is the safe direction. It is
        // logged rather than swallowed silently, because a probe that quietly
        // reports nothing would hide every organizer view with no explanation.
        try {
            $sql = match ($driver) {
                'pgsql' => 'SELECT table_name, column_name
                              FROM information_schema.columns
                             WHERE table_schema = current_schema()',
                // `pragma_table_info` as a table-valued function (SQLite 3.16+,
                // far older than anything PHP 8.4 ships against).
                'sqlite' => "SELECT m.name AS table_name, p.name AS column_name
                               FROM sqlite_master m
                               JOIN pragma_table_info(m.name) p
                              WHERE m.type = 'table'",
                default => null,
            };

            if ($sql === null) {
                error_log("[PdoSchemaPresence] no catalogue dialect for driver '{$driver}'; "
                    . 'every document-organizer substrate will report absent');

                return [];
            }

            $stmt = $this->db->query($sql);

            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (\Throwable $e) {
            error_log('[PdoSchemaPresence] reading the schema catalogue failed: ' . $e->getMessage());

            return [];
        }
    }
}
