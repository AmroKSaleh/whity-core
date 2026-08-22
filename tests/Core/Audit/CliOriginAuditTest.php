<?php

declare(strict_types=1);

namespace Tests\Core\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Audit\AuditOrigin;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Database\Seeder;

/**
 * Real-engine (in-memory SQLite) tests for CLI-driven auditing (#844).
 *
 * #842 wired plugin-declared audited events into the CLI kernel but deliberately
 * left {@see AuditLogger::subscribe()} unbound there, so a worker process
 * recorded a plugin's `acme:thing.created` and stayed silent about core's
 * `user.created`. That asymmetry is the failure this file pins closed: an
 * operator who finds a plugin's action in the trail reasonably infers core's
 * would be there too, and discovers otherwise during an incident.
 *
 * Subscribing raises the question the trail cannot dodge — WHO a shell command
 * is attributed to. The answer taken here, and asserted below, has two halves:
 *
 *  1. `actor_user_id` is never invented. Nothing authenticated, so the column
 *     says so (NULL). A row naming a default user would read exactly like a row
 *     naming a real one, and no later reader could separate them.
 *  2. The row states its PROVENANCE instead, via an {@see AuditOrigin} the
 *     writer stamps and no caller can forge (`_origin: cli`, plus the command
 *     word). "No actor" then has two readings that are told apart by data
 *     rather than by guesswork: a pre-auth HTTP action carries no origin, a
 *     shell command carries `cli`.
 */
