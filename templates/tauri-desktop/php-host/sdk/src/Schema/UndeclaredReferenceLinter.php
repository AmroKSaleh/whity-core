<?php

declare(strict_types=1);

namespace Whity\Sdk\Schema;

/**
 * Finds relationships CORE DOES NOT KNOW ABOUT.
 *
 * The bug
 * -------
 * An adopter's schema has zero foreign keys, and deleting a parent silently
 * orphaned its children: rows left pointing at an id that no longer resolves,
 * in a state no screen lists, no guard protects, and no error mentions. The
 * delete answered 200.
 *
 * The rule this linter deliberately DOES NOT implement
 * ----------------------------------------------------
 * "Flag every `*_id` column without a foreign key" is the obvious rule and it
 * is the wrong one. **No foreign keys between plugin tables is this platform's
 * established convention** — it is why `blocks_delete` exists at all, and why
 * the reference graph is declared as DATA rather than enforced by the engine.
 * A rule that fires on the intended design fires on nearly every row, is
 * therefore ignored within a day, and takes the credibility of the other
 * linters down with it.
 *
 * The rule this linter DOES implement
 * -----------------------------------
 * A reference is fine when it is enforced, and fine when it is declared. It is
 * a problem only when it is NEITHER:
 *
 *   FLAG a column named `<something>_id` when
 *     (a) `<something>` resolves to a table that actually exists, AND
 *     (b) the column carries no FOREIGN KEY / REFERENCES clause, AND
 *     (c) no data type declares that `<table>.<column>` in `blocks_delete`
 *         or `cascade_delete`.
 *
 * Which is exactly "you have a relationship core cannot see" — the orphaning
 * bug — and says nothing at all about a plugin that follows the convention and
 * declares its graph. A schema with zero foreign keys passes this linter
 * completely, provided its relationships are declared.
 *
 * (a) is what keeps it narrow. `external_ref_id`, `stripe_customer_id`,
 * `legacy_id` name no table here, so they are not references and are not
 * flagged. Only a column that points at something the platform can actually
 * resolve — and therefore could actually orphan — is considered.
 *
 * A reference is read from the WHOLE migration set
 * -----------------------------------------------
 * Two things follow from "is this relationship enforced or declared?" being a
 * question about the SCHEMA rather than about one statement, and #751 found the
 * guard answering both wrongly:
 *
 *  - A column is resolved from its stem AND from that stem with leading
 *    qualifier segments dropped, so `recipient_profile_id` reaches `profiles`.
 *    Reading only the whole stem, the column #751 is about resolved to nothing
 *    and was reported as clean. {@see stems()}.
 *  - A foreign key installed by an `ALTER TABLE` in a LATER file counts, so a
 *    migration set is judged on what it adds up to. {@see lintDirectory()}.
 *
 * `tenant_id` is exempt, always
 * -----------------------------
 * Every tenant-owned table has one, none of them carries a foreign key by
 * convention, and none of them is declared in a reference graph — because the
 * tenant relationship is a different invariant with its own enforcement:
 * {@see \Whity\Sdk\Tenant\MigrationTenantColumnLinter} proves the column
 * exists and {@see \Whity\Sdk\Tenant\TenantPredicateScanner} proves every
 * query binds it. Flagging it here would fire on every single tenant table and
 * prove nothing that is not already proven better elsewhere.
 *
 * The escape hatch
 * ----------------
 * False positives are what kill linters, so there is a way out that mirrors the
 * tenant guard's `@tenant-guard-ignore`. Put
 * `-- @reference-lint-ignore: <reason>` on the column's line (or the line above
 * it) inside the `CREATE TABLE`, and that column is skipped. A REASON is
 * required — a bare tag is not accepted — because the point of the annotation
 * is that a human decided, and a decision with no reason recorded is
 * indistinguishable from a silenced alarm.
 *
 * A whole table can be exempted with `-- @reference-lint-ignore-table: <reason>`
 * anywhere in its body, for the cases (an import staging table, a denormalised
 * projection) where every column would otherwise need the same annotation.
 *
 * Pure PHP, depends on nothing but the SDK, so an out-of-repo plugin runs it in
 * its own CI exactly as it runs the tenant conformance kit.
 *
 * @phpstan-type ReferenceViolation array{file: string, table: string, column: string, target: string, reason: string}
 */
final class UndeclaredReferenceLinter
{
    /** The annotation that exempts one column. */
    public const IGNORE_TAG = '@reference-lint-ignore';

