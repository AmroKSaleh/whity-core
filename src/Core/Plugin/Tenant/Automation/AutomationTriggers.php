<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant\Automation;

use Whity\Core\Plugin\Tenant\RecordEvent;
use Whity\Core\Plugin\Tenant\RecordEventListener;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Queue\QueueService;
use Whity\Sdk\Expression\Expression;
use Whity\Sdk\Expression\ExpressionContext;
use Whity\Sdk\Expression\ExpressionError;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Starts a workspace plugin's automations when its records change
 * (WHIT-763; docs/design/WHITY-BUILDER.md 2.5).
 *
 * Runs in the WRITE's transaction and does only the cheap part: match the
 * event against the plugin's automations, check `when` on the record as
 * written, record a queued run and enqueue the job that runs it. The steps
 * themselves run in the background ({@see AutomationRunner}), never in the
 * request.
 *
 * GUARDS, each recorded as one stopped run with its reason (one per write,
 * not one per automation it would have started), so a trace answers "why
 * didn't it run":
 *  - depth: a write made by a run carries the run's depth; a run deeper than
 *    {@see self::MAX_DEPTH} is stopped (automations triggering each other);
 *  - budget: every run a chain of automations starts counts against its first
 *    run, at most {@see self::MAX_RUNS_PER_CHAIN} in all, so depth can't
 *    multiply into thousands of runs before it stops them;
 *  - fan-out: at most {@see self::MAX_RUNS_PER_EVENT} runs per write;
 *  - a `when` that can't be evaluated stops the run rather than guessing.
 * A plugin whose automations are switched off starts nothing.
 */
final class AutomationTriggers implements RecordEventListener
{
    /** Runs started by a person's write are depth 1; their writes start depth 2, and so on. */
    public const MAX_DEPTH = 3;

    public const MAX_RUNS_PER_EVENT = 10;

    public const MAX_RUNS_PER_CHAIN = 100;

    public function __construct(
        private readonly AutomationRunRepository $runs,
        private readonly TenantPluginRepository $plugins,
        private readonly QueueService $queue,
    ) {
    }

    public function recordWritten(int $tenantId, PluginManifest $manifest, RecordEvent $event): void
    {
        $matching = self::matching($manifest->automations(), $event);
        if ($matching === [] || !$this->plugins->automationsEnabled($tenantId, $manifest->name())) {
            return;
        }

        $depth = $event->actor->depth + 1;
        $root = $event->actor->rootRunId;
        $chain = $root === null ? 0 : $this->runs->countInChain($tenantId, $root);
        $started = 0;
        foreach ($matching as $key => $automation) {
            $stop = null;
            if (array_key_exists('when', $automation)) {
                try {
                    $context = new ExpressionContext($event->data, $event->state, $event->roles, gmdate('Y-m-d'), gmdate('Y-m-d\TH:i:s\Z'));
                    if (Expression::evaluate($automation['when'], $context) !== true) {
                        continue;
                    }
                } catch (ExpressionError) {
                    $stop = 'condition_error';
                }
            }
            $guard = match (true) {
                $depth > self::MAX_DEPTH => 'depth_limit',
                $chain + $started >= self::MAX_RUNS_PER_CHAIN => 'run_budget',
                $started >= self::MAX_RUNS_PER_EVENT => 'fan_out_limit',
                default => null,
            };
            $stop ??= $guard;

            $runId = $this->runs->create(
                $tenantId,
                $manifest->name(),
                $key,
                $event->name(),
                $event->recordType,
                $event->recordId,
                $depth,
                $event->actor->profileId,
                $root,
                AutomationRunRepository::hash($automation),
                $stop === null ? AutomationRunRepository::QUEUED : AutomationRunRepository::STOPPED,
                $stop,
            );
            if ($guard !== null) {
                // The same guard would stop every automation after this one.
                return;
            }
            if ($stop !== null) {
                continue;
            }
            $started++;
            $this->queue->dispatch(RunPluginAutomationJob::NAME, ['run_id' => $runId], [
                'tenant_id' => $tenantId,
                'idempotency_key' => 'plugin-automation-run:' . $runId,
            ]);
        }
    }

    /**
     * The automations whose `on` this event satisfies.
     *
     * @param array<string, array<string, mixed>> $automations
     * @return array<string, array<string, mixed>>
     */
    public static function matching(array $automations, RecordEvent $event): array
    {
        $out = [];
        foreach ($automations as $key => $automation) {
            $parts = explode('.', (string) ($automation['on'] ?? ''), 3);
            if (count($parts) < 2 || $parts[0] !== $event->recordType || $parts[1] !== $event->kind) {
                continue;
            }
            $detail = $parts[2] ?? null;
            if ($detail !== null) {
                $hit = $event->kind === RecordEvent::MOVED
                    ? $detail === $event->state
                    : in_array($detail, $event->changedFields, true);
                if (!$hit) {
                    continue;
                }
            }
            $out[(string) $key] = $automation;
        }

        return $out;
    }
}