final class CliOriginAuditTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        // audit_log.tenant_id carries an FK to tenants, which real PostgreSQL
        // enforces and SQLite does not. Without these rows the writer's own
        // fail-soft swallows the INSERT and the assertions below would be
        // checking an empty table (the same guard AuditLoggerTest keeps).
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES
            (1, 't1'), (2, 't2'), (3, 't3'), (7, 't7'), (9, 't9')");
        TenantContext::reset();
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    /**
     * The headline of #844: core CRUD driven from a command is now in the trail,
     * and the row it writes is honest about who did it.
     *
     * `tenant.created` is the case that matters most — creating a tenant is a
     * platform-level act that has only ever been reachable from an operator's
     * shell, so before this it was the single most consequential mutation the
     * platform performed with no audit row at all.
     */
    public function testCliDrivenCoreCrudIsRecordedWithNoInventedActor(): void
    {
        $hooks = new HookManager();
        $this->cliLogger('tenant')->subscribe($hooks);

        TenantContext::setTenantId(1);
        $hooks->dispatch('tenant.created', ['id' => 9, 'name' => 'Acme', 'slug' => 'acme', 'tenant_id' => 9]);

        $row = $this->onlyRow();
        self::assertSame('tenant.created', $row['action']);
        self::assertSame('tenant', $row['target_type']);
        self::assertSame('9', (string) $row['target_id']);
        self::assertNull(
            $row['actor_user_id'],
            'A shell command authenticated nobody, so the actor column must stay empty rather '
            . 'than name a default user the trail cannot vouch for.'
        );

        $metadata = $this->metadata($row);
        self::assertSame(
            'cli',
            $metadata[AuditOrigin::METADATA_KEY] ?? null,
            'The row must say where it came from, since it cannot say who.'
        );
        self::assertSame('tenant', $metadata[AuditOrigin::COMMAND_METADATA_KEY] ?? null);
    }

    /**
     * The property that makes an empty actor column readable rather than
     * ambiguous.
     *
     * Three rows with no actor, produced by three different situations. Before
     * #844 the third could not exist; the point of the origin stamp is that now
     * that it can, it is not silently confused with the second — which is a
     * failed login from the public internet, and means something very different
     * during an incident.
     */
    public function testACliRowIsDistinguishableFromAWebRowWithNoActorEither(): void
    {
        $web = new AuditLogger($this->pdo);
        $cli = $this->cliLogger('tenant');

        // A real person over HTTP.
        AuditContext::set(42, '203.0.113.5');
        $web->record('user.deleted', ['tenant_id' => 1, 'target_type' => 'user', 'target_id' => 7]);

        // A pre-auth HTTP action: nobody authenticated, but there was a client.
        AuditContext::set(null, '198.51.100.9');
        $web->record('auth.login.failure', ['tenant_id' => 1]);

        // A shell command: nobody authenticated and there is no client at all.
        AuditContext::reset();
        $cli->record('user.deleted', ['tenant_id' => 1, 'target_type' => 'user', 'target_id' => 8]);

        [$person, $preAuth, $shell] = $this->allRows();

        self::assertSame('42', (string) $person['actor_user_id']);
        self::assertArrayNotHasKey(
            AuditOrigin::METADATA_KEY,
            $this->metadata($person),
            'A web row carries no origin key — its absence is what "an HTTP request" means, '
            . 'including for every row written before #844.'
        );

        self::assertNull($preAuth['actor_user_id']);
        self::assertArrayNotHasKey(AuditOrigin::METADATA_KEY, $this->metadata($preAuth));
        self::assertSame('198.51.100.9', $preAuth['ip_address'], 'a pre-auth web action still has a client IP');

        self::assertNull($shell['actor_user_id']);
        self::assertSame('cli', $this->metadata($shell)[AuditOrigin::METADATA_KEY] ?? null);
        self::assertNull($shell['ip_address']);
    }

    /**
     * The origin describes the PROCESS, not the person, so it never displaces a
     * person who is genuinely known.
     *
     * This is what keeps the decision forward-compatible. The CLI has no
     * authenticated principal today, but the moment one exists — an `--as`
     * flag, a device login, a dedicated CLI service profile — {@see AuditContext}
     * carries it and the row must record BOTH facts. Suppressing the actor
     * whenever the channel is `cli` would quietly throw away the better answer
     * on the day it finally arrives.
     */
    public function testAnAuthenticatedActorIsRecordedBesideTheOriginNotReplacedByIt(): void
    {
        $hooks = new HookManager();
        $this->cliLogger('tenant')->subscribe($hooks);

        AuditContext::set(77, null);
        TenantContext::setTenantId(3);
        $hooks->dispatch('role.deleted', ['id' => 4, 'name' => 'editor', 'tenant_id' => 3]);

        $row = $this->onlyRow();
        self::assertSame('77', (string) $row['actor_user_id'], 'a known actor must survive the origin stamp');
        self::assertSame('cli', $this->metadata($row)[AuditOrigin::METADATA_KEY] ?? null);
    }

    /**
     * Provenance a caller could overwrite is provenance an attacker could
     * launder.
     *
     * The metadata on a hook-driven row is caller data — a core handler's
     * payload, or a plugin's declared event, which travels the identical path
     * through {@see AuditLogger::recordFromHook()}. If a payload key named
     * `_origin` could win, any plugin able to dispatch an audited event could
     * make its CLI-driven writes read as ordinary web traffic.
     */
    public function testAHookPayloadCannotForgeOrOverwriteTheOrigin(): void
    {
        $hooks = new HookManager();
        $this->cliLogger('tenant')->subscribe($hooks);

        TenantContext::setTenantId(1);
        $hooks->dispatch('user.updated', [
            'id' => 5,
            'tenant_id' => 1,
            AuditOrigin::METADATA_KEY => 'http',
            AuditOrigin::COMMAND_METADATA_KEY => 'not-really',
        ]);

        $metadata = $this->metadata($this->onlyRow());
        self::assertSame(
            'cli',
            $metadata[AuditOrigin::METADATA_KEY] ?? null,
            'The writer owns this key; a payload claiming it must be overwritten, not merged.'
        );
        self::assertSame('tenant', $metadata[AuditOrigin::COMMAND_METADATA_KEY] ?? null);
    }

    /**
     * A command LINE never reaches the trail — only the command WORD does.
     *
     * The trail is readable by any tenant administrator holding `audit:read`,
     * and command lines routinely carry secrets (`--admin-password=…`, a token,
     * a DSN). The writer's key-based sanitiser cannot help with this: a raw
     * command line is one opaque string with no keys to inspect, so the guard
     * has to be a shape check on the way in.
     *
     * @dataProvider unusableCommandProvider
     */
    public function testOnlyARecognisableCommandWordIsEverRecorded(?string $typed): void
    {
        (new AuditLogger($this->pdo, null, AuditOrigin::cli($typed)))->record('user.created');

        $metadata = $this->metadata($this->onlyRow());
        self::assertSame('cli', $metadata[AuditOrigin::METADATA_KEY] ?? null);
        self::assertArrayNotHasKey(
            AuditOrigin::COMMAND_METADATA_KEY,
            $metadata,
            'An unusable command token is dropped: the row still says cli and simply does not '
            . 'claim to know which command.'
        );
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableCommandProvider(): array
    {
        return [
            'a whole command line with a secret in it' => ['tenant create Acme --admin-password=hunter2'],
            'a phpunit-style argv[1]'                  => ['--filter=SomeTest'],
            'a file path'                              => ['/app/vendor/bin/phpunit'],
            'nothing at all'                           => [null],
        ];
    }

    /**
     * The tenant a CLI row lands in is the one the change actually happened to,
     * not the system tenant by default.
     *
     * {@see AuditLogger::recordFromHook()} prefers the payload's `tenant_id`,
     * then the hook context (which {@see HookManager} fills from
     * {@see TenantContext}), then tenant 0. That order was written for a request
     * that always has a resolved tenant, so it is worth proving on the CLI path
     * rather than assuming: a trail whose CLI rows all silt up in tenant 0 would
     * be invisible on the very tenant's audit screen where the change landed.
     */
    public function testTheAffectedTenantWinsOverTheProcessTenant(): void
    {
        $hooks = new HookManager();
        $this->cliLogger('tenant')->subscribe($hooks);

        // The process is operating as tenant 1; the affected role belongs to 7.
        TenantContext::setTenantId(1);
        $hooks->dispatch('role.created', ['id' => 3, 'name' => 'editor', 'tenant_id' => 7]);

        // A payload with no tenant of its own falls back to the process tenant.
        $hooks->dispatch('ou.created', ['id' => 11, 'name' => 'Branch']);

        [$role, $ou] = $this->allRows();
        self::assertSame('7', (string) $role['tenant_id'], 'the row belongs to the tenant that changed');
        self::assertSame('1', (string) $ou['tenant_id'], 'and otherwise to the tenant the command is acting in');
        self::assertNotSame('0', (string) $ou['tenant_id'], 'never silently the system tenant');
    }

    /**
     * Fail-soft survives the new wiring.
     *
     * This is the guarantee subscribing a second entry point most endangers:
     * every core CRUD hook in the CLI now runs a listener that touches the
     * database, and a listener that throws would take down the command it was
     * only supposed to observe. The filter chain must also come back intact —
     * a listener returning something other than the payload would corrupt the
     * data every later listener sees.
     */
    public function testAFailedAuditWriteNeverBreaksTheCommandItRecords(): void
    {
        $brokenPdo = new PDO('sqlite::memory:');
        $brokenPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $hooks = new HookManager();
        (new AuditLogger($brokenPdo, null, AuditOrigin::cli('tenant')))->subscribe($hooks);

        $payload = ['id' => 1, 'name' => 'editor', 'tenant_id' => 2];
        $result = $hooks->dispatch('role.created', $payload);

        self::assertSame($payload, $result, 'the audited action carries on with its payload untouched');
    }

    /**
     * Seeding and migrations are deliberately OUT of scope, and structurally so.
     *
     * `seed` and `migrate` do not build the CLI kernel: they hold a
     * {@see Database} and write SQL, so no hook is dispatched and no audit
     * listener exists to hear one. That is the desired answer as well as the
     * accidental one — fixture data is not activity, and a bootstrap that
     * produced hundreds of `user.created` rows attributed to nobody would bury
     * the first real administrator action under noise on day one.
     *
     * Asserted behaviourally rather than by reading the source, because the
     * property worth protecting is "running the seeder writes no audit rows",
     * not "the seeder does not currently mention a class". The schema here is
     * built by running every production migration, so the count also covers
     * `migrate`.
     */
    public function testSeedingAndMigrationsWriteNoAuditRows(): void
    {
        // A pristine schema, not the one setUp() dropped fixture tenants into:
        // the seeder must be observed against exactly what `migrate` leaves
        // behind, with nothing this test class put there.
        $pdo = SchemaFromMigrations::make(true);
        $database = Database::withFactory(fn (): PDO => $pdo, 86400, 86400);
        $database->forceConnect();

        Seeder::seed($database);

        self::assertSame(
            0,
            $this->auditRowCount($pdo),
            'Neither the migrations that built this schema nor the seeder may write audit rows: '
            . 'fixture data is not activity.'
        );
    }

    // ==================== helpers ====================

    /**
     * A logger wired the way {@see \Whity\Cli\Commands\BaseCommand::setupKernel()}
     * wires one.
     */
    private function cliLogger(string $command): AuditLogger
    {
        return new AuditLogger($this->pdo, null, AuditOrigin::cli($command));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metadata(array $row): array
    {
        $decoded = json_decode((string) $row['metadata'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(): array
    {
        $rows = $this->allRows();
        self::assertCount(1, $rows, 'exactly one audit row expected');

        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRows(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM audit_log ORDER BY id ASC');
        self::assertNotFalse($stmt);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function auditRowCount(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT COUNT(*) FROM audit_log');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
