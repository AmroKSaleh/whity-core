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

/**
 * Real-engine tests for job-driven auditing (#935).
 *
 * #844/#931 closed the CLI half of this and, in doing so, made the worker's
 * silence the last hole: `queue:work` builds its OWN plugin loader, with no hook
 * manager and no audit writer, so core CRUD performed by a job dispatched into
 * nothing and a plugin's declared events did too. Nothing was recorded.
 *
 * That gap is the awkward one. A command is run by a person who can go and look
 * for the row; a job runs unattended, so an absent row is never noticed — and
 * the trail reads as complete, because everything that IS in it looks normal.
 * Background jobs are also where bulk and scheduled changes happen: the work
 * most likely to touch many records and least likely to be reconstructable
 * afterwards.
 *
 * The answer mirrors #931's rather than inventing a second vocabulary:
 *
 *  1. `actor_user_id` stays NULL. Nothing authenticated. Whoever enqueued the
 *     work may be long gone, and naming them would claim they performed an
 *     action they only scheduled.
 *  2. The row states its provenance instead — `_origin: job`, plus the job name
 *     in `_origin_command` — stamped by the writer where no caller can forge it.
 *  3. `job` is a channel of its own, not `cli`. Both are unattended and both
 *     lack an actor, but they send an investigator to different places: `cli` to
 *     a shell history, `job` to whatever enqueued the work.
 */
