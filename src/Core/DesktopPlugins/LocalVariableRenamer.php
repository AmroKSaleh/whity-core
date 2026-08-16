<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Global_;

/**
 * Renames local variables inside "clean" function scopes, provably preserving
 * behaviour. Operates directly on the AST (mutating {@see Variable} nodes in
 * place) rather than as a NodeVisitor, because a correct rename needs the full
 * variable set of a scope known BEFORE any variable is renamed.
 *
 * SAFETY MODEL — a scope's local variables are renamed only when ALL hold:
 *   - It is NOT an arrow function (implicit by-value capture of enclosing
 *     variables makes renaming across the boundary unsafe).
 *   - It contains NO nested function-like (closure/arrow/function). This single
 *     rule sidesteps every capture question: with no inner scope there is
 *     nothing that could reference this scope's variables from outside it.
 *   - It uses no name-reflective construct: compact()/extract()/
 *     get_defined_vars()/func_get_args() & friends, eval(), variable-variables
 *     (`$$x`), or `global $x`.
 *
 * Within a renameable scope, these names are EXCLUDED from renaming:
 *   - Parameters — renaming them would break PHP 8 named-argument callers.
 *   - Closure `use (...)` variables — bound from the enclosing scope.
 *   - `$this` and the superglobals.
 * Every other local (including interpolated `"$x"`, destructuring targets and
 * catch variables — all just {@see Variable} nodes) is renamed consistently, so
 * the transformation is a within-scope bijection: behaviour is identical.
 *
 * Coverage is deliberately traded for correctness: a method that contains a
 * closure keeps its own variables' names, but that closure's OWN locals are
 * still renamed when it is itself clean.
 */
final class LocalVariableRenamer
{
    /**
     * Functions whose presence makes a scope's variable names observable by
     * name/position, so renaming would change behaviour.
     */
    private const NAME_REFLECTIVE_FUNCTIONS = [
        'compact', 'extract', 'get_defined_vars',
        'func_get_args', 'func_get_arg', 'func_num_args',
        'parse_str', 'mb_parse_str', 'eval',
    ];

    private const SUPERGLOBALS = [
        'GLOBALS', '_GET', '_POST', '_REQUEST', '_SERVER',
        '_SESSION', '_ENV', '_COOKIE', '_FILES', 'this',
        'http_response_header', 'argv', 'argc',
    ];

    /**
     * Rename local variables throughout a parsed file.
     *
     * @param Node[] $nodes The file's top-level statements.
     */
    public static function apply(array $nodes): void
    {
        // The file/global scope is never renamed; only function-like scopes are.
        foreach ($nodes as $node) {
            self::findScopes($node);
        }
    }

    private static function findScopes(Node $node): void
    {
        if ($node instanceof FunctionLike) {
            // This handles the scope's body AND recurses into nested scopes.
            self::processScope($node);

            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                self::findScopes($child);
            } elseif (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Node) {
                        self::findScopes($c);
                    }
                }
            }
        }
    }

    private static function processScope(FunctionLike $fn): void
    {
        $body = $fn->getStmts() ?? [];

        /** @var array<string, list<Variable>> $vars */
        $vars = [];
        /** @var list<FunctionLike> $nested */
        $nested = [];
        $unsafe = false;

        foreach ($body as $stmt) {
            if ($stmt instanceof Node) {
                self::collect($stmt, $vars, $nested, $unsafe);
            }
        }

        $renameable = !($fn instanceof ArrowFunction) && !$unsafe && $nested === [];

        if ($renameable) {
            $excluded = self::excludedNames($fn);
            $forbidden = $excluded;
            foreach (array_keys($vars) as $name) {
                $forbidden[$name] = true;
            }

            $map = self::buildRenameMap(array_keys($vars), $excluded, $forbidden);
            foreach ($map as $original => $replacement) {
                foreach ($vars[$original] as $variable) {
                    $variable->name = $replacement;
                }
            }
        }

        // Recurse into nested scopes regardless of this scope's renameability.
        foreach ($nested as $child) {
            self::processScope($child);
        }
    }

    /**
     * Collect, for ONE scope, its directly-owned variables, the function-like
     * children that begin their own scopes, and whether any name-reflective
     * construct makes the scope unsafe. Does not descend into nested scopes.
     *
     * @param array<string, list<Variable>> $vars
     * @param list<FunctionLike>            $nested
     */
    private static function collect(Node $node, array &$vars, array &$nested, bool &$unsafe): void
    {
        if ($node instanceof FunctionLike) {
            $nested[] = $node;

            return;
        }

        if ($node instanceof Variable) {
            if (is_string($node->name)) {
                $vars[$node->name][] = $node;
            } else {
                // `$$x` / `${expr}` — the target is dynamic; disable renaming.
                $unsafe = true;
            }

            return;
        }

        if ($node instanceof Global_) {
            // `global $x` links a local name to a real global by that name.
            $unsafe = true;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            if (in_array(strtolower($node->name->toString()), self::NAME_REFLECTIVE_FUNCTIONS, true)) {
                $unsafe = true;
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                self::collect($child, $vars, $nested, $unsafe);
            } elseif (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Node) {
                        self::collect($c, $vars, $nested, $unsafe);
                    }
                }
            }
        }
    }

    /**
     * Names that must not be renamed in a scope: its parameters, any closure
     * `use` variables, `$this` and the superglobals.
     *
     * @return array<string, true>
     */
    private static function excludedNames(FunctionLike $fn): array
    {
        $excluded = [];
        foreach (self::SUPERGLOBALS as $name) {
            $excluded[$name] = true;
        }

        foreach ($fn->getParams() as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $excluded[$param->var->name] = true;
            }
        }

        if ($fn instanceof Closure) {
            foreach ($fn->uses as $use) {
                if (is_string($use->var->name)) {
                    $excluded[$use->var->name] = true;
                }
            }
        }

        return $excluded;
    }

    /**
     * Build a bijection from renameable names to fresh short identifiers that
     * collide with nothing already present in the scope.
     *
     * (Keys are declared int|string because PHP coerces a numeric-string array
     * key to int — a PHP variable name can never actually be numeric, but the
     * key type reflects the language, not that guarantee.)
     *
     * @param list<int|string>        $names
     * @param array<int|string, true> $excluded
     * @param array<int|string, true> $forbidden Names the generated identifiers must avoid.
     * @return array<int|string, string>
     */
    private static function buildRenameMap(array $names, array $excluded, array $forbidden): array
    {
        $map = [];
        $index = 0;
        foreach ($names as $name) {
            if (isset($excluded[$name])) {
                continue;
            }

            do {
                $candidate = self::nameForIndex($index++);
            } while (isset($forbidden[$candidate]) || isset($map[$candidate]));

            $map[$name] = $candidate;
            // The freshly-issued name is now taken for this scope.
            $forbidden[$candidate] = true;
        }

        return $map;
    }

    /** 0 -> "a", 25 -> "z", 26 -> "aa", … — always a valid PHP identifier. */
    private static function nameForIndex(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $index--;
            $name = chr(97 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }
}
