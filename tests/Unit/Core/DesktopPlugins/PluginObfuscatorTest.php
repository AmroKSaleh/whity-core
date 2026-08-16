<?php

declare(strict_types=1);

namespace Tests\Unit\Core\DesktopPlugins;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Whity\Core\DesktopPlugins\ObfuscationException;
use Whity\Core\DesktopPlugins\PluginObfuscator;

/**
 * {@see PluginObfuscator} — proves the two things that matter for a package the
 * device will actually load: the transform PRESERVES every name PSR-4 and the
 * PluginInterface contract depend on, and it PRESERVES runtime behaviour. The
 * behaviour proof is empirical: obfuscated source is written out, required, and
 * its function is called — the returned value must be identical.
 */
final class PluginObfuscatorTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    public function testStripsCommentsAndDocblocks(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Demo;
        /** A docblock that must not survive. */
        class Thing
        {
            // an inline comment
            public function go(): int
            {
                return 1; # trailing comment
            }
        }
        PHP;

        $out = (new PluginObfuscator())->obfuscate($code);

        $this->assertStringNotContainsString('docblock that must not survive', $out);
        $this->assertStringNotContainsString('inline comment', $out);
        $this->assertStringNotContainsString('trailing comment', $out);
        $this->assertStringNotContainsString('/**', $out);
    }

    public function testPreservesNamespaceClassAndMethodNames(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Acme\Widget;
        use Whity\Sdk\PluginInterface;
        class WidgetPlugin implements PluginInterface
        {
            public function getName(): string { return 'Widget'; }
            public function importantMethod(int $x): int { $local = $x + 1; return $local; }
        }
        PHP;

        $out = (new PluginObfuscator())->obfuscate($code);

        $this->assertStringContainsString('namespace Acme\Widget', $out);
        $this->assertStringContainsString('class WidgetPlugin', $out);
        $this->assertStringContainsString('implements PluginInterface', $out);
        $this->assertStringContainsString('function getName', $out);
        $this->assertStringContainsString('function importantMethod', $out);
        // Method parameter names are part of the contract (named args) — kept.
        $this->assertStringContainsString('$x', $out);
    }

    public function testPreservesBehaviourOfCleanFunction(): void
    {
        $uid = $this->uid();
        $code = "<?php function calc_{$uid}(int \$a, int \$b): int { \$sum = \$a + \$b; \$scaled = \$sum * 3; return \$scaled; }";

        $out = (new PluginObfuscator())->obfuscate($code);

        // The named local variables are gone…
        $this->assertStringNotContainsString('$sum', $out);
        $this->assertStringNotContainsString('$scaled', $out);
        // …but the parameters (named-arg contract) remain…
        $this->assertStringContainsString('$a', $out);
        $this->assertStringContainsString('$b', $out);
        // …and the function computes exactly the same result.
        $this->loadCode($out);
        $fn = $this->fn("calc_{$uid}");
        $this->assertSame(24, $fn(3, 5));
    }

    public function testDoesNotRenameWhenCompactIsUsed(): void
    {
        $uid = $this->uid();
        $code = "<?php function pack_{$uid}(): array { \$keepMe = 7; return compact('keepMe'); }";

        $out = (new PluginObfuscator())->obfuscate($code);

        // compact() reads the variable by NAME — renaming it would break it.
        $this->assertStringContainsString('$keepMe', $out);
        $this->loadCode($out);
        $fn = $this->fn("pack_{$uid}");
        $this->assertSame(['keepMe' => 7], $fn());
    }

    public function testDoesNotRenameParameters(): void
    {
        $uid = $this->uid();
        $code = "<?php function id_{$uid}(int \$publicParam): int { return \$publicParam; }";

        $out = (new PluginObfuscator())->obfuscate($code);

        $this->assertStringContainsString('$publicParam', $out);
    }

    public function testStringEncodingHidesLiteralAndPreservesValue(): void
    {
        $uid = $this->uid();
        $code = "<?php function secret_{$uid}(): string { return 'topSecretValue'; }";

        $out = (new PluginObfuscator(renameLocals: true, encodeStrings: true))->obfuscate($code);

        $this->assertStringNotContainsString('topSecretValue', $out);
        $this->assertStringContainsString('base64_decode', $out);
        $this->loadCode($out);
        $fn = $this->fn("secret_{$uid}");
        $this->assertSame('topSecretValue', $fn());
    }

    public function testStringEncodingSkipsConstantContext(): void
    {
        $uid = $this->uid();
        // A class constant is a constant expression — a function call is illegal
        // there, so the literal must be left alone.
        $code = "<?php class Konst_{$uid} { const LABEL = 'constLiteral'; }";

        $out = (new PluginObfuscator(renameLocals: true, encodeStrings: true))->obfuscate($code);

        $this->assertStringContainsString("'constLiteral'", $out);
        // And the class still defines the constant with the right value.
        $this->loadCode($out);
        $class = "Konst_{$uid}";
        $this->assertSame('constLiteral', constant("{$class}::LABEL"));
    }

    public function testFailsClosedOnUnparseableInput(): void
    {
        $this->expectException(ObfuscationException::class);
        (new PluginObfuscator())->obfuscate('<?php class { function (');
    }

    public function testOutputAlwaysReparses(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Acme;
        class Complex {
            public function m(array $items): array {
                $out = [];
                foreach ($items as $key => $value) {
                    $doubled = $value * 2;
                    $out[$key] = $doubled;
                }
                $closure = function ($n) use ($out) { return $n + count($out); };
                return ['mapped' => $out, 'extra' => $closure(1)];
            }
        }
        PHP;

        $out = (new PluginObfuscator(renameLocals: true, encodeStrings: true))->obfuscate($code);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->assertNotNull($parser->parse($out));
    }

    private function loadCode(string $code): void
    {
        $file = sys_get_temp_dir() . '/whity-obf-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($file, $code);
        $this->tmpFiles[] = $file;
        require $file;
    }

    /** Resolve a just-loaded function name to a callable (narrowed for analysis). */
    private function fn(string $name): callable
    {
        $this->assertTrue(function_exists($name), "expected obfuscated function {$name} to be defined");

        return $name;
    }

    private function uid(): string
    {
        return 'u' . bin2hex(random_bytes(6));
    }
}
