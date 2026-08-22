<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * #841 — two plugin files declaring the same class must cost those plugins
 * their load, never the host its boot.
 *
 * WHAT WAS BROKEN
 * ---------------
 * {@see \Whity\Core\PluginLoader::discover()} `require_once`d every plugin file
 * it found with no guard. `require_once` deduplicates by PATH, not by class
 * name, so two DIFFERENT files declaring one fully-qualified name are both
 * executed and the second declaration raises `Cannot redeclare class …`. That
 * is a FATAL, not an exception: the per-plugin error boundary cannot catch it,
 * the plugin lifecycle never gets to record it, and because discovery runs at
 * BOOT it happened on every request. The only way out was shell access to the
 * server to delete a directory. Each site did call `class_exists()` — after the
 * require, where it can no longer prevent anything.
 *
 * WHY THESE TESTS RUN IN A CHILD PROCESS
 * --------------------------------------
 * The regression they guard against is an uncatchable fatal. Reproduced
 * in-process, a regression would not fail these tests — it would end the entire
 * PHPUnit run partway through, in whichever file happened to be executing,
 * which is exactly the diagnosis cost that made #825 expensive. Run in a child,
 * the same regression is a non-zero exit code and a missing report: an ordinary
 * assertion failure that names the scenario, with the rest of the suite intact.
 * It also keeps the fixtures' classes out of the suite's process, where they
 * would outlive the temp directory that declared them.
 *
 * COVERAGE
 * --------
 * All three require sites, because they fail independently: the directory
 * scan, the single-file scan, and the manifest-cache path — the last being the
 * one a warm worker actually takes, and the easy one to leave unguarded.
 */
final class PluginClassCollisionTest extends TestCase
{
    /** Temp root holding this test's plugin trees, probe, and markers. */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/whity_class_collision_' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            self::fail('could not create the fixture root');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    /**
     * The reachable-today case: an operator copies a plugin directory before
     * editing it — `cp -r plugins/HelloWorld plugins/HelloWorld-old`.
     *
     * It looks like the safest possible thing to do, and it used to take the
     * whole instance down at the next boot. Nothing about the copy is
     * malformed: discovery skips only dot-prefixed entries, so `HelloWorld-old/`
     * is scanned like any other plugin, and the class its file declares comes
     * from the `namespace` line it inherited — the original's.
     *
     * Note what a `class_exists($fqcn, false)` check could NOT have caught here.
     * The name discovery looks up is derived from the PATH
     * (`HelloWorld-old\HelloWorldPlugin`, which nothing has ever declared), while
     * the name the file DECLARES is `HelloWorld\HelloWorldPlugin`. The lookup
     * misses and the require fatals anyway. Only reading the source before
     * executing it answers the question that matters.
     */
    public function testACopiedPluginDirectoryCostsOnlyItselfAndNotTheBoot(): void
    {
        $plugins = $this->makePluginsDir('live');
        $copyMarker = $this->root . '/copy-executed.txt';

        $this->writeDirectoryPlugin($plugins, 'HelloWorld', 'HelloWorld', 'HelloWorldPlugin');
        // The copy: same file contents (so the same class), different directory.
        $this->writeDirectoryPlugin($plugins, 'HelloWorld-old', 'HelloWorld', 'HelloWorldPlugin', $copyMarker);
        // An unrelated, blameless third plugin. A defect in one plugin must not
        // spread to its neighbours — that is the whole difference between a
        // refused plugin and a refused boot.
        $this->writeDirectoryPlugin($plugins, 'Bystander', 'Bystander', 'Plugin');

        $report = $this->runProbe([['dir' => $plugins]]);
        $step = $report['steps'][0];

        self::assertSame(
            ['Bystander\Plugin', 'HelloWorld\HelloWorldPlugin'],
            $this->sorted($step['discovered']),
            'the original and the bystander must both load; only the copy is refused'
        );
        self::assertSame(
            ['Bystander', 'HelloWorld'],
            $this->sorted($step['loaded']),
            'a refused plugin must not disturb the plugins around it'
        );

        self::assertFileDoesNotExist(
            $copyMarker,
            'the copy must be refused BEFORE it is required — its top-level code must never run'
        );

        self::assertTrue(
            $this->logMentions($report['logs'], 'HelloWorld-old', 'HelloWorld\HelloWorldPlugin'),
            "the refusal must be logged against the copy, naming the class it collides on.\nLogs:\n"
            . implode("\n", $report['logs'])
        );
    }

