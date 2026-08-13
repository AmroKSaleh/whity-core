<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Derives the ENGLISH catalogue from the code that renders it.
 *
 * WHY THIS EXISTS
 * ---------------
 * `t('login.email.label', 'Email')` carries both halves of an English catalogue
 * entry at the call site: the key AND the source string. So the English
 * catalogue is not a document somebody maintains next to the code — it is a
 * PROJECTION of the code, and this class computes it. Nothing hand-enters an
 * English string twice, and no screen can drift from its own strings.
 *
 * That property is what makes a large extraction effort safe to fan out: an
 * agent converting a screen writes `t()` calls, regenerates, and the catalogue
 * follows. No two agents edit the same list of strings.
 *
 * WHAT IT CANNOT SEE, AND SAYS SO
 * -------------------------------
 * A scanner reads text; it does not evaluate JavaScript. `t(entry.key)` and
 * ``t(`status.${row.state}`)`` hide their keys behind a value that only exists
 * at runtime. This class does NOT quietly skip those — it CANNOT read the key,
 * but it CAN see that a key is being hidden, and it reports every such call site
 * as a problem until the file declares what those keys are:
 *
 *     /**
 *      * @i18n-keys auth
 *      *   sso.error.denied = Sign-in was cancelled.
 *      *   sso.error.failed = Sign-in failed. Please try again.
 *      *\/
 *
 * or, when the keys genuinely cannot be enumerated in code at all (they come
 * from the database, from a plugin, from user data), acknowledges that with a
 * REASON — the same doctrine as `@tenant-guard-ignore:`, because a decision with
 * no reason recorded is indistinguishable from a silenced alarm:
 *
 *     // @i18n-dynamic-ignore: field labels are tenant data, not source strings
 *
 * Both tags are FILE-scoped. That is deliberately coarse: the declaration
 * usually sits on the lookup table forty lines above the call, and a rule that
 * demanded adjacency would push authors to duplicate it. The cost is that a
 * SECOND dynamic call added to an already-declaring file raises no new alarm —
 * accepted, because these files are small and the declaration is right there in
 * the diff.
 *
 * WHICH DOMAIN A CALL BELONGS TO
 * ------------------------------
 * `useTranslation(domain)` binds the translate function, so the binding names
 * the domain:
 *
 *   1. `const t = useTranslation('auth')` → every `t(...)` in the file is `auth`.
 *   2. A helper that takes the function as a parameter (`f(t: TranslateFn)`)
 *      inherits the file's domain, PROVIDED the file has exactly one. This is
 *      how `ssoErrorMessage(t, reason)` on the sign-in screen resolves.
 *   3. Anything else — two domains in one file plus a helper, or a domain that
 *      is itself a variable — is reported, not guessed.
 *
 * Mirrors src/Core/Tenant/TenantPredicateGuard.php: pure static analysis, no
 * HTTP, no database, a `scanSource()` seam for unit tests, and the CI shell
 * lives in scripts/ (scripts/ci-i18n-catalog-drift.php).
 *
 * @phpstan-type ExtractedEntry array{domain: string, key: string, text: string, file: string, line: int}
 * @phpstan-type ExtractionProblem array{file: string, line: int, code: string, message: string}
 * @phpstan-type ExtractionReport array{catalog: array<string, array<string, string>>, problems: list<ExtractionProblem>, entries: list<ExtractedEntry>, files: int}
 */
final class TranslationKeyExtractor
{
    /**
     * Declares the keys a dynamic call site reaches, and their English text.
     *
     * Interpolated into every remediation message so the message can never
     * drift from the parser.
     */
    public const KEYS_TAG = '@i18n-keys';

    /**
     * Acknowledges a dynamic call site whose keys cannot be enumerated in code.
     * A REASON is mandatory (the colon is part of the tag, exactly as with
     * {@see \Whity\Sdk\Tenant\TenantPredicateScanner::IGNORE_TAG}).
     */
    public const DYNAMIC_IGNORE_TAG = '@i18n-dynamic-ignore:';

    /** The hook whose argument names the domain a translate function serves. */
    private const TRANSLATE_HOOK = 'useTranslation';