final class JobOriginAuditTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        // audit_log.tenant_id carries an FK real PostgreSQL enforces; without
        // these the writer's fail-soft swallows the INSERT and the assertions
        // below would be checking an empty table.
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 't1'), (2, 't2'), (9, 't9')");
        TenantContext::reset();
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    /** The headline: core CRUD performed by a job is in the trail at all. */
    public function testJobDrivenCoreCrudIsRecordedWithNoInventedActor(): void
    {
        $hooks = new HookManager();
        $this->jobLogger()->subscribe($hooks);

        AuditContext::setJob('core.notifications.deliver');
        TenantContext::setTenantId(1);
        $hooks->dispatch('user.created', ['id' => 5, 'email' => 'a@b.test', 'tenant_id' => 1]);

        $row = $this->onlyRow();
        self::assertSame('user.created', $row['action']);
        self::assertNull(
            $row['actor_user_id'],
            'A job authenticated nobody; whoever enqueued it did not perform this action.'
        );

        $metadata = $this->metadata($row);
        self::assertSame(AuditOrigin::CHANNEL_JOB, $metadata[AuditOrigin::METADATA_KEY] ?? null);
        self::assertSame(
            'core.notifications.deliver',
            $metadata[AuditOrigin::COMMAND_METADATA_KEY] ?? null,
            'Which job did this is the whole value of the stamp.'
        );
    }

    /** A job row must not be mistaken for an operator's shell command. */
    public function testJobRowIsDistinguishableFromACliRow(): void
    {
        $cli = new AuditLogger($this->pdo, null, AuditOrigin::cli('tenant'));
        $job = $this->jobLogger();

        AuditContext::reset();
        $cli->record('user.deleted', ['tenant_id' => 1, 'target_type' => 'user', 'target_id' => 7]);

        AuditContext::setJob('acme:sync');
        $job->record('user.deleted', ['tenant_id' => 1, 'target_type' => 'user', 'target_id' => 8]);

        [$shell, $worker] = $this->allRows();

        self::assertSame(AuditOrigin::CHANNEL_CLI, $this->metadata($shell)[AuditOrigin::METADATA_KEY]);
        self::assertSame(AuditOrigin::CHANNEL_JOB, $this->metadata($worker)[AuditOrigin::METADATA_KEY]);
        self::assertNull($shell['actor_user_id']);
        self::assertNull($worker['actor_user_id']);
    }

    /**
     * The row must carry the ENQUEUEING tenant, not the system tenant.
     *
     * `JobRunner` restores the job's tenant before the handler, and that is what
     * makes this true — a job-driven row landing on tenant 0 would be invisible
     * to the tenant whose data actually changed.
     */
    public function testTheRowCarriesTheJobsOwnTenant(): void
    {
        $hooks = new HookManager();
        $this->jobLogger()->subscribe($hooks);

        AuditContext::setJob('acme:sync');
        TenantContext::setTenantId(9);
        $hooks->dispatch('user.created', ['id' => 3, 'email' => 'x@y.test', 'tenant_id' => 9]);

        self::assertSame('9', (string) $this->onlyRow()['tenant_id']);
    }

    /**
     * A job name cannot leak into work done after it.
     *
     * `JobRunner` clears the context in the same `finally` that already clears
     * the tenant and the actor, so the next job — or an idle worker writing for
     * some other reason — cannot inherit the previous job's name and mislabel
     * its row.
     */
    public function testTheJobNameDoesNotLeakPastTheJob(): void
    {
        $logger = $this->jobLogger();

        AuditContext::setJob('acme:sync');
        $logger->record('user.deleted', ['tenant_id' => 1, 'target_type' => 'user', 'target_id' => 1]);

        AuditContext::reset();
        $logger->record('user.deleted', ['tenant_id' => 1, 'target_type' => 'user', 'target_id' => 2]);

        [$during, $after] = $this->allRows();

        self::assertSame('acme:sync', $this->metadata($during)[AuditOrigin::COMMAND_METADATA_KEY] ?? null);
        self::assertSame(
            AuditOrigin::CHANNEL_JOB,
            $this->metadata($after)[AuditOrigin::METADATA_KEY] ?? null,
            'The channel is a property of the PROCESS and stays.'
        );
        self::assertArrayNotHasKey(
            AuditOrigin::COMMAND_METADATA_KEY,
            $this->metadata($after),
            'The job name is a property of the unit of work and must not outlive it.'
        );
    }

    /**
     * A caller cannot forge its own provenance.
     *
     * The stamp goes on after the caller's metadata is sanitised, so a hook
     * payload — or a plugin's declared event, which travels the same path —
     * shipping `_origin` keys cannot launder a row into looking like something
     * else.
     */
    public function testMetadataCannotOverwriteTheJobStamp(): void
    {
        AuditContext::setJob('acme:sync');

        $this->jobLogger()->record('user.deleted', [
            'tenant_id' => 1,
            'target_type' => 'user',
            'target_id' => 4,
            'metadata' => [
                AuditOrigin::METADATA_KEY => 'http',
                AuditOrigin::COMMAND_METADATA_KEY => 'something-else',
            ],
        ]);

        $metadata = $this->metadata($this->onlyRow());
        self::assertSame(AuditOrigin::CHANNEL_JOB, $metadata[AuditOrigin::METADATA_KEY]);
        self::assertSame('acme:sync', $metadata[AuditOrigin::COMMAND_METADATA_KEY]);
    }

    /**
     * An unusable job name is dropped rather than cleaned up or recorded raw.
     *
     * The row still says `job`; it simply does not claim to know which one —
     * the same treatment #931 gave a command word that does not look like one.
     */
    public function testAnUnusableJobNameIsDroppedButTheChannelRemains(): void
    {
        AuditContext::setJob("not a job name\nwith a newline");

        $this->jobLogger()->record('user.deleted', [
            'tenant_id' => 1,
            'target_type' => 'user',
            'target_id' => 6,
        ]);

        $metadata = $this->metadata($this->onlyRow());
        self::assertSame(AuditOrigin::CHANNEL_JOB, $metadata[AuditOrigin::METADATA_KEY]);
        self::assertArrayNotHasKey(AuditOrigin::COMMAND_METADATA_KEY, $metadata);
    }

    /** Real registry names must survive the name filter. */
    public function testRealJobNamesAreAccepted(): void
    {
        foreach (['core.notifications.deliver', 'acme:sync', 'core.errors.alert'] as $name) {
            self::assertSame(
                $name,
                AuditOrigin::normalizeUnitName($name),
                'A name the JobRegistry accepts must reach the trail unchanged.'
            );
        }
    }

    /**
     * A writer with NO origin must not let a caller invent one.
     *
     * Found while building the job stamp and fixed with it: the writer used to
     * state its provenance by OVERWRITING, and a writer with no origin overwrites
     * nothing — so a hook payload carrying `_origin: cli` reached the row intact
     * and an ordinary HTTP action could present itself as an operator's shell
     * command. Writer-owned keys are now stripped from caller metadata whether or
     * not there is an origin to replace them with.
     */
    public function testAnOriginlessWriterStripsForgedProvenance(): void
    {
        (new AuditLogger($this->pdo))->record('user.deleted', [
            'tenant_id' => 1,
            'target_type' => 'user',
            'target_id' => 5,
            'metadata' => [
                AuditOrigin::METADATA_KEY => AuditOrigin::CHANNEL_CLI,
                AuditOrigin::COMMAND_METADATA_KEY => 'tenant',
                'kept' => 'ordinary caller data',
            ],
        ]);

        $metadata = $this->metadata($this->onlyRow());
        self::assertArrayNotHasKey(AuditOrigin::METADATA_KEY, $metadata);
        self::assertArrayNotHasKey(AuditOrigin::COMMAND_METADATA_KEY, $metadata);
        self::assertSame(
            'ordinary caller data',
            $metadata['kept'] ?? null,
            'Only the writer-owned keys are removed; the caller keeps its own.'
        );
    }

    private function jobLogger(): AuditLogger
    {
        return new AuditLogger($this->pdo, null, AuditOrigin::job());
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

    /** @return array<string, mixed> */
    private function onlyRow(): array
    {
        $rows = $this->allRows();
        self::assertCount(1, $rows, 'exactly one audit row expected');

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private function allRows(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM audit_log ORDER BY id ASC');
        self::assertNotFalse($stmt);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
