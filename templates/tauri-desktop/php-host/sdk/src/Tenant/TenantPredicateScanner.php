<?php

declare(strict_types=1);

namespace Whity\Sdk\Tenant;

/**
 * Portable static-analysis scanner enforcing the platform's #1 isolation
 * invariant (WC-192, generalised in WC-194): every SELECT/UPDATE/DELETE on a
 * TENANT-OWNED table must bind a `tenant_id` predicate, unless the table is a
 * sanctioned global one or the statement carries an explicit, reasoned ignore
 * annotation.
 *
 * This is the SINGLE SOURCE OF TRUTH for the scan logic. It lives in the
 * standalone SDK (depends on nothing but PHP) so it is reachable by BOTH
 * whity-core's CI guard AND out-of-repo plugins running the conformance kit —
 * no logic is duplicated and so none can drift. The set of tenant-owned /
 * global tables is NOT baked in: it is supplied per call via a
 * {@see TenantTableRegistry}, so the same engine polices core's schema and a
 * plugin's own tables alike.
 *
 * The scanner parses PHP source with the native tokenizer (no new
 * dependencies), stitches each SQL string literal together with the literals
 * that build the SAME statement (`.` concatenation and `$sql .= '...'` builder
 * fragments within the enclosing function), and decides per statement:
 *
 *   FLAG  — the statement reads/writes a tenant-owned table with NO `tenant_id`
 *           predicate and NO ignore annotation.
 *   PASS  — it binds a `tenant_id` predicate (`tenant_id = ?`, `t.tenant_id IN
 *           (...)`, `p.tenant_id = r.tenant_id`, a correlated `EXISTS (... WHERE
 *           ... tenant_id = ?)`, etc.), OR it only touches sanctioned global
 *           tables, OR it carries a `@tenant-guard-ignore: <reason>` annotation.
 *
 * Why "predicate" not "mention": a `tenant_id` appearing only in a SELECT list
 * or INSERT column list (e.g. `SELECT id, tenant_id FROM users WHERE id = ?`)
 * does NOT scope the row — the scanner requires `tenant_id` in a comparison
 * position (after WHERE/AND/ON or immediately before =, IN, IS, <, >, !=, <>).
 *
 * This is a heuristic SQL scanner, deliberately conservative: it errs toward
 * flagging (a developer can annotate a genuine exception with a reason) rather
 * than toward silently passing an unscoped tenant query.
 *
 * @phpstan-type Violation array{file: string, line: int, tables: list<string>, sql: string}
 */
final class TenantPredicateScanner
{
    /**
     * Inline annotation that suppresses a flag. MUST carry a non-empty reason,
     * so every sanctioned exception is self-documenting and reviewable:
     *
     *   // @tenant-guard-ignore: system-tenant (id 0) unscoped branch — sees all tenants
     *
     * The annotation may sit on the statement's own line(s) or on any of the
     * lines immediately above it (a contiguous comment block). The reason is the
     * text after the colon and must be non-empty.
     */
    public const IGNORE_TAG = '@tenant-guard-ignore:';

    /** SQL DML verbs the scanner polices. INSERT is out of scope (it sets, not selects, a row). */
    private const DML_VERBS = ['SELECT', 'UPDATE', 'DELETE'];

    private TenantTableRegistry $registry;