    /**
     * The same copy, named so that it is scanned FIRST.
     *
     * Directory order is scandir order, so whether the operator called the copy
     * `HelloWorld-old` or `Backup of HelloWorld` decides which of the two files
     * is required first. If the refusal were decided purely by "is this class
     * already declared", the copy would win the race and the REAL plugin — the
     * one whose directory name matches its namespace, the one serving traffic —
     * would be the one refused. So a file whose declared class is not the class
     * its own location implies is skipped outright: it cannot be found by
     * discovery under that path and the PSR-4 autoloader cannot load it there
     * either, so requiring it could only squat on another plugin's name.
     */
    public function testTheRealPluginStillLoadsWhenTheCopyIsScannedFirst(): void
    {
        $plugins = $this->makePluginsDir('live');
        $copyMarker = $this->root . '/early-copy-executed.txt';

        $this->writeDirectoryPlugin($plugins, 'HelloWorld', 'HelloWorld', 'HelloWorldPlugin');
        $this->writeDirectoryPlugin($plugins, 'AaaBackupOfHelloWorld', 'HelloWorld', 'HelloWorldPlugin', $copyMarker);

        $report = $this->runProbe([['dir' => $plugins]]);

        self::assertSame(
            ['HelloWorld\HelloWorldPlugin'],
            $this->sorted($report['steps'][0]['discovered']),
            'the plugin whose directory matches its namespace must win regardless of scan order'
        );
        self::assertFileDoesNotExist($copyMarker, 'the copy must never be executed');
    }

    /**
     * A refused plugin has to be visible somewhere other than a log file.
     *
     * The complaint in #841 is not only that the host died — it is that there
     * was no in-product way to see it or act on it. A refused entry file now
     * gets a lifecycle record quarantined with the reason, keyed by the
     * directory name the admin plugins listing matches on-disk entries by, so
     * the copy shows up in the list as failed with an explanation instead of
     * silently doing nothing. Quarantine, specifically: it is the one state
     * `reEnablePlugin()` refuses, so an admin cannot click the copy back into
     * the collision. Only fixing the disk clears it.
     */
    public function testARefusedPluginIsQuarantinedWithAReasonAnAdminCanRead(): void
    {
        $plugins = $this->makePluginsDir('live');
        $this->writeDirectoryPlugin($plugins, 'HelloWorld', 'HelloWorld', 'HelloWorldPlugin');
        $this->writeDirectoryPlugin($plugins, 'HelloWorld-old', 'HelloWorld', 'HelloWorldPlugin');

        $report = $this->runProbe([['dir' => $plugins]]);
        $statuses = $report['steps'][0]['statuses'];

        self::assertArrayHasKey(
            'HelloWorld-old\HelloWorldPlugin',
            $statuses,
            "the refused copy must be reported, not silently missing.\nReported: "
            . implode(', ', array_keys($statuses))
        );

        $refused = $statuses['HelloWorld-old\HelloWorldPlugin'];
        self::assertSame('failed', $refused['state']);
        self::assertSame(
            'HelloWorld-old',
            $refused['name'],
            'the display name must be the on-disk directory, which is how the admin listing matches it'
        );
        self::assertSame('quarantine', $refused['error_type'], 'a quarantine cannot be re-enabled into the fatal');
        self::assertStringContainsString('HelloWorld\HelloWorldPlugin', (string) $refused['reason']);

        // The plugin that DID load keeps its own clean record.
        self::assertSame('active', $statuses['HelloWorld\HelloWorldPlugin']['state']);
    }

