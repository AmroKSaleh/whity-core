<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\DeclaredClassScanner;

/**
 * #841 — what a PHP file WILL declare, decided before it is allowed to run.
 *
 * The scanner is the input to the only guard that can prevent a redeclaration
 * fatal, so its two error directions are not symmetric and neither is this
 * test's emphasis:
 *
 *  - OVER-reporting a name costs a WORKING plugin its load. The loader refuses
 *    any file that claims a name something else already declared, so a `class`
 *    keyword mistaken for a declaration — `Foo::class`, an anonymous class, a
 *    shim inside `if (!class_exists(…))` — takes an innocent plugin offline.
 *    Most of the cases below exist to pin that down.
 *  - UNDER-reporting only returns that one file to the behaviour we already
 *    had, so the exotic-syntax cases fail safe rather than dangerously.
 *
 * It is a lexical pass on purpose: the question is what a file declares, and
 * the one way to answer it without a redeclaration fatal is to not run the file.
 */
final class DeclaredClassScannerTest extends TestCase
{
    public function testReportsTheClassADirectoryPluginDeclares(): void
    {
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace HelloWorld;

        use Whity\Sdk\PluginInterface;

        final class HelloWorldPlugin implements PluginInterface
        {
        }
        PHP;

        self::assertSame(['HelloWorld\HelloWorldPlugin'], DeclaredClassScanner::declaredIn($source));
    }

    /**
     * Every class-like PHP can refuse to redeclare, in declaration order: a
     * `Cannot redeclare` fatal is not specific to `class`.
     */
    public function testReportsInterfacesTraitsAndEnumsToo(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Acme\Deep;

        interface Contract {}
        trait Helper {}
        enum Status: string { case Live = 'live'; }
        abstract class Base {}
        final readonly class Impl extends Base implements Contract {}
        PHP;

        self::assertSame(
            [
                'Acme\Deep\Contract',
                'Acme\Deep\Helper',
                'Acme\Deep\Status',
                'Acme\Deep\Base',
                'Acme\Deep\Impl',
            ],
            DeclaredClassScanner::declaredIn($source)
        );
    }

    /**
     * The polyfill idiom. Requiring this file when `Shim` already exists is
     * SAFE — the guard is the point of the `if` — so reporting it would refuse
     * a plugin that works perfectly. Nothing nested inside braces is reported,
     * for the same reason: it declares nothing until something runs it.
     */
    public function testDoesNotReportConditionalOrNestedDeclarations(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Acme;

        if (!class_exists('Acme\Shim')) {
            class Shim {}
        }

        function make(): object
        {
            class Inner {}

            return new Inner();
        }

        final class Real {}
        PHP;

        self::assertSame(['Acme\Real'], DeclaredClassScanner::declaredIn($source));
    }

    /**
     * `T_CLASS` without a declaration behind it: a constant expression and an
     * anonymous class. Both are ordinary in plugin code and neither can be
     * redeclared.
     */
    public function testDoesNotReportClassConstantsOrAnonymousClasses(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Acme;

        $id = \Whity\Sdk\PluginInterface::class;
        $anon = new class extends \stdClass {};

        final class OnlyThis {}
        PHP;

        self::assertSame(['Acme\OnlyThis'], DeclaredClassScanner::declaredIn($source));
    }

    /**
     * Braced namespace blocks. Their body IS top-level code, so a file written
     * this way must not have every declaration swallowed as "nested".
     */
    public function testHandlesBracedNamespaceBlocks(): void
    {
        $source = <<<'PHP'
        <?php

        namespace First { class One {} }
        namespace Second { class Two {} }
        namespace { class Global_ {} }
        PHP;

        self::assertSame(['First\One', 'Second\Two', 'Global_'], DeclaredClassScanner::declaredIn($source));
    }

    /**
     * A file with no namespace declares into the global namespace — which is
     * where the collisions with core and vendor code would happen.
     */
    public function testReportsGlobalNamespaceDeclarations(): void
    {
        self::assertSame(['Bare'], DeclaredClassScanner::declaredIn('<?php class Bare {}'));
    }

    /**
     * String interpolation opens braces that a plain `{` counter would never
     * see closed, which would push everything after it to a phantom depth and
     * silently stop reporting. The declaration after the interpolation is the
     * assertion.
     */
    public function testInterpolationDoesNotUnbalanceTheDepthCounter(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Acme;

        $greeting = "hello {$name} and ${legacy}";

        final class AfterTheString {}
        PHP;

        self::assertSame(['Acme\AfterTheString'], DeclaredClassScanner::declaredIn($source));
    }

    /**
     * `namespace\Thing` is the relative-name operator, not a declaration; it
     * must not be read as re-entering some other namespace.
     */
    public function testRelativeNamespaceOperatorDoesNotChangeTheNamespace(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Acme;

        $value = namespace\Registry::get('x');

        final class StillAcme {}
        PHP;

        self::assertSame(['Acme\StillAcme'], DeclaredClassScanner::declaredIn($source));
    }

    /**
     * An unparseable or empty file declares nothing we can promise, and saying
     * so is the under-reporting direction: the require fails on its own terms.
     */
    public function testUnparseableSourceReportsNothingRatherThanGuessing(): void
    {
        self::assertSame([], DeclaredClassScanner::declaredIn(''));
        self::assertSame([], DeclaredClassScanner::declaredIn('<?php return ["config" => true];'));
    }
}