    /** The exported type of a translate function passed between functions. */
    private const TRANSLATE_FN_TYPE = 'TranslateFn';

    /**
     * Where user-facing strings live, relative to the repository root.
     *
     * Everything a person reads on screen comes from one of these. Extending
     * the list is how a new surface (another app shell, another package) joins
     * the catalogue.
     *
     * @var list<string>
     */
    public const SOURCE_ROOTS = [
        'web/app',
        'web/components',
        'web/hooks',
        'web/lib',
        'packages/ui/src',
        'packages/features/src',
    ];

    /**
     * Paths that contain `t()` calls which are NOT product strings.
     *
     * Tests and stories assert against invented keys; EXAMPLE.tsx is the i18n
     * package's own documentation and demonstrates the API with placeholder
     * text ('Default Title'). Seeding either into the database would put words
     * no user will ever see in front of a translator.
     *
     * @var list<string>
     */
    public const EXCLUDED_PATHS = [
        '/node_modules/',
        '/.next/',
        '/__tests__/',
        '/__mocks__/',
        '.test.',
        '.spec.',
        '.stories.',
        'packages/features/src/i18n/EXAMPLE.tsx',
    ];

    /** @var list<string> */
    private const SCANNED_EXTENSIONS = ['ts', 'tsx'];

    /**
     * A key is a dot-delimited path whose segments start lowercase:
     * `login.error.invalidCredentials`. Named for the SCREEN, never for the
     * English words — rewording copy must never rename a key, because a rename
     * orphans that string in every other language at once.
     */
    private const KEY_PATTERN = '/^[a-z][a-zA-Z0-9_]*(\.[a-z][a-zA-Z0-9_]*)*$/';

    /**
     * Characters after which a `/` may legally begin a regular expression.
     *
     * `<` is deliberately absent: it would make the closing `</` of every JSX
     * element a candidate, so `</div><span>{t('a', 'b')}</span>` would read as
     * one regex literal. Nothing is dropped when that happens — spans are
     * copied out verbatim and the call sites are re-parsed from their own
     * parenthesis — but the quote pairing shifts, and a shifted quote means a
     * `//` further down the line stops being recognised as a comment. The keys
     * inside that comment then enter the catalogue as if they were real.
     */
    private const REGEX_PRECEDING_PUNCTUATION = '(,=:[!&|?{};+-*%~^';

    /** Keywords after which a `/` may legally begin a regular expression. */
    private const REGEX_PRECEDING_KEYWORDS = 'return|typeof|case|in|of|void|delete|await|yield|new|throw|do|else';

    public function __construct(
        private readonly string $baseDir,
    ) {
    }

    /**
     * Scan the repository's source roots and fold every call site into one
     * catalogue, grouped by domain and sorted for a stable diff.
     *
     * @param list<string>|null $roots Repo-relative roots; defaults to {@see self::SOURCE_ROOTS}.
     * @return ExtractionReport
     */
    public function extract(?array $roots = null): array
    {
        $files = [];
        foreach ($roots ?? self::SOURCE_ROOTS as $root) {
            foreach ($this->filesUnder($this->baseDir . '/' . $root) as $file) {
                $files[] = $file;
            }
        }
        sort($files);

        return $this->extractFiles($files);
    }

