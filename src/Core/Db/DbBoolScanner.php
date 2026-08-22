<?php

declare(strict_types=1);

namespace Whity\Core\Db;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static scanner for reads of a SQL boolean that depend on how the driver
 * happened to spell it (#891).
 *
 * WHAT IT FLAGS, AND WHY ONLY THIS
 * --------------------------------
 * A bare `(bool)` cast is fine on almost everything. It is only a hazard when
 * the value on the right came out of a database, because that is the one place
 * where the SAME logical value arrives in several different spellings — native
 * `bool`, `'1'`/`'0'`, `'t'`/`'f'`, or `'true'`/`'false'` from a `::text`
 * projection, the last of which a bare cast reads as TRUE in both directions.
 * So the rule is deliberately not "no `(bool)` casts". It is two narrow shapes:
 *
 *   1. `(bool) $var['col']` where `col` is a column some migration declares
 *      `BOOLEAN`. The column list is PARSED FROM THE MIGRATIONS rather than
 *      guessed from naming, so the guard knows `is_primary` is a boolean and
 *      `dry_run` is not, and it learns new columns the day they are added.
 *
 *   2. `(bool) $stmt->fetchColumn()` and friends — a value straight off a
 *      statement handle, where there is no key to look up at all.
 *
 * HOW IT AVOIDS CRYING WOLF
 * -------------------------
 * A guard that fires on correct code gets muted, and a muted guard is worse
 * than no guard, so both exemptions below are DERIVED from the source — nobody
 * has to remember to annotate the ordinary cases:
 *
 *   - REQUEST-SOURCED VARIABLES. `(bool) $body['enabled']` is not a database
 *     read even though `enabled` is a boolean column. Any variable assigned
 *     from `JsonBody::parsed()`, `json_decode()`, `$request->…`, `$_POST` or
 *     `$_GET` in the same file is treated as request-sourced and its subscripts
 *     are ignored. If a name is assigned from BOTH a request and a fetch in one
 *     file it is flagged rather than excused, because then the guard genuinely
 *     cannot tell which one reached the cast.
 *
 *   - CONSTANT PROJECTIONS. `(bool) $stmt->fetchColumn()` after
 *     `SELECT 1 FROM … LIMIT 1` or `SELECT COUNT(…)` is an existence probe, not
 *     a boolean read: the projected value is a literal the database never
 *     spells two ways. Those are exempt by looking at the nearest preceding SQL
 *     literal, so the existence probes already in `src/Auth` stay silent while
 *     `SELECT EXISTS (…)` — which really does return a boolean — does not.
 *
 * What is left after those two is small enough to annotate by hand with
 * `@db-bool-ignore: <reason>`, and the reason is MANDATORY: a bare tag with no
 * justification is itself reported, so the escape hatch cannot become a way to
 * silence the guard without saying anything.
 *
 * LIMITS, STATED PLAINLY. Provenance is tracked per FILE, not per function, and
 * there is no cross-function dataflow: a row handed to a mapper as a parameter
 * has no assignment to inspect, so it is flagged — which is correct here, since
 * that is exactly the shape every instance in #891 had. The scanner reads
 * tokens, not types; it cannot prove a value came from PDO, only that it is
 * shaped like a database read and is not shaped like a request read.
 *
 * @phpstan-type Violation array{file: string, line: int, snippet: string, reason: string}
 */
final class DbBoolScanner
{
    /** Inline annotation that suppresses a flag. The reason after it is required. */
    public const IGNORE_TAG = '@db-bool-ignore:';

    /** Statement-handle methods whose return value is a raw database read. */
    private const FETCH_METHODS = ['fetch', 'fetchColumn', 'fetchAll', 'fetchObject'];

    /** How many lines above a flagged line an annotation may sit. */
    private const ANNOTATION_LOOKBEHIND = 3;

    /** Call shapes that mark a variable as carrying REQUEST data, not a DB row. */
    private const REQUEST_SOURCES = [
        'JsonBody::parsed', 'json_decode', '$request->', '$_POST', '$_GET',
    ];

    /** Call shapes that mark a variable as carrying a DATABASE row. */
    private const FETCH_SOURCES = ['->fetch(', '->fetchAll(', '->fetchColumn(', '->fetchObject('];

    /**
     * Columns declared BOOLEAN by some migration, as a set.
     *
     * @var array<string, true>
     */
    private array $booleanColumns = [];

    /** @param iterable<string> $booleanColumns */
    public function __construct(iterable $booleanColumns)
    {
        foreach ($booleanColumns as $column) {
            $this->booleanColumns[strtolower($column)] = true;
        }
    }

    /**
     * Build a scanner that knows core's (and any in-tree plugin's) boolean
     * columns by reading the migrations, so the guard cannot fall behind the
     * schema.
     *
     * @param string ...$migrationRoots Directories searched recursively for
     *                                  migration files. Defaults to core's.
     */
    public static function fromMigrations(string ...$migrationRoots): self
    {
        if ($migrationRoots === []) {
            $projectRoot = dirname(__DIR__, 3);
            $migrationRoots = [$projectRoot . '/database/migrations'];
            foreach (glob($projectRoot . '/plugins/*/Migrations') ?: [] as $pluginMigrations) {
                $migrationRoots[] = $pluginMigrations;
            }
        }

        $columns = [];
        foreach ($migrationRoots as $root) {
            foreach (self::phpFilesIn($root) as $file) {
                $columns = array_merge($columns, self::booleanColumnsIn((string) file_get_contents($file)));
            }
        }

        return new self(array_unique($columns));
    }

    /**
     * Every column name a migration source declares as BOOLEAN.
     *
     * Matches both `flag BOOLEAN NOT NULL` inside a CREATE TABLE and
     * `ADD COLUMN [IF NOT EXISTS] flag BOOLEAN`, by taking the identifier
     * immediately preceding the type keyword in either case.
     *
     * @return list<string>
     */
    public static function booleanColumnsIn(string $source): array
    {
        if (!preg_match_all('/\b([a-z_][a-z0-9_]*)\s+BOOLEAN\b/i', $source, $matches)) {
            return [];
        }

        $columns = [];
        foreach ($matches[1] as $column) {
            $lower = strtolower($column);
            // Keywords that can sit immediately before the type, never a name.
            if (in_array($lower, ['column', 'exists', 'as', 'is', 'not', 'type'], true)) {
                continue;
            }
            $columns[] = $lower;
        }

        return $columns;
    }

    /**
     * Scan a directory tree of PHP files.
     *
     * @return list<Violation>
     */
    public function scanDirectory(string $dir): array
    {
        $violations = [];
        foreach (self::phpFilesIn($dir) as $file) {
            foreach ($this->scanSource((string) file_get_contents($file), $file) as $violation) {
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
        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = token_get_all($source);
        $significant = self::significantTokens($tokens);

        $requestSourced = self::variablesAssignedFrom($significant, self::REQUEST_SOURCES);
        $fetchSourced = self::variablesAssignedFrom($significant, self::FETCH_SOURCES);
        // Ambiguous names are NOT excused: if one file assigns `$row` from both
        // a request and a statement, the scanner cannot say which reached the
        // cast, and guessing in the excusing direction is how a guard goes
        // quiet on a real defect.
        $exemptVariables = array_diff_key($requestSourced, $fetchSourced);

        $annotations = self::annotationLines($tokens);
        $sqlLiterals = self::sqlLiterals($significant);

        $violations = [];
        foreach ($this->findCasts($significant, $sqlLiterals) as $cast) {
            $variable = $cast['variable'] ?? null;
            if ($variable !== null && isset($exemptVariables[$variable])) {
                continue;
            }
            if (self::isAnnotated($cast['line'], $annotations)) {
                continue;
            }
            $violations[] = [
                'file' => $file,
                'line' => $cast['line'],
                'snippet' => $cast['snippet'],
                'reason' => $cast['reason'],
            ];
        }

        foreach (self::unreasonedAnnotationLines($tokens) as $line) {
            $violations[] = [
                'file' => $file,
                'line' => $line,
                'snippet' => self::IGNORE_TAG,
                'reason' => 'The ' . self::IGNORE_TAG . ' annotation carries no reason. '
                    . 'State why this value is not a driver-dependent boolean read.',
            ];
        }

        usort($violations, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return array_values($violations);
    }

    /**
     * Locate every `(bool)` cast whose operand is shaped like a database read.
     *
     * @param list<array{token: int|string, text: string, line: int}> $tokens
     * @param list<array{line: int, sql: string}>                     $sqlLiterals
     * @return list<array{line: int, snippet: string, reason: string, variable?: string}>
     */
    private function findCasts(array $tokens, array $sqlLiterals): array
    {
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['token'] !== T_BOOL_CAST) {
                continue;
            }

            // Step over any wrapping parentheses: `(bool)($row['x'] ?? false)`.
            $j = $i + 1;
            while ($j < $count && $tokens[$j]['text'] === '(') {
                $j++;
            }
            if ($j >= $count || $tokens[$j]['token'] !== T_VARIABLE) {
                continue;
            }

            $variable = $tokens[$j]['text'];
            $line = $tokens[$i]['line'];

            // Shape 1: $var['literal_key']
            if (
                isset($tokens[$j + 1], $tokens[$j + 2], $tokens[$j + 3])
                && $tokens[$j + 1]['text'] === '['
                && $tokens[$j + 2]['token'] === T_CONSTANT_ENCAPSED_STRING
                && $tokens[$j + 3]['text'] === ']'
            ) {
                $key = strtolower(trim($tokens[$j + 2]['text'], "'\""));
                if (!isset($this->booleanColumns[$key])) {
                    continue;
                }
                $found[] = [
                    'line' => $line,
                    'variable' => $variable,
                    'snippet' => "(bool) {$variable}['{$key}']",
                    'reason' => "`{$key}` is declared BOOLEAN by a migration, so this reads a database "
                        . 'boolean through a cast whose answer depends on how the driver spells it.',
                ];
                continue;
            }

            // Shape 2: $stmt->fetchColumn() / ->fetch() / ->fetchAll() / ->fetchObject()
            if (
                isset($tokens[$j + 1], $tokens[$j + 2])
                && $tokens[$j + 1]['token'] === T_OBJECT_OPERATOR
                && in_array($tokens[$j + 2]['text'], self::FETCH_METHODS, true)
            ) {
                $method = $tokens[$j + 2]['text'];
                if (self::projectsAConstant($line, $sqlLiterals)) {
                    continue;
                }
                $found[] = [
                    'line' => $line,
                    'snippet' => "(bool) {$variable}->{$method}()",
                    'reason' => 'This casts a value straight off a statement handle. If the statement '
                        . 'projects a boolean (e.g. `SELECT EXISTS (…)`), the cast depends on the '
                        . "driver's spelling of it.",
                ];
            }
        }

        return $found;
    }

    /**
     * Whether the SQL nearest above $line projects a literal constant, which no
     * driver returns in more than one spelling — an existence probe, not a
     * boolean column read.
     *
     * @param list<array{line: int, sql: string}> $sqlLiterals
     */
    private static function projectsAConstant(int $line, array $sqlLiterals): bool
    {
        $nearest = null;
        foreach ($sqlLiterals as $literal) {
            if ($literal['line'] <= $line) {
                $nearest = $literal['sql'];
            }
        }

        return $nearest !== null
            && preg_match('/^\s*SELECT\s+(?:1\b|COUNT\s*\()/i', $nearest) === 1;
    }

    /**
     * Every string literal in the file that reads as a SELECT statement, in
     * source order.
     *
     * @param list<array{token: int|string, text: string, line: int}> $tokens
     * @return list<array{line: int, sql: string}>
     */
    private static function sqlLiterals(array $tokens): array
    {
        $literals = [];
        foreach ($tokens as $token) {
            if (!in_array($token['token'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $text = trim($token['text'], "'\"");
            if (preg_match('/^\s*SELECT\b/i', $text) === 1) {
                $literals[] = ['line' => $token['line'], 'sql' => $text];
            }
        }

        return $literals;
    }

    /**
     * Variables assigned from any of the given call shapes, as a set.
     *
     * Also understands `foreach ($stmt->fetchAll(…) as $row)`, which is how
     * most row mappers in this codebase get their row.
     *
     * @param list<array{token: int|string, text: string, line: int}> $tokens
     * @param list<string>                                            $markers
     * @return array<string, true>
     */
    private static function variablesAssignedFrom(array $tokens, array $markers): array
    {
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            // `$var = <expr>` — inspect the few tokens that follow the `=`.
            if ($tokens[$i]['token'] === T_VARIABLE && ($tokens[$i + 1]['text'] ?? '') === '=') {
                $window = '';
                for ($k = $i + 2; $k < min($i + 12, $count); $k++) {
                    $window .= $tokens[$k]['text'];
                }
                if (self::windowMatches($window, $markers)) {
                    $names[$tokens[$i]['text']] = true;
                }
            }

            // `foreach (<expr> as $row)` / `as $k => $row`
            if ($tokens[$i]['token'] === T_FOREACH) {
                $window = '';
                $asIndex = null;
                for ($k = $i + 1; $k < min($i + 40, $count); $k++) {
                    if ($tokens[$k]['token'] === T_AS) {
                        $asIndex = $k;
                        break;
                    }
                    $window .= $tokens[$k]['text'];
                }
                if ($asIndex === null || !self::windowMatches($window, $markers)) {
                    continue;
                }
                for ($k = $asIndex + 1; $k < min($asIndex + 8, $count); $k++) {
                    if ($tokens[$k]['text'] === ')') {
                        break;
                    }
                    if ($tokens[$k]['token'] === T_VARIABLE) {
                        $names[$tokens[$k]['text']] = true;
                    }
                }
            }
        }

        return $names;
    }

    /** @param list<string> $markers */
    private static function windowMatches(string $window, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($window, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lines carrying an ignore annotation WITH a reason.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, true>
     */
    private static function annotationLines(array $tokens): array
    {
        $lines = [];
        foreach ($tokens as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match('/' . preg_quote(self::IGNORE_TAG, '/') . '\s*\S+/', $token[1]) !== 1) {
                continue;
            }
            // A multi-line comment annotates every line it spans, plus the line
            // that follows it (the usual "comment above the code" placement).
            $span = substr_count($token[1], "\n");
            for ($l = $token[2]; $l <= $token[2] + $span + 1; $l++) {
                $lines[$l] = true;
            }
        }

        return $lines;
    }

    /**
     * Lines where the ignore tag appears with nothing after it — reported, so
     * the escape hatch always costs a sentence.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<int>
     */
    private static function unreasonedAnnotationLines(array $tokens): array
    {
        $lines = [];
        foreach ($tokens as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (!str_contains($token[1], self::IGNORE_TAG)) {
                continue;
            }
            if (preg_match('/' . preg_quote(self::IGNORE_TAG, '/') . '\s*\S+/', $token[1]) !== 1) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /** @param array<int, true> $annotations */
    private static function isAnnotated(int $line, array $annotations): bool
    {
        for ($l = $line; $l >= $line - self::ANNOTATION_LOOKBEHIND; $l--) {
            if (isset($annotations[$l])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop whitespace and comments, and flatten to a uniform shape so the
     * matchers above can index neighbours without re-checking types.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{token: int|string, text: string, line: int}>
     */
    private static function significantTokens(array $tokens): array
    {
        $out = [];
        $line = 1;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out[] = ['token' => $token[0], 'text' => $token[1], 'line' => $token[2]];
                continue;
            }
            $out[] = ['token' => $token, 'text' => $token, 'line' => $line];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
