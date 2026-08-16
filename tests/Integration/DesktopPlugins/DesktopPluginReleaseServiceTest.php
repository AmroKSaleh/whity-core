<?php

declare(strict_types=1);

namespace Tests\Integration\DesktopPlugins;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DesktopPluginsApiHandler;
use Whity\Core\DesktopPlugins\DesktopPluginReleaseException;
use Whity\Core\DesktopPlugins\DesktopPluginReleaseService;
use Whity\Core\Request;
use ZipArchive;

/**
 * End-to-end for the desktop-plugin release pipeline against a REAL migrated
 * schema (SchemaFromMigrations builds `desktop_plugin_releases` from migration
 * 097) and the REAL {@see DesktopPluginsApiHandler} that serves it. Packages the
 * bundled HelloWorld plugin, so the catalog/download contract, the checksum
 * (no-drift) invariant, immutability, and that the OBFUSCATED package still
 * loads and behaves are all exercised together.
 */
final class DesktopPluginReleaseServiceTest extends TestCase
{
    private PDO $pdo;
    private string $storageDir;
    private string $source;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->storageDir = sys_get_temp_dir() . '/whity-dpr-int-' . bin2hex(random_bytes(6));
        mkdir($this->storageDir, 0o775, true);
        $this->source = dirname(__DIR__, 3) . '/templates/tauri-desktop/php-host/plugins/HelloWorld';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->storageDir);
    }

    public function testReleaseInsertsRowAndStoresPackage(): void
    {
        $result = $this->service()->release($this->source, 'HelloWorld', '1.0.0');

        $this->assertSame('HelloWorld/1.0.0/package.zip', $result->storagePath);
        $this->assertFileExists($this->storageDir . '/' . $result->storagePath);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->sha256);
        $this->assertSame(
            hash_file('sha256', $this->storageDir . '/' . $result->storagePath),
            $result->sha256
        );

        $stmt = $this->pdo->query(
            "SELECT * FROM desktop_plugin_releases WHERE plugin_name = 'HelloWorld' AND version = '1.0.0'"
        );
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($result->sha256, $row['sha256']);
        $this->assertSame($result->sizeBytes, (int) $row['size_bytes']);
        $this->assertSame('HelloWorld/1.0.0/package.zip', $row['storage_path']);
    }

    public function testCatalogAndDownloadServeReleaseWithoutDrift(): void
    {
        $result = $this->service()->release($this->source, 'HelloWorld', '2.1.0');
        $handler = new DesktopPluginsApiHandler($this->storageDir, $this->pdo);

        // catalog()
        $catalog = $handler->catalog(new Request('GET', '/api/desktop-plugins'));
        $this->assertSame(200, $catalog->getStatusCode());
        $data = json_decode($catalog->getBody(), true);
        $this->assertIsArray($data);
        $entry = $data['data'][0];
        $this->assertSame('HelloWorld', $entry['name']);
        $this->assertSame('2.1.0', $entry['latestVersion']);
        $version = $entry['versions'][0];
        $this->assertSame(['version', 'sha256', 'sizeBytes', 'releasedAt'], array_keys($version));
        $this->assertSame($result->sha256, $version['sha256']);
        $this->assertSame($result->sizeBytes, $version['sizeBytes']);

        // download() — the served bytes must hash to exactly the catalog sha256.
        $download = $handler->download(new Request('GET', '/x'), ['name' => 'HelloWorld', 'version' => '2.1.0']);
        $this->assertSame(200, $download->getStatusCode());
        $headers = array_change_key_case($download->getHeaders());
        $this->assertSame('application/zip', $headers['content-type']);
        $this->assertSame($result->sha256, hash('sha256', $download->getBody()));
    }

    public function testReleaseIsImmutableWithoutForce(): void
    {
        $this->service()->release($this->source, 'HelloWorld', '1.0.0');

        $this->expectException(DesktopPluginReleaseException::class);
        $this->expectExceptionMessageMatches('/already exists/');
        $this->service()->release($this->source, 'HelloWorld', '1.0.0');
    }

    public function testForceRecutsRelease(): void
    {
        $this->service()->release($this->source, 'HelloWorld', '1.0.0');
        $recut = $this->service()->release($this->source, 'HelloWorld', '1.0.0', force: true);

        $this->assertTrue($recut->replacedExisting);
        $countStmt = $this->pdo->query(
            "SELECT COUNT(*) FROM desktop_plugin_releases WHERE plugin_name = 'HelloWorld' AND version = '1.0.0'"
        );
        $this->assertNotFalse($countStmt);
        $count = (int) $countStmt->fetchColumn();
        $this->assertSame(1, $count, 'a re-cut must not leave a duplicate row');
    }

    public function testForceRecutUpdatesInPlacePreservingRowIdentity(): void
    {
        $this->service()->release($this->source, 'HelloWorld', '1.0.0');
        $before = $this->row('HelloWorld', '1.0.0');

        $this->service()->release($this->source, 'HelloWorld', '1.0.0', force: true);
        $after = $this->row('HelloWorld', '1.0.0');

        // DELETE-then-INSERT would hand out a fresh id and created_at; an in-place
        // update keeps both, so nothing keyed off the release id is invalidated.
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame($before['created_at'], $after['created_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $name, string $version): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM desktop_plugin_releases WHERE plugin_name = :n AND version = :v'
        );
        $stmt->execute([':n' => $name, ':v' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return $row;
    }

    public function testCorruptedPackageIsDetectableByTheCatalogChecksum(): void
    {
        // The deliberately-broken case: once bytes on disk no longer match the
        // catalogued sha256, the client's checksum check (which we reproduce
        // here over the served bytes) must fail.
        $result = $this->service()->release($this->source, 'HelloWorld', '1.0.0');
        $path = $this->storageDir . '/' . $result->storagePath;
        file_put_contents($path, file_get_contents($path) . 'corruption');

        $handler = new DesktopPluginsApiHandler($this->storageDir, $this->pdo);
        $served = $handler->download(new Request('GET', '/x'), ['name' => 'HelloWorld', 'version' => '1.0.0']);

        $this->assertNotSame(
            $result->sha256,
            hash('sha256', $served->getBody()),
            'a tampered package must not still match the catalogued checksum'
        );
    }

    public function testObfuscatedPackageLoadsAndBehavesIdentically(): void
    {
        $result = $this->service()->release($this->source, 'HelloWorld', '1.0.0');

        $extract = sys_get_temp_dir() . '/whity-dpr-extract-' . bin2hex(random_bytes(6));
        mkdir($extract, 0o775, true);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->storageDir . '/' . $result->storagePath) === true);
        $zip->extractTo($extract);
        $zip->close();

        $this->assertDirectoryExists($extract . '/HelloWorld');

        // Register the SAME dir-name -> namespace PSR-4 mapping the device uses.
        spl_autoload_register(static function (string $class) use ($extract): void {
            $prefix = 'HelloWorld\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $extract . '/HelloWorld/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        $this->assertTrue(class_exists('HelloWorld\\HelloWorldPlugin'));
        $plugin = new \HelloWorld\HelloWorldPlugin();
        $this->assertInstanceOf(\Whity\Sdk\PluginInterface::class, $plugin);
        $this->assertSame('HelloWorld', $plugin->getName());
        $this->assertIsArray($plugin->getRoutes());
        $this->assertIsArray($plugin->getPermissions());

        $this->removeTree($extract);
    }

    private function service(): DesktopPluginReleaseService
    {
        return new DesktopPluginReleaseService($this->pdo, $this->storageDir);
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
