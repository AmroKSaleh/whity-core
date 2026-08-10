<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Boots the REAL hosts and asks the REAL container for the permission
 * catalogue.
 *
 * This test exists because unit tests could not have caught the bug. The
 * container was correct, the registry was correct, the plugin loader was
 * correct, every unit test passed — and a plugin asking
 * `\Whity\app(PermissionRegistry::class)` still got a fresh, EMPTY registry,
 * because `public/index.php` built the real one and never registered it. The
 * defect lived exactly in the seam no unit test crosses. Source-scanning tests
 * (PermissionRegistryEntryPointWiringTest) pin the wiring's SHAPE; only a boot
 * proves the shape produces the behaviour.
 *
 * Both entry points are booted in a child process:
 *
 *  - HTTP: `public/index.php` in its single-request fallback mode (the worker
 *    loop needs frankenphp_handle_request). The probe then asserts that the
 *    container-resolved registry is the SAME OBJECT as the `$permissionRegistry`
 *    the file handed to `new PluginLoader(...)`, and that it contains a
 *    permission only the plugin loader could have put there.
 *  - CLI: `BaseCommand::setupKernel()`, the path every `whity-cli` command that
 *    simulates an API call runs through.
 *
 * Requires a real PostgreSQL (both hosts open a connection during bootstrap),
 * so it is skipped unless PHPUNIT_PG_DSN is configured — the same gate the rest
 * of the real-engine suite uses. In CI this runs in the "Migrations +
 * Integration + Security on real PostgreSQL" job, whose database has already
 * had `migrate run` + `seed` applied.
 */
final class EntryPointServiceWiringRealBootTest extends TestCase
{
    /** A permission that exists ONLY because the plugin loader registered it. */
    private const PLUGIN_PERMISSION = 'demo_catalog:view';

    private string $probePath = '';