    /**
     * The directory-scan require site, with a genuine redeclaration.
     *
     * Two plugin roots — a second instance's tree, a leftover checkout, or the
     * two loaders a test process builds — each holding `Acme/Plugin.php`. Here
     * both files declare exactly the class their own path implies, so the
     * skip-the-orphan rule does not apply and the collision check is the only
     * thing standing between discovery and `Cannot redeclare class Acme\Plugin`.
     * The FIRST tree keeps working; the second is refused, one plugin, with a
     * log line naming both sides.
     */
    public function testADirectoryPluginDeclaringAnAlreadyDeclaredClassIsRefused(): void
    {
        $first = $this->makePluginsDir('first');
        $second = $this->makePluginsDir('second');
        $secondMarker = $this->root . '/second-tree-executed.txt';

        $this->writeDirectoryPlugin($first, 'Acme', 'Acme', 'Plugin');
        $this->writeDirectoryPlugin($second, 'Acme', 'Acme', 'Plugin', $secondMarker);

        $report = $this->runProbe([['dir' => $first], ['dir' => $second]]);

        self::assertSame(['Acme\Plugin'], $report['steps'][0]['discovered'], 'the first tree loads normally');
        self::assertSame([], $report['steps'][1]['discovered'], 'the second must be refused, not required');
        self::assertFileDoesNotExist($secondMarker, 'the refused file must not be executed');
        self::assertTrue(
            $this->logMentions($report['logs'], 'already declares', 'Acme\Plugin'),
            "the log must name the class and the file that already declares it.\nLogs:\n"
            . implode("\n", $report['logs'])
        );
    }

    /**
     * The single-file require site — `plugins/Solo.php`, no directory involved.
     *
     * A separate branch of discover() with its own `require_once`, so it fails
     * independently of the directory one and is tested independently.
     */
    public function testASingleFilePluginDeclaringAnAlreadyDeclaredClassIsRefused(): void
    {
        $first = $this->makePluginsDir('first');
        $second = $this->makePluginsDir('second');
        $secondMarker = $this->root . '/second-solo-executed.txt';

        $this->writeSingleFilePlugin($first, 'Solo');
        $this->writeSingleFilePlugin($second, 'Solo', $secondMarker);

        $report = $this->runProbe([['dir' => $first], ['dir' => $second]]);

        self::assertSame(['Whity\Plugins\Solo'], $report['steps'][0]['discovered']);
        self::assertSame([], $report['steps'][1]['discovered']);
        self::assertFileDoesNotExist($secondMarker, 'the refused single-file plugin must not be executed');
    }

    /**
     * The manifest-cache require site — the path a WARM worker takes.
     *
     * On a cache hit discovery never scans the tree: it walks the manifest's
     * FQCN => path map and requires each entry directly. That is a third,
     * separate `require_once`, it runs on the overwhelming majority of boots,
     * and guarding only the two scan sites would leave the common case
     * unprotected while every scan-based test stayed green.
     *
     * The manifest is written by hand here because that is what a warm boot
     * genuinely is — a map a PREVIOUS process persisted, describing a tree this
     * process has not scanned. The shape mirrors what saveManifest() writes, and
     * the fingerprint is taken from the loader itself so the entry is a real
     * cache hit rather than a miss that quietly falls through to a full scan.
     */
    public function testTheWarmManifestPathRefusesACollidingEntry(): void
    {
        $first = $this->makePluginsDir('first');
        $second = $this->makePluginsDir('second');
        $secondMarker = $this->root . '/warm-second-executed.txt';

        $this->writeDirectoryPlugin($first, 'Acme', 'Acme', 'Plugin');
        $this->writeDirectoryPlugin($second, 'Acme', 'Acme', 'Plugin', $secondMarker);
        $this->writeDirectoryPlugin($second, 'Untouched', 'Untouched', 'Plugin');

        $cacheFile = $this->root . '/second-manifest.json';
        $report = $this->runProbe([
            ['dir' => $first],
            [
                'dir' => $second,
                'cache' => $cacheFile,
                'manifest' => [
                    'Acme\Plugin' => $second . '/Acme/Plugin.php',
                    'Untouched\Plugin' => $second . '/Untouched/Plugin.php',
                ],
            ],
        ]);

        self::assertTrue($report['steps'][1]['cacheHit'], 'the manifest must have been a genuine cache hit');
        self::assertSame(
            ['Untouched\Plugin'],
            $this->sorted($report['steps'][1]['discovered']),
            'the colliding entry is skipped and the rest of the warm manifest still serves'
        );
        self::assertFileDoesNotExist($secondMarker, 'the refused manifest entry must not be executed');
    }