    /**
     * Fold a specific list of absolute file paths into a catalogue.
     *
     * @param list<string> $files
     * @return ExtractionReport
     */
    public function extractFiles(array $files): array
    {
        /** @var list<ExtractedEntry> $entries */
        $entries = [];
        /** @var list<ExtractionProblem> $problems */
        $problems = [];

        foreach ($files as $file) {
            $source = @file_get_contents($file);
            if ($source === false) {
                $problems[] = [
                    'file' => $this->relative($file),
                    'line' => 0,
                    'code' => 'unreadable',
                    'message' => 'File could not be read.',
                ];
                continue;
            }

            $scan = $this->scanSource($source, $this->relative($file));
            foreach ($scan['entries'] as $entry) {
                $entries[] = $entry;
            }
            foreach ($scan['problems'] as $problem) {
                $problems[] = $problem;
            }
        }

        $catalog = [];
        /** @var array<string, array<string, ExtractedEntry>> $firstSeen */
        $firstSeen = [];

        foreach ($entries as $entry) {
            $domain = $entry['domain'];
            $key = $entry['key'];
            $previous = $firstSeen[$domain][$key] ?? null;

            if ($previous === null) {
                $firstSeen[$domain][$key] = $entry;
                $catalog[$domain][$key] = $entry['text'];
                continue;
            }

            if ($previous['text'] !== $entry['text']) {
                $problems[] = [
                    'file' => $entry['file'],
                    'line' => $entry['line'],
                    'code' => 'conflicting-source-text',
                    'message' => sprintf(
                        '`%s` in domain `%s` has two different English strings: %s says %s, this says %s. '
                        . 'One key is one string — give the second wording its own key.',
                        $key,
                        $domain,
                        $previous['file'] . ':' . $previous['line'],
                        self::quote($previous['text']),
                        self::quote($entry['text'])
                    ),
                ];
            }
        }

        foreach ($catalog as $domain => $keys) {
            ksort($keys);
            $catalog[$domain] = $keys;
        }
        ksort($catalog);

        usort($problems, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['code']] <=> [$b['file'], $b['line'], $b['code']];
        });

        return [
            'catalog' => $catalog,
            'problems' => array_values($problems),
            'entries' => $entries,
            'files' => count($files),
        ];
    }

    /**
     * Scan one file's source text.
     *
     * Exposed for unit testing (the same seam as
     * {@see \Whity\Core\Tenant\TenantPredicateGuard::scanSource()}), and used by
     * {@see self::extractFiles()} for the real thing.
     *
     * @return array{entries: list<ExtractedEntry>, problems: list<ExtractionProblem>}
     */
    public function scanSource(string $source, string $file = '<source>'): array
    {
        /** @var list<ExtractedEntry> $entries */
        $entries = [];
        /** @var list<ExtractionProblem> $problems */
        $problems = [];

        [$code, $comments] = self::splitComments($source);

        $declarations = self::parseAnnotations($comments, $file, $problems);
        $hasDeclaration = $declarations['keys'] !== [] || $declarations['ignored'];

        foreach ($declarations['keys'] as $declared) {
            $entries[] = $declared;
        }

        $bindings = self::translateBindings($code, $file, $problems);
        if ($bindings['names'] === []) {
            return ['entries' => $entries, 'problems' => $problems];
        }

        $resolvedDomains = array_values(array_unique(array_filter(
            $bindings['names'],
            static fn (?string $domain): bool => $domain !== null
        )));
        $soleDomain = count($resolvedDomains) === 1 ? $resolvedDomains[0] : null;

        foreach (self::callSites($code, array_keys($bindings['names'])) as $site) {
            $line = self::lineAt($code, $site['offset']);
            $domain = $bindings['names'][$site['name']] ?? $soleDomain;

            $key = $site['args'] === [] ? null : self::literalValue($site['args'][0]);

            if ($key === null) {
                if (!$hasDeclaration) {
                    $problems[] = [
                        'file' => $file,
                        'line' => $line,
                        'code' => 'undeclared-dynamic-key',
                        'message' => sprintf(
                            'A translation key here is computed, so no scanner can read it: %s(%s…). '
                            . 'Declare the keys it can reach with a `%s <domain>` block listing '
                            . '`key = English text`, or — if they genuinely cannot be enumerated in code — '
                            . 'record why with `// %s <reason>`.',
                            $site['name'],
                            trim($site['args'][0] ?? ''),
                            self::KEYS_TAG,
                            self::DYNAMIC_IGNORE_TAG
                        ),
                    ];
                }
                continue;
            }

            if ($domain === null) {
                $problems[] = [
                    'file' => $file,
                    'line' => $line,
                    'code' => 'unresolved-domain',
                    'message' => sprintf(
                        'Cannot tell which domain `%s` belongs to: `%s` was not bound by a literal '
                        . '%s(\'…\') call in this file, and the file does not have exactly one domain to '
                        . 'inherit. Bind it locally, or declare the key with a `%s <domain>` block.',
                        $key,
                        $site['name'],
                        self::TRANSLATE_HOOK,
                        self::KEYS_TAG
                    ),
                ];
                continue;
            }

            if (!self::isValidKey($key)) {
                $problems[] = [
                    'file' => $file,
                    'line' => $line,
                    'code' => 'invalid-key',
                    'message' => sprintf(
                        '`%s` is not a well-formed key. Keys are dot-delimited paths whose segments '
                        . 'start with a lowercase letter, named for the screen rather than the English '
                        . 'words: `login.email.label`, not `enter_your_email`.',
                        $key
                    ),
                ];
                continue;
            }

            $text = count($site['args']) > 1 ? self::literalValue($site['args'][1]) : null;

            if ($text === null || $text === '') {
                if (isset($declarations['declaredKeys'][$domain][$key])) {
                    // The English text came from an @i18n-keys block instead.
                    continue;
                }
                $problems[] = [
                    'file' => $file,
                    'line' => $line,
                    'code' => 'missing-source-text',
                    'message' => sprintf(
                        '`%s` has no English source string. Always pass the English text as the second '
                        . 'argument — it is what renders before the bundle arrives, what renders if the '
                        . 'key was never seeded, what a reviewer reads in the diff, and the only place '
                        . 'the English catalogue comes from.',
                        $key
                    ),
                ];
                continue;
            }

            $entries[] = [
                'domain' => $domain,
                'key' => $key,
                'text' => $text,
                'file' => $file,
                'line' => $line,
            ];
        }

        return ['entries' => $entries, 'problems' => $problems];
    }

    /** Whether a key follows the naming convention. */
    public static function isValidKey(string $key): bool
    {
        return $key !== '' && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Every scannable file under a directory, in a deterministic order.
     *
     * @return list<string>
     */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            if (!in_array($fileInfo->getExtension(), self::SCANNED_EXTENSIONS, true)) {
                continue;
            }
            $path = str_replace('\\', '/', $fileInfo->getPathname());
            if (self::isExcluded($path)) {
                continue;
            }
            $paths[] = $path;
        }

        sort($paths);

        return $paths;
    }

    private static function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_PATHS as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Repo-relative, slash-normalised, so CI output reads the same on every platform. */
    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $this->baseDir) . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Split source into (code with comments blanked out, the comments).
     *
     * Comments must come OUT before scanning: this repository's own reference
     * conversion documents the anti-pattern `t('welcome') + siteName` inside a
     * JSX comment, and a naive scan would seed `welcome` as a real key. They
     * must also come out INTACT, because the @i18n-keys declarations live in
     * them. Blanking preserves byte offsets and newlines, so line numbers
     * reported against the stripped code still point at the real source.
     *
     * @return array{0: string, 1: list<array{line: int, text: string}>}
     */
    private static function splitComments(string $src): array
    {
        $len = strlen($src);
        $out = '';
        $comments = [];
        $line = 1;
        $i = 0;
        $previous = '';

        while ($i < $len) {
            $char = $src[$i];
            $next = $i + 1 < $len ? $src[$i + 1] : '';

            if ($char === '/' && $next === '/') {
                $end = strpos($src, "\n", $i);
                $end = $end === false ? $len : $end;
                $comments[] = ['line' => $line, 'text' => substr($src, $i + 2, $end - $i - 2)];
                $out .= str_repeat(' ', $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $close = strpos($src, '*/', $i + 2);
                $end = $close === false ? $len : $close + 2;
                $chunk = substr($src, $i, $end - $i);
                $comments[] = ['line' => $line, 'text' => $chunk];
                $line += substr_count($chunk, "\n");
                $out .= (string) preg_replace('/[^\n]/', ' ', $chunk);
                $i = $end;
                continue;
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                $end = self::endOfString($src, $i);
                $chunk = substr($src, $i, $end - $i);
                $line += substr_count($chunk, "\n");
                $out .= $chunk;
                $i = $end;
                $previous = $char;
                continue;
            }

            if ($char === '/' && self::isRegexLiteralWithQuotes($src, $i, $out, $previous)) {
                $end = self::endOfRegexLiteral($src, $i);
                $out .= substr($src, $i, $end - $i);
                $i = $end;
                $previous = '/';
                continue;
            }

            if ($char === "\n") {
                $line++;
            }
            $out .= $char;
            if ($char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
                $previous = $char;
            }
            $i++;
        }

        return [$out, $comments];
    }

    /**
     * Whether the `/` at $i opens a regular-expression literal that CONTAINS a
     * quote character.
     *
     * A regex is only worth recognising when ignoring it would corrupt the
     * scan, and the single way that happens is a quote inside it (`/['"]/`)
     * opening a string that runs on past the end of the line — after which a
     * `//` comment is no longer seen as one, and the example keys inside it are
     * catalogued as real. Every other `/` — division, a JSX self-closing `/>`,
     * a path inside a string — is left alone, which keeps this heuristic from
     * mis-reading real code in the same way.
     */
    private static function isRegexLiteralWithQuotes(string $src, int $i, string $emitted, string $previous): bool
    {
        // `/>` closes a JSX element; `/=` is an operator; `/ ` is division.
        $after = $i + 1 < strlen($src) ? $src[$i + 1] : '';
        if ($after === '' || $after === '>' || $after === '=' || $after === '/' || $after === '*'
            || $after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
            return false;
        }

        $canStartExpression = $previous === ''
            || str_contains(self::REGEX_PRECEDING_PUNCTUATION, $previous)
            || preg_match('/(?:^|[^\w$])(?:' . self::REGEX_PRECEDING_KEYWORDS . ')$/', rtrim($emitted)) === 1;

        if (!$canStartExpression) {
            return false;
        }

        $end = self::endOfRegexLiteral($src, $i);

        return $end > $i && strpbrk(substr($src, $i, $end - $i), '\'"`') !== false;
    }

    /**
     * The offset just past a regular-expression literal, or $i when the text at
     * $i is not one. A regex literal never spans a newline.
     */
    private static function endOfRegexLiteral(string $src, int $i): int
    {
        $eol = strpos($src, "\n", $i);
        $rest = substr($src, $i, ($eol === false ? strlen($src) : $eol) - $i);

        $matched = preg_match(
            '#^/(?:[^/\\\\\[\r\n]|\\\\.|\[(?:[^\]\\\\\r\n]|\\\\.)*\])+/[a-z]*#',
            $rest,
            $match
        );

        return $matched === 1 ? $i + strlen($match[0]) : $i;
    }

    /**
     * The offset just past the string (or template literal) beginning at $i.
     * Template expressions are walked recursively so a `${cond ? 'a' : 'b'}`
     * cannot end the literal early.
     */
    private static function endOfString(string $src, int $i): int
    {
        $quote = $src[$i];
        $len = strlen($src);
        $j = $i + 1;

        while ($j < $len) {
            $char = $src[$j];

            if ($char === '\\') {
                $j += 2;
                continue;
            }
            if ($quote === '`' && $char === '$' && $j + 1 < $len && $src[$j + 1] === '{') {
                $j = self::endOfTemplateExpression($src, $j + 1);
                continue;
            }
            if ($char === $quote) {
                return $j + 1;
            }
            // An unterminated quote is a syntax error upstream; stop at the
            // line end rather than consuming the remainder of the file.
            if ($quote !== '`' && $char === "\n") {
                return $j;
            }
            $j++;
        }

        return $len;
    }

    /** The offset just past the `}` closing a `${` template expression. */
    private static function endOfTemplateExpression(string $src, int $i): int
    {
        $len = strlen($src);
        $depth = 0;
        $j = $i;

        while ($j < $len) {
            $char = $src[$j];

            if ($char === '"' || $char === "'" || $char === '`') {
                $j = self::endOfString($src, $j);
                continue;
            }
            if ($char === '{') {
                $depth++;
                $j++;
                continue;
            }
            if ($char === '}') {
                $depth--;
                $j++;
                if ($depth <= 0) {
                    return $j;
                }
                continue;
            }
            $j++;
        }

        return $len;
    }

    /**
     * The translate functions in scope in this file, mapped to their domain
     * (null when the file must supply it).
     *
     * @param list<ExtractionProblem> $problems
     * @return array{names: array<string, string|null>}
     */
    private static function translateBindings(string $code, string $file, array &$problems): array
    {
        $names = [];

        preg_match_all(
            '/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*' . self::TRANSLATE_HOOK . '\s*\(([^)]*)\)/',
            $code,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = $match[1][0];
            $domain = self::literalValue($match[2][0]);

            if ($domain === null) {
                $problems[] = [
                    'file' => $file,
                    'line' => self::lineAt($code, (int) $match[0][1]),
                    'code' => 'dynamic-domain',
                    'message' => sprintf(
                        '%s() is called with a computed domain, so no scanner can tell which bundle '
                        . '`%s` writes into. Pass a literal domain, or declare this file\'s keys with a '
                        . '`%s <domain>` block.',
                        self::TRANSLATE_HOOK,
                        $name,
                        self::KEYS_TAG
                    ),
                ];
                $names[$name] = null;
                continue;
            }

            if (!TranslationDomain::isValid($domain)) {
                $problems[] = [
                    'file' => $file,
                    'line' => self::lineAt($code, (int) $match[0][1]),
                    'code' => 'invalid-domain',
                    'message' => sprintf(
                        '`%s` is not a valid domain. Core domains are bare (`auth`, `common`); a '
                        . 'plugin\'s carries its source slug (`acme:catalog`). See %s.',
                        $domain,
                        TranslationDomain::class
                    ),
                ];
                continue;
            }

            $names[$name] = $domain;
        }

        // A helper that receives the translate function as a parameter — the
        // shape `ssoErrorMessage(t: TranslateFn, reason: string)` uses.
        preg_match_all(
            '/\b([A-Za-z_$][\w$]*)\s*:\s*' . self::TRANSLATE_FN_TYPE . '\b/',
            $code,
            $typed,
            PREG_SET_ORDER
        );
        foreach ($typed as $match) {
            if (!array_key_exists($match[1], $names)) {
                $names[$match[1]] = null;
            }
        }

        return ['names' => $names];
    }

    /**
     * Every call to one of the named translate functions, with its arguments
     * split at the top level.
     *
     * @param list<string> $names
     * @return list<array{name: string, offset: int, args: list<string>}>
     */
    private static function callSites(string $code, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $alternatives = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            $names
        ));

        // `(?<![\w$.])` keeps `obj.t(` and `format(` out: only a bare call to
        // the bound identifier counts.
        preg_match_all(
            '/(?<![\w$.])(' . $alternatives . ')\s*\(/',
            $code,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        $sites = [];
        foreach ($matches as $match) {
            $offset = (int) $match[0][1];
            $open = $offset + strlen($match[0][0]) - 1;
            $close = self::matchingParen($code, $open);
            if ($close === null) {
                continue;
            }

            $sites[] = [
                'name' => $match[1][0],
                'offset' => $offset,
                'args' => self::splitArguments(substr($code, $open + 1, $close - $open - 1)),
            ];
        }

        return $sites;
    }

    /** The offset of the `)` matching the `(` at $open, or null. */
    private static function matchingParen(string $code, int $open): ?int
    {
        $len = strlen($code);
        $depth = 0;

        for ($i = $open; $i < $len; $i++) {
            $char = $code[$i];

            if ($char === '"' || $char === "'" || $char === '`') {
                $i = self::endOfString($code, $i) - 1;
                continue;
            }
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Split an argument list on its top-level commas.
     *
     * @return list<string>
     */
    private static function splitArguments(string $inner): array
    {
        $args = [];
        $current = '';
        $depth = 0;
        $len = strlen($inner);

        for ($i = 0; $i < $len; $i++) {
            $char = $inner[$i];

            if ($char === '"' || $char === "'" || $char === '`') {
                $end = self::endOfString($inner, $i);
                $current .= substr($inner, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $args[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }

        $args[] = $current;

        if (count($args) === 1 && trim($args[0]) === '') {
            return [];
        }
        // A trailing comma — `t('key', 'text',)` — leaves an empty slot.
        if (count($args) > 1 && trim($args[count($args) - 1]) === '') {
            array_pop($args);
        }

        return $args;
    }

    /**
     * The value of a JavaScript string literal, or null when the expression is
     * anything else.
     *
     * A template literal counts only when it has no `${}` substitution — with
     * one, its value is not knowable here. Concatenation (`'a' + b`) is
     * deliberately NOT unwrapped: a sentence assembled from fragments cannot be
     * translated, because word order differs between languages.
     */
    private static function literalValue(string $expression): ?string
    {
        $trimmed = trim($expression);
        if (strlen($trimmed) < 2) {
            return null;
        }

        $quote = $trimmed[0];
        if ($quote !== '"' && $quote !== "'" && $quote !== '`') {
            return null;
        }
        if ($trimmed[strlen($trimmed) - 1] !== $quote) {
            return null;
        }
        if (self::endOfString($trimmed, 0) !== strlen($trimmed)) {
            // The literal ends before the expression does: `'a' + b`.
            return null;
        }

        $body = substr($trimmed, 1, -1);
        if ($quote === '`' && str_contains($body, '${')) {
            return null;
        }

        return self::decodeEscapes($body);
    }

    /**
     * Resolve the JavaScript escape sequences a source string may carry.
     *
     * `\uXXXX`, `\u{XXXXX}` and `\xNN` are decoded properly rather than being
     * stripped to their first character: a curly apostrophe (`’`) and a
     * non-breaking space (` `) are ordinary things to find in copy, and
     * `café` reaching the catalogue as `cafu00e9` would put that on screen.
     *
     * `\0` is deliberately NOT decoded to a NUL byte. It is correct JavaScript
     * and impossible product copy, and PostgreSQL rejects NUL in a text column
     * — so decoding it would let the scanner produce a catalogue that cannot be
     * seeded.
     */
    private static function decodeEscapes(string $body): string
    {
        $out = '';
        $len = strlen($body);

        for ($i = 0; $i < $len; $i++) {
            if ($body[$i] !== '\\' || $i + 1 >= $len) {
                $out .= $body[$i];
                continue;
            }

            $i++;
            $escape = $body[$i];

            if ($escape === 'u' || $escape === 'x') {
                $decoded = self::decodeCodePoint($body, $i);
                if ($decoded !== null) {
                    [$character, $consumed] = $decoded;
                    $out .= $character;
                    $i += $consumed;
                    continue;
                }
            }

            $out .= match ($escape) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                default => $escape,
            };
        }

        return $out;
    }

    /**
     * Decode the `\uXXXX` / `\u{XXXXX}` / `\xNN` escape whose introducer sits at
     * $i, returning [the UTF-8 character, characters consumed after $i].
     *
     * @return array{0: string, 1: int}|null Null when the escape is malformed.
     */
    private static function decodeCodePoint(string $body, int $i): ?array
    {
        $rest = substr($body, $i);

        if (preg_match('/^u\{([0-9a-fA-F]{1,6})\}/', $rest, $match) === 1) {
            return [self::utf8((int) hexdec($match[1])), strlen($match[0]) - 1];
        }
        if (preg_match('/^u([0-9a-fA-F]{4})/', $rest, $match) === 1) {
            return [self::utf8((int) hexdec($match[1])), 4];
        }
        if (preg_match('/^x([0-9a-fA-F]{2})/', $rest, $match) === 1) {
            return [self::utf8((int) hexdec($match[1])), 2];
        }

        return null;
    }

    /** A code point as UTF-8, without depending on ext-mbstring or ext-intl. */
    private static function utf8(int $codePoint): string
    {
        if ($codePoint === 0) {
            // See decodeEscapes(): a NUL can never be seeded, so it is kept as
            // the literal text rather than produced as a byte.
            return '\\0';
        }

        return html_entity_decode('&#' . $codePoint . ';', ENT_QUOTES, 'UTF-8');
    }

    /**
     * Read the `@i18n-keys` and `@i18n-dynamic-ignore:` declarations out of a
     * file's comments.
     *
     * @param list<array{line: int, text: string}> $comments
     * @param list<ExtractionProblem> $problems
     * @return array{keys: list<ExtractedEntry>, declaredKeys: array<string, array<string, true>>, ignored: bool}
     */
    private static function parseAnnotations(array $comments, string $file, array &$problems): array
    {
        /** @var list<ExtractedEntry> $keys */
        $keys = [];
        /** @var array<string, array<string, true>> $declaredKeys */
        $declaredKeys = [];
        $ignored = false;

        foreach ($comments as $comment) {
            $lines = preg_split('/\r?\n/', $comment['text']) ?: [];
            $domain = null;

            foreach ($lines as $index => $raw) {
                // Strip the leading ` * ` of a block comment.
                $text = trim((string) preg_replace('#^\s*\*+/?\s?#', '', $raw));
                $lineNumber = $comment['line'] + $index;

                $ignorePosition = stripos($text, self::DYNAMIC_IGNORE_TAG);
                if ($ignorePosition !== false) {
                    $reason = trim(rtrim(substr($text, $ignorePosition + strlen(self::DYNAMIC_IGNORE_TAG)), '*/'));
                    if ($reason === '') {
                        $problems[] = [
                            'file' => $file,
                            'line' => $lineNumber,
                            'code' => 'unreasoned-ignore',
                            'message' => sprintf(
                                '`%s` needs a reason on the same line. A decision with no reason '
                                . 'recorded is indistinguishable from a silenced alarm.',
                                self::DYNAMIC_IGNORE_TAG
                            ),
                        ];
                        continue;
                    }
                    $ignored = true;
                    continue;
                }

                if (preg_match('/^' . preg_quote(self::KEYS_TAG, '/') . '\s+(\S+)\s*$/', $text, $match) === 1) {
                    $domain = $match[1];
                    if (!TranslationDomain::isValid($domain)) {
                        $problems[] = [
                            'file' => $file,
                            'line' => $lineNumber,
                            'code' => 'invalid-domain',
                            'message' => sprintf(
                                '`%s` declares keys in `%s`, which is not a valid domain. Core domains '
                                . 'are bare (`auth`, `common`); a plugin\'s carries its source slug '
                                . '(`acme:catalog`).',
                                self::KEYS_TAG,
                                $domain
                            ),
                        ];
                        $domain = null;
                    }
                    continue;
                }

                if ($domain === null) {
                    continue;
                }

                if ($text === '' || str_starts_with($text, '@')) {
                    $domain = null;
                    continue;
                }

                if (preg_match('/^(\S+)\s*=\s*(.+?)\s*$/', $text, $match) !== 1) {
                    $problems[] = [
                        'file' => $file,
                        'line' => $lineNumber,
                        'code' => 'malformed-declaration',
                        'message' => sprintf(
                            'Expected `key = English text` inside the `%s` block, got: %s',
                            self::KEYS_TAG,
                            self::quote($text)
                        ),
                    ];
                    $domain = null;
                    continue;
                }

                if (!self::isValidKey($match[1])) {
                    $problems[] = [
                        'file' => $file,
                        'line' => $lineNumber,
                        'code' => 'invalid-key',
                        'message' => sprintf(
                            '`%s` is not a well-formed key. Keys are dot-delimited paths whose segments '
                            . 'start with a lowercase letter.',
                            $match[1]
                        ),
                    ];
                    continue;
                }

                $keys[] = [
                    'domain' => $domain,
                    'key' => $match[1],
                    'text' => $match[2],
                    'file' => $file,
                    'line' => $lineNumber,
                ];
                $declaredKeys[$domain][$match[1]] = true;
            }
        }

        return ['keys' => $keys, 'declaredKeys' => $declaredKeys, 'ignored' => $ignored];
    }

    /** The 1-based line number of a byte offset. */
    private static function lineAt(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, $offset), "\n") + 1;
    }

    /** A short, single-line rendering of a string for an error message. */
    private static function quote(string $value): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', $value);
        if (strlen($flat) > 60) {
            $flat = substr($flat, 0, 57) . '…';
        }

        return '"' . $flat . '"';
    }
}