    protected function setUp(): void
    {
        if ($this->postgresDsn() === null) {
            self::markTestSkipped('PHPUNIT_PG_DSN is not set; the real host bootstrap needs a live PostgreSQL.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->probePath !== '' && file_exists($this->probePath)) {
            @unlink($this->probePath);
        }
    }

    /**
     * The reported bug, end to end: after a real boot, the container must hand
     * back the populated registry — not a different, empty object.
     */
    public function testHttpEntryPointResolvesTheSamePopulatedPermissionRegistry(): void
    {
        $result = $this->runProbe(<<<'PHP'
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI']    = '/api/health';
            $_SERVER['HTTP_HOST']      = 'localhost';
            $_GET = [];

            ob_start();
            require __DIR__ . '/public/index.php';
            ob_end_clean();

            $resolved = \Whity\app(\Whity\Core\RBAC\PermissionRegistry::class);

            whity_probe_emit([
                // $permissionRegistry is index.php's own variable, i.e. literally
                // the object it passed to new PluginLoader(...).
                'same_instance'        => $resolved === $permissionRegistry,
                'has_plugin_permission' => $resolved->exists('demo_catalog:view'),
                'has_core_permission'   => $resolved->exists('users:read'),
                'plugin_permissions'    => array_values($resolved->getBySource('DemoCatalog')),
            ]);
            PHP);

        self::assertTrue(
            $result['same_instance'],
            'The container must return the very object public/index.php filled and handed to the '
            . 'plugin loader. A different object is the bug: it is empty, and emptiness is a '
            . 'legitimate-looking registry state, so the caller fails closed with nothing to '
            . 'diagnose.'
        );
        self::assertTrue(
            $result['has_plugin_permission'],
            'The resolved registry must contain ' . self::PLUGIN_PERMISSION . ', which only the '
            . 'plugin loader could have registered — the proof that this is the populated '
            . 'instance and not a plausible empty one.'
        );
        self::assertTrue($result['has_core_permission'], 'and the core catalogue too.');
        self::assertContains('demo_catalog:manage', $result['plugin_permissions']);
    }

    /**
     * The same property through the CLI kernel. A registry wired in only one
     * entry point is the divergence bug class this repo has already paid for in
     * #717 and #724: the same plugin, reached two ways, would disagree about
     * which permissions exist.
     */
    public function testCliEntryPointResolvesTheSamePopulatedPermissionRegistry(): void
    {
        $result = $this->runProbe(<<<'PHP'
            require __DIR__ . '/vendor/autoload.php';
            require __DIR__ . '/src/helpers.php';

            $command = new class extends \Whity\Cli\Commands\BaseCommand {
                public function execute(array $argv): int
                {
                    return 0;
                }

                /** Exposes the protected bootstrap every whity-cli API command runs. */
                public function boot(): void
                {
                    $this->setupKernel();
                }
            };

            ob_start();
            $command->boot();
            ob_end_clean();

            $resolved = \Whity\app(\Whity\Core\RBAC\PermissionRegistry::class);

            whity_probe_emit([
                'has_plugin_permission' => $resolved->exists('demo_catalog:view'),
                'has_core_permission'   => $resolved->exists('users:read'),
                // Resolving twice must yield one object: a container that
                // improvised would mint a new empty registry each time.
                'stable_identity'       => $resolved === \Whity\app(\Whity\Core\RBAC\PermissionRegistry::class),
            ]);
            PHP);

        self::assertTrue(
            $result['has_plugin_permission'],
            'BaseCommand::setupKernel() must register the catalogue its own plugin loader fills, '
            . 'or a plugin reached through a CLI command sees no plugin permissions while the '
            . 'same plugin reached over HTTP sees them all.'
        );
        self::assertTrue($result['has_core_permission']);
        self::assertTrue($result['stable_identity'], 'The container must not mint a new registry per lookup.');
    }

    /**
     * The safety net, on a booted host: with the registration removed, the
     * container must THROW rather than resolve an empty catalogue.
     *
     * This is what makes the fix durable. Registering the instance fixes today;
     * failing loudly is what stops the next unregistered stateful registry from
     * degrading into "the permission simply does not exist".
     */
    public function testUnregisteringTheCatalogueMakesTheLookupThrowRatherThanReturnAnEmptyOne(): void
    {
        $result = $this->runProbe(<<<'PHP'
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI']    = '/api/health';
            $_SERVER['HTTP_HOST']      = 'localhost';
            $_GET = [];

            ob_start();
            require __DIR__ . '/public/index.php';
            ob_end_clean();

            // Simulate the pre-fix host: the registry exists and is populated,
            // but nobody registered it.
            unset($GLOBALS['whity_services'][\Whity\Core\RBAC\PermissionRegistry::class]);

            $threw = false;
            $message = '';
            $improvised = null;
            try {
                // Deliberately catching \Exception, exactly as a plugin would.
                $improvised = \Whity\app(\Whity\Core\RBAC\PermissionRegistry::class);
            } catch (\Exception $e) {
                $threw = true;
                $message = $e->getMessage();
            }

            whity_probe_emit([
                'threw'            => $threw,
                'improvised_empty' => $improvised !== null && !$improvised->exists('demo_catalog:view'),
                'message'          => $message,
            ]);
            PHP);

        self::assertFalse(
            $result['improvised_empty'],
            'The container improvised an EMPTY permission catalogue — the original defect.'
        );
        self::assertTrue(
            $result['threw'],
            'An unregistered permission catalogue must raise a catchable \Exception on a real '
            . 'host, not resolve to something plausible.'
        );
        self::assertStringContainsString('HostWiredService', $result['message']);
    }

    // ─── probe plumbing ──────────────────────────────────────────────────────

    /**
     * Run $body in a child PHP process rooted at the repository, with the
     * database environment baked in (the hosts read $_ENV, and whether a
     * subprocess's environment reaches $_ENV depends on variables_order — the
     * exact trap the CI workflow documents).
     *
     * @return array<string, mixed>
     */
    private function runProbe(string $body): array
    {
        $root = dirname(__DIR__, 2);
        $env = $this->hostEnvironment();

        $probe = "<?php\n"
            . "declare(strict_types=1);\n"
            . 'foreach (' . var_export($env, true) . " as \$k => \$v) { \$_ENV[\$k] = \$v; putenv(\"\$k=\$v\"); }\n"
            . "function whity_probe_emit(array \$data): void {\n"
            . "    fwrite(STDERR, '<<<WHITY_PROBE>>>' . json_encode(\$data) . '<<<END>>>');\n"
            . "}\n"
            . $body . "\n";

        $this->probePath = $root . '/.whity-probe-' . bin2hex(random_bytes(6)) . '.php';
        self::assertNotFalse(file_put_contents($this->probePath, $probe), 'Could not write the probe.');

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $this->probePath],
            $descriptors,
            $pipes,
            $root
        );
        self::assertIsResource($process, 'Could not start the probe process.');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        preg_match('/<<<WHITY_PROBE>>>(.*?)<<<END>>>/s', $stderr, $matches);
        if (!isset($matches[1])) {
            self::fail("The probe did not report. Exit code {$exitCode}.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function hostEnvironment(): array
    {
        // "pgsql:host=…;port=…;dbname=…" — drop the driver prefix, then split the
        // remaining key=value pairs. (Parsing `host=` with a leading-boundary
        // regex silently matches nothing, because the first pair sits behind a
        // colon, not a semicolon.)
        $dsn = (string) $this->postgresDsn();
        $pairs = [];
        foreach (explode(';', (string) preg_replace('/^[a-z]+:/i', '', $dsn)) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $pairs[trim($parts[0])] = trim($parts[1]);
            }
        }

        $value = static fn(string $key, string $fallback): string => ($pairs[$key] ?? '') !== ''
            ? $pairs[$key]
            : $fallback;

        return [
            'APP_ENV' => 'development',
            'DB_HOST' => $value('host', 'localhost'),
            'DB_PORT' => $value('port', '5432'),
            'DB_NAME' => $value('dbname', 'whity_core'),
            'DB_USER' => (string) ($_ENV['PHPUNIT_PG_USER'] ?? getenv('PHPUNIT_PG_USER') ?: 'whity'),
            'DB_PASSWORD' => (string) ($_ENV['PHPUNIT_PG_PASSWORD'] ?? getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev'),
            'JWT_SECRET' => (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET')
                ?: 'probe_jwt_secret_min_32_chars_aaaaaaaaaa'),
            'ENCRYPTION_KEY' => (string) ($_ENV['ENCRYPTION_KEY'] ?? getenv('ENCRYPTION_KEY')
                ?: 'probe_encryption_key_min_32_chars_bbbbbb'),
        ];
    }

    private function postgresDsn(): ?string
    {
        $dsn = $_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN') ?: null;

        return $dsn === null ? null : (string) $dsn;
    }
}
