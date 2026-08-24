<?php

declare(strict_types=1);

/**
 * Core's live route table, read out of public/index.php.
 *
 * WHY THIS IS A FILE AND NOT A REGEX IN EACH GUARD
 * ------------------------------------------------
 * Two CI guards and one test already needed to know which routes core
 * registers, and each grew its own `preg_match_all` over public/index.php:
 *
 *   scripts/ci-plugin-frontend-features.php  (#969/#980)
 *   tests/OpenAPI/RouteCatalogueCompletenessTest::extractLiveRoutes()
 *
 * Both capture the same two things — method and path — because that is all
 * either question needed. The permission-holder guard (#990) needs a THIRD
 * thing, `requiredPermission`, and that is where a regex stops being adequate:
 * it is the SIXTH positional argument, behind a handler that is an array
 * literal, behind two nullable arguments, in calls that are sometimes written
 * across several lines. A pattern that tries to count commas from the outside
 * gets the wrong argument the first time a handler literal contains one.
 *
 * So the extraction moved here, once, over PHP's own tokenizer, and
 * ci-plugin-frontend-features.php was pointed at it — verified to produce a
 * byte-identical route set to the pattern it replaced, so the collision ordering
 * that guard depends on is unchanged. A third scraper would have been a third
 * thing to keep in step with the same file, and the failure mode of a scraper
 * that has silently stopped matching is a guard that passes while measuring
 * nothing, which is the whole family of bug this repository's guards exist to
 * remove.
 *
 * RouteCatalogueCompletenessTest was deliberately NOT converted, and it is the
 * one honest piece of duplication left. Its own pattern needs method and path
 * only, and tests/ is inside PHPStan's analysed paths while scripts/ is not — a
 * test calling a function from a plain, non-autoloaded file would need
 * phpstan.neon widened to reach it, which is a bigger change than the two lines
 * of regex it would delete. If a third consumer ever appears, convert it then
 * and pay that cost once.
 *
 * WHY IT PARSES THE SOURCE INSTEAD OF RUNNING IT
 * ----------------------------------------------
 * public/index.php IS the route table — there is no separate declaration to
 * read. Executing it means constructing every handler, opening a database
 * connection, loading plugins and entering the FrankenPHP worker loop; the file
 * is a bootstrap, not a manifest. Reading it as source is the cheap half of
 * that, and it is what makes a guard runnable in a job that has no application
 * stack.
 *
 * The cost of reading rather than running is that a route registered from
 * anywhere ELSE is invisible here. One such place exists today:
 * src/Cli/Commands/BaseCommand.php carries a second, smaller copy of the table
 * ("copied from public/index.php", its own comment says) for the CLI's router.
 * Its permission slugs are a strict subset of index.php's, so nothing is missed
 * by reading only index.php — but that is a fact about today, not a guarantee,
 * and it is a copy that can drift. The floor check below is what stops the
 * drift from turning into silence.
 *
 * THE FLOOR
 * ---------
 * {@see CORE_ROUTE_TABLE_FLOOR}. A refactor that moves core registration out of
 * index.php would leave every caller measuring an EMPTY table and reporting
 * success: no collisions to find, no gated permissions to check. That is not a
 * hypothetical failure mode, it is the only one an extractor has. So a table
 * that comes back implausibly small is an error here rather than a green build
 * somewhere else.
 *
 * AND THE SECOND FLOOR, WHICH THE FIRST DOES NOT COVER
 * ----------------------------------------------------
 * The route floor says the calls were found. It says nothing about whether the
 * ARGUMENTS inside them were read correctly — an extraction that returned 281
 * routes all carrying `requiredPermission: null` would clear it comfortably. A
 * caller that depends on the permission argument must therefore assert its own
 * floor on what it found; {@see CORE_GATED_PERMISSION_FLOOR} is that number, and
 * it lives here beside the scraper it constrains rather than in the guard, so
 * both floors move together when this file does.
 *
 * REQUIREMENTS. An autoloader must already be in place: a `requiredPermission`
 * written as `CorePermissions::ROLES_READ` is resolved by reading the actual
 * constant, so the class has to be loadable. Resolving it any other way would
 * mean re-implementing the constant table, which is a second copy of the thing
 * being checked.
 */

/**
 * The fewest routes public/index.php may plausibly register.
 *
 * 281 at the time of writing. The number is a floor, not a count: it exists to
 * catch an extractor that has stopped matching, so it wants to sit far enough
 * below the real figure that ordinary route churn never touches it and far
 * enough above zero that a total failure cannot slip past.
 */
const CORE_ROUTE_TABLE_FLOOR = 100;

/**
 * The fewest DISTINCT permission slugs core's route table may gate on.
 *
 * 49 at the time of writing. Same reasoning as the route floor, applied one
 * level in: this is the number that goes to zero when the argument reader
 * breaks while the call matcher keeps working, and a permission-holder check
 * over an empty set of gates is a green build that checked nothing.
 */
const CORE_GATED_PERMISSION_FLOOR = 20;

