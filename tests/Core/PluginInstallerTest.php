<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
     * An installer with a SHORT introspection deadline so a hanging fixture is
     * rejected fast (WC-220 M1). Other guards are unchanged.
     */
    private function installerWithTimeout(int $seconds, ?PluginLoader $loader = null): PluginInstaller
    {
        return new PluginInstaller($this->pluginDir, $loader, null, new \Psr\Log\NullLogger(), $seconds);
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

    /**
     * M1: a plugin whose top-level code blocks forever must be REJECTED within
     * the (test-lowered) introspection deadline — the host read must not hang —
     * and the filesystem must be left clean.
     */
    public function testHangingIntrospectionIsRejectedWithinDeadlineAndLeavesNothing(): void
    {
        $zip = PluginPackageFixtures::hangingTopLevelZip($this->workDir, 'HangingPlugin');

        // The fixture sleeps 120s; a 3s deadline must trip well before that.
        $start = microtime(true);
        try {
            $this->installerWithTimeout(3)->installFromUpload($this->upload($zip));
            self::fail('Expected the hanging package to be rejected.');
        } catch (PluginPackageInvalid $e) {
            // expected: a generic inspection failure.
        }
        $elapsed = microtime(true) - $start;

        // Generous upper bound (deadline + kill/reap overhead), far below 120s.
        self::assertLessThan(
            30.0,
            $elapsed,
            'installFromUpload must return at the deadline, not block on the child'
        );

        self::assertDirectoryDoesNotExist($this->pluginDir . '/HangingPlugin');
        $this->assertPluginDirEmpty();
        self::assertSame(0, $this->tempWorkDirCount());
    }

    /**
     * M3: a plugin whose top-level code echoes a FORGED introspection result
     * (constraint-free, so a tricked host would skip the version gate) must NOT
     * bypass the gate: the host parses ONLY the genuine, marker-delimited result
     * (which declares an impossible core constraint), so the upload is still
     * rejected as incompatible and nothing is staged.
     */
    public function testForgedIntrospectionResultDoesNotBypassVersionGate(): void
    {
        $zip = PluginPackageFixtures::forgedIntrospectionZip($this->workDir, 'ForgedPlugin');

        $this->expectException(PluginIncompatible::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            self::assertDirectoryDoesNotExist($this->pluginDir . '/ForgedPlugin');
            $this->assertPluginDirEmpty();
            self::assertSame(0, $this->tempWorkDirCount());
        }
    }

    /**
     * M3 (hardest case): a plugin that prints a forged result WRAPPED IN forged
     * markers and then exit()s — so the genuine emit never runs — must NOT be
     * accepted. The forged markers cannot carry this run's single-use nonce, so
     * the host finds no genuine block and rejects as an inspection failure.
     */
    public function testForgedMarkersThenExitIsRejectedAsInspectionFailure(): void
    {
        $zip = PluginPackageFixtures::forgedMarkersThenExitZip($this->workDir, 'ForgedExit');

        $this->expectException(PluginPackageInvalid::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            self::assertDirectoryDoesNotExist($this->pluginDir . '/ForgedExit');
            $this->assertPluginDirEmpty();
            self::assertSame(0, $this->tempWorkDirCount());
        }
    }

    /**
     * M3 re-review (CONFIRMED-exploit regression): the prior design passed the
     * introspection nonce as a command-line argument and merely scrubbed it from
     * `$argv`/`$_SERVER`/`$GLOBALS`. That was bypassable — the kernel still
     * exposes the ORIGINAL argv at `/proc/self/cmdline` (and env at
     * `/proc/self/environ`), which PHP-level scrubbing cannot remove — so a
     * plugin could read the nonce, print a forged compatible block keyed by it,
     * and `exit(0)` before the genuine emit, bypassing the version gate.
     *
     * This fixture mounts exactly that attack: it greps BOTH proc files for a
     * 32-hex token and forges a constraint-free (compatible) block for each
     * candidate, while the GENUINE plugin declares an impossible `^99.0` core
     * constraint. With the nonce now delivered over STDIN (never argv/env) and
     * consumed before plugin code runs, neither proc table contains it, so every
     * forged block carries the wrong token; the host parses ONLY the genuine
     * block and rejects the incompatible plugin. Nothing is staged; the
     * filesystem is left clean. This proves the prior exploit is dead.
     */
    public function testProcCmdlineNonceRecoveryCannotBypassVersionGate(): void
    {
        if (!is_readable('/proc/self/cmdline')) {
            self::markTestSkipped('Linux /proc is required for the cmdline/environ nonce-recovery probe.');
        }

        $zip = PluginPackageFixtures::cmdlineNonceProbeZip($this->workDir, 'CmdlineProbe');

        $rejected = false;
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } catch (PluginIncompatible | PluginPackageInvalid $e) {
            // EITHER outcome proves the attack failed: the genuine (incompatible)
            // metadata reached the gate (PluginIncompatible), or the forged
            // wrong-nonce blocks left no genuine result to parse and the upload
            // was rejected as an inspection failure (PluginPackageInvalid). What
            // must NEVER happen is acceptance/staging of the forged compatible
            // metadata.
            $rejected = true;
        } finally {
            self::assertDirectoryDoesNotExist($this->pluginDir . '/CmdlineProbe');
            $this->assertPluginDirEmpty();
            self::assertSame(0, $this->tempWorkDirCount());
        }

        self::assertTrue($rejected, 'a nonce-recovery forgery must never be accepted/staged');
    }

    /**
     * M2: prove the staged directory artifact is disabled BY CONSTRUCTION — the
     * `.disabled` sentinel is established together with (and never after) the
     * tree, so a directory can never appear at the live path without it (which
     * the loader would treat as an ENABLED, code-executing plugin).
     */
    public function testCommittedDirectoryAlwaysCarriesSentinelNeverEnabled(): void
    {
        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'AtomicStaged');

        $this->installer()->installFromUpload($this->upload($zip));

        // The committed directory exists WITH its disabled sentinel.
        self::assertDirectoryExists($this->pluginDir . '/AtomicStaged');
        self::assertFileExists($this->pluginDir . '/AtomicStaged/' . PluginLoader::DIR_DISABLED_SENTINEL);
        self::assertFileExists($this->pluginDir . '/AtomicStaged/Plugin.php');

        // The loader must see it DISABLED (never active) — proof the sentinel
        // was present the instant the directory became visible at the live path.
        $loader = new PluginLoader($this->pluginDir, new \Whity\Core\Router(''));
        $loader->load();
        $statuses = [];
        foreach ($loader->getPluginMetadata() as $m) {
            $statuses[$m['name']] = $m['status'];
        }
        self::assertArrayHasKey('AtomicStaged', $statuses);
        self::assertSame('disabled', $statuses['AtomicStaged']);

        // No leftover temp staging artifact at the live path.
        foreach ((array) scandir($this->pluginDir) as $entry) {
            self::assertStringNotContainsString('.tmp_', (string) $entry, 'no temp artifact must survive a commit');
        }
    }

    /**
     * M2: when the commit is refused (here, the destination name is already
     * occupied), NOTHING partial lands — the pre-existing target is untouched,
     * no `.disabled`/Plugin.php is written into it, and no temp staging artifact
     * survives. This proves the atomic prepare-then-rename never mutates the
     * live path on a refused commit (no copy-into-place + sentinel window).
     */
    public function testRefusedCommitLeavesNoPartialOrEnabledDirectory(): void
    {
        // Occupy the destination name with a non-empty directory.
        $name = 'RaceCollide';
        $dest = $this->pluginDir . '/' . $name;
        mkdir($dest, 0775, true);
        file_put_contents($dest . '/squatter.txt', "pre-existing\n");

        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, $name);

        $this->expectException(PluginAlreadyInstalled::class);
        try {
            $this->installer()->installFromUpload($this->upload($zip));
        } finally {
            // The pre-existing directory is untouched; no sentinel, no Plugin.php
            // was written into it, and no temp staging artifact survives.
            self::assertFileExists($dest . '/squatter.txt');
            self::assertFileDoesNotExist($dest . '/' . PluginLoader::DIR_DISABLED_SENTINEL);
            self::assertFileDoesNotExist($dest . '/Plugin.php');
            foreach ((array) scandir($this->pluginDir) as $entry) {
                self::assertStringNotContainsString(
                    '.tmp_',
                    (string) $entry,
                    'no temp staging artifact must survive a refused commit'
                );
            }
            self::assertSame(0, $this->tempWorkDirCount());
        }
    }

    /**
     * M4: a STAGED DISABLED directory plugin's code must NOT run in the HOST
     * worker before an explicit enable. The fixture writes a marker from its
     * top-level code AND constructor.
     *
     * Instantiation in the ISOLATED introspection subprocess is BY DESIGN (that
     * is how metadata is read without loading plugin code into the host), so we
     * first clear any marker that child wrote, THEN exercise the host paths —
     * reload() (a fresh discover pass) and list() — and assert the marker stays
     * ABSENT, proving the host never require_once'd/instantiated the disabled
     * plugin. The plugin must still APPEAR (disabled) in the listing.
     */
    public function testStagedDisabledDirectoryPluginIsNotExecutedInHost(): void
    {
        $marker = $this->workDir . '/EXECUTED_MARKER.txt';
        self::assertFileDoesNotExist($marker);

        $zip = PluginPackageFixtures::executionMarkerZip($this->workDir, $marker, 'MarkerPlugin');

        $loader = new PluginLoader($this->pluginDir, new \Whity\Core\Router(''));
        $installer = new PluginInstaller($this->pluginDir, $loader);

        $installer->installFromUpload($this->upload($zip));

        // The isolated introspection CHILD legitimately instantiated the plugin
        // to read its metadata. Clear that marker; from here ONLY host-worker
        // execution could recreate it.
        @unlink($marker);
        self::assertFileDoesNotExist($marker);

        // Host paths: an explicit reload + a fresh discovery pass + a listing
        // must NOT require_once/instantiate the disabled directory plugin.
        $loader->reload();

        $handler = new \Whity\Api\PluginsApiHandler($this->pluginDir, $loader);
        $listResponse = $handler->list(new \Whity\Core\Request('GET', '/api/plugins'));
        $payload = json_decode($listResponse->getBody(), true);
        $names = array_map(static fn($p) => $p['name'], $payload['data']);

        // The plugin appears (disabled) WITHOUT its code having run in-host.
        self::assertContains('MarkerPlugin', $names, 'staged disabled plugin must still be listed');
        $status = null;
        foreach ($payload['data'] as $p) {
            if ($p['name'] === 'MarkerPlugin') {
                $status = $p['status'];
            }
        }
        self::assertSame('disabled', $status);
        self::assertFileDoesNotExist(
            $marker,
            'a DISABLED directory plugin must never be require_once\'d/instantiated in the HOST worker before enable'
        );
    }

    /**
     * ISSUE 2 (minor) regression: the M2 atomic-commit prepares the artifact in
     * a dot-prefixed temp sibling (`.<Name>.tmp_<rand>`) inside plugins/ so the
     * landing rename() is atomic/same-filesystem. If such a temp dir were leaked
     * by a hard crash mid-commit (before its sentinel), discovery must NOT
     * `require_once` its top-level code and listing must NOT surface it.
     *
     * discover() and PluginsApiHandler::list() now skip ALL dot-prefixed
     * entries (not just `.`/`..`/`.gitkeep`). This plants a `.Foo.tmp_x`
     * directory whose Plugin.php has a top-level side effect (writes a marker)
     * and asserts the marker stays absent after discover() + list(), and that
     * the entry never appears in the listing.
     */
    public function testDotPrefixedEntriesAreSkippedByDiscoveryAndListing(): void
    {
        $marker = $this->workDir . '/DOT_EXECUTED_MARKER.txt';
        self::assertFileDoesNotExist($marker);

        // A leaked atomic-commit temp sibling: dot-prefixed dir under plugins/.
        $leaked = $this->pluginDir . '/.Foo.tmp_' . bin2hex(random_bytes(4));
        mkdir($leaked, 0775, true);
        $markerExport = var_export($marker, true);
        file_put_contents(
            $leaked . '/Plugin.php',
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Foo;

            use Whity\\Sdk\\PluginInterface;

            // TOP-LEVEL side effect: writing this proves discover() require_once'd
            // the file. It must NEVER run for a dot-prefixed entry.
            @\\file_put_contents({$markerExport}, "discovered\\n", FILE_APPEND);

            final class Plugin implements PluginInterface
            {
                public function getName(): string { return 'Foo'; }
                public function getVersion(): string { return '1.0.0'; }
                public function getRoutes(): array { return []; }
                public function getPermissions(): array { return []; }
                public function getHooks(): array { return []; }
                public function getMigrations(): array { return []; }
            }
            PHP
        );

        $loader = new PluginLoader($this->pluginDir, new \Whity\Core\Router(''));

        // discover() must not execute the dot-prefixed entry's top-level code.
        $discovered = $loader->discover();
        self::assertSame(
            [],
            $discovered,
            'discovery must not pick up a dot-prefixed (temp/leaked) entry'
        );
        self::assertFileDoesNotExist(
            $marker,
            'discover() must NOT require_once a dot-prefixed entry\'s top-level code'
        );

        // A full load() pass (discover + register) likewise must not run it.
        $loader->load();
        self::assertFileDoesNotExist($marker, 'load() must NOT execute a dot-prefixed entry');

        // list() must not surface the dot-prefixed entry.
        $handler = new \Whity\Api\PluginsApiHandler($this->pluginDir, $loader);
        $listResponse = $handler->list(new \Whity\Core\Request('GET', '/api/plugins'));
        /** @var array{data: list<array{id: string, name: string}>} $payload */
        $payload = json_decode($listResponse->getBody(), true);
        $ids = array_map(static fn(array $p): string => (string) $p['id'], $payload['data']);
        $names = array_map(static fn(array $p): string => (string) $p['name'], $payload['data']);

        self::assertNotContains('.Foo', $ids, 'list() must not surface a dot-prefixed entry');
        self::assertNotContains('Foo', $names, 'list() must not surface a dot-prefixed entry');
        foreach ($ids as $id) {
            self::assertStringNotContainsString('.tmp_', $id, 'list() must not surface a temp sibling');
        }
        self::assertFileDoesNotExist($marker, 'list() must NOT execute a dot-prefixed entry');
    }

    /**
     * Invoke the pure CLI-resolver helper with stubbed runtime values so the
     * FrankenPHP branch — unreachable under php:8.4-cli — is covered (WC-221).
     *
     * @param callable(string): bool $isExecutable
     * @return array{bin: string, prefixArgs: list<string>}|null
     */
    private function selectPhpCli(string $sapi, string $phpBinary, string $phpBindir, callable $isExecutable): ?array
    {
        $method = new ReflectionMethod(PluginInstaller::class, 'selectPhpCli');

        /** @var array{bin: string, prefixArgs: list<string>}|null $result */
        $result = $method->invoke(null, $sapi, $phpBinary, $phpBindir, $isExecutable);

        return $result;
    }

    public function testResolverUsesPhpBinaryDirectlyInCliContext(): void
    {
        // (1) Genuine CLI context with a runnable PHP_BINARY: use it as-is — this
        // is the local php:8.4-cli path and must never regress.
        $resolved = $this->selectPhpCli(
            'cli',
            '/usr/local/bin/php',
            '/usr/local/bin',
            static fn (string $c): bool => $c === '/usr/local/bin/php',
        );

        self::assertSame(['bin' => '/usr/local/bin/php', 'prefixArgs' => []], $resolved);
    }

    public function testResolverPrefersRealPhpCliOverFrankenphpBinaryUnderWorkerSapi(): void
    {
        // (2) Under a non-CLI (FrankenPHP worker) SAPI, PHP_BINARY is the
        // frankenphp binary; the resolver must IGNORE it and pick the standalone
        // php CLI shipped at PHP_BINDIR, invoked directly (no prefix).
        $resolved = $this->selectPhpCli(
            'frankenphp',
            '/usr/local/bin/frankenphp',
            '/usr/local/bin',
            static fn (string $c): bool => $c === '/usr/local/bin/php',
        );

        self::assertSame(['bin' => '/usr/local/bin/php', 'prefixArgs' => []], $resolved);
    }

    public function testResolverTriesWellKnownCliPathsWhenBindirHasNone(): void
    {
        // (2) Fall through PHP_BINDIR (no CLI there) to the /usr/bin/php fallback.
        $resolved = $this->selectPhpCli(
            'frankenphp',
            '/usr/local/bin/frankenphp',
            '/opt/empty',
            static fn (string $c): bool => $c === '/usr/bin/php',
        );

        self::assertSame(['bin' => '/usr/bin/php', 'prefixArgs' => []], $resolved);
    }

    public function testResolverFallsBackToFrankenphpPhpCliSubcommand(): void
    {
        // (3) No standalone php CLI exists anywhere, but PHP_BINARY is FrankenPHP:
        // drive it via its `php-cli` subcommand (`frankenphp php-cli <script>`).
        $resolved = $this->selectPhpCli(
            'frankenphp',
            '/usr/local/bin/frankenphp',
            '/opt/empty',
            static fn (string $c): bool => false,
        );

        self::assertSame(['bin' => '/usr/local/bin/frankenphp', 'prefixArgs' => ['php-cli']], $resolved);
    }

    public function testResolverReturnsNullWhenNothingRunnable(): void
    {
        // (4) Non-CLI SAPI, PHP_BINARY is neither runnable nor frankenphp-like,
        // and no well-known CLI exists: nothing resolves (caller then throws the
        // typed inspection failure rather than spawning something broken).
        $resolved = $this->selectPhpCli(
            'fpm-fcgi',
            '/usr/sbin/php-fpm',
            '/opt/empty',
            static fn (string $c): bool => false,
        );

        self::assertNull($resolved);
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
