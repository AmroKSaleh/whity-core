<?php

declare(strict_types=1);

namespace Whity\Core\Ui;

/**
 * The scanner behind the date-display guard (#1068).
 *
 * WHY A GUARD EXISTS AT ALL
 * -------------------------
 * `ui.hide_dates` promises a tenant that no date or time appears anywhere in
 * the interface. Unlike most settings, that promise is falsifiable by a SINGLE
 * screen: a tenant who turns it on and then finds one timestamp has been given a
 * broken promise, and the screen that leaks is by definition the one nobody
 * checked. Consolidating every date onto one path made the promise true once;
 * this guard is what keeps it true, because the next surface somebody builds
 * will format a date inline, nobody will notice, and a tenant who believes dates
 * are hidden will be wrong.
 *
 * The shape is deliberately the one this repository already trusts:
 * {@see \Whity\Core\Tenant\TenantPredicateGuard} enforces that every query on a
 * tenant-owned table carries its predicate, with a reasoned inline annotation as
 * the escape hatch. Same discipline, same escape hatch, different invariant.
 *
 * WHAT IT REFUSES, AND WHY EACH RULE EARNS ITS PLACE
 * --------------------------------------------------
 * Each rule corresponds to a way a date actually reached a screen in this
 * codebase before #1068 — none of them is hypothetical:
 *
 *   LOCALE_METHOD  `toLocaleDateString` / `toLocaleTimeString` / `toDateString`
 *                  / `toTimeString` / `toUTCString`. Seven screens carried their
 *                  own private `formatWhen`/`formatStamp`/`formatTimestamp`
 *                  built on these.
 *
 *   LOCALE_STRING  `toLocaleString`, but ONLY when the receiver reads as a date.
 *                  It is also how a NUMBER is formatted (`count.toLocaleString()`
 *                  appears twice in this tree, correctly), and a guard that fires
 *                  on correct code is worse than no guard at all — the standard
 *                  this repository already holds `ci-db-bool-guard.php` to.
 *
 *   INTL           `Intl.DateTimeFormat` / `Intl.RelativeTimeFormat`.
 *
 *   RAW_FALLBACK   A sanctioned formatter whose result falls back to something
 *                  other than a string literal: `dates.dateTime(x) ?? x`. This is
 *                  the subtle one and it is why the shared formatters return
 *                  `null` rather than the raw value. Six call sites wrote exactly
 *                  this — the formatter declines, the caller prints the wire
 *                  timestamp anyway, and the gate is bypassed by code that looks
 *                  defensive.
 *
 *   RAW_RENDER     A date-shaped MEMBER expression rendered directly in JSX:
 *                  `{ver.releasedAt}`, `{phase.data.issued_on}`,
 *                  `{item.timestamp}`. These printed the raw wire string,
 *                  unlocalised, long before any setting existed.
 *
 *   RAW_PROP       The same expression handed to a rendering prop:
 *                  `when: code.revoked_at`, `value={x.created_at}`.
 *
 * WHAT IT DELIBERATELY DOES NOT CATCH
 * -----------------------------------
 * A BARE identifier in JSX (`{createdAt}`) is not flagged, because `{ created_at }`
 * is also object destructuring and a shorthand property, and telling them apart
 * needs a TypeScript parser rather than a lexer. Requiring a dot — a MEMBER
 * expression, which is never valid destructuring — removes that whole class of
 * false positive and still catches every real leak found in this tree.
 *
 * A date INPUT is not a violation and never will be: `ui.hide_dates` is about
 * read-only rendering of the platform's own record of when work happened, not
 * about a control somebody types into. Blanking a form field would break the
 * form and delete a value the user themselves put there.
 *
 * WHY IT LEXES INSTEAD OF PARSING
 * -------------------------------
 * There is no TypeScript parser in a PHP CI job, and adding a Node step to run
 * one would put this check behind the `packages/**` path filter that already
 * skips the frontend job on some PRs. The lexer below is small and its job is
 * narrow: mask comments and string bodies so a mention of `toLocaleString` in a
 * doc block is not a violation — which matters, because the file this guard
 * protects discusses `toLocaleString` at length in its own header.
 *
 * @phpstan-type Violation array{file: string, line: int, code: string, snippet: string}
 */
