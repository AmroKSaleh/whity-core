<?php

declare(strict_types=1);

namespace Whity\Core\Api;

/**
 * A URL the backend RETURNS to a client must carry the version prefix (#1046).
 *
 * WHICH LAYER ADDS `/v1`, WHICH IS THE FIRST QUESTION AND THE CODEBASE DOES NOT
 * ANSWER IT ANYWHERE ELSE
 * --------------------------------------------------------------------------
 * `Router::versionPrefix()` applies the prefix at REGISTRATION. A route declared
 * as `/api/users` is served at `/api/v1/users`, so a declaration written bare is
 * correct and must stay that way.
 *
 * `web/lib/api-client.ts` passes `/api` paths through VERBATIM and adds no
 * version, which is why all 93 production call sites write `/api/v1/...`
 * themselves. So a URL the backend hands to a client has to arrive already
 * versioned — nothing downstream will fix it.
 *
 * That asymmetry is the whole rule: **declared bare, emitted versioned.** Both
 * shapes are `'/api/...'` string literals, which is why a grep cannot tell them
 * apart and why this reads the syntax around them instead.
 *
 * WHY A GUARD AND NOT A CONVENTION
 * --------------------------------
 * The convention already existed, in comments, before #1016: `PluginLoader`
 * says "rewrite it to the versioned URL the browser must actually call";
 * `CoreApiSchemas::apiPath()` says "a URL the BROWSER fetches, so it has to
 * carry the prefix already". Seven emitters got it right by reading their
 * neighbours. One did not, and nothing caught it — for an entire release, while
 * every document viewer showed an error box on first click.
 *
 * WHAT THIS DOES NOT COVER, AND THE READER NEEDS THIS BEFORE TRUSTING A GREEN RUN
 * ------------------------------------------------------------------------------
 * It catches a SHAPE: a bare `/api/` literal in a returned expression. It is
 * blind to a REIMPLEMENTATION of the prefix arithmetic, because a reimplemented
 * copy produces correct output today and drifts later — that is #1020, a
 * different failure with a different fix (one definition, which #1020 landed).
 * Deleting the copies does not close this class either; nothing stops the next
 * inline copy. Green here means "no bare literal is emitted", not "every emitted
 * URL is correct".
 *
 * It is also deliberately blind to a path assembled entirely at runtime — a
 * variable holding a route read from a database can carry anything. The rule
 * only reaches what is written in source, which is where the defect it is named
 * after lived.
 */
final class EmittedUrlVersionGuard
{
    /**
     * Helpers that apply the prefix. A literal passed through one of these is
     * correct BY CONSTRUCTION, and flagging it would force a suppression at
     * every correct call site — which is how a guard becomes an allowlist and
     * then noise.
     *
     * @var list<string>
     */
    private const VERSIONING_HELPERS = ['apiPath', 'versionedPath', 'getVersionPrefix', 'versionPrefix'];

    /**
     * Keys whose VALUE is a URL handed to a client, even when it is not returned
     * directly — `['content_url' => '/api/…']` is an emission the `return` rule
     * alone would miss.
     */
    private const URL_KEY = '/^[a-z0-9_]*url$/i';

    /**
     * @return list<array{file: string, line: int, literal: string, why: string}>
     */
    public function scanDirectory(string $dir): array
    {
        $violations = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $violations = array_merge(
                $violations,
                $this->scanSource((string) file_get_contents($file->getPathname()), $file->getPathname())
            );
        }

        usort($violations, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $violations;
    }

    /**
     * @return list<array{file: string, line: int, literal: string, why: string}>
     */
    public function scanSource(string $source, string $file = '<source>'): array
    {
        // Comments first: `@return string The prefixed path (e.g. '/api/v1/users')`
        // and every "must start with '/api/'" docblock are prose about the rule,
        // not emissions of it. Reading them would make the guard fire on its own
        // documentation.
        $code = $this->stripComments($source);

        $violations = [];
        foreach ($this->emittingStatements($code) as [$offset, $statement]) {
            foreach ($this->bareApiLiterals($statement) as $literal) {
                $violations[] = [
                    'file' => $file,
                    'line' => $this->lineAt($source, $code, $offset),
                    'literal' => $literal,
                    'why' => 'returned to a client without the version prefix the router serves it at',
                ];
            }
        }

        return $violations;
    }