    /**
     * The guard must not fire on a plugin for declaring its own class.
     *
     * Every boot discovers twice over (a `load()` followed by the `reload()` a
     * FrankenPHP worker runs), and each pass re-requires files whose classes are
     * already declared — by those same files, where `require_once` is a harmless
     * no-op. A guard that only asked "is this name taken?" would refuse every
     * plugin in the process from the second pass onward, which is a far larger
     * outage than the one being fixed. Sameness is decided on the resolved path,
     * and this pins it.
     */
    public function testRediscoveringTheSameTreeDoesNotRefuseItsOwnPlugins(): void
    {
        $plugins = $this->makePluginsDir('live');
        $this->writeDirectoryPlugin($plugins, 'Acme', 'Acme', 'Plugin');
        $this->writeSingleFilePlugin($plugins, 'Solo');

        $report = $this->runProbe([['dir' => $plugins], ['dir' => $plugins], ['dir' => $plugins, 'reload' => true]]);

        $expected = ['Acme\Plugin', 'Whity\Plugins\Solo'];
        self::assertSame($expected, $this->sorted($report['steps'][0]['discovered']));
        self::assertSame($expected, $this->sorted($report['steps'][1]['discovered']), 'a second loader must load the same tree');
        self::assertSame($expected, $this->sorted($report['steps'][2]['discovered']), 'a reload must not refuse what it just loaded');
        self::assertSame(
            [],
            array_values(array_filter($report['logs'], static fn (string $line): bool => str_contains($line, 'was not loaded'))),
            'nothing may be refused when the same tree is discovered repeatedly'
        );
    }

    // -----------------------------------------------------------------------
    // Fixtures and the child-process probe
    // -----------------------------------------------------------------------

    /**
     * A plugins root under this test's temp tree.
     */
    private function makePluginsDir(string $name): string
    {
        $dir = $this->root . '/' . $name . '-plugins';
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("could not create the plugins dir {$dir}");
        }

