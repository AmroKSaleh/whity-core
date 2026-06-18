<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Tests\Support\PluginPackageFixtures;
use Whity\Core\Exception\PluginAlreadyInstalled;
use Whity\Core\Exception\PluginExtractionUnsafe;
use Whity\Core\Exception\PluginIncompatible;
use Whity\Core\Exception\PluginNameUnsafe;
use Whity\Core\Exception\PluginPackageInvalid;
use Whity\Core\PluginInstaller;
use Whity\Core\PluginLoader;
use Whity\Sdk\Http\UploadedFile;

/**
 * WC-220: staged-install guard coverage for {@see PluginInstaller}.
 *
 * Each test drives the installer with a programmatically built package fixture
 * and asserts both the typed outcome AND that the filesystem is left exactly as
 * before on every failure path (no temp dir, no partial plugins/<Name>).
 */
final class PluginInstallerTest extends TestCase
{
    private string $pluginDir;
    private string $workDir;

    protected function setUp(): void
    {
        $this->pluginDir = sys_get_temp_dir() . '/whity_installer_plugins_' . uniqid();
        $this->workDir = sys_get_temp_dir() . '/whity_installer_work_' . uniqid();
        mkdir($this->pluginDir, 0775, true);
        mkdir($this->workDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->pluginDir);
        $this->removeRecursive($this->workDir);
    }

    private function installer(): PluginInstaller
    {
        return new PluginInstaller($this->pluginDir);
    }

    /**
     * Wrap a built package file as an UploadedFile (UPLOAD_ERR_OK).
     */
    private function upload(string $path, ?string $clientName = null): UploadedFile
    {
        return new UploadedFile(
            $path,
            (int) filesize($path),
            UPLOAD_ERR_OK,
            $clientName ?? basename($path),
            'application/octet-stream'
        );
    }

    /** Count how many temp upload working dirs currently exist. */
    private function tempWorkDirCount(): int
    {
        $matches = glob(sys_get_temp_dir() . '/whity_plugin_upload_*') ?: [];
        return count($matches);
    }

    public function testValidZipStagesDisabledWithSentinelAndNoMigrationsRecorded(): void
    {
        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'AcmeUploaded');

        $entry = $this->installer()->installFromUpload($this->upload($zip));

        self::assertSame('AcmeUploaded', $entry['name']);
        self::assertSame('disabled', $entry['status']);
        self::assertFalse($entry['enabled']);

        // Landed as a directory plugin marked disabled by the sentinel.
        self::assertDirectoryExists($this->pluginDir . '/AcmeUploaded');
        self::assertFileExists(
            $this->pluginDir . '/AcmeUploaded/' . PluginLoader::DIR_DISABLED_SENTINEL
        );
        self::assertFileExists($this->pluginDir . '/AcmeUploaded/Plugin.php');

