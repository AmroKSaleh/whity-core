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
     * The SDK rendering seam resolves after a real boot, fully assembled
     * (#1072).
     *
     * A source-scanning test already pins that index.php contains the
     * registration ({@see \Tests\Core\DocumentRenderSeamEntryPointWiringTest}),
     * and it cannot catch the failure this one is for. The seam is registered
     * roughly 160 lines below the renderer it wraps, because it also needs
     * `$documentQrService`, which is built later still — and PHP does not
     * hoist. Get that order wrong and every request 500s at boot on an
     * undefined variable, while the source scan, PHPStan and the whole unit
     * suite stay green because none of them ever runs the file.
     *
     * So this boots it, and then asks the container for the CONTRACT a plugin
     * would ask for.
     */
    public function testHttpEntryPointResolvesTheSdkRenderingSeamFullyAssembled(): void
    {
        $result = $this->runProbe(<<<'PHP'
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI']    = '/api/health';
            $_SERVER['HTTP_HOST']      = 'localhost';
            $_GET = [];

            ob_start();
            require __DIR__ . '/public/index.php';
            ob_end_clean();

            // Exactly the line a plugin author writes (Plugin-Development.md
            // Step 12). Resolving by the SDK INTERFACE is the point: a plugin
            // may not reference a core namespace at all.
            $renderer = \Whity\app(\Whity\Sdk\Render\DocumentRenderer::class);

            $reflection = new \ReflectionClass($renderer);
            $qr = $reflection->getProperty('qr');
            $qr->setAccessible(true);

            whity_probe_emit([
                'implements_contract' => $renderer instanceof \Whity\Sdk\Render\DocumentRenderer,
                'is_the_host_adapter' => $renderer instanceof \Whity\Core\Document\Render\SdkDocumentRenderer,
                'stable_identity'     => $renderer === \Whity\app(\Whity\Sdk\Render\DocumentRenderer::class),
                // The collaborator that arrives last and is therefore the one a
                // wrong ordering would leave null.
                'qr_service_wired'    => $qr->getValue($renderer) !== null,
                // Answers rather than throws with no tenant context, which is
                // what makes it safe for a plugin to call speculatively.
                'availability_answers' => $renderer->isAvailable() === false,
            ]);
            PHP);

        self::assertTrue(
            $result['implements_contract'],
            'The container must hand back something implementing the SDK contract; a plugin '
            . 'type-hints that interface and can reference nothing else.'
        );
        self::assertTrue($result['is_the_host_adapter'], 'and it must be the host adapter.');
        self::assertTrue($result['stable_identity'], 'Resolving twice must return one instance.');
        self::assertTrue(
            $result['qr_service_wired'],
            'The verification-code service must be wired in. It is constructed AFTER the renderer '
            . 'the seam wraps, so this is the collaborator a wrong registration order silently '
            . 'leaves null — and the symptom would be documents that issue perfectly well and '
            . 'quietly carry no verification code at all.'
        );
        self::assertTrue(
            $result['availability_answers'],
            'isAvailable() must ANSWER outside a tenant context rather than throw: it exists to '
            . 'be called speculatively, before a plugin spends its queries assembling a document.'
        );
    }

    /**
     * The report registry boots POPULATED, and its routes are registered
     * (#947 item 6).
     *
     * Two failures this catches that nothing else can. The registry is built
     * from `$documentRepository`, `$documentVisibilityPolicy` and
     * `$serverLabels`, all defined further UP the file, and PHP does not hoist
     * — so a registration that drifted above one of them is a boot-time fatal
     * on every request while every unit test stays green.
     *
     * And the registry is a {@see \Whity\Core\Container\HostWiredService} for a
     * specific reason worth proving rather than asserting: an empty one answers
     * "no such report" for every key, which is exactly what an installation
     * with no reports configured looks like. So this checks the source is
     * actually THERE, not merely that something resolved.
     */
    public function testHttpEntryPointResolvesAPopulatedReportRegistryAndRegistersItsRoutes(): void
    {
        $result = $this->runProbe(<<<'PHP'
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI']    = '/api/health';
            $_SERVER['HTTP_HOST']      = 'localhost';
            $_GET = [];

            ob_start();
            require __DIR__ . '/public/index.php';
            ob_end_clean();

            $registry = \Whity\app(\Whity\Core\Report\ReportSourceRegistry::class);
            $documents = $registry->get(\Whity\Core\Report\ReportSourceRegistry::CORE_DOCUMENTS);

            $routes = array_map(
                static fn (array $r): string => strtoupper((string) $r['method']) . ' ' . (string) $r['path'],
                $router->getRoutes()
            );

            whity_probe_emit([
                'keys'                => $registry->keys(),
                'documents_source'    => $documents !== null,
                // The gate that decides who may see these rows. An empty string
                // here would mean every caller the ROUTE admits can read them.
                'required_permission' => $documents?->requiredPermission(),
                'has_index_route'     => in_array('GET /api/v1/reports', $routes, true),
                'has_document_route'  => (bool) preg_grep('#^POST /api/v1/reports/#', $routes),
            ]);
            PHP);

        self::assertSame(
            [\Whity\Core\Report\ReportSourceRegistry::CORE_DOCUMENTS],
            $result['keys'],
            'The registry must boot POPULATED. An empty one is indistinguishable from an '
            . 'installation with no reports, so the caller is told their report does not exist '
            . 'and goes looking at their own permissions.'
        );
        self::assertTrue($result['documents_source']);
        self::assertSame(
            'documents:read',
            $result['required_permission'],
            'The documents report must be gated on the permission that already governs reading '
            . 'documents. A report is a READ; a second vocabulary for it would be a second answer '
            . 'to one question.'
        );
        self::assertTrue($result['has_index_route'], 'GET /api/v1/reports must be registered.');
        self::assertTrue($result['has_document_route'], 'POST /api/v1/reports/{source}/document must be registered.');
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

    /**
     * The restore-state memory must be RESOLVABLE and USABLE from a booted HTTP
     * host.
     *
     * `LifecycleStateMemory::forget()` is what a plugin calls after hard-deleting
     * a record outside core. The class existed and was simply unreachable: the
     * container refuses to build it (it takes a PDO), so an unregistered lookup
     * threw and the only remaining option was a hand-written `DELETE` against a
     * core-owned table. The row nobody deletes carries no foreign key and no
     * cascade — for a client-supplied key, a later record re-using that key
     * inherits the dead record's state and can be restored into a state it never
     * held.
     *
     * The probe resolves it, checks it is the lifecycle service's OWN instance,
     * and CALLS `forget()` against the live database for a record id that does
     * not exist — harmless, and the only way to prove the object is wired to a
     * real connection rather than merely constructed.
     */
    public function testHttpEntryPointResolvesAUsableRestoreStateMemory(): void
    {
        $result = $this->runProbe(<<<'PHP'
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI']    = '/api/health';
            $_SERVER['HTTP_HOST']      = 'localhost';
            $_GET = [];

            ob_start();
            require __DIR__ . '/public/index.php';
            ob_end_clean();

            $memory = \Whity\app(\Whity\Core\DataType\LifecycleStateMemory::class);

            // The very object the lifecycle service uses, not a second one.
            $sameAsServices = $memory === \Whity\app(
                \Whity\Core\DataType\DataTypeLifecycleService::class
            )->stateMemory();

            // Proof it holds a live connection: a forget() for a record that was
            // never remembered is a no-op against the real table, and a
            // recall() straight after must agree.
            $called = false;
            $error = '';
            try {
                $memory->forget('probe:none', 0, 'no-such-record');
                $called = $memory->recall('probe:none', 0, 'no-such-record') === null;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            whity_probe_emit([
                'same_as_services' => $sameAsServices,
                'forget_worked'    => $called,
                'error'            => $error,
            ]);
            PHP);

        self::assertTrue(
            $result['same_as_services'],
            'The container must hand back the memory the lifecycle service keeps, so there is no '
            . 'second instance for a later change to make diverge.'
        );
        self::assertTrue(
            $result['forget_worked'],
            'forget() must run against the live connection. Error: ' . (string) $result['error']
        );
    }

    /**
     * The same property through the CLI kernel. A capability wired in only one
     * entry point is the divergence this file exists to catch: "clear the
     * memory" would work over HTTP and throw under a command.
     */
    public function testCliEntryPointResolvesAUsableRestoreStateMemory(): void
    {
        $result = $this->runProbe(<<<'PHP'
            require __DIR__ . '/vendor/autoload.php';
            require __DIR__ . '/src/helpers.php';

            $command = new class extends \Whity\Cli\Commands\BaseCommand {
                public function execute(array $argv): int
                {
                    return 0;
                }

                public function boot(): void
                {
                    $this->setupKernel();
                }
            };

            ob_start();
            $command->boot();
            ob_end_clean();

            $memory = \Whity\app(\Whity\Core\DataType\LifecycleStateMemory::class);

            $called = false;
            $error = '';
            try {
                $memory->forget('probe:none', 0, 'no-such-record');
                $called = $memory->recall('probe:none', 0, 'no-such-record') === null;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            whity_probe_emit([
                'same_as_services' => $memory === \Whity\app(
                    \Whity\Core\DataType\DataTypeLifecycleService::class
                )->stateMemory(),
                'forget_worked'    => $called,
                'error'            => $error,
            ]);
            PHP);

        self::assertTrue($result['same_as_services']);
        self::assertTrue(
            $result['forget_worked'],
            'A plugin reached through a CLI command must be able to clear a memory row too. Error: '
            . (string) $result['error']
        );
    }

    /**
     * The SDK's lifecycle WRITE contract must be resolvable from a booted HTTP
     * host — and must be the SAME object the generated endpoints gate themselves
     * with.
     *
     * The second half is the load-bearing one. Core told adopters to route their
     * writes through core and published only a read contract, so they duck-typed
     * a core internal; publishing a write contract fixes that only if it cannot
     * become a way around a check the endpoint enforces. Two gates — one for the
     * container, one for the handler — would be identical the day they were
     * written and free to drift on every day after, silently, in the direction
     * that grants a plugin more authority than the endpoint would.
     *
     * The probe also CALLS it, against a type that does not exist, to prove the
     * object is wired to a live registry and connection rather than merely
     * constructed. The answer must be the unknown-type refusal, not an exception.
     */
    public function testHttpEntryPointResolvesTheSameGatedLifecycleTheEndpointsUse(): void
    {
        $result = $this->runProbe(<<<'PHP'
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI']    = '/api/health';
            $_SERVER['HTTP_HOST']      = 'localhost';
            $_GET = [];

            ob_start();
            require __DIR__ . '/public/index.php';
            ob_end_clean();

            $resolved = \Whity\app(\Whity\Sdk\DataType\DataTypeLifecycle::class);

            $outcome = null;
            $error = '';
            try {
                // A type nobody declared: the gate answers before any table is
                // touched, so this is harmless and still proves the object works.
                $outcome = $resolved->trash('probe:none', 0, 'no-such-record', 1);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            whity_probe_emit([
                // $gatedDataTypeLifecycle is index.php's own variable — literally
                // the object it passed to new DataTypesApiHandler(...).
                'same_instance' => $resolved === $gatedDataTypeLifecycle,
                'is_contract'   => $resolved instanceof \Whity\Sdk\DataType\DataTypeLifecycle,
                'status'        => $outcome?->httpStatus(),
                'reason'        => $outcome?->reason(),
                'error'         => $error,
            ]);
            PHP);

        self::assertTrue(
            $result['same_instance'],
            'The container must publish the very gate the generated endpoints authorize through. A '
            . 'second instance is a second authorization implementation waiting to diverge.'
        );
        self::assertTrue($result['is_contract']);
        self::assertSame(
            404,
            $result['status'],
            'The contract must be callable on a live host. Error: ' . (string) $result['error']
        );
        self::assertSame(
            'unknown_data_type',
            $result['reason'],
            'and an unknown type must be refused rather than thrown, in the published vocabulary.'
        );
    }

    /**
     * The same capability through the CLI kernel. Registered in one entry point
     * only, an in-process trash would work over HTTP and throw inside a
     * `whity-cli` command — and a command is exactly where a bulk sweep
     * (empty-trash, bulk retire) runs.
     */
    public function testCliEntryPointResolvesTheSameWriteContract(): void
    {
        $result = $this->runProbe(<<<'PHP'
            require __DIR__ . '/vendor/autoload.php';
            require __DIR__ . '/src/helpers.php';

            $command = new class extends \Whity\Cli\Commands\BaseCommand {
                public function execute(array $argv): int
                {
                    return 0;
                }

                public function boot(): void
                {
                    $this->setupKernel();
                }
            };

            ob_start();
            $command->boot();
            ob_end_clean();

            $resolved = \Whity\app(\Whity\Sdk\DataType\DataTypeLifecycle::class);

            $outcome = null;
            $error = '';
            try {
                $outcome = $resolved->delete('probe:none', 0, 'no-such-record', 1);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            whity_probe_emit([
                'is_contract'     => $resolved instanceof \Whity\Sdk\DataType\DataTypeLifecycle,
                'stable_identity' => $resolved === \Whity\app(\Whity\Sdk\DataType\DataTypeLifecycle::class),
                'status'          => $outcome?->httpStatus(),
                'reason'          => $outcome?->reason(),
                'error'           => $error,
            ]);
            PHP);

        self::assertTrue(
            $result['is_contract'],
            'BaseCommand::setupKernel() must register the write contract too. Error: '
            . (string) $result['error']
        );
        self::assertTrue($result['stable_identity'], 'The container must not mint a new gate per lookup.');
        self::assertSame(404, $result['status']);
        self::assertSame('unknown_data_type', $result['reason']);
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