        return $dir;
    }

    /**
     * Write `<plugins>/<dirName>/<class>.php` declaring `<namespace>\<class>`.
     *
     * $marker, when given, makes the file APPEND to that path as top-level code.
     * A file that was refused correctly is a file that was never executed, and
     * the marker is how a test can tell "not discovered" from "not required".
     */
    private function writeDirectoryPlugin(
        string $pluginsDir,
        string $dirName,
        string $namespace,
        string $class,
        ?string $marker = null,
    ): void {
        $dir = $pluginsDir . '/' . $dirName;
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("could not create the plugin dir {$dir}");
        }

        self::assertNotFalse(
            file_put_contents($dir . '/' . $class . '.php', $this->pluginSource($namespace, $class, $dirName, $marker)),
            'could not write the plugin fixture'
        );
    }

    /**
     * Write `<plugins>/<name>.php`, the single-file plugin shape, whose FQCN is
     * `Whity\Plugins\<name>` by discovery's path convention.
     */
    private function writeSingleFilePlugin(string $pluginsDir, string $name, ?string $marker = null): void
    {
        self::assertNotFalse(
            file_put_contents(
                $pluginsDir . '/' . $name . '.php',
                $this->pluginSource('Whity\\Plugins', $name, $name, $marker)
            ),
            'could not write the single-file plugin fixture'
        );
    }

    private function pluginSource(string $namespace, string $class, string $pluginName, ?string $marker): string
    {
        $sideEffect = $marker === null
            ? ''
            : "\n@\\file_put_contents(" . var_export($marker, true) . ", \"required\\n\", FILE_APPEND);\n";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Whity\\Sdk\\PluginInterface;
        {$sideEffect}
        final class {$class} implements PluginInterface
        {
            public function getName(): string { return '{$pluginName}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }
        }
        PHP;
    }

    /**
     * Run the discovery steps in a child PHP process and return its report.
     *
     * Each step builds a fresh {@see \Whity\Core\PluginLoader} over one plugins
     * root and loads it, in one process, so classes declared by an earlier step
     * are still declared for the later ones — which is the whole condition under
     * test. A step may pre-write a manifest (the warm-boot path) or re-load an
     * already-loaded tree (the reload path).
     *
     * A regression here is a fatal, so the child dying without a report is
     * itself the failure signal, and its exit code and output are reported.
     *
     * @param list<array{dir: string, cache?: string, manifest?: array<string, string>, reload?: bool}> $steps
     * @return array{steps: list<array{discovered: list<string>, loaded: list<string>, cacheHit: bool,
     *         statuses: array<string, array{name: string, state: string, error_type: string|null, reason: string|null}>}>,
     *         logs: list<string>}
     */
    private function runProbe(array $steps): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $specPath = $this->root . '/probe-spec.json';
        $probePath = $this->root . '/discovery-probe.php';

        self::assertNotFalse(
            file_put_contents($specPath, json_encode($steps, JSON_THROW_ON_ERROR)),
            'could not write the probe spec'
        );
        self::assertNotFalse(file_put_contents($probePath, $this->probeSource($repoRoot)), 'could not write the probe');

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $probePath, $specPath], $descriptors, $pipes, $repoRoot);
        self::assertIsResource($process, 'could not start the discovery probe');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        preg_match('/<<<WHITY_DISCOVERY>>>(.*?)<<<END>>>/s', $stderr, $matches);
        if (!isset($matches[1])) {
            self::fail(
                "Discovery did not complete. A 'Cannot redeclare class' fatal here is the #841 regression: "
                . "discovery must refuse the offending file, not die with it.\n"
                . "Exit code {$exitCode}.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
            );
        }

        self::assertSame(0, $exitCode, "the probe must exit cleanly.\nSTDERR:\n{$stderr}");

        /** @var array{steps: list<array{discovered: list<string>, loaded: list<string>, cacheHit: bool, statuses: array<string, array{name: string, state: string, error_type: string|null, reason: string|null}>}>, logs: list<string>} $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The child script: load each step's tree, report what survived.
     */
    private function probeSource(string $repoRoot): string
    {
        $autoload = var_export($repoRoot . '/vendor/autoload.php', true);

        return <<<PHP
        <?php

        declare(strict_types=1);

        require {$autoload};

        \$steps = json_decode((string) file_get_contents(\$argv[1]), true, 512, JSON_THROW_ON_ERROR);

        \$logger = new class extends \\Psr\\Log\\AbstractLogger {
            /** @var list<string> */
            public array \$lines = [];

            public function log(\$level, \$message, array \$context = []): void
            {
                \$this->lines[] = (string) \$level . ': ' . (string) \$message;
            }
        };

        \$report = ['steps' => [], 'logs' => []];

        foreach (\$steps as \$step) {
            \$loader = new \\Whity\\Core\\PluginLoader(
                \$step['dir'],
                new \\Whity\\Core\\Router(''),
                null,
                null,
                \$logger
            );

            if (isset(\$step['cache'])) {
                // A manifest a previous process left behind: the map plus the
                // fingerprint that makes it a hit rather than a miss.
                if (isset(\$step['manifest'])) {
                    file_put_contents(\$step['cache'], (string) json_encode([
                        'scanned_at' => time(),
                        'fingerprint' => \$loader->getFingerprint(),
                        'plugins' => \$step['manifest'],
                    ]));
                }
                \$loader->enableCache(\$step['cache']);
            }

            \$discovered = array_keys(\$loader->discover());
            \$loader->load();
            if (!empty(\$step['reload'])) {
                \$loader->reload();
            }

            \$statuses = [];
            foreach (\$loader->getPluginStatuses() as \$status) {
                \$statuses[\$status['id']] = [
                    'name' => \$status['name'],
                    'state' => \$status['state'],
                    'error_type' => \$status['last_error']['type'] ?? null,
                    'reason' => \$status['last_error']['message'] ?? null,
                ];
            }

            \$report['steps'][] = [
                'discovered' => \$discovered,
                'loaded' => array_map(
                    static fn (\\Whity\\Sdk\\PluginInterface \$plugin): string => \$plugin->getName(),
                    \$loader->getPlugins()
                ),
                // Proof the manifest branch was the one exercised: a miss would
                // have rewritten the cache with a fresh scanned_at.
                'cacheHit' => isset(\$step['manifest'])
                    && (int) (json_decode((string) file_get_contents(\$step['cache']), true)['scanned_at'] ?? 0) > 0
                    && \$discovered === array_values(array_intersect(array_keys(\$step['manifest']), \$discovered)),
                'statuses' => \$statuses,
            ];
        }

        \$report['logs'] = \$logger->lines;

        fwrite(STDERR, '<<<WHITY_DISCOVERY>>>' . json_encode(\$report) . '<<<END>>>');
        PHP;
    }

    /**
     * Whether one logged line mentions all of the given fragments.
     *
     * @param list<string> $logs
     */
    private function logMentions(array $logs, string ...$fragments): bool
    {
        foreach ($logs as $line) {
            $hit = true;
            foreach ($fragments as $fragment) {
                if (!str_contains($line, $fragment)) {
                    $hit = false;
                    break;
                }
            }
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
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
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