/**
 * Every route public/index.php registers, with the authorization it gates on.
 *
 * `requiredPermission` is fully resolved to the slug string a request is
 * actually checked against — a class constant is read from the class, not
 * guessed from its name. `requiredRole` is returned as the source wrote it,
 * because no caller needs more than "is there one" and the role names are
 * literals anyway.
 *
 * @param  string $indexPhpPath Absolute path to public/index.php.
 * @return list<array{
 *     call: string,
 *     method: string,
 *     path: string,
 *     requiredRole: string|null,
 *     requiredPermission: string|null,
 *     line: int
 * }>
 * @throws RuntimeException When the file cannot be read, when fewer than
 *   {@see CORE_ROUTE_TABLE_FLOOR} routes are found, or when a route's method,
 *   path or permission argument cannot be resolved to a literal. All three are
 *   failures of the extractor rather than of the code under inspection, and all
 *   three fail loudly: a caller that cannot see the route table must not report
 *   on it.
 */
function whity_core_route_table(string $indexPhpPath): array
{
    $source = @file_get_contents($indexPhpPath);
    if (!is_string($source)) {
        throw new RuntimeException(
            'could not read ' . $indexPhpPath . " to build core's route table."
        );
    }

    $useMap = whity_route_table_use_map($source);
    $tokens = token_get_all($source);
    $count = count($tokens);

    $routes = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$router') {
            continue;
        }

        $arrow = whity_route_table_next_significant($tokens, $i + 1);
        if ($arrow === null || !is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        $name = whity_route_table_next_significant($tokens, $arrow + 1);
        if ($name === null || !is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
            continue;
        }

        $call = $tokens[$name][1];
        if ($call !== 'register' && $call !== 'registerUnversioned') {
            continue;
        }

        $open = whity_route_table_next_significant($tokens, $name + 1);
        if ($open === null || $tokens[$open] !== '(') {
            continue;
        }

        $line = is_array($tokens[$name]) ? (int) $tokens[$name][2] : 0;
        [$arguments, $close] = whity_route_table_arguments($tokens, $open, $indexPhpPath, $line);
        $i = $close;

        // Router::register($method, $path, $handler, $requiredRole = null,
        //                  $namespacePrefix = null, $requiredPermission = null,
        //                  $schema = null)
        $method = whity_route_table_scalar($arguments[0] ?? 'null', $useMap);
        $path = whity_route_table_scalar($arguments[1] ?? 'null', $useMap);
        $permissionExpression = $arguments[5] ?? 'null';
        $permission = whity_route_table_scalar($permissionExpression, $useMap);

        if (!is_string($method) || !is_string($path)) {
            throw new RuntimeException(sprintf(
                "%s:%d — could not read the method and path of this \$router->%s() call.\n"
                . "  method argument: %s\n"
                . "  path argument:   %s\n"
                . 'Both must be literal strings; a route whose identity is computed cannot be '
                . 'checked by anything that reads this file.',
                $indexPhpPath,
                $line,
                $call,
                $arguments[0] ?? '(missing)',
                $arguments[1] ?? '(missing)'
            ));
        }

        if ($permission === false) {
            throw new RuntimeException(sprintf(
                "%s:%d — could not resolve the requiredPermission of %s %s.\n"
                . "  expression: %s\n"
                . 'It must be a literal string or a class constant that resolves to one. This '
                . "fails rather than skipping the route: a gate nobody can read is a gate nobody\n"
                . 'can check, and skipping it silently is how an unheld permission reaches '
                . 'production.',
                $indexPhpPath,
                $line,
                $method,
                $path,
                $permissionExpression
            ));
        }

        $roleExpression = $arguments[3] ?? 'null';
        $role = whity_route_table_scalar($roleExpression, $useMap);

        $routes[] = [
            'call' => $call,
            'method' => strtoupper($method),
            'path' => $path,
            // A role that is not a readable literal is reported as the source
            // text: no caller decides anything on it, and role names are #990's
            // subject rather than this file's.
            'requiredRole' => is_string($role) ? $role : ($role === null ? null : $roleExpression),
            'requiredPermission' => $permission,
            'line' => $line,
        ];
    }

    if (count($routes) < CORE_ROUTE_TABLE_FLOOR) {
        throw new RuntimeException(sprintf(
            "only %d route(s) were found in %s, which is below the floor of %d.\n\n"
            . "Every caller of this extractor needs core's REAL route table; an empty or truncated\n"
            . "one makes each of them pass while measuring nothing. If core registration moved,\n"
            . 'point this extractor at its new home rather than lowering the floor.',
            count($routes),
            $indexPhpPath,
            CORE_ROUTE_TABLE_FLOOR
        ));
    }

    return $routes;
}

/**
 * Alias => fully-qualified class name, for every top-level `use` in a file.
 *
 * Read with a line-anchored pattern rather than from the token stream because
 * the two things that look alike — an import and a closure's `use (...)` — are
 * told apart trivially by shape: an import starts a line in column 0 and ends
 * in a semicolon. This file's only consumer is public/index.php, which writes
 * all 97 of its imports that way.
 *
 * @return array<string, string>
 */