final class DateDisplayScanner
{
    /**
     * Inline annotation that suppresses a flag.
     *
     * A REASON IS REQUIRED. An annotation with nothing after the colon does not
     * suppress anything, exactly as {@see \Whity\Sdk\Tenant\TenantPredicateScanner}
     * requires: the value of an escape hatch is the argument it forces somebody
     * to write down, and a bare tag is a silencer rather than a decision.
     *
     *   // @date-display-ignore: this is a time ZONE NAME, not an instant
     */
    public const IGNORE_TAG = '@date-display-ignore:';

    /**
     * The directory whose contents ARE the sanctioned path, and so are exempt.
     *
     * One directory, not a list of files, and not "any file whose name contains
     * format". The exemption is a statement about a module with a contract, and
     * keeping it to a path prefix is what stops it becoming a habit.
     */
    public const SANCTIONED_PATH = 'packages/features/src/datetime/';

    /**
     * The functions a call site is allowed to get a date from.
     *
     * `dates.*` are {@see useDateDisplay}'s members; the bare names are the pure
     * formatters inside the sanctioned directory, listed so that RAW_FALLBACK
     * still applies to them there.
     *
     * @var list<string>
     */
    private const SANCTIONED_CALLS = [
        'dates.date',
        'dates.dateTime',
        'dates.relative',
        'dates.age',
        'formatDate',
        'formatDateTime',
        'relativeAge',
    ];

    /**
     * Method names that are ALWAYS a date being formatted, whatever the receiver.
     *
     * @var list<string>
     */
    private const DATE_METHODS = [
        'toLocaleDateString',
        'toLocaleTimeString',
        'toDateString',
        'toTimeString',
        'toUTCString',
    ];

    /**
     * Rendering props — a value handed to one of these is on a screen.
     *
     * @var list<string>
     */
    private const RENDER_PROPS = [
        'label', 'value', 'primary', 'secondary', 'title', 'subtitle',
        'description', 'meta', 'detail', 'text', 'header', 'when', 'date',
        'issued', 'children', 'placeholder', 'tooltip',
    ];

    /**
     * Whether a field NAME reads as a recorded timestamp.
     *
     * Kept in step BY HAND with `isDateFieldName` in
     * packages/features/src/datetime/format.ts, which the schema-driven screens
     * use at runtime. Two copies in two languages is a real cost; the
     * alternative is a PHP CI job shelling out to Node, which is worse for the
     * reason the class docblock gives. The TS side has a unit test and this side
     * has one, and both list the same examples, so a change to one that is not
     * mirrored fails a test rather than going quiet.
     */
    public static function isDateFieldName(string $name): bool
    {
        return preg_match('/(^|_|\b)(at|date|time|timestamp|expires|deadline|since|until)$/i', $name) === 1
            || preg_match('/[a-z0-9](At|Date|Time|Timestamp|Expires|Deadline|Since|Until)$/', $name) === 1
            // `_on` in SNAKE case only: `issued_on`, `revoked_on`, `stage_on` are
            // this platform's vocabulary for a value the server has already
            // reduced to a calendar date. Not the camel-case `On`, which reads
            // as a boolean (`turnedOn`, `isOn`) far more often than as a date.
            || preg_match('/_on$/', $name) === 1;
    }

