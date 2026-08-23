<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * Answers "does this table (or this column) actually exist in the database this
 * process is connected to?" — the ground truth a {@see DocumentSubstrate}
 * resolves against.
 *
 * WHY THIS IS NOT JUST A CONSTANT
 * -------------------------------
 * A registry of hand-declared capabilities is only as honest as the person
 * updating it, and there is one state where a declaration is reliably wrong:
 * new code deployed against a database whose migrations have not been run yet.
 * That window is real here — core ships a migrations API and a runner precisely
 * because it happens — and it is exactly the window in which a folder backed by
 * a table that does not exist would render, be clicked, and 500. Or worse,
 * return nothing and read as "there is nothing here".
 *
 * So availability is MEASURED. A substrate names the tables and columns it
 * needs; this asks the database. The registry then cannot claim a capability
 * the schema does not back, whoever wrote the declaration.
 *
 * NOT STATIC, DELIBERATELY
 * ------------------------
 * Implementations cache on the INSTANCE, and instances are built per request in
 * public/index.php. A process-level static would be per FrankenPHP worker — the
 * platform runs eight — so the first worker to answer before a migration ran
 * would keep answering that way until it recycled, on a schedule nobody can
 * see. That failure mode has already cost this codebase a permission-cache bug
 * (#701) and it is not worth re-earning to save one cheap query per request.
 *
 * The direction of any staleness inside a single request is still the safe one:
 * a table that appears mid-request is reported absent, so a view is hidden
 * rather than falsely offered.
 */
interface SchemaPresence
{
    /** Whether a table of this name exists. */
    public function hasTable(string $table): bool;

    /** Whether `$table` exists AND carries a column of this name. */
    public function hasColumn(string $table, string $column): bool;
}