    public function __construct(TenantTableRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Scan a directory tree of PHP files and return every violation found.
     *
     * @return list<Violation>
     */
    public function scanDirectory(string $dir): array
    {
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var array<int, string> $paths */
        $paths = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $paths[] = $fileInfo->getPathname();
            }
        }
        // Deterministic ordering so CI output is stable across platforms.
        sort($paths);

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            foreach ($this->scanSource($source, $path) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Scan a single PHP source string. Exposed for unit testing.
     *
     * @return list<Violation>
     */
    public function scanSource(string $source, string $file = '<source>'): array
    {
        $tokens = \PhpToken::tokenize($source);

        // Pre-compute, per line, whether an ignore annotation with a non-empty
        // reason is in scope. A statement is annotated if the tag appears on its
        // own line(s) or on the contiguous comment lines directly above it.
        $annotatedLines = $this->annotatedLines($tokens);

        $statements = $this->extractStatements($tokens);

        $violations = [];
        foreach ($statements as $statement) {
            $sql = $statement['sql'];

            if (!$this->isPoliciedDml($sql)) {
                continue;
            }

            $tables = $this->tenantOwnedTablesReferenced($sql);
            if ($tables === []) {
                continue;
            }

            if ($this->hasTenantPredicate($sql)) {
                continue;
            }

            if ($this->statementIsAnnotated($statement['lines'], $annotatedLines)) {
                continue;
            }

            $violations[] = [
                'file' => $file,
                'line' => $statement['line'],
                'tables' => $tables,
                'sql' => $this->normalizeWhitespace($sql),
            ];
        }

        return $violations;
    }

    /**
     * Stitch SQL string literals into per-statement units.
     *
     * The unit of analysis is the PHP STATEMENT: we split the token stream on
     * statement boundaries (`;`) and structural braces, and within each statement
     * we concatenate EVERY string literal it contains — regardless of intervening
     * `implode(...)` / function calls — because they all flow into the one SQL
     * string the statement hands to the driver. This correctly merges
     * `'UPDATE users SET ' . implode(', ', $u) . ' WHERE id = ? AND tenant_id = ?'`
     * into a single scoped statement.
     *
     * To handle the `$sql = '...'; ... $sql .= ' WHERE ...';` builder pattern, a
     * statement that is an APPEND to (or read of) a `$var` also pulls in the
     * literals previously assigned/appended to that `$var` within the same
     * function scope. Builder state resets at every function boundary so it can
     * never bleed across methods.
     *
     * NOTE: dynamically-built WHERE clauses where the `tenant_id` fragment is a
     * CONDITIONAL array element (e.g. `$where[] = 'tenant_id = :tid'` only when
     * `$tenantId !== 0`, later `implode`d in) are deliberately NOT merged — the
     * fragment is only sometimes present, so such a statement is a genuine
     * system-tenant "sees all" exception that must be annotated, not silently
     * passed.
     *
     * @param list<\PhpToken> $tokens
     * @return list<array{sql: string, line: int, lines: list<int>}>
     */
    private function extractStatements(array $tokens): array
    {
        $statements = [];

        // SQL accumulated into a `$var` within the current function, so a
        // `$sql = '...'; $sql .= '...'; $db->prepare($sql);` chain is judged as
        // ONE reassembled statement at its consume site rather than per fragment.
        // Reset per function scope so builder state never bleeds across methods.
        /** @var array<string, array{sql: string, lines: list<int>}> $builderVars */
        $builderVars = [];

        $flushBuilders = static function () use (&$builderVars, &$statements): void {
            // Any builder var assigned SQL but never consumed (e.g. returned) is
            // still judged, so a deferred fragment can't escape the scanner.
            foreach ($builderVars as $b) {
                if (trim($b['sql']) !== '') {
                    $statements[] = [
                        'sql' => $b['sql'],
                        'line' => $b['lines'][0] ?? 0,
                        'lines' => $b['lines'] === [] ? [0] : $b['lines'],
                    ];
                }
            }
            $builderVars = [];
        };

        $count = count($tokens);
        $i = 0;
        while ($i < $count) {
            $token = $tokens[$i];
            $text = $token->text;

            // Function boundaries clear builder state (no cross-method bleed).
            if ($token->is(T_FUNCTION)) {
                $flushBuilders();
                $i++;
                continue;
            }

            // Statement / structural boundaries: nothing to collect, advance.
            if ($text === ';' || $text === '{' || $text === '}') {
                $i++;
                continue;
            }

            // A reference to a builder var that is NOT the LHS of an assignment to
            // it (e.g. `$db->prepare($sql)`, not `$sql .= ...`) CONSUMES the
            // accumulated SQL: judge it here and clear it so it isn't
            // double-counted at function end.
            if ($token->is(T_VARIABLE) && isset($builderVars[$text]) && !$this->isAssignmentLhs($tokens, $i)) {
                $b = $builderVars[$text];
                if (trim($b['sql']) !== '') {
                    $statements[] = [
                        'sql' => $b['sql'],
                        'line' => $b['lines'][0] ?? $token->line,
                        'lines' => $b['lines'] === [] ? [$token->line] : $b['lines'],
                    ];
                }
                unset($builderVars[$text]);
                $i++;
                continue;
            }

            if (!$token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                $i++;
                continue;
            }

            // Collect the whole statement that this first literal opens: every
            // string literal up to the next `;`/`{`/`}`, plus builder-var fills.
            [$parts, $lines, $end] = $this->collectStatementStrings($tokens, $i, $builderVars);

            // Is this statement an assignment/append to a `$var`? If so, DEFER it:
            // accumulate into the builder and judge it when the var is consumed.
            $assignedVar = $this->precedingAssignmentTarget($tokens, $i, $isAppend);
            if ($assignedVar !== null) {
                $prior = ($isAppend && isset($builderVars[$assignedVar]))
                    ? $builderVars[$assignedVar]
                    : ['sql' => '', 'lines' => []];
                $builderVars[$assignedVar] = [
                    'sql' => trim($prior['sql'] . ' ' . implode(' ', $parts)),
                    'lines' => array_values(array_unique([...$prior['lines'], ...$lines])),
                ];
                $i = $end + 1;
                continue;
            }

            // Not a builder assignment: judge this statement directly (e.g. an
            // inline `$db->prepare('SELECT ...')`).
            $sql = implode(' ', $parts);
            if (trim($sql) !== '') {
                $statements[] = [
                    'sql' => $sql,
                    'line' => $lines[0] ?? $token->line,
                    'lines' => $lines === [] ? [$token->line] : $lines,
                ];
            }

            $i = $end + 1;
        }

        $flushBuilders();

        return $statements;
    }

    /**
     * Collect every string-literal fragment in the statement opened at $start,
     * up to the next statement/structural boundary, substituting builder-vars.
     * Returns [parts, coveredLines, lastTokenIndexConsumed].
     *
     * @param list<\PhpToken>                                  $tokens
     * @param array<string, array{sql: string, lines: list<int>}> $builderVars
     * @return array{0: list<string>, 1: list<int>, 2: int}
     */
    private function collectStatementStrings(array $tokens, int $start, array $builderVars): array
    {
        $parts = [];
        $lines = [];
        $count = count($tokens);
        $i = $start;

        // A double-quoted string with interpolation (e.g. "... {$column} ...")
        // tokenizes as: `"`  T_ENCAPSED…  T_CURLY_OPEN `{` T_VARIABLE `}`
        // T_ENCAPSED…  `"`. The interior `{`/`}` are interpolation, NOT statement
        // braces, so we must not treat them as boundaries while inside the string.
        // If collection begins right after an opening `"` (the caller landed on
        // the first encapsed chunk), we are already inside such a string.
        $inDoubleQuote = $start > 0 && $tokens[$start - 1]->text === '"';

        for (; $i < $count; $i++) {
            $t = $tokens[$i];
            $text = $t->text;

            if ($text === '"') {
                // Toggle in/out of an interpolated double-quoted string literal.
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }

            if (!$inDoubleQuote && ($text === ';' || $text === '{' || $text === '}')) {
                break;
            }

            // Curly-open / curly-close that belong to interpolation are skipped.
            if ($inDoubleQuote && ($t->is(T_CURLY_OPEN) || $text === '{' || $text === '}')) {
                continue;
            }

            if ($t->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                $parts[] = self::literalValue($t);
                $lines[] = $t->line;
                continue;
            }

            // A builder variable READ outside a double-quoted interpolation pulls
            // in its accumulated SQL (the `$sql .= ...` builder pattern). Inside a
            // double-quoted string a T_VARIABLE is interpolation, not a builder.
            if (!$inDoubleQuote && $t->is(T_VARIABLE) && isset($builderVars[$t->text])) {
                $parts[] = $builderVars[$t->text]['sql'];
                foreach ($builderVars[$t->text]['lines'] as $bl) {
                    $lines[] = $bl;
                }
            }
        }

        return [$parts, array_values(array_unique($lines)), $i - 1];
    }

    /**
     * Whether the variable token at $i is the left-hand side of an assignment
     * (`$v = ...` or `$v .= ...`) — i.e. it is being written, not read.
     *
     * @param list<\PhpToken> $tokens
     */
    private function isAssignmentLhs(array $tokens, int $i): bool
    {
        $count = count($tokens);
        $j = $i + 1;
        while ($j < $count && $tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $j++;
        }
        if ($j >= $count) {
            return false;
        }

        return $tokens[$j]->text === '=' || $tokens[$j]->is(T_CONCAT_EQUAL);
    }

    /**
     * If the literal at $start begins the right-hand side of `$var = ...` or
     * `$var .= ...`, return the variable name (and set $isAppend); else null.
     *
     * @param list<\PhpToken> $tokens
     */
    private function precedingAssignmentTarget(array $tokens, int $start, ?bool &$isAppend = null): ?string
    {
        $isAppend = false;
        $i = $this->skipWhitespaceBackward($tokens, $start - 1);
        if ($i < 0) {
            return null;
        }
        $isConcatEqual = $tokens[$i]->is(T_CONCAT_EQUAL);
        if ($tokens[$i]->text !== '=' && !$isConcatEqual) {
            return null;
        }
        $isAppend = $isConcatEqual;
        $i = $this->skipWhitespaceBackward($tokens, $i - 1);
        if ($i < 0 || !$tokens[$i]->is(T_VARIABLE)) {
            return null;
        }

        return $tokens[$i]->text;
    }

    /**
     * Lines on which an ignore annotation (with a non-empty reason) is present.
     *
     * @param list<\PhpToken> $tokens
     * @return array<int, true>
     */
    private function annotatedLines(array $tokens): array
    {
        $lines = [];
        foreach ($tokens as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $pos = stripos($token->text, self::IGNORE_TAG);
            if ($pos === false) {
                continue;
            }
            $reason = trim(substr($token->text, $pos + strlen(self::IGNORE_TAG)));
            // Strip a trailing block-comment terminator if present.
            $reason = trim(rtrim($reason, '*/'));
            if ($reason === '') {
                continue; // An annotation with no reason does NOT suppress.
            }
            // A comment can span multiple lines; mark each line it covers.
            $lineCount = substr_count($token->text, "\n");
            for ($l = 0; $l <= $lineCount; $l++) {
                $lines[$token->line + $l] = true;
            }
        }

        return $lines;
    }

    /**
     * A statement is annotated if any of its lines, or the line directly above
     * its first line, carries an ignore annotation.
     *
     * @param list<int>        $statementLines
     * @param array<int, true> $annotatedLines
     */
    private function statementIsAnnotated(array $statementLines, array $annotatedLines): bool
    {
        if ($statementLines === []) {
            return false;
        }
        foreach ($statementLines as $line) {
            if (isset($annotatedLines[$line])) {
                return true;
            }
        }
        // Allow the annotation to sit on the line(s) directly above the statement.
        $first = min($statementLines);
        for ($above = $first - 1; $above >= $first - 3; $above--) {
            if (isset($annotatedLines[$above])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the SQL is a SELECT/UPDATE/DELETE the scanner polices.
     *
     * INSERT (including `INSERT ... ON CONFLICT ... DO UPDATE` upserts) is out of
     * scope: an INSERT names `tenant_id` as a VALUE, never as a predicate, and
     * creates a row rather than reading/mutating existing cross-tenant rows, so
     * it cannot leak another tenant's data. Such statements would otherwise trip
     * the scanner on the trailing `DO UPDATE SET`, so they are excluded up front.
     */
    private function isPoliciedDml(string $sql): bool
    {
        $upper = strtoupper($sql);

        if (preg_match('/\bINSERT\b/', $upper) === 1) {
            return false;
        }

        foreach (self::DML_VERBS as $verb) {
            if (preg_match('/\b' . $verb . '\b/', $upper) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tenant-owned tables referenced in a FROM / JOIN / UPDATE / DELETE FROM
     * position. Sanctioned global tables never count. Returns [] when the SQL
     * touches no tenant-owned table.
     *
     * @return list<string>
     */
    private function tenantOwnedTablesReferenced(string $sql): array
    {
        // Match table identifiers following FROM / JOIN / UPDATE (allow an
        // optional INTO for completeness, though INSERT is out of scope).
        // Quoted identifiers are stripped of their quotes.
        $pattern = '/\b(?:FROM|JOIN|UPDATE|INTO)\s+["`\']?([A-Za-z_][A-Za-z0-9_]*)["`\']?/i';
        if (preg_match_all($pattern, $sql, $matches) === false) {
            return [];
        }

        $found = [];
        foreach ($matches[1] as $candidate) {
            $table = strtolower($candidate);
            if ($this->registry->isGlobal($table)) {
                continue; // Allowlisted global table — never flag.
            }
            if ($this->registry->isTenantOwned($table)) {
                $found[$table] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Whether the SQL binds a `tenant_id` PREDICATE (not merely names the column
     * in a projection/insert list).
     *
     * A predicate is `tenant_id` (optionally `alias.tenant_id`) immediately
     * followed by a comparison/membership operator: =, IN, IS, <, >, <=, >=, !=,
     * <>. This matches every scoped form in the codebase — `WHERE tenant_id = ?`,
     * `AND t.tenant_id = :tid`, `(tenant_id = ? OR tenant_id IS NULL)`,
     * `p.tenant_id = r.tenant_id` (transitive join), and the correlated-EXISTS
     * sub-select — while rejecting a bare `SELECT id, tenant_id FROM ...`.
     */
    private function hasTenantPredicate(string $sql): bool
    {
        return preg_match(
            '/(?:[A-Za-z_][A-Za-z0-9_]*\.)?tenant_id\s*(?:=|!=|<>|<=|>=|<|>|\bIN\b|\bIS\b)/i',
            $sql
        ) === 1;
    }

    /** Collapse all whitespace runs to single spaces for compact reporting. */
    private function normalizeWhitespace(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** The literal text of a string token, with surrounding quotes removed. */
    private static function literalValue(\PhpToken $token): string
    {
        $text = $token->text;
        if ($token->is(T_CONSTANT_ENCAPSED_STRING) && strlen($text) >= 2) {
            $quote = $text[0];
            if ($quote === '"' || $quote === "'") {
                return substr($text, 1, -1);
            }
        }

        return $text;
    }

    /** @param list<\PhpToken> $tokens */
    private function skipWhitespaceBackward(array $tokens, int $i): int
    {
        while ($i >= 0 && $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $i--;
        }

        return $i;
    }
}
