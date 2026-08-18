<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\NodeVisitorAbstract;

/**
 * Opt-in string-literal encoder: rewrites a plain string literal `'secret'` to
 * `\base64_decode('c2VjcmV0')`, so the plain text no longer appears in the
 * shipped source (defeats casual `strings`/grep). base64 is a builtin, always
 * available and byte-exact — no injected helper, no new dependency — and the
 * base64 alphabet never contains a quote or backslash, so the emitted literal is
 * always syntactically safe.
 *
 * This is obfuscation, not encryption: the value is trivially recoverable at
 * runtime (that is the point — the PHP must still run unmodified). It is off by
 * default because it necessarily skips every CONSTANT-EXPRESSION context, where
 * a function call is illegal:
 *   const / class-const / enum-case values, parameter & property & static-var
 *   defaults, and attribute arguments.
 * It also leaves alone a string used as a class name in `new`/`::`/`instanceof`.
 * Everything else — call arguments, returns, assignments, echoes, concatenation,
 * runtime array literals — is encoded.
 */
final class StringLiteralEncoder extends NodeVisitorAbstract
{
    /** Ancestor node types inside which a string is a constant expression. */
    private const CONSTANT_CONTEXTS = [
        Const_::class,
        ClassConst::class,
        EnumCase::class,
        Declare_::class,
        Param::class,
        PropertyItem::class,
        StaticVar::class,
        Attribute::class,
    ];

    /** @var list<Node> The current ancestor chain (root first, self last). */
    private array $stack = [];

    public function enterNode(Node $node): null
    {
        $this->stack[] = $node;

        return null;
    }

    public function leaveNode(Node $node): Node|null
    {
        array_pop($this->stack);

        if (!$node instanceof String_) {
            return null;
        }
        if ($node->value === '') {
            return null;
        }
        if (!$this->isSafeContext()) {
            return null;
        }

        return new FuncCall(
            new FullyQualified('base64_decode'),
            [new Arg(new String_(base64_encode($node->value)))],
        );
    }

    /** True when the just-left string may be replaced with a function call. */
    private function isSafeContext(): bool
    {
        // $this->stack holds the string's ancestors (self already popped).
        foreach ($this->stack as $ancestor) {
            if (in_array($ancestor::class, self::CONSTANT_CONTEXTS, true)) {
                return false;
            }
        }

        $parent = end($this->stack);
        if ($parent instanceof New_
            || $parent instanceof StaticCall
            || $parent instanceof StaticPropertyFetch
            || $parent instanceof ClassConstFetch
            || $parent instanceof Instanceof_
        ) {
            // A string sitting in the class-name position must stay a literal.
            if (isset($parent->class) && $parent->class instanceof String_) {
                return false;
            }
        }

        return true;
    }
}
