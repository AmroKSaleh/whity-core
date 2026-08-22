<?php

declare(strict_types=1);

namespace Whity\Core;

use PhpToken;
use Throwable;

/**
 * The class-like names a PHP file declares, read WITHOUT executing it (#841).
 *
 * WHY THIS EXISTS
 * ---------------
 * `require_once` deduplicates by PATH, not by class name. Two DIFFERENT files
 * declaring the same fully-qualified name are therefore both required, and the
 * second declaration is a FATAL error ("Cannot redeclare class …") that no
 * try/catch and no error boundary can intercept. {@see PluginLoader} requires
 * plugin files at boot, so one such pair used to take the whole host down on
 * every request until somebody deleted a directory from disk.
 *
 * The only way to avoid that fatal is to know what a file WILL declare before
 * requiring it, and the only way to know that without running the file is to
 * read its source. So this is a lexical pass ({@see PhpToken::tokenize()}) — no
 * `require`, no `eval`, no autoload, nothing from the file is executed. It is
 * also what {@see PluginInstaller} uses to read the names an already-installed
 * plugin occupies, since asking the host process (`class_exists()`) only sees
 * the plugins THIS worker happens to have loaded.
 *
 * WHAT COUNTS AS A DECLARATION
 * ----------------------------
 * Only TOP-LEVEL `class`/`interface`/`trait`/`enum` declarations — the ones a
 * plain `require` of the file always executes. Deliberate exclusions:
 *
 *  - Anything nested inside braces. `if (!class_exists(X::class)) { class X {} }`
 *    is the polyfill/shim idiom: requiring that file is SAFE precisely when the
 *    class already exists, so reporting it would refuse a plugin that works.
 *    Declarations inside functions and methods are skipped for the same reason —
 *    they only happen when something calls them.
 *  - `new class { … }`, which declares no name, and `Foo::class`, which is a
 *    string expression. Both put `T_CLASS` in the token stream next to nothing
 *    that could be redeclared.
 *
 * Over-reporting is the dangerous direction: a name reported here that the file
 * does not really declare costs a working plugin its load. Under-reporting only
 * loses the guard for an exotic file shape, which is where we were already.
 *
 * Names come back exactly as written (namespace + short name, no leading `\`).
 * PHP class names are CASE-INSENSITIVE, so every comparison against this list
 * has to be too — see {@see PluginLoader::findRedeclarationConflict()}.
 */
final class DeclaredClassScanner
{
    /**
     * The class-like names a plain `require` of this source would declare.
     *
     * @param string $source PHP source text (the whole file).
     * @return list<string> Fully-qualified names, in declaration order.
     */
    public static function declaredIn(string $source): array
    {
        try {
            $tokens = PhpToken::tokenize($source);
        } catch (Throwable) {
            // An untokenizable file cannot be reasoned about; it will fail on
            // its own terms when required. Claiming it declares nothing is the
            // under-reporting direction, which is the safe one here.
            return [];
        }

        // Drop the noise up front so the declaration checks below can look at
        // the immediately neighbouring tokens instead of re-skipping whitespace
        // and comments at every step.
        $significant = [];
        foreach ($tokens as $token) {
            if (!$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $significant[] = $token;
            }
        }

        $declared = [];
        $namespace = '';
        $depth = 0;
        $count = count($significant);

        for ($index = 0; $index < $count; $index++) {
            $token = $significant[$index];

            if ($token->is(T_NAMESPACE)) {
                $read = self::readNamespace($significant, $index, $count);
                if ($read !== null) {
                    [$namespace, $index] = $read;
                    continue;
                }
                // Not a declaration but the `namespace\Foo` relative-name
                // operator: the file's namespace is unchanged.
            }

            // Curly-brace depth, so only top-level declarations are reported.
            // T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES are the string
            // interpolation forms of `{`; their closer is a plain `}`, so they
            // have to be counted or interpolation would unbalance the depth.
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                continue;
            }
            if ($token->text === '}') {
                // Clamped rather than allowed to go negative: readNamespace()
                // deliberately does not count the opening brace of a
                // `namespace Foo { … }` block (its body IS top level), so that
                // block's closer arrives with nothing to match.
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth !== 0 || !$token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                continue;
            }

            // `new class …` (anonymous, no name to redeclare) and `Foo::class`
            // (a string expression) are the two ways T_CLASS appears without a
            // declaration behind it.
            $previous = $significant[$index - 1] ?? null;
            if ($previous !== null && $previous->is([T_NEW, T_DOUBLE_COLON])) {
                continue;
            }

            $name = $significant[$index + 1] ?? null;
            if ($name === null || !$name->is(T_STRING)) {
                continue;
            }

            $declared[] = ($namespace === '' ? '' : $namespace . '\\') . $name->text;
        }

        return $declared;
    }

    /**
     * Read the namespace a `namespace` token opens.
     *
     * Stops at the `;` or `{` that ends the declaration. The brace of a braced
     * namespace block is INTENTIONALLY consumed here (the returned index points
     * at it) so the caller never counts it as depth: the body of
     * `namespace Foo { … }` is top-level code, and counting its brace would
     * hide every declaration in a file written that way.
     *
     * Returns null when the token is not a namespace DECLARATION at all but the
     * `namespace\Foo` relative-name operator, whose first token is a separator.
     *
     * @param list<PhpToken> $tokens The significant-token stream.
     * @param int $index Index of the T_NAMESPACE token.
     * @param int $count Total token count.
     * @return array{0: string, 1: int}|null The namespace (no leading `\`) and
     *         the index the caller should resume from.
     */
    private static function readNamespace(array $tokens, int $index, int $count): ?array
    {
        $first = $tokens[$index + 1] ?? null;
        if ($first === null || !$first->is([T_STRING, T_NAME_QUALIFIED, '{'])) {
            return null;
        }

        $namespace = '';

        for ($next = $index + 1; $next < $count; $next++) {
            $token = $tokens[$next];
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
                $namespace .= $token->text;
                continue;
            }
            break;
        }

        return [trim($namespace, '\\'), $next];
    }
}