        // No migrations run during staging — installer never touches the DB.
        self::assertSame(0, $this->tempWorkDirCount(), 'temp work dir must be cleaned up');
    }

    public function testValidSinglePhpStagesAsDisabledFile(): void
    {
        $php = PluginPackageFixtures::validSinglePhp($this->workDir, 'AcmeSingle');

        $entry = $this->installer()->installFromUpload($this->upload($php));

        self::assertSame('AcmeSingle', $entry['name']);
        self::assertSame('disabled', $entry['status']);
        self::assertFileExists($this->pluginDir . '/AcmeSingle.php.disabled');
        self::assertFileDoesNotExist($this->pluginDir . '/AcmeSingle.php');
    }

    public function testZipSlipIsRejectedAndNothingIsWrittenOutsideTarget(): void
    {
        $marker = $this->workDir . '/evil_zipslip_marker.php';
        self::assertFileDoesNotExist($marker);

        $zip = PluginPackageFixtures::zipSlipArchive($this->workDir);

        try {
            $this->installer()->installFromUpload($this->upload($zip));
            self::fail('Expected PluginExtractionUnsafe');
        } catch (PluginExtractionUnsafe $e) {
            // expected
        }

        // The escape target must not have been created anywhere.
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist(dirname($this->workDir) . '/evil_zipslip_marker.php');
        $this->assertPluginDirEmpty();
        self::assertSame(0, $this->tempWorkDirCount());
    }

    public function testZipBombIsRejected(): void
    {
        $zip = PluginPackageFixtures::zipBombArchive($this->workDir);

        $this->expectException(PluginExtractionUnsafe::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            $this->assertPluginDirEmpty();
            self::assertSame(0, $this->tempWorkDirCount());
        }
    }

    public function testUnsafePluginNameIsRejected(): void
    {
        $zip = PluginPackageFixtures::unsafeNameZip($this->workDir);

        $this->expectException(PluginNameUnsafe::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            $this->assertPluginDirEmpty();
            self::assertSame(0, $this->tempWorkDirCount());
        }
    }

    public function testZeroPluginClassesIsRejected(): void
    {
        $zip = PluginPackageFixtures::noPluginZip($this->workDir);

        $this->expectException(PluginPackageInvalid::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            $this->assertPluginDirEmpty();
        }
    }

    public function testMultiplePluginClassesIsRejected(): void
    {
        $zip = PluginPackageFixtures::multiPluginZip($this->workDir);

        $this->expectException(PluginPackageInvalid::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            $this->assertPluginDirEmpty();
        }
    }

    public function testIncompatiblePluginIsNotStaged(): void
    {
        $zip = PluginPackageFixtures::incompatibleZip($this->workDir, 'IncompatibleUploaded');

        $this->expectException(PluginIncompatible::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            self::assertDirectoryDoesNotExist($this->pluginDir . '/IncompatibleUploaded');
            $this->assertPluginDirEmpty();
            self::assertSame(0, $this->tempWorkDirCount());
        }
    }

    public function testCollisionIsRejected(): void
    {
        // First install succeeds.
        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'CollideUploaded');
        $this->installer()->installFromUpload($this->upload($zip));
        self::assertDirectoryExists($this->pluginDir . '/CollideUploaded');

        // A second, distinct archive with the SAME plugin name collides.
        $secondDir = $this->workDir . '/second';
        mkdir($secondDir, 0775, true);
        $zip2 = PluginPackageFixtures::validDirectoryZip($secondDir, 'CollideUploaded');

        $this->expectException(PluginAlreadyInstalled::class);
        $this->installer()->installFromUpload($this->upload($zip2));
    }

    public function testNonZipNonPhpUploadIsRejected(): void
    {
        $junk = $this->workDir . '/notes.txt';
        file_put_contents($junk, "just some text, not a plugin\n");

        $this->expectException(PluginPackageInvalid::class);
        try {
            $this->installer()->installFromUpload($this->upload($junk));
        } finally {
            $this->assertPluginDirEmpty();
        }
    }

    public function testUploadErrorIsRejected(): void
    {
        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'WontMatter');
        $bad = new UploadedFile($zip, (int) filesize($zip), UPLOAD_ERR_PARTIAL, 'p.zip', 'application/zip');

        $this->expectException(PluginPackageInvalid::class);
        $this->installer()->installFromUpload($bad);
    }

    public function testClientFilenameCannotMasqueradeNonZipAsZip(): void
    {
        // A text file uploaded with a .zip client filename must still be rejected
        // because detection is by CONTENT, not the attacker-controlled name.
        $junk = $this->workDir . '/payload';
        file_put_contents($junk, "definitely not a zip or php\n");

        $this->expectException(PluginPackageInvalid::class);
        $this->installer()->installFromUpload($this->upload($junk, 'innocent.zip'));
    }

    public function testSentinelMakesLoaderListPluginDisabled(): void
    {
        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'LoaderListed');
        $loader = new PluginLoader($this->pluginDir, new \Whity\Core\Router(''));
        $installer = new PluginInstaller($this->pluginDir, $loader);

        $installer->installFromUpload($this->upload($zip));
        $loader->reload();

        $names = array_map(static fn($m) => $m['name'], $loader->getPluginMetadata());
        // The staged plugin is present (disabled) — never registered as active.
        $statuses = [];
        foreach ($loader->getPluginMetadata() as $m) {
            $statuses[$m['name']] = $m['status'];
        }
        self::assertArrayHasKey('LoaderListed', $statuses);
        self::assertSame('disabled', $statuses['LoaderListed']);
    }

    private function assertPluginDirEmpty(): void
    {
        $entries = array_values(array_diff((array) scandir($this->pluginDir), ['.', '..']));
        self::assertSame([], $entries, 'plugins dir must be left clean on failure');
    }

    private function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeRecursive($path . '/' . (string) $entry);
        }
        @rmdir($path);
    }
}
