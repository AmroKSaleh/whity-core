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
 * in-tree HelloWorld plugin source, so the catalog/download contract, the checksum
 * (no-drift) invariant, immutability, and that the OBFUSCATED package still
 * loads and behaves are all exercised together.
 *
 * The obfuscated package is loaded in a CHILD process (#825). It carries the
 * same class name as the in-tree plugin this suite also exercises, and PHP has
 * no way to unload a class: whichever copy is declared first owns the name for
 * the life of the process, and the loser's `require` is a FATAL redeclare that
 * kills the whole run rather than failing one test. Both orderings were wrong
 * here — extracting first fataled the suite the moment anything discovered the
 * real plugins/ directory, and loading the in-tree copy first made this test
 * silently assert against it and prove nothing about the artefact. A child
 * process is not a workaround for that: it is what a device actually does, and
 * it is the only place the real PSR-4 mapping can be exercised under the real
 * class name without the parent inheriting the declaration.
 */
final class DesktopPluginReleaseServiceTest extends TestCase
{
    private PDO $pdo;
    private string $storageDir;
    private string $source;

    /** Extraction root of the released package, if a test made one. */
    private ?string $extractDir = null;

    /**
     * The process's autoloader stack as this test found it.
     *
     * @var list<callable>
     */
    private array $autoloadersOnEntry = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->storageDir = sys_get_temp_dir() . '/whity-dpr-int-' . bin2hex(random_bytes(6));
        mkdir($this->storageDir, 0o775, true);
        // Package the canonical in-tree plugin source, not the vendored offline-host
        // copy under templates/tauri-desktop/php-host/plugins — that bundle was
        // removed once the desktop app stopped shipping demo/example plugins, and
        // plugins/HelloWorld is the source of truth the release pipeline packages.
        $this->source = dirname(__DIR__, 3) . '/plugins/HelloWorld';
        $this->autoloadersOnEntry = array_values(spl_autoload_functions());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->storageDir);

        // Cleaned here, not at the end of the test body: an assertion that fires
        // mid-test aborts the method, and a leftover extraction of a plugin under
        // its real class name is exactly the debris that makes the next run
        // confusing.
        if ($this->extractDir !== null) {
            $this->removeTree($this->extractDir);
            $this->extractDir = null;
        }

        // A test that registers a global autoloader must remove it (#825). A
        // leaked one is not a local defect: it silently re-points a namespace for
        // every test that runs afterwards, and the failure surfaces thousands of
        // tests later as a fatal in an unrelated file. Pinned here because this is
        // the class that had the leak, and the cost of the check is a comparison.
        $this->assertSame(
            $this->autoloadersOnEntry,
            array_values(spl_autoload_functions()),
            'this test changed the process-wide autoloader stack and did not restore it'
        );
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
        $this->extractDir = $extract;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->storageDir . '/' . $result->storagePath) === true);
        $zip->extractTo($extract);
        $zip->close();

        $this->assertDirectoryExists($extract . '/HelloWorld');

        $probe = $this->loadInChildProcess($extract);

        $this->assertSame('', $probe['error'], 'the obfuscated package must load without error');
        $this->assertTrue($probe['classExists'], 'the PSR-4 mapping must resolve the obfuscated class');

        // The assertion that keeps this test honest. Without it, an in-tree
        // HelloWorld already declared in the process satisfies every check below
        // while the artefact is never opened — a green test proving nothing.
        $this->assertSame(
            realpath($extract . '/HelloWorld/HelloWorldPlugin.php'),
            $probe['declaredIn'],
            'the class under test must be the extracted package, not the in-tree source'
        );

        $this->assertTrue($probe['isPluginInterface'], 'the obfuscated class must still satisfy the SDK contract');
        $this->assertSame('HelloWorld', $probe['name']);
        $this->assertTrue($probe['routesIsArray']);
        $this->assertTrue($probe['permissionsIsArray']);
    }

    /**
     * Load the extracted package under the SAME dir-name -> namespace PSR-4
     * mapping a device uses, in a child PHP process, and report what it found.
     *
     * Everything the parent would have to mutate to do this itself — the global
     * autoloader stack and the `HelloWorld\` class table — is process-scoped and
     * unrecoverable, so the child owns both and exits. The report travels on
     * STDERR between markers because the plugin's own top-level code is free to
     * write to STDOUT, and mixing the two would make the JSON undecodable.
     *
     * @return array<string, mixed>
     */
    private function loadInChildProcess(string $extract): array
    {
        $root = dirname(__DIR__, 3);

        $probe = "<?php\n"
            . "declare(strict_types=1);\n"
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . ";\n"
            . '$extract = ' . var_export($extract, true) . ";\n"
            . <<<'PHP'
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

                $report = [
                    'error' => '',
                    'classExists' => false,
                    'declaredIn' => null,
                    'isPluginInterface' => false,
                    'name' => null,
                    'routesIsArray' => false,
                    'permissionsIsArray' => false,
                ];

                try {
                    $report['classExists'] = class_exists('HelloWorld\\HelloWorldPlugin');
                    if ($report['classExists']) {
                        $plugin = new \HelloWorld\HelloWorldPlugin();
                        $report['declaredIn'] = (new \ReflectionClass($plugin))->getFileName();
                        $report['isPluginInterface'] = $plugin instanceof \Whity\Sdk\PluginInterface;
                        $report['name'] = $plugin->getName();
                        $report['routesIsArray'] = is_array($plugin->getRoutes());
                        $report['permissionsIsArray'] = is_array($plugin->getPermissions());
                    }
                } catch (\Throwable $e) {
                    $report['error'] = $e::class . ': ' . $e->getMessage();
                }

                fwrite(STDERR, '<<<WHITY_PROBE>>>' . json_encode($report) . '<<<END>>>');
                PHP;

        // Inside the extraction, so the tearDown that removes the extraction
        // removes this too. The autoloader above only maps HelloWorld/, so a
        // sibling file cannot collide with the package.
        $probePath = $extract . '/load-probe.php';
        $this->assertNotFalse(file_put_contents($probePath, $probe), 'could not write the load probe');

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $probePath], $descriptors, $pipes, $root);
        $this->assertIsResource($process, 'could not start the load probe');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        preg_match('/<<<WHITY_PROBE>>>(.*?)<<<END>>>/s', $stderr, $matches);
        if (!isset($matches[1])) {
            $this->fail("The load probe did not report. Exit code {$exitCode}.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
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
