<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * #935: the `queue:work` worker must subscribe the audit writer to core's CRUD
 * hooks, and hand the same hook manager to the plugin loader it builds.
 *
 * The behaviour is covered against a real SQL engine by
 * {@see \Tests\Core\Audit\JobOriginAuditTest}. What that cannot reach is the
 * WIRING: {@see \Whity\Cli\Commands\QueueWorkCommand} opens a live database
 * connection and loads every plugin, so a unit test cannot run it — and an audit
 * subsystem that is perfect but unsubscribed records nothing at all. This pins
 * the wiring by scanning the source, the technique
 * {@see CliAuditWiringTest} already uses for the CLI kernel and the same
 * conventions these entry points keep drifting on (#717, #724, #727).
 *
 * The worker is the third entry point to need this and the second to have been
 * missed, so the drift is the pattern rather than the accident.
 */
final class WorkerAuditWiringTest extends TestCase
{
    /**
     * The regression itself: with no subscription, a job writes nothing.
     */
    public function testTheWorkerSubscribesTheAuditWriterToCoreCrudHooks(): void
    {
        self::assertMatchesRegularExpression(
            '/\$auditLogger->subscribe\(\$hookManager\)/',
            $this->queueWorkCommand(),
            'QueueWorkCommand must subscribe the audit writer to its hook manager, as '
            . 'public/index.php and BaseCommand do; otherwise core CRUD performed by a job '
            . 'writes no audit row at all.'
        );
    }

    /**
     * A job row must be able to say what it is, and say `job` rather than `cli`.
     */
    public function testTheWorkerAuditWriterIsBuiltWithAJobOrigin(): void
    {
        self::assertMatchesRegularExpression(
            '/new AuditLogger\(\s*\$pdo,\s*\$this->logger,\s*AuditOrigin::job\(\)\s*\)/',
            $this->queueWorkCommand(),
            'The worker writer must carry AuditOrigin::job(). Without an origin its rows have '
            . 'no actor and no explanation, which reads exactly like a failed login.'
        );
    }

    /**
     * The plugin loader must get the SAME manager, or plugin-declared events
     * (#842) still reach nobody even though core CRUD is now covered.
     */
    public function testThePluginLoaderIsGivenTheWiredHookManagerAndWriter(): void
    {
        self::assertMatchesRegularExpression(
            '/PluginJobs::register\(\s*\$registry,\s*null,\s*\$this->logger,\s*\$hookManager,\s*\$auditLogger\s*\)/',
            $this->queueWorkCommand(),
            'PluginJobs::register() must receive the worker\'s hook manager and audit writer, '
            . 'or a plugin dispatching its declared event from inside a job produces no trail '
            . 'entry — the half of #935 that is invisible even to someone checking core.'
        );
    }

    /**
     * The wired manager must also be in the container.
     *
     * HookManager's constructor arguments are all optional, so the service
     * container will happily build one on demand. A handler that resolves
     * `\Whity\app(HookManager::class)` from a worker that registered none gets a
     * fresh manager with no subscribers: a dispatch that succeeds, reaches
     * nobody, and reports nothing.
     */
    public function testTheWiredHookManagerIsRegisteredAsAService(): void
    {
        self::assertMatchesRegularExpression(
            '/register_service\(HookManager::class,\s*\$hookManager\)/',
            $this->queueWorkCommand(),
            'The worker must register its wired HookManager, or the container improvises an '
            . 'unsubscribed one for any handler that asks.'
        );
    }

    /**
     * The job name reaches the trail from the runner, not from the origin.
     *
     * One worker process runs many jobs, so the name is a per-unit-of-work fact.
     * `JobRunner` is the only place that knows which job is executing.
     */
    public function testTheRunnerNamesTheJobForTheTrail(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Core/Queue/JobRunner.php'
        );

        self::assertStringContainsString(
            'AuditContext::setJob($name)',
            $runner,
            'JobRunner must name the unit of work, or every job-driven row says only "job".'
        );
        self::assertStringContainsString(
            'AuditContext::reset()',
            $runner,
            'And must clear it, or a job name leaks into whatever the worker does next.'
        );
    }

    /**
     * The payload must never be recorded — only the name.
     *
     * Payloads carry caller-supplied data and the trail is readable by any tenant
     * administrator with `audit:read`. This is the same rule #931 adopted for
     * command arguments, and it is easier to keep than to restore.
     */
    public function testTheJobPayloadIsNotRecorded(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Core/Queue/JobRunner.php'
        );

        self::assertStringNotContainsString(
            'AuditContext::setJob($payload',
            $runner,
            'Only the job NAME belongs in the trail.'
        );
    }

    private function queueWorkCommand(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Cli/Commands/QueueWorkCommand.php'
        );
    }
}