    /** The annotation that exempts a whole table. */
    public const IGNORE_TABLE_TAG = '@reference-lint-ignore-table';

    /**
     * Columns never treated as references, whatever they are named.
     *
     * `tenant_id` is the tenant invariant, enforced by its own two linters.
     * `id` is a primary key, not a reference to a table called `` .
     *
     * @var list<string>
     */
    private const ALWAYS_EXEMPT = ['tenant_id', 'id'];

    /**
     * Known table names, lowercased, as a set.
     *
     * @var array<string, true>
     */
    private array $knownTables;

    private ReferenceDeclarations $declarations;

    /**
     * @param list<string>          $knownTables  Every table the platform can resolve —
     *        the host's own plus every loaded plugin's. A column only counts as a
     *        reference when it points at one of these, which is what keeps the rule
     *        narrow enough to be believed.
     * @param ReferenceDeclarations $declarations Every relationship already declared.
     */
    public function __construct(array $knownTables, ReferenceDeclarations $declarations)
    {
        $set = [];
        foreach ($knownTables as $table) {
            $set[strtolower($table)] = true;
        }
        $this->knownTables = $set;
        $this->declarations = $declarations;
    }

    /**
     * Lint a directory tree of migration PHP files.
     *
     * TWO PASSES, because a migration set's schema is what its files ADD UP TO
     * and not what any one of them says on its own. The rekeys in core's own
     * history (`ADD COLUMN … NULL`, backfill, then add the constraint in a
     * later step) and every migration that retro-fits a foreign key somebody
     * missed install a REAL constraint from a file other than the `CREATE
     * TABLE`. Judging each file alone calls those undeclared, and the only way
     * to satisfy that reading is to go back and edit the original `CREATE
     * TABLE` — so a single-pass guard would spend its authority pushing people
     * to rewrite migration history in order to silence it. Pass one gathers
     * every foreign key the set installs anywhere; pass two lints each file
     * knowing them.
     *
     * @return list<ReferenceViolation>
     */
    public function lintDirectory(string $dir): array
    {
        $paths = self::migrationPaths($dir);

        /** @var array<string, array<string, true>> $enforcedElsewhere */
        $enforcedElsewhere = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            foreach ($this->alterEnforcedColumns($source) as $table => $columns) {
                foreach (array_keys($columns) as $column) {
                    $enforcedElsewhere[$table][$column] = true;
                }
            }
        }