    /**
     * Scan a directory tree of .ts/.tsx files.
     *
     * @return list<Violation>
     */
    public function scanDirectory(string $dir): array
    {
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!$this->isScannable($path)) {
                continue;
            }
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
     * Whether a path is one this guard has anything to say about.
     *
     * Stories and tests are excluded: a Storybook fixture renders in Storybook,
     * and a test that asserts on a formatted date has to produce one. Both are
     * development surfaces no tenant ever sees, and flagging them would train
     * people to annotate rather than to think.
     */
    public function isScannable(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        if (!preg_match('/\.(ts|tsx)$/', $path)) {
            return false;
        }
        if (str_ends_with($path, '.d.ts')) {
            return false;
        }
        if (str_contains($path, self::SANCTIONED_PATH)) {
            return false;
        }

        foreach ([
            '/node_modules/', '/.next/', '/dist/', '/coverage/', '/storybook-static/',
            '/__tests__/', '/e2e/', '/.storybook/', '/test-results/',
        ] as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        return !preg_match('/\.(stories|test|spec)\.tsx?$/', $path);
    }

    /**
     * Scan a single source string. Exposed for unit testing.
     *
     * @return list<Violation>
     */
    public function scanSource(string $source, string $file = '<source>'): array
    {
        $lexed = $this->mask($source);
        $masked = $lexed['masked'];
        $annotated = $lexed['annotated'];
        $lines = preg_split('/\R/', $source) ?: [];

        $violations = [];

        foreach ($this->findLocaleMethods($masked) as $hit) {
            $violations[] = $hit;
        }
        foreach ($this->findIntl($masked) as $hit) {
            $violations[] = $hit;
        }
        foreach ($this->findRawFallbacks($masked) as $hit) {
            $violations[] = $hit;
        }
        foreach ($this->findRawRenders($masked) as $hit) {
            $violations[] = $hit;
        }
        foreach ($this->findRawProps($masked) as $hit) {
            $violations[] = $hit;
        }

        $out = [];
        foreach ($violations as $violation) {
            $line = $this->lineOf($source, $violation['offset']);
            if ($this->isAnnotated($line, $annotated)) {
                continue;
            }
            $out[] = [
                'file' => $file,
                'line' => $line,
                'code' => $violation['code'],
                'snippet' => trim($lines[$line - 1] ?? ''),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $out;
    }

    /**
     * An annotation suppresses a violation on its own line(s) or on one of the
     * three lines below it, mirroring the tenant guard's window: the natural
     * place to write the reason is directly above the code it excuses, and a JSX
     * comment block ends on the line before its subject.
     *
     * @param array<int, true> $annotated
     */
    private function isAnnotated(int $line, array $annotated): bool
    {
        for ($above = $line; $above >= $line - 3; $above--) {
            if (isset($annotated[$above])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Blank out comments and string bodies, and record which lines carry a
     * reasoned ignore annotation.
     *
     * The masked copy is the SAME LENGTH as the source — every removed character
     * becomes a space and every newline is preserved — so an offset found in it
     * is an offset in the original and line numbers need no bookkeeping.
     *
     * QUOTES SURVIVE MASKING. Only the body of a string is blanked, which is what
     * lets {@see findRawFallbacks} tell `?? '—'` (a literal, fine) from `?? raw`
     * (the wire value, not fine) without re-lexing.
     *
     * @return array{masked: string, annotated: array<int, true>}
     */
    private function mask(string $source): array
    {
        $length = strlen($source);
        $masked = $source;
        $annotated = [];
        $line = 1;
        $i = 0;

        // The last significant character seen, which is how a regex literal is
        // told from a division: `/` opens a regex only where a VALUE may begin.
        $lastSignificant = '';

        while ($i < $length) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : '';

            if ($char === "\n") {
                $line++;
                $i++;
                continue;
            }

            // ---- line comment ------------------------------------------------
            if ($char === '/' && $next === '/') {
                $end = strpos($source, "\n", $i);
                $end = $end === false ? $length : $end;
                $text = substr($source, $i, $end - $i);
                $this->recordAnnotation($text, $line, $annotated);
                $masked = substr_replace($masked, str_repeat(' ', $end - $i), $i, $end - $i);
                $i = $end;
                continue;
            }

            // ---- block comment (JSX comments arrive here too) -----------------
            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $text = substr($source, $i, $end - $i);
                $span = substr_count($text, "\n");
                $this->recordAnnotation($text, $line, $annotated, $span);
                $masked = substr_replace(
                    $masked,
                    preg_replace('/[^\n]/', ' ', $text) ?? '',
                    $i,
                    $end - $i
                );
                $line += $span;
                $i = $end;
                continue;
            }

            // ---- string / template literal ------------------------------------
            if ($char === "'" || $char === '"' || $char === '`') {
                $j = $i + 1;
                while ($j < $length) {
                    if ($source[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($source[$j] === $char) {
                        break;
                    }
                    $j++;
                }
                $j = min($j, $length - 1);
                $body = substr($source, $i + 1, $j - $i - 1);
                // The body is blanked, the quotes are not — see the docblock.
                $masked = substr_replace(
                    $masked,
                    preg_replace('/[^\n]/', ' ', $body) ?? '',
                    $i + 1,
                    $j - $i - 1
                );
                $line += substr_count($body, "\n");
                $lastSignificant = $char;
                $i = $j + 1;
                continue;
            }

            // ---- regex literal -------------------------------------------------
            if ($char === '/' && $this->opensRegex($lastSignificant)) {
                $j = $i + 1;
                $inClass = false;
                while ($j < $length && $source[$j] !== "\n") {
                    if ($source[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($source[$j] === '[') {
                        $inClass = true;
                    } elseif ($source[$j] === ']') {
                        $inClass = false;
                    } elseif ($source[$j] === '/' && !$inClass) {
                        break;
                    }
                    $j++;
                }
                if ($j < $length && $source[$j] === '/') {
                    $body = substr($source, $i, $j - $i + 1);
                    $masked = substr_replace($masked, str_repeat(' ', strlen($body)), $i, strlen($body));
                    $lastSignificant = '/';
                    $i = $j + 1;
                    continue;
                }
            }

            if (!ctype_space($char)) {
                $lastSignificant = $char;
            }
            $i++;
        }

        return [
            'masked' => $masked,
            'annotated' => $this->extendThroughComment($source, $masked, $annotated),
        ];
    }

    /**
     * Carry an annotation down through the rest of the comment block it opens.
     *
     * A run of `//` lines is several comment tokens to a lexer and ONE comment
     * to the person who wrote it, and the tag naturally goes on the first line
     * because that is where a sentence starts. Without this, a six-line reason
     * pushes its own subject out of the suppression window — which would teach
     * authors to write short unhelpful reasons, the opposite of what the
     * mechanism is for.
     *
     * A line counts as part of the block when the mask blanked all of it: the
     * mask replaces comment text with spaces, so a line that is whitespace in
     * the masked copy and not in the source is a line that held only a comment.
     *
     * @param array<int, true> $annotated
     * @return array<int, true>
     */
    private function extendThroughComment(string $source, string $masked, array $annotated): array
    {
        if ($annotated === []) {
            return $annotated;
        }

        $sourceLines = preg_split('/\R/', $source) ?: [];
        $maskedLines = preg_split('/\R/', $masked) ?: [];

        foreach (array_keys($annotated) as $line) {
            for ($l = $line + 1; $l <= count($sourceLines); $l++) {
                $raw = $sourceLines[$l - 1] ?? '';
                $blank = $maskedLines[$l - 1] ?? '';
                if (trim($raw) === '' || trim($blank) !== '') {
                    break;
                }
                $annotated[$l] = true;
            }
        }

        return $annotated;
    }

    /**
     * Whether a `/` at this point opens a regex literal rather than dividing.
     *
     * The standard heuristic: a regex may only start where a VALUE may start.
     * After an identifier, a number, or a closing bracket, `/` is division.
     */
    private function opensRegex(string $lastSignificant): bool
    {
        if ($lastSignificant === '') {
            return true;
        }

        return str_contains('(,=:[!&|?{};+-*%~^<>', $lastSignificant);
    }

    /**
     * @param array<int, true> $annotated
     */
    private function recordAnnotation(string $text, int $line, array &$annotated, int $span = 0): void
    {
        $pos = stripos($text, self::IGNORE_TAG);
        if ($pos === false) {
            return;
        }

        $reason = substr($text, $pos + strlen(self::IGNORE_TAG));
        // A JSX comment closes with `*/}`; a block comment with `*/`.
        $reason = trim(rtrim(trim($reason), '}/*'));
        if ($reason === '') {
            // An annotation with no reason does NOT suppress. The reason is the
            // whole point of the mechanism.
            return;
        }

        for ($l = $line; $l <= $line + $span; $l++) {
            $annotated[$l] = true;
        }
    }

    /**
     * @return list<array{offset: int, code: string}>
     */
    private function findLocaleMethods(string $masked): array
    {
        $hits = [];

        $pattern = '/\.\s*(' . implode('|', self::DATE_METHODS) . ')\s*\(/';
        if (preg_match_all($pattern, $masked, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $match) {
                $hits[] = ['offset' => (int) $match[1], 'code' => 'LOCALE_METHOD'];
            }
        }

        // `toLocaleString` only when the RECEIVER reads as a date — it is also
        // how a number is formatted, correctly, elsewhere in this tree.
        if (preg_match_all('/([\w$.\[\]()\'"` ]{0,90})\.\s*toLocaleString\s*\(/', $masked, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[1] as $index => $receiver) {
                $text = (string) $receiver[0];
                if (!$this->readsAsDate($text)) {
                    continue;
                }
                $hits[] = ['offset' => (int) $m[0][$index][1], 'code' => 'LOCALE_STRING'];
            }
        }

        return $hits;
    }

    /**
     * Whether a receiver expression is a date rather than a number.
     */
    private function readsAsDate(string $receiver): bool
    {
        if (preg_match('/\bDate\b/', $receiver) === 1) {
            return true;
        }

        // The trailing identifier: `row.created_at.toLocaleString()`.
        if (preg_match('/([A-Za-z_$][\w$]*)\s*$/', $receiver, $m) === 1) {
            return self::isDateFieldName($m[1]);
        }

        return false;
    }

    /**
     * @return list<array{offset: int, code: string}>
     */
    private function findIntl(string $masked): array
    {
        $hits = [];

        if (preg_match_all('/\bIntl\s*\.\s*(DateTimeFormat|RelativeTimeFormat)\b/', $masked, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $match) {
                $hits[] = ['offset' => (int) $match[1], 'code' => 'INTL'];
            }
        }

        return $hits;
    }

    /**
     * A sanctioned formatter whose `??` fallback is not a string literal.
     *
     * @return list<array{offset: int, code: string}>
     */
    private function findRawFallbacks(string $masked): array
    {
        $hits = [];
        $names = array_map(
            static fn (string $n): string => preg_quote($n, '/'),
            self::SANCTIONED_CALLS
        );

        if (preg_match_all('/\b(' . implode('|', $names) . ')\s*\(/', $masked, $m, PREG_OFFSET_CAPTURE) === 0) {
            return $hits;
        }

        $length = strlen($masked);
        foreach ($m[0] as $match) {
            $open = (int) $match[1] + strlen((string) $match[0]) - 1;
            $close = $this->matchingParen($masked, $open);
            if ($close === null) {
                continue;
            }

            $i = $close + 1;
            while ($i < $length && ctype_space($masked[$i])) {
                $i++;
            }
            if ($i + 1 >= $length || $masked[$i] !== '?' || $masked[$i + 1] !== '?') {
                continue;
            }

            $i += 2;
            while ($i < $length && ctype_space($masked[$i])) {
                $i++;
            }
            if ($i >= $length) {
                continue;
            }
            // A string LITERAL is a fine fallback, and so is a `t()` call:
            // both come from the catalogue rather than from the wire, and
            // neither can be the timestamp the formatter just declined to
            // print. Anything else may be, which is the bypass this rule exists
            // for.
            if ($masked[$i] === "'" || $masked[$i] === '"' || $masked[$i] === '`') {
                continue;
            }
            if (preg_match('/\\G\\s*t\\s*\\(/', $masked, $ignored, 0, $i) === 1) {
                continue;
            }

            $hits[] = ['offset' => (int) $match[1], 'code' => 'RAW_FALLBACK'];
        }

        return $hits;
    }

    private function matchingParen(string $s, int $open): ?int
    {
        $depth = 0;
        $length = strlen($s);
        for ($i = $open; $i < $length; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * A date-shaped MEMBER expression rendered directly: `{row.created_at}`.
     *
     * @return list<array{offset: int, code: string}>
     */
    private function findRawRenders(string $masked): array
    {
        $hits = [];

        $pattern = '/\{\s*([A-Za-z_$][\w$]*(?:\??\.[A-Za-z_$][\w$]*)+)\s*\}/';
        if (preg_match_all($pattern, $masked, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[1] as $index => $expression) {
                $segments = preg_split('/\??\./', (string) $expression[0]) ?: [];
                $last = (string) end($segments);
                if (!self::isDateFieldName($last)) {
                    continue;
                }
                $hits[] = ['offset' => (int) $m[0][$index][1], 'code' => 'RAW_RENDER'];
            }
        }

        return $hits;
    }

    /**
     * A date-shaped member expression handed straight to a rendering prop.
     *
     * @return list<array{offset: int, code: string}>
     */
    private function findRawProps(string $masked): array
    {
        $hits = [];

        $props = implode('|', self::RENDER_PROPS);
        $pattern = '/\b(' . $props . ')\s*[:=]\s*\{?\s*([A-Za-z_$][\w$]*(?:\??\.[A-Za-z_$][\w$]*)+)\s*[,}\n]/';
        if (preg_match_all($pattern, $masked, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[2] as $index => $expression) {
                $segments = preg_split('/\??\./', (string) $expression[0]) ?: [];
                $last = (string) end($segments);
                if (!self::isDateFieldName($last)) {
                    continue;
                }
                $hits[] = ['offset' => (int) $m[0][$index][1], 'code' => 'RAW_PROP'];
            }
        }

        return $hits;
    }

    private function lineOf(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
