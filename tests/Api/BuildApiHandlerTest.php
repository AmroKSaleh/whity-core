<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Whity\Api\BuildApiHandler;
use Whity\Core\BuildIdentity;
use Whity\Core\CoreVersion;
use Whity\Core\Request;
use Whity\Database\Database;

/**
 * #1049: `GET /api/build` — the backend half of the `/web-build` comparison.
 *
 * THE FAILURE THIS SUITE EXISTS TO CATCH is not a 500. It is `commit` quietly
 * becoming a constant again — reverting to `CoreVersion::VERSION`, or to any
 * other value that is the same on every commit — because that is what
 * `/api/health` already does and it is the exact reason drift went unreported
 * twice. A constant passes a smoke test, passes a schema check, and answers
 * 200 forever. The assertions below are shaped so that it does not pass them:
 * the same handler code must produce different commits for different builds,
 * must produce NOTHING when it has nothing, and must never produce the version.
 */
final class BuildApiHandlerTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/whity_build_migrations_' . uniqid('', true);
        mkdir($this->migrationsDir, 0o755, true);

        foreach (['001_create_users', '002_create_tenants', '003_add_documents'] as $name) {
            file_put_contents($this->migrationsDir . '/' . $name . '.php', "<?php\n");
        }
        // Not a migration; must not be counted.
        file_put_contents($this->migrationsDir . '/README.md', "not a migration\n");
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->migrationsDir . '/*') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->migrationsDir)) {
            rmdir($this->migrationsDir);
        }
    }

    /**
     * The whole contract, on a healthy instance.
     */
    public function testReportsTheFullShape(): void
    {
        $handler = $this->handler(
            identity: BuildIdentity::fromBuild(str_repeat('a', 40), '2026-08-30T01:02:03Z'),
            applied: ['001_create_users', '002_create_tenants'],
            bootTimestamp: time() - 90
        );

        $response = $handler->handle(new Request('GET', '/api/build'));
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response->getBody());

        self::assertSame(str_repeat('a', 40), $body['commit']);
        self::assertSame(BuildIdentity::SOURCE_BUILD, $body['source']);
        self::assertSame(CoreVersion::VERSION, $body['core_version']);
        self::assertSame('2026-08-30T01:02:03+00:00', $body['built_at']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $body['booted_at']);
        self::assertGreaterThanOrEqual(90, $body['uptime_seconds']);
        self::assertSame(2, $body['applied_migration_count']);
        self::assertSame('002_create_tenants', $body['latest_applied_migration']);
        self::assertSame(1, $body['pending_migration_count']);
        self::assertArrayHasKey('checkout_commit', $body);
    }

    /**
     * THE REGRESSION GUARD. `commit` must come from the captured identity, not
     * from a constant: two builds of identical code report two different
     * commits, and neither of them is the version.
     *
     * A reversion to `CoreVersion::VERSION` (or to any fixed string) fails the
     * first assertion pair, because one value cannot equal both.
     */
    public function testCommitTracksTheBuildAndIsNotTheVersionConstant(): void
    {
        $first = $this->decode(
            $this->handler(identity: BuildIdentity::fromBuild(str_repeat('1', 40)))
                ->handle(new Request('GET', '/api/build'))
                ->getBody()
        );
        $second = $this->decode(
            $this->handler(identity: BuildIdentity::fromBuild(str_repeat('2', 40)))
                ->handle(new Request('GET', '/api/build'))
                ->getBody()
        );

        self::assertSame(str_repeat('1', 40), $first['commit']);
        self::assertSame(str_repeat('2', 40), $second['commit']);
        self::assertNotSame(
            $first['commit'],
            $second['commit'],
            'Two builds reporting the same commit means the field is a constant again — '
            . 'which is precisely what /api/health\'s `version` already is, and why drift went unreported.'
        );

        foreach ([$first, $second] as $body) {
            self::assertNotSame(CoreVersion::VERSION, $body['commit']);
            self::assertNotSame($body['core_version'], $body['commit']);
            // core_version is STILL the constant, and correctly so — it is the
            // field `/web-build` compares against. The two must not converge.
            self::assertSame(CoreVersion::VERSION, $body['core_version']);
        }
    }

    /**
     * A deployment that cannot establish its own commit says so. Reporting a
     * plausible-looking wrong value is worse than reporting nothing, because a
     * monitor cannot tell the two apart.
     */
    public function testAnUnknownIdentityIsNullRatherThanPlausible(): void
    {
        $body = $this->decode(
            $this->handler(identity: BuildIdentity::unknown())
                ->handle(new Request('GET', '/api/build'))
                ->getBody()
        );

        self::assertNull($body['commit']);
        self::assertNull($body['built_at']);
        self::assertSame(BuildIdentity::SOURCE_UNKNOWN, $body['source']);
        self::assertNotSame('', $body['commit']);
        self::assertNotSame(CoreVersion::VERSION, $body['commit']);
        // The version is still reported — a deployment that cannot name its
        // commit can still name its release.
        self::assertSame(CoreVersion::VERSION, $body['core_version']);
    }

    /**
     * `source` distinguishes an identity baked by a build from one read off a
     * mounted checkout at boot. A consumer must not have to infer it from the
     * presence of a hash — the two are equally 40 hex characters.
     */
    public function testSourceNamesWhereTheCommitCameFrom(): void
    {
        $root = sys_get_temp_dir() . '/whity_build_root_' . uniqid('', true);
        mkdir($root . '/.git', 0o755, true);
        file_put_contents($root . '/.git/HEAD', str_repeat('c', 40) . "\n");

        try {
            $body = $this->decode(
                (new BuildApiHandler($this->database(), $root, $this->migrationsDir))
                    ->handle(new Request('GET', '/api/build'))
                    ->getBody()
            );

            self::assertSame(str_repeat('c', 40), $body['commit']);
            self::assertSame(BuildIdentity::SOURCE_CHECKOUT, $body['source']);
            self::assertNull($body['built_at'], 'A checkout was pulled, not built — there is no build time to report.');
        } finally {
            unlink($root . '/.git/HEAD');
            rmdir($root . '/.git');
            rmdir($root);
        }
    }

    /**
     * THE REPORTED INCIDENT, IN ONE REQUEST: the checkout moved and the workers
     * were never restarted, so the process is serving code that is no longer on
     * disk. `commit` is frozen at boot; `checkout_commit` is read now; they
     * disagree — and every other signal, `/api/health` included, still says the
     * instance is fine.
     */
    public function testAMovedCheckoutUnderARunningWorkerIsVisibleAsTwoDisagreeingFields(): void
    {
        $root = sys_get_temp_dir() . '/whity_build_root_' . uniqid('', true);
        mkdir($root . '/.git', 0o755, true);
        file_put_contents($root . '/.git/HEAD', str_repeat('7', 40) . "\n");

        try {
            // Constructed once, as a worker is: the identity freezes here.
            $handler = new BuildApiHandler($this->database(), $root, $this->migrationsDir);

            // Somebody pulls. The worker is not restarted.
            file_put_contents($root . '/.git/HEAD', str_repeat('8', 40) . "\n");

            $body = $this->decode($handler->handle(new Request('GET', '/api/build'))->getBody());

            self::assertSame(str_repeat('7', 40), $body['commit'], 'The RUNNING commit must not follow the disk.');
            self::assertSame(str_repeat('8', 40), $body['checkout_commit'], 'The on-disk commit must be read per request.');
            self::assertNotSame($body['commit'], $body['checkout_commit']);
        } finally {
            unlink($root . '/.git/HEAD');
            rmdir($root . '/.git');
            rmdir($root);
        }
    }

    /**
     * The field #1049 calls the highest-value one: the difference between "the
     * code is new and the schema is not" and a green health check.
     */
    public function testPendingMigrationCountIsTheFilesNotYetApplied(): void
    {
        $body = $this->decode(
            $this->handler(applied: ['001_create_users'])
                ->handle(new Request('GET', '/api/build'))
                ->getBody()
        );

        self::assertSame(1, $body['applied_migration_count']);
        self::assertSame('001_create_users', $body['latest_applied_migration']);
        self::assertSame(2, $body['pending_migration_count']);
    }

    public function testAFullyMigratedInstanceReportsZeroPending(): void
    {
        $body = $this->decode(
            $this->handler(applied: ['001_create_users', '002_create_tenants', '003_add_documents'])
                ->handle(new Request('GET', '/api/build'))
                ->getBody()
        );

        self::assertSame(3, $body['applied_migration_count']);
        self::assertSame('003_add_documents', $body['latest_applied_migration']);
        self::assertSame(0, $body['pending_migration_count']);
    }

    /**
     * PLUGIN MIGRATIONS SHARE THE CORE LEDGER TABLE, and must not be counted.
     *
     * Found by booting the release image rather than by reading the code:
     * `PluginMigrationRunner` records rows as `plugin:<Plugin>:<Class>` in
     * `core_schema_migrations`, and because `plugin:` sorts after every `NNN_`
     * the endpoint reported `latest_applied_migration:
     * "plugin:HelloWorld:GrantGreetingsPermissionsToAdmin"` on a fully
     * migrated instance — naming a plugin class instead of the core migration
     * the schema is at, with a count inflated against a `pending` computed
     * from core files only.
     */
    public function testPluginMigrationRowsAreNotCountedAsCoreMigrations(): void
    {
        $body = $this->decode(
            $this->handler(applied: [
                '001_create_users',
                '002_create_tenants',
                '003_add_documents',
                'plugin:HelloWorld:CreateHelloGreetingsTable',
                'plugin:HelloWorld:GrantGreetingsPermissionsToAdmin',
            ])->handle(new Request('GET', '/api/build'))->getBody()
        );

        self::assertSame(3, $body['applied_migration_count']);
        self::assertSame('003_add_documents', $body['latest_applied_migration']);
        self::assertSame(0, $body['pending_migration_count']);
    }

    /**
     * A database that has never been migrated has no `core_schema_migrations`
     * table. That is an answer — applied 0, everything pending — not an error.
     */
    public function testAnUnmigratedDatabaseReportsEverythingPending(): void
    {
        $db = Database::withFactory(function (): PDO {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        });

        $body = $this->decode(
            (new BuildApiHandler($db, sys_get_temp_dir(), $this->migrationsDir, null, BuildIdentity::unknown()))
                ->handle(new Request('GET', '/api/build'))
                ->getBody()
        );

        self::assertSame(0, $body['applied_migration_count']);
        self::assertNull($body['latest_applied_migration']);
        self::assertSame(3, $body['pending_migration_count']);
    }

    /**
     * A dead database still answers the identity question — which is exactly
     * when somebody asks which build is misbehaving. The schema fields go NULL,
     * never 0: `pending_migration_count: 0` means "nothing to apply" and must
     * never be produced by a query that failed.
     */
    public function testADeadDatabaseNullsTheSchemaFieldsAndStillReportsIdentity(): void
    {
        $db = Database::withFactory(static function (): PDO {
            throw new PDOException('SQLSTATE[08006] could not connect to server');
        });

        $handler = new BuildApiHandler(
            $db,
            sys_get_temp_dir(),
            $this->migrationsDir,
            null,
            BuildIdentity::fromBuild(str_repeat('b', 40))
        );

        $response = $handler->handle(new Request('GET', '/api/build'));

        // 200, not 503: liveness is /api/health's job and it still 503s.
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response->getBody());
        self::assertSame(str_repeat('b', 40), $body['commit']);
        self::assertSame(CoreVersion::VERSION, $body['core_version']);
        self::assertNull($body['applied_migration_count']);
        self::assertNull($body['latest_applied_migration']);
        self::assertNull($body['pending_migration_count']);
        self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $response->getBody());
    }

    /**
     * A cached build identity is the PREVIOUS build's identity, which is the
     * very lie being hunted. Same header, same reason, as `/web-build`.
     */
    public function testTheResponseIsNotCacheable(): void
    {
        $response = $this->handler()->handle(new Request('GET', '/api/build'));

        // The response normalizes header names to lowercase-with-hyphens.
        self::assertSame('no-store', $response->getHeaders()['cache-control'] ?? null);
    }

    /**
     * @param list<string> $applied
     */
    private function handler(
        ?BuildIdentity $identity = null,
        array $applied = [],
        ?int $bootTimestamp = null
    ): BuildApiHandler {
        return new BuildApiHandler(
            $this->database($applied),
            sys_get_temp_dir(),
            $this->migrationsDir,
            $bootTimestamp,
            $identity ?? BuildIdentity::fromBuild(str_repeat('a', 40))
        );
    }

    /**
     * An in-memory SQLite database carrying the migration ledger.
     *
     * @param list<string> $applied
     */
    private function database(array $applied = []): Database
    {
        return Database::withFactory(function () use ($applied): PDO {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE core_schema_migrations (id INTEGER PRIMARY KEY, migration_name TEXT, executed_at TEXT)');

            // Inserted OUT OF ORDER on purpose: `latest_applied_migration` must
            // be the highest migration NAME, not whichever row the engine hands
            // back first. A batch run stamps many rows with one instant, so
            // "the newest row" is not a defined thing.
            foreach (array_reverse($applied) as $name) {
                $statement = $pdo->prepare('INSERT INTO core_schema_migrations (migration_name, executed_at) VALUES (?, ?)');
                $statement->execute([$name, '2026-01-01 00:00:00']);
            }

            return $pdo;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
