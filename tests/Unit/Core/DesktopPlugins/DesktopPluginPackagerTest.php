<?php

declare(strict_types=1);

namespace Tests\Unit\Core\DesktopPlugins;

use PHPUnit\Framework\TestCase;
use Whity\Core\DesktopPlugins\DesktopPluginPackager;
use Whity\Core\DesktopPlugins\DesktopPluginReleaseException;
use Whity\Core\PluginInstaller;
use ZipArchive;

/**
 * {@see DesktopPluginPackager} — proves the produced .zip satisfies the exact
 * package contract the desktop installer enforces (single top-level directory
 * === name, checksum/size computed from the final bytes) and that it refuses,
 * at release time, inputs the device would reject or quarantine.
 */
final class DesktopPluginPackagerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/whity-pkg-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    public function testPackagesWithSingleTopLevelDirectoryMatchingName(): void
    {
        $source = $this->writePlugin('Widget');
        $zipPath = $this->workDir . '/out/widget.zip';

        $result = (new DesktopPluginPackager())->package($source, 'Widget', $zipPath);

        $this->assertSame('Widget', $result->name);
        $this->assertFileExists($zipPath);

        $topLevel = [];
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $topLevel[explode('/', $name)[0]] = true;
            $this->assertStringStartsWith('Widget/', $name, "entry escapes top-level dir: {$name}");
        }
        $zip->close();

        $this->assertSame(['Widget'], array_keys($topLevel));
    }

    public function testChecksumAndSizeMatchTheFinalFile(): void
    {
        $source = $this->writePlugin('Widget');
        $zipPath = $this->workDir . '/widget.zip';

        $result = (new DesktopPluginPackager())->package($source, 'Widget', $zipPath);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->sha256);
        $this->assertSame(hash_file('sha256', $zipPath), $result->sha256);
        $this->assertSame(filesize($zipPath), $result->sizeBytes);
        $this->assertGreaterThan(0, $result->entryCount);
    }

    public function testRejectsInvalidName(): void
    {
        $source = $this->writePlugin('Widget');

        $this->expectException(DesktopPluginReleaseException::class);
        (new DesktopPluginPackager())->package($source, 'bad name!', $this->workDir . '/x.zip');
    }

    public function testRejectsNamespaceNotUnderName(): void
    {
        // Source declares `namespace Widget` but we package it as `Other` — the
        // device's dir->namespace loader would never find the class.
        $source = $this->writePlugin('Widget');

        $this->expectException(DesktopPluginReleaseException::class);
        $this->expectExceptionMessageMatches('/will not autoload/');
        (new DesktopPluginPackager())->package($source, 'Other', $this->workDir . '/x.zip');
    }

    public function testRejectsSourceWithoutPluginInterface(): void
    {
        $source = $this->workDir . '/NoIface';
        mkdir($source, 0o775, true);
        file_put_contents(
            $source . '/Thing.php',
            "<?php\nnamespace NoIface;\nclass Thing { public function x(): int { return 1; } }\n"
        );

        $this->expectException(DesktopPluginReleaseException::class);
        $this->expectExceptionMessageMatches('/implements PluginInterface/');
        (new DesktopPluginPackager())->package($source, 'NoIface', $this->workDir . '/x.zip');
    }

    public function testCompressionRatioGuardUsesFloatDivision(): void
    {
        // Regression: integer division floored the ratio and let a package the
        // guard's own limit forbids slip through. 51201/256 = 200.0039 > 200, but
        // intdiv(51201, 256) = 200, which is NOT > 200. (Compressed must be >= 256
        // for the ratio to be evaluated at all.)
        $this->assertTrue(PluginInstaller::exceedsCompressionRatio(51201, 256));
        // Exactly at the limit is allowed (51200/256 = 200, not > 200).
        $this->assertFalse(PluginInstaller::exceedsCompressionRatio(51200, 256));
        // Too little compressed data for the ratio to be meaningful — skipped.
        $this->assertFalse(PluginInstaller::exceedsCompressionRatio(1_000_000, 255));
    }

    public function testAcceptsPluginReachingInterfaceThroughAnAbstractBaseInSource(): void
    {
        $dir = $this->workDir . '/Inherited';
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/Base.php',
            "<?php\nnamespace Inherited;\nabstract class Base implements \\Whity\\Sdk\\PluginInterface {}\n"
        );
        // The instantiable class only `extends Base` — no direct implements.
        file_put_contents(
            $dir . '/InheritedPlugin.php',
            "<?php\nnamespace Inherited;\nclass InheritedPlugin extends Base { public function getName(): string { return 'Inherited'; } }\n"
        );

        $result = (new DesktopPluginPackager())->package($dir, 'Inherited', $this->workDir . '/inherited.zip');
        $this->assertSame('Inherited', $result->name);
    }

    public function testAcceptsPluginReachingInterfaceThroughASubInterfaceInSource(): void
    {
        $dir = $this->workDir . '/Sub';
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/SubPluginInterface.php',
            "<?php\nnamespace Sub;\ninterface SubPluginInterface extends \\Whity\\Sdk\\PluginInterface {}\n"
        );
        file_put_contents(
            $dir . '/SubPlugin.php',
            "<?php\nnamespace Sub;\nclass SubPlugin implements SubPluginInterface { public function getName(): string { return 'Sub'; } }\n"
        );

        $result = (new DesktopPluginPackager())->package($dir, 'Sub', $this->workDir . '/sub.zip');
        $this->assertSame('Sub', $result->name);
    }

    public function testAcceptsPluginWhoseSupertypeIsDefinedOutsideTheSource(): void
    {
        // The class implements an SDK interface not present in the source; we
        // can't disprove it descends from PluginInterface, so we defer to the
        // device rather than false-reject a valid plugin.
        $dir = $this->workDir . '/External';
        mkdir($dir, 0o775, true);
        file_put_contents(
            $dir . '/ExternalPlugin.php',
            "<?php\nnamespace External;\nclass ExternalPlugin implements \\Whity\\Sdk\\PluginFrontendInterface { public function getName(): string { return 'External'; } }\n"
        );

        $result = (new DesktopPluginPackager())->package($dir, 'External', $this->workDir . '/external.zip');
        $this->assertSame('External', $result->name);
    }

    public function testEnforcesEntryCountGuard(): void
    {
        $source = $this->writePlugin('Big');
        // One .php (the plugin) plus enough loose files to exceed the limit.
        for ($i = 0; $i <= PluginInstaller::MAX_ZIP_ENTRIES; $i++) {
            file_put_contents($source . "/asset_{$i}.txt", 'x');
        }

        $this->expectException(DesktopPluginReleaseException::class);
        $this->expectExceptionMessageMatches('/too many entries/');
        (new DesktopPluginPackager())->package($source, 'Big', $this->workDir . '/big.zip');
    }

    /**
     * Write a minimal, valid plugin source tree and return its directory.
     */
    private function writePlugin(string $name): string
    {
        $dir = $this->workDir . '/' . $name;
        mkdir($dir . '/Api', 0o775, true);
        file_put_contents(
            $dir . '/' . $name . 'Plugin.php',
            "<?php\ndeclare(strict_types=1);\nnamespace {$name};\n"
            . "class {$name}Plugin implements \\Whity\\Sdk\\PluginInterface {\n"
            . "    public function getName(): string { return '{$name}'; }\n"
            . "}\n"
        );
        file_put_contents(
            $dir . '/Api/Handler.php',
            "<?php\ndeclare(strict_types=1);\nnamespace {$name}\\Api;\n"
            . "class Handler { public function run(int \$n): int { \$doubled = \$n * 2; return \$doubled; } }\n"
        );

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