function whity_route_table_use_map(string $source): array
{
    preg_match_all(
        '/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m',
        $source,
        $matches,
        PREG_SET_ORDER
    );

    $map = [];
    foreach ($matches as $match) {
        $fqcn = ltrim($match[1], '\\');
        $segments = explode('\\', $fqcn);
        $alias = ($match[2] ?? '') !== '' ? $match[2] : (string) end($segments);
        $map[$alias] = $fqcn;
    }

    return $map;
}

/**
 * The index of the next token that is not whitespace or a comment.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function whity_route_table_next_significant(array $tokens, int $from): ?int
{
    for ($i = $from, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * The top-level argument expressions of a call, as source text.
 *
 * Nesting is tracked by BRACKET TEXT rather than by token id, so a handler
 * written `[$usersHandler, 'list']`, an inline `schema` array of any depth, a
 * closure body, a `match`, an attribute and an interpolated `${...}` all count
 * as one argument each — which is the entire reason this is a tokenizer walk
 * and not a pattern. Comments inside the argument list are dropped; runs of
 * whitespace collapse to a single space so the returned text is comparable.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
 * @param  int $openParen Index of the call's own '('.
 * @return array{0: list<string>, 1: int} The argument texts, and the index of
 *   the matching ')'.
 * @throws RuntimeException When the argument list never closes.
 */
function whity_route_table_arguments(array $tokens, int $openParen, string $path, int $line): array
{
    /** Bracket-opening token TEXTS. '${' and '#[' are single tokens that close with a plain '}' / ']'. */
    static $openers = ['(' => true, '[' => true, '{' => true, '${' => true, '#[' => true];
    static $closers = [')' => true, ']' => true, '}' => true];

    $depth = 0;
    $arguments = [];
    $current = '';

    for ($i = $openParen, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;

        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }

        if (isset($openers[$text])) {
            $depth++;
            if ($depth > 1) {
                $current .= $text;
            }
            continue;
        }

        if (isset($closers[$text])) {
            $depth--;
            if ($depth === 0) {
                $arguments[] = trim($current);

                // A trailing comma leaves one empty tail; a zero-argument call
                // leaves exactly one empty entry. Neither is an argument.
                if (($arguments[count($arguments) - 1] ?? '') === '') {
                    array_pop($arguments);
                }

                return [$arguments, $i];
            }
            $current .= $text;
            continue;
        }

        if ($depth === 1 && $text === ',') {
            $arguments[] = trim($current);
            $current = '';
            continue;
        }

        $current .= $id === T_WHITESPACE ? ' ' : $text;
    }

    throw new RuntimeException(sprintf(
        '%s:%d — a $router->register() argument list is never closed, so the route table cannot '
        . 'be read.',
        $path,
        $line
    ));
}

/**
 * The value a route-argument expression denotes.
 *
 * @param  array<string, string> $useMap Alias => FQCN, from {@see whity_route_table_use_map()}.
 * @return string|null|false The string value; null for a literal `null`; false
 *   when the expression is something this reader cannot evaluate. The caller
 *   decides whether an unreadable argument is fatal — for a permission it is.
 */
function whity_route_table_scalar(string $expression, array $useMap): string|null|false
{
    $expression = trim($expression);

    if ($expression === '' || strcasecmp($expression, 'null') === 0) {
        return null;
    }

    // A single-quoted literal, the form index.php uses throughout. Only the two
    // escapes single quotes have are unescaped, so a path's '{id:\d+}' keeps its
    // backslash exactly as PHP would.
    if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $expression, $match) === 1) {
        return str_replace(['\\\'', '\\\\'], ['\'', '\\'], $match[1]);
    }

    // A double-quoted literal with nothing in it that double quotes do
    // differently. One carrying an escape or an interpolation is deliberately
    // NOT evaluated — it would be this reader guessing at PHP's semantics, and
    // an unreadable answer is safer than a wrong one.
    if (preg_match('/^"([^"$\\\\]*)"$/', $expression, $match) === 1) {
        return $match[1];
    }

    // A class constant, read from the class. `CorePermissions::ROLES_READ` and
    // `\Whity\Core\RBAC\CorePermissions::ROLES_READ` both appear in index.php
    // for the same constant.
    if (preg_match(
        '/^(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::([A-Za-z_][A-Za-z0-9_]*)$/',
        $expression,
        $match
    ) !== 1) {
        return false;
    }

    $class = ltrim($match[1], '\\');
    if ($match[1][0] !== '\\') {
        // A name that is not fully qualified resolves through the file's
        // imports, exactly as PHP resolves it: the FIRST segment is the alias,
        // whether the name is one segment or several.
        $segments = explode('\\', $class);
        if (isset($useMap[$segments[0]])) {
            $segments[0] = $useMap[$segments[0]];
            $class = implode('\\', $segments);
        }
    }

    $constant = $class . '::' . $match[2];
    if (!class_exists($class) || !defined($constant)) {
        return false;
    }

    $value = constant($constant);

    return is_string($value) ? $value : false;
}
