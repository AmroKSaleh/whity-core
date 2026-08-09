<?php

declare(strict_types=1);

namespace Tests\Core\Health;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whity\Core\Health\HealthProbe;
use Whity\Core\Health\HealthProbeRegistry;
use Whity\Core\Health\HealthSampleRepository;
use Whity\Core\Health\HealthStatus;
use Whity\Core\Health\InvalidHealthProbeException;
use Whity\Core\Health\StatusReport;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Sdk\Health\HealthProbeDefinition;
use Whity\Sdk\Health\ProbeResult;

/**
 * WC-status-probes: the status page's component set is a registry a plugin can
 * contribute to, not a literal array.
 *
 * Two things are being pinned at once, and they pull in opposite directions:
 *
 *  1. a plugin CAN get a component of its own sampled and published; and
 *  2. a plugin CANNOT touch the four components core has always sampled —
 *     neither by renaming one, nor by intercepting one, nor by taking the whole
 *     collection pass down with it.
 *
 * (2) is the one worth over-testing. The status page is what an operator reads
 * when everything else is on fire; a plugin that can make `database` report
 * "operational" during an outage is worse than having no plugin probes at all.
 */
final class HealthProbeRegistryTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            $this->removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    // ==================== core is unchanged ====================

    public function testCoreProbeSetIsExactlyTheFourItHasAlwaysBeen(): void
    {
        self::assertSame(
            ['database', 'queue', 'scheduler', 'render'],
            (new HealthProbeRegistry())->getAll(),
            'The registry replaced a literal array in HealthProbe::runAll(); its core '
            . 'contents and ORDER must match that array exactly, or the collector starts '
            . 'writing a different component set than the status page reports on.'
        );
    }

    public function testCoreProbesAreBareAndOwnedByCore(): void
    {
        $registry = new HealthProbeRegistry();

        self::assertSame(
            ['database', 'queue', 'scheduler', 'render'],
            $registry->getBySource(HealthProbeRegistry::CORE_SOURCE)
        );
        self::assertTrue($registry->exists('database'));
        self::assertNull(
            $registry->definitionFor('database'),
            'Core probes carry no contributed definition: HealthProbe implements them itself, '
            . 'which is what keeps this change a widening rather than a rewrite.'
        );
    }

    public function testAProbeRunWithoutARegistrySamplesExactlyTheCoreFour(): void
    {
        $pdo = $this->sqlite();
        $results = (new HealthProbe($pdo, new HealthSampleRepository($pdo)))->runAll();

        self::assertSame(['database', 'queue', 'scheduler', 'render'], array_keys($results));
        self::assertSame(HealthStatus::Operational, $results['database']);
    }

    public function testCoreProbesAreUnaffectedByAContribution(): void
    {
        $pdo = $this->sqlite();
        $registry = new HealthProbeRegistry();
        $registry->register('Acme', [
            new HealthProbeDefinition('ldap', 'Directory service', static fn (): ProbeResult
                => ProbeResult::down('directory unreachable')),
        ]);

        $results = (new HealthProbe($pdo, new HealthSampleRepository($pdo), null, $registry))->runAll();

        self::assertSame(
            ['database', 'queue', 'scheduler', 'render', 'acme:ldap'],
            array_keys($results),
            'Core components stay first and in their original order; contributions append.'
        );
        foreach (['database', 'queue', 'scheduler', 'render'] as $core) {
            self::assertSame(
                HealthStatus::Operational,
                $results[$core],
                "A plugin reporting its own component DOWN must not change {$core}."
            );
        }
    }

    // ==================== a plugin probe is collected ====================

    public function testAContributedProbeIsSampledAndPersisted(): void
    {
        $pdo = $this->sqlite();
        $registry = new HealthProbeRegistry();
        $registry->register('Acme', [
            new HealthProbeDefinition('ldap', 'Directory service', static fn (): ProbeResult
                => ProbeResult::degraded('bind took 900ms', 900)),
        ]);

        $results = (new HealthProbe($pdo, new HealthSampleRepository($pdo), null, $registry))->runAll();

        self::assertSame(HealthStatus::Degraded, $results['acme:ldap'] ?? null);

        $row = $this->query(
            $pdo,
            "SELECT component, status, source, latency_ms, detail FROM health_samples WHERE component = 'acme:ldap'"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'The contributed probe must produce a health_samples row like any other.');
        self::assertSame('degraded', $row['status']);
        self::assertSame('internal', $row['source']);
        self::assertSame(900, (int) $row['latency_ms']);
        self::assertSame('bind took 900ms', $row['detail']);
    }

    public function testAContributedComponentIsRenderedOnTheStatusPage(): void
    {
        $pdo = $this->sqlite();
        $registry = new HealthProbeRegistry();
        $registry->register('Acme', [
            new HealthProbeDefinition('ldap', 'Directory service', static fn (): ProbeResult
                => ProbeResult::operational()),
        ]);

        $keys = array_column((new StatusReport(new HealthSampleRepository($pdo), $registry))->build()['components'], 'name', 'key');

        self::assertSame(
            ['api', 'database', 'queue', 'scheduler', 'render', 'web', 'acme:ldap'],
            array_keys($keys),
            'Core cards keep their position — an operator looks for the database card where it '
            . 'has always been — and contributed cards follow.'
        );
        self::assertSame('Directory service', $keys['acme:ldap']);
    }

    public function testAStatusPageWithNoRegistryShowsExactlyTheCoreCards(): void
    {
        $pdo = $this->sqlite();

        $keys = array_column((new StatusReport(new HealthSampleRepository($pdo)))->build()['components'], 'key');

        self::assertSame(['api', 'database', 'queue', 'scheduler', 'render', 'web'], $keys);
    }

    // ==================== a plugin cannot collide or shadow ====================

    public function testTwoPluginsDeclaringTheSameKeyDoNotCollide(): void
    {
        $registry = new HealthProbeRegistry();
        $registry->register('Acme', [
            new HealthProbeDefinition('ldap', 'Acme directory', static fn (): ProbeResult => ProbeResult::operational()),
        ]);
        $registry->register('Globex', [
            new HealthProbeDefinition('ldap', 'Globex directory', static fn (): ProbeResult => ProbeResult::operational()),
        ]);

        self::assertTrue($registry->exists('acme:ldap'));
        self::assertTrue($registry->exists('globex:ldap'));
        self::assertFalse(
            $registry->exists('ldap'),
            'A bare plugin key must never become a component on its own — two plugins sharing '
            . 'one health_samples key would make BOTH uptime figures fiction.'
        );
        self::assertSame('Acme directory', $registry->contributedLabels()['acme:ldap']);
        self::assertSame('Globex directory', $registry->contributedLabels()['globex:ldap']);
    }

    public function testAPluginDeclaringACoreKeyGetsItsOwnNamespacedComponent(): void
    {
        $registry = new HealthProbeRegistry();
        $registry->register('Impostor', [
            new HealthProbeDefinition('database', 'Not the database', static fn (): ProbeResult
                => ProbeResult::down('hijacked')),
        ]);

        self::assertTrue($registry->exists('impostor:database'));
        self::assertSame(
            ['database', 'queue', 'scheduler', 'render'],
            $registry->getBySource(HealthProbeRegistry::CORE_SOURCE),
            "Core's components must remain exactly the four bare keys core owns."
        );
        self::assertNull(
            $registry->definitionFor('database'),
            'Nothing a plugin declares may become the definition of a bare core key.'
        );
    }

    /**
     * The end the namespacing exists to serve: even a plugin that declares
     * `database` cannot make the database card lie.
     */
    public function testAPluginCannotInterceptACoreProbe(): void
    {
        $pdo = $this->sqlite();
        $invoked = 0;

        $registry = new HealthProbeRegistry();
        $registry->register('Impostor', [
            new HealthProbeDefinition('database', 'Not the database', static function () use (&$invoked): ProbeResult {
                $invoked++;

                return ProbeResult::down('hijacked');
            }),
        ]);

        $results = (new HealthProbe($pdo, new HealthSampleRepository($pdo), null, $registry))->runAll();

        self::assertSame(
            HealthStatus::Operational,
            $results['database'],
            "The core database probe must have run — not the plugin's."
        );
        self::assertSame(HealthStatus::Down, $results['impostor:database']);
        self::assertSame(1, $invoked, "The plugin's callable runs for its OWN component only.");

        $statuses = $this->query(
            $pdo,
            "SELECT status FROM health_samples WHERE component = 'database'"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['operational'], $statuses);
    }

    public function testAPluginCannotRegisterUnderTheReservedCoreSource(): void
    {
        $this->expectException(InvalidHealthProbeException::class);

        (new HealthProbeRegistry())->register(HealthProbeRegistry::CORE_SOURCE, [
            new HealthProbeDefinition('sneaky', 'Sneaky', static fn (): ProbeResult => ProbeResult::operational()),
        ]);
    }

    public function testCanonicalKeyIsTheOnePlaceTheNamespacingRuleLives(): void
    {
        self::assertSame('acme:ldap', HealthProbeRegistry::canonicalKey('Acme', 'ldap'));
        self::assertSame(
            'acme_widgets:ldap',
            HealthProbeRegistry::canonicalKey('Acme\\Widgets\\Acme Widgets', 'ldap')
        );
        self::assertSame(
            'database',
            HealthProbeRegistry::canonicalKey(HealthProbeRegistry::CORE_SOURCE, 'database'),
            'Core keys stay bare.'
        );
    }

    // ==================== malformed declarations ====================

    /**
     * @param array<int, mixed> $definitions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDeclarations')]
    public function testAMalformedDeclarationIsRefusedWholesale(array $definitions): void
    {
        $registry = new HealthProbeRegistry();

        try {
            $registry->register('Acme', $definitions);
            self::fail('A malformed declaration must be rejected.');
        } catch (InvalidHealthProbeException) {
            // expected
        }

        self::assertSame(
            [],
            $registry->getBySource('Acme'),
            'All-or-nothing: a partially applied catalogue would leave some of a plugin\'s '
            . 'components watched and others silently not.'
        );
    }

    /** @return array<string, array{0: array<int, mixed>}> */
    public static function malformedDeclarations(): array
    {
        $good = new HealthProbeDefinition('ldap', 'Directory', static fn (): ProbeResult => ProbeResult::operational());

        return [
            'key with a colon (writing its own prefix)' => [[
                new HealthProbeDefinition('acme:ldap', 'Directory', static fn (): ProbeResult => ProbeResult::operational()),
            ]],
            'uppercase key' => [[
                new HealthProbeDefinition('LDAP', 'Directory', static fn (): ProbeResult => ProbeResult::operational()),
            ]],
            'empty key' => [[
                new HealthProbeDefinition('', 'Directory', static fn (): ProbeResult => ProbeResult::operational()),
            ]],
            'not a definition at all' => [['ldap']],
            'one good, one bad' => [[
                $good,
                new HealthProbeDefinition('Nope!', 'Directory', static fn (): ProbeResult => ProbeResult::operational()),
            ]],
            'the same key twice' => [[$good, $good]],
            'key too wide for health_samples.component' => [[
                new HealthProbeDefinition(str_repeat('x', 64), 'Directory', static fn (): ProbeResult => ProbeResult::operational()),
            ]],
        ];
    }

    public function testASourceWithNoUsableNamespaceIsRejected(): void
    {
        $this->expectException(InvalidHealthProbeException::class);

        (new HealthProbeRegistry())->register('123', [
            new HealthProbeDefinition('ldap', 'Directory', static fn (): ProbeResult => ProbeResult::operational()),
        ]);
    }

    // ==================== the boundary around a bad probe ====================

    public function testAThrowingProbeRecordsThatComponentDownAndDoesNotAbortThePass(): void
    {
        $pdo = $this->sqlite();
        $registry = new HealthProbeRegistry();
        $registry->register('Acme', [
            new HealthProbeDefinition('ldap', 'Directory', static function (): ProbeResult {
                throw new RuntimeException('boom');
            }),
            new HealthProbeDefinition('billing', 'Billing gateway', static fn (): ProbeResult
                => ProbeResult::operational(12)),
        ]);

        $results = (new HealthProbe($pdo, new HealthSampleRepository($pdo), null, $registry))->runAll();

        self::assertSame(HealthStatus::Down, $results['acme:ldap']);
        self::assertSame(
            HealthStatus::Operational,
            $results['acme:billing'],
            'A probe that throws must not stop the components AFTER it from being sampled.'
        );
        self::assertSame(HealthStatus::Operational, $results['database']);

        $detail = $this->query($pdo, "SELECT detail FROM health_samples WHERE component = 'acme:ldap'")
            ->fetchColumn();
        self::assertSame('boom', $detail, 'The failure reason is kept for the operator log.');
    }

    public function testAProbeReturningTheWrongTypeIsTreatedAsDown(): void
    {
        $pdo = $this->sqlite();
        $registry = new HealthProbeRegistry();

        // A plugin ignoring the contract: PHP will not catch a wrong return type
        // through a `callable` parameter, so the host has to. It must degrade to
        // "down" — not fatal, and above all not silently "operational".
        /** @var callable(): \Whity\Sdk\Health\ProbeResult $rogue */
        $rogue = static fn (): string => 'fine, honest';

        $registry->register('Acme', [
            new HealthProbeDefinition('ldap', 'Directory', $rogue),
        ]);

        $results = (new HealthProbe($pdo, new HealthSampleRepository($pdo), null, $registry))->runAll();

        self::assertSame(HealthStatus::Down, $results['acme:ldap']);
    }

    // ==================== through the real plugin loader ====================

    public function testTheLoaderAttributesAProbeToThePluginNameItSupplies(): void
    {
        $registry = new HealthProbeRegistry();
        $this->loadFixturePlugin($registry);

        self::assertTrue(
            $registry->exists('healthprobefixture:ldap'),
            'The loader must namespace a declared probe under $plugin->getName().'
        );
        self::assertSame(
            ['healthprobefixture:ldap'],
            $registry->getBySource('HealthProbeFixture'),
            'Attribution comes from the loader, never from anything the plugin returns.'
        );
        $definition = $registry->definitionFor('healthprobefixture:ldap');
        self::assertNotNull($definition, 'The declared probe must be runnable through the registry.');
        self::assertSame('Directory service', $definition->label);
        self::assertSame(ProbeResult::STATUS_OPERATIONAL, $definition->run()->status);
    }

    public function testAPluginWithAnInvalidDeclarationStillLoadsAndContributesNothing(): void
    {
        $registry = new HealthProbeRegistry();
        $loader = $this->loadFixturePlugin($registry, invalid: true);

        self::assertSame(
            [],
            $registry->getBySource('HealthProbeFixtureBad'),
            'A bad declaration contributes nothing…'
        );
        self::assertSame(
            ['database', 'queue', 'scheduler', 'render'],
            $registry->getAll(),
            '…and leaves the core catalogue intact.'
        );
        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        self::assertSame(
            'active',
            $states['HealthProbeFixtureBad'] ?? null,
            '…and is a logged warning, not a dead plugin (and certainly not a dead host).'
        );
    }

    // ==================== helpers ====================

    private function loadFixturePlugin(HealthProbeRegistry $registry, bool $invalid = false): PluginLoader
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_health_probes_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $name = $invalid ? 'HealthProbeFixtureBad' : 'HealthProbeFixture';
        $class = $name . 'Plugin';
        $key = $invalid ? 'Not A Key!' : 'ldap';

        file_put_contents($this->tempDir . '/' . $class . '.php', <<<PHP
        <?php

        namespace Whity\\Plugins;

        use Whity\\Sdk\\PluginInterface;
        use Whity\\Sdk\\Health\\HealthProbeDefinition;
        use Whity\\Sdk\\Health\\PluginHealthProbesInterface;
        use Whity\\Sdk\\Health\\ProbeResult;

        class {$class} implements PluginInterface, PluginHealthProbesInterface
        {
            public function getName(): string { return '{$name}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }

            public function getHealthProbes(): array
            {
                return [
                    new HealthProbeDefinition(
                        '{$key}',
                        'Directory service',
                        static fn (): ProbeResult => ProbeResult::operational(3)
                    ),
                ];
            }
        }
        PHP);

        $loader = new PluginLoader(
            $this->tempDir,
            new Router(''),
            null,
            null,
            null,
            null,
            null,
            $registry
        );
        $loader->load();

        return $loader;
    }

    /**
     * An in-memory `health_samples` mirroring migration 085 closely enough for
     * the repository's SQLite path.
     */
    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE health_samples (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                component   TEXT NOT NULL,
                status      TEXT NOT NULL,
                source      TEXT NOT NULL DEFAULT 'internal',
                latency_ms  INTEGER,
                detail      TEXT,
                observed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now'))
            )"
        );

        return $pdo;
    }

    /** A query whose failure is a broken test, not a case to handle. */
    private function query(PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement, "Query failed: {$sql}");

        return $statement;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