    /**
     * Statements that hand a value OUTWARD: a `return`, or a `*url` array entry.
     *
     * Everything else — a route registration, a comparison, a parameter default
     * — is a DECLARATION, where a bare `/api/` path is correct and required.
     *
     * @return list<array{0: int, 1: string}> offset in the stripped code, and the statement text
     */
    private function emittingStatements(string $code): array
    {
        $out = [];

        // `return <expr>;` where the expression yields a STRING — a literal,
        // `sprintf`, a concatenation, an interpolation.
        //
        // A returned ARRAY is excluded, and that exclusion is load-bearing
        // rather than a convenience. `CoreApiSchemas::routes()` returns one
        // array holding every route DECLARATION in core; without this the guard
        // reported 218 violations there on its first run, every one of them a
        // correctly-bare declaration. A rule that fires on the whole route table
        // is not a strict rule, it is a broken one — and the noise would have
        // buried the single real emission it exists to find.
        //
        // Emissions that live inside an array are caught by the `*url` key rule
        // below instead, which is the shape they actually take.
        if (preg_match_all('/\breturn\b[^;]*;/s', $code, $m, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m[0] as [$text, $offset]) {
                if (str_contains($text, '=>') || str_contains($text, '[')) {
                    continue;
                }
                $out[] = [$offset, $text];
            }
        }

        // `'content_url' => <expr>` up to the next comma or closing bracket.
        if (preg_match_all('/([\'"])([a-z0-9_]*url)\1\s*=>[^,\]\)]*/i', $code, $m, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m[0] as $i => [$text, $offset]) {
                if (preg_match(self::URL_KEY, $m[2][$i][0]) === 1) {
                    $out[] = [$offset, $text];
                }
            }
        }

        return $out;
    }

    /**
     * `/api/...` literals in the statement that carry no version and are not
     * handed to a versioning helper.
     *
     * @return list<string>
     */
    private function bareApiLiterals(string $statement): array
    {
        // A literal inside a versioning helper's argument list is correct by
        // construction. Removing those calls wholesale is cruder than tracking
        // argument positions and is enough here: a statement mixing a wrapped
        // and an unwrapped literal does not occur, and if one ever did the
        // guard would under-report rather than cry wolf.
        $helpers = implode('|', self::VERSIONING_HELPERS);
        $stripped = (string) preg_replace('/\b(?:' . $helpers . ')\s*\([^)]*\)/', '', $statement);

        $found = [];
        if (preg_match_all('/([\'"])(\/api\/[^\'"]*)\1/', $stripped, $m) !== false) {
            foreach ($m[2] as $literal) {
                if ($this->isVersioned($literal) || $this->isProse($literal) || $this->namesNoResource($literal)) {
                    continue;
                }
                $found[] = $literal;
            }
        }

        return $found;
    }

    /** `/api/v1/...`, and any future major — the guard must not need editing on a version bump. */
    private function isVersioned(string $literal): bool
    {
        return preg_match('#^/api/v\d+(/|$)#', $literal) === 1;
    }

    /**
     * Prose that happens to contain a path, e.g.
     * `"resource.basePath must be a string starting with '/api/'"`.
     *
     * A URL has no spaces. This is what keeps the three `$drop(...)` messages in
     * PluginLoader — which describe the rule — from being read as breaches of it.
     */
    private function isProse(string $literal): bool
    {
        return str_contains($literal, ' ');
    }

    /**
     * The prefix on its own — `'/api/'` — which names no resource and so cannot
     * be a URL anyone fetches.
     *
     * This is how the guard stays quiet about sentences that QUOTE the rule:
     * `"resource.basePath must be a string starting with '/api/'"` is one
     * double-quoted string, but the inner `'/api/'` is a separately-quoted run
     * as far as a regex is concerned, so {@see isProse()} never sees the
     * surrounding words. Requiring a resource segment reaches the same answer
     * without needing to know the literal is nested — and it is true on its own
     * terms: an emitted URL always names something.
     */
    private function namesNoResource(string $literal): bool
    {
        return preg_match('#^/api/.+#', $literal) !== 1;
    }

    /** Comments removed but LENGTH PRESERVED, so offsets still map to source lines. */
    private function stripComments(string $source): string
    {
        $blanked = (string) preg_replace_callback(
            '#/\*.*?\*/|//[^\n]*#s',
            static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            $source
        );

        return $blanked;
    }

    private function lineAt(string $source, string $code, int $offset): int
    {
        return substr_count(substr($code, 0, $offset), "\n") + 1;
    }
}
