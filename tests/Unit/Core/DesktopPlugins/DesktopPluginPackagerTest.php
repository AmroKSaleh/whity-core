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