        $violations = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            foreach ($this->lintSource($source, $path, $enforcedElsewhere) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Every `.php` file under a directory tree, in a stable order.
     *
     * @return list<string>
     */
    private static function migrationPaths(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var list<string> $paths */
        $paths = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $paths[] = $fileInfo->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * Lint one source string. Exposed for unit testing.
     *
     * @param array<string, array<string, true>> $enforcedElsewhere Columns that
     *        ANOTHER file in the same migration set installs a foreign key on,
     *        as table => column => true. {@see lintDirectory()} for why a
     *        constraint added from a later file counts. An `ALTER TABLE` in
     *        THIS source is picked up without being passed in.
     *
     * @return list<ReferenceViolation>
     */
    public function lintSource(string $source, string $file = '<source>', array $enforcedElsewhere = []): array
    {
        $violations = [];
        $alterEnforced = $this->alterEnforcedColumns($source);

        foreach ($this->createTableBodies($source) as [$table, $body]) {
            // A whole-table exemption carries the same requirement as a
            // per-column one: a REASON. A bare tag is not an exemption, it is
            // a muted alarm, and the wider the exemption the more that matters.
            // The reason must be on the SAME LINE as the tag: `\s` matches a
            // newline, so a bare tag would otherwise swallow the next line's
            // column name as its "reason" and exempt the table anyway — a
            // silent hole in the one rule that keeps the hatch honest.
            if (preg_match(
                '/' . preg_quote(self::IGNORE_TABLE_TAG, '/') . '[^\S\r\n]*:[^\S\r\n]*\S/',
                $body
            ) === 1) {
                continue;
            }

            $enforced = $this->foreignKeyColumns($body)
                + ($alterEnforced[$table] ?? [])
                + ($enforcedElsewhere[$table] ?? []);

            foreach ($this->candidateColumns($body) as $column => $line) {
                if (in_array($column, self::ALWAYS_EXEMPT, true)) {
                    continue;
                }
                if (isset($enforced[$column])) {
                    continue; // The engine enforces it. Nothing to declare.
                }

                $target = $this->resolveTarget($column, $table);
                if ($target === null) {
                    continue; // Names no table here, so it references nothing.
                }
                if ($this->declarations->declares($table, $column)) {
                    continue; // Core knows.
                }
                if ($this->isIgnored($body, $column, $line)) {
                    continue; // A human decided, and said why.
                }

                $violations[] = [
                    'file' => $file,
                    'table' => $table,
                    'column' => $column,
                    'target' => $target,
                    'reason' => sprintf(
                        '%s.%s points at `%s` but is neither enforced by a FOREIGN KEY nor '
                        . 'declared to core. Deleting a `%s` row will leave these rows pointing '
                        . 'at an id that no longer resolves, and nothing will refuse it or clean '
                        . 'them up. Declare the edge in the owning data type — `blocks_delete` '
                        . 'if these rows must OUTLIVE the %s, `cascade_delete` if they are PART '
                        . 'OF it — or add a FOREIGN KEY, or, if this genuinely is not a '
                        . 'reference, annotate the column `%s: <reason>`.',
                        $table,
                        $column,
                        $target,
                        $target,
                        $target,
                        self::IGNORE_TAG
                    ),
                ];
            }
        }

        return $violations;
    }

    /**
     * Every `CREATE TABLE <name> (...)` in the source, as [table, body].
     *
     * Walks each opener to its MATCHING close paren so a nested
     * `REFERENCES parent(id)` cannot truncate the body — the same walk
     * {@see \Whity\Sdk\Tenant\MigrationTenantColumnLinter} uses, so the two
     * linters read a schema identically and cannot disagree about what a table
     * contains.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function createTableBodies(string $source): array
    {
        $bodies = [];

        if (preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*\(/i',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $table = strtolower($m[1][0]);
                $openParen = $m[0][1] + strlen($m[0][0]) - 1;
                $bodies[] = [$table, $this->balancedParenBody($source, $openParen)];
            }
        }

        return $bodies;
    }

    /**
     * Columns whose NAME says "this is a reference", as column => defining line.
     *
     * The line is carried so the ignore annotation can be looked for beside the
     * column rather than anywhere in the file — an exemption that applied from
     * a distance would drift onto columns it was never written for.
     *
     * @return array<string, int>
     */
    private function candidateColumns(string $body): array
    {
        $columns = [];

        foreach (explode("\n", $body) as $number => $line) {
            foreach ($this->definitionsOnLine($line) as $definition) {
                $column = $this->definedColumn($definition);
                if ($column === null || !str_ends_with($column, '_id')) {
                    continue;
                }

                $columns[$column] = $number;
            }
        }

        return $columns;
    }

    /**
     * The column-definition fragments on one line of a CREATE TABLE body.
     *
     * Migrations here put one column per line, but nothing enforces that, and a
     * body written as `a INTEGER, b_id INTEGER` on one line would otherwise
     * have everything after the first comma go unexamined — a false negative
     * that looks exactly like a clean pass, which is the failure mode this
     * whole file is built to avoid.
     *
     * Commas inside parentheses are not separators: `FOREIGN KEY (a, b)` and
     * `REFERENCES other(x, y)` are one fragment each.
     *
     * @return list<string>
     */
    private function definitionsOnLine(string $line): array
    {
        $fragments = [];
        $depth = 0;
        $start = 0;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth <= 0) {
                $fragments[] = substr($line, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $fragments[] = substr($line, $start);

        return $fragments;
    }

    /**
     * The column a definition fragment declares, or null when the fragment is a
     * table-level constraint, a comment, or nothing at all.
     */
    private function definedColumn(string $definition): ?string
    {
        // A column definition is `<name> <type…>`; a table-level constraint
        // starts with a keyword instead.
        if (preg_match('/^\s*["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s+[A-Za-z]/', $definition, $m) !== 1) {
            return null;
        }

        $column = strtolower($m[1]);

        return in_array($column, ['primary', 'foreign', 'unique', 'constraint', 'check'], true)
            ? null
            : $column;
    }

    /**
     * Columns the engine already enforces, as a set.
     *
     * Covers both spellings: the inline `owner_id INTEGER REFERENCES owners(id)`
     * and the table-level `FOREIGN KEY (owner_id) REFERENCES owners(id)`,
     * including the multi-column form.
     *
     * @return array<string, true>
     */
    private function foreignKeyColumns(string $body): array
    {
        $enforced = [];

        // Inline: the column's own definition carries REFERENCES. Split the
        // same way as the candidate scan, so `a INTEGER, b_id INTEGER
        // REFERENCES x(id)` credits `b_id` rather than `a`.
        foreach (explode("\n", $body) as $line) {
            foreach ($this->definitionsOnLine($line) as $definition) {
                if (stripos($definition, 'REFERENCES') === false) {
                    continue;
                }
                $column = $this->definedColumn($definition);
                if ($column !== null) {
                    $enforced[$column] = true;
                }
            }
        }

        // Table-level: FOREIGN KEY (a, b) REFERENCES other(x, y)
        if (preg_match_all('/FOREIGN\s+KEY\s*\(([^)]*)\)/i', $body, $matches)) {
            foreach ($matches[1] as $columnList) {
                foreach (explode(',', $columnList) as $column) {
                    $name = strtolower(trim($column, " \t\n\r\"`"));
                    if ($name !== '') {
                        $enforced[$name] = true;
                    }
                }
            }
        }

        return $enforced;
    }

    /**
     * Columns an `ALTER TABLE` in this source installs a foreign key on, as
     * table => column => true.
     *
     * Both spellings are in use here:
     *
     *     ALTER TABLE t ADD CONSTRAINT t_c_fkey FOREIGN KEY (c) REFERENCES p(id)
     *     ALTER TABLE t ADD COLUMN IF NOT EXISTS c BIGINT REFERENCES p(id)
     *
     * These statements are read out of PHP source, where the constraint NAME is
     * routinely an expression rather than a literal — `'… ADD CONSTRAINT ' .
     * self::FK_NAME . ' FOREIGN KEY …'`. Everything between `ADD` and `FOREIGN
     * KEY` is therefore SKIPPED rather than matched, bounded by `;` so the scan
     * cannot run out of one statement and into the next and credit a foreign key
     * to a table that does not have one.
     *
     * @return array<string, array<string, true>>
     */
    private function alterEnforcedColumns(string $source): array
    {
        $enforced = [];

        // Table-level: ALTER TABLE t ADD [CONSTRAINT <expr>] FOREIGN KEY (a, b) …
        if (preg_match_all(
            '/ALTER\s+TABLE\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s+ADD\s+[^;]*?FOREIGN\s+KEY\s*\(([^)]*)\)/is',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $table = strtolower($m[1]);
                foreach (explode(',', $m[2]) as $column) {
                    $name = strtolower(trim($column, " \t\n\r\"`"));
                    if ($name !== '') {
                        $enforced[$table][$name] = true;
                    }
                }
            }
        }

        // Inline on an added column: ALTER TABLE t ADD COLUMN c BIGINT REFERENCES p(id)
        if (preg_match_all(
            '/ALTER\s+TABLE\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s+ADD\s+COLUMN\s+'
            . '(?:IF\s+NOT\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?[^,;]*?\bREFERENCES\b/is',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $enforced[strtolower($m[1])][strtolower($m[2])] = true;
            }
        }

        return $enforced;
    }

    /**
     * The table a `<something>_id` column points at, or null when it points at
     * nothing this platform knows.
     *
     * Two passes, in order of confidence:
     *
     *  1. The stem, or an ordinary English plural of it, IS a known table.
     *     Both conventions are in use here — `user_id` → `users`,
     *     `audit_log_id` → `audit_log`, `category_id` → `categories`.
     *  2. Otherwise, exactly ONE known table ends in `_<stem>` (or a plural of
     *     it) AND SHARES A NAME PREFIX with the table doing the referencing.
     *     Plugin tables are prefixed, so a column is routinely named `item_id`
     *     for a table called `demo_catalog_items`, and a linter that only did
     *     pass 1 would silently see no reference there at all — a false
     *     negative that looks exactly like a clean pass.
     *
     * The shared-prefix requirement in pass 2 is what keeps it from guessing.
     * Without it, `notification_deliveries.provider_id` "resolves" to
     * `identity_providers` — two unrelated subsystems that happen to share a
     * word — and the linter accuses a table of a relationship it does not have.
     * Sharing a leading `_`-delimited segment is what makes a bare stem mean
     * the neighbour rather than a stranger.
     *
     * If SEVERAL known tables still match, the column is ambiguous and is left
     * alone. A wrong accusation is worse than a missed one for a linter that
     * has to stay believed, and pass 1 already covers the unambiguous case.
     *
     * A column matching nothing is not treated as a reference at all, which is
     * what stops this inventing relationships out of `external_ref_id` and
     * `stripe_customer_id`.
     *
     * @param string $column The `<something>_id` column.
     * @param string $owner  The table declaring it.
     */
    private function resolveTarget(string $column, string $owner): ?string
    {
        foreach ($this->stems($column) as $stem) {
            $forms = [$stem, $stem . 's', $stem . 'es'];
            if (str_ends_with($stem, 'y')) {
                $forms[] = substr($stem, 0, -1) . 'ies';
            }

            // Pass 1 — the column names the table outright.
            foreach ($forms as $form) {
                if (isset($this->knownTables[$form])) {
                    return $form;
                }
            }

            // Pass 2 — the column names a neighbour's unprefixed tail.
            $matches = [];
            foreach (array_keys($this->knownTables) as $table) {
                if (!$this->sharePrefix($table, $owner)) {
                    continue;
                }
                foreach ($forms as $form) {
                    if (str_ends_with($table, '_' . $form)) {
                        $matches[$table] = true;
                        break;
                    }
                }
            }

            if (count($matches) === 1) {
                return (string) array_key_first($matches);
            }
        }

        return null;
    }

    /**
     * The stems a `<something>_id` column could be naming, longest first.
     *
     * The WHOLE stem is tried before any part of it, so a column named after
     * its table always resolves to that table. After that, leading
     * `_`-delimited segments are dropped one at a time, because a reference
     * here is routinely QUALIFIED BY THE ROLE IT PLAYS rather than named after
     * the table it points at: `recipient_profile_id`, `grantor_profile_id`,
     * `actor_user_id`, `parent_ou_id`.
     *
     * Reading the whole stem only, `notifications.recipient_profile_id`
     * resolved to nothing at all — there is no `recipient_profiles` table — so
     * the missing foreign key #751 is about was invisible to this linter even
     * once core migrations were in scope. A guard that reports nothing because
     * it could not parse the name is worse than a noisy one: silence is
     * indistinguishable from a clean pass.
     *
     * Trimming from the LEFT ONLY is what keeps this from guessing. The last
     * segment is the noun and everything before it is the qualifier, so
     * `stripe_customer_id` still resolves to nothing unless a `customers` table
     * genuinely exists, `external_ref_id` to nothing at all, and the
     * one-match-or-nothing rule in pass 2 plus the reasoned escape hatch are
     * unchanged.
     *
     * @return list<string>
     */
    private function stems(string $column): array
    {
        $stem = substr($column, 0, -3); // strip `_id`
        if ($stem === '') {
            return [];
        }

        $stems = [$stem];
        $segments = explode('_', $stem);
        while (count($segments) > 1) {
            array_shift($segments);
            $stems[] = implode('_', $segments);
        }

        return $stems;
    }

    /**
     * Whether two table names begin with the same `_`-delimited segment.
     *
     * `demo_catalog_item_notes` and `demo_catalog_items` do; that shared
     * prefix is what makes a bare `item_id` in the first mean the second.
     * `notification_deliveries` and `identity_providers` do not, so a bare
     * `provider_id` in the first means nothing this linter will guess at.
     */
    private function sharePrefix(string $a, string $b): bool
    {
        $headA = strtok($a, '_');
        $headB = strtok($b, '_');

        return $headA !== false && $headA === $headB;
    }

    /**
     * Whether the column carries a reasoned ignore annotation.
     *
     * The tag must be on the column's own line or the line immediately above
     * it, and must be followed by a reason. A bare tag does not silence
     * anything: the value of the annotation is that somebody decided, and a
     * decision nobody wrote down is indistinguishable from a muted alarm.
     */
    private function isIgnored(string $body, string $column, int $line): bool
    {
        $lines = explode("\n", $body);

        foreach ([$line, $line - 1] as $candidate) {
            if (!isset($lines[$candidate])) {
                continue;
            }
            if (preg_match(
                '/' . preg_quote(self::IGNORE_TAG, '/') . '[^\S\r\n]*:[^\S\r\n]*\S/',
                $lines[$candidate]
            ) === 1) {
                return true;
            }
        }

        unset($column);

        return false;
    }

    /**
     * Given the position of an opening `(`, the substring up to its matching
     * close paren.
     */
    private function balancedParenBody(string $sql, int $openParen): string
    {
        $depth = 0;
        $len = strlen($sql);
        for ($i = $openParen; $i < $len; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $openParen + 1, $i - $openParen - 1);
                }
            }
        }

        return substr($sql, $openParen + 1);
    }
}
