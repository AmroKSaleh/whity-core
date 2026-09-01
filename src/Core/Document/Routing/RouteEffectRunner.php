<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Core\Hooks\HookManager;
use Whity\Core\Notification\NotificationDispatcher;

/**
 * The engine migration 112 refused to ship this feature without (#1032).
 *
 *   "an effect declaration with no engine to run it is a stored intention that
 *   silently does nothing, which is the precise failure class this whole item
 *   is written against."
 *
 * This is that engine. It subscribes to the router's post-commit broadcast,
 * reads the stage's declared effects, asks each one what it wants done, does
 * it, and records what happened — every time, including the times nothing
 * happened.
 *
 * WHERE IT RUNS, AND WHY THAT IS THE ONLY PLACE
 * ---------------------------------------------
 * A SUBSCRIBER, exactly like {@see RoutingNotifications}, and not a call from
 * inside {@see DocumentRouter}. The router plans before its transaction, writes
 * inside it, and broadcasts AFTER the commit — deliberately, because a listener
 * runs synchronously and "a listener that throws inside our transaction would
 * roll back a routing act that had already succeeded."
 *
 * An effect called from inside that transaction would be strictly worse than a
 * plugin listener: it would hold the routing write open for as long as a
 * notification write takes, and a failure to notify would undo an approval
 * somebody had already been told was recorded.
 *
 * FAIL-SOFT, PER EFFECT
 * ---------------------
 * One effect's failure must not stop the next one, and no effect's failure may
 * reach the caller — the routing act is already committed and cannot be
 * un-done by a mail problem. So every effect is wrapped on its own, and the
 * failure is written to the attempt log where an operator can find it rather
 * than only to a log line where nobody will.
 *
 * That is the difference between this and a bare try/catch: a swallowed
 * exception is silence, and silence is the thing this feature exists to
 * eliminate. Every path through {@see runEffect()} ends in a recorded row.
 *
 * ONE ROW PER EFFECT, NOT PER RECIPIENT. The issue's shape is "which effect,
 * succeeded or failed, how many attempts" — a stage that told forty people is
 * one effect that worked, and forty rows would bury the four stages that did
 * not under the one that did.
 */
final class RouteEffectRunner
{
    public function __construct(
        private readonly RouteEffectRegistry $registry,
        private readonly RouteStepEffectRepository $declarations,
        private readonly RouteEffectAttemptRepository $attempts,
        private readonly NotificationDispatcher $notifications,
    ) {
    }

    /**
     * Bind to the same two events {@see RoutingNotifications} binds to.
     *
     * The SYNCHRONOUS names, not their `.async` twins. The router also persists
     * an async event, but `dispatchAsync()` runs no listeners — it writes to the
     * outbox, and whether anything drains that outbox is a deployment question.
     * An effect engine hung off a queue nobody drains is the stored intention
     * one level up.
     */
    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen('document.routed', [$this, 'onRoutingEvent']);
        $hooks->listen('document.route_acted', [$this, 'onRoutingEvent']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed> The payload, unchanged — a listener that
     *         rewrote it would be editing what every later listener sees.
     */
    public function onRoutingEvent(array $data, array $context): array
    {
        try {
            $this->run($data);
        } catch (\Throwable $e) {
            // The outermost net. Anything that gets here is a fault in this
            // class rather than in one effect, and it must not propagate: the
            // routing act it is reacting to has already committed.
            error_log('[RouteEffectRunner] running stage effects failed: ' . $e->getMessage());
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function run(array $data): void
    {
        $tenantId = self::intOrNull($data['tenant_id'] ?? null);
        $documentId = self::intOrNull($data['document_id'] ?? null);
        $stepId = self::intOrNull($data['step_id'] ?? null);

        // No step means no declarations to read. `document.routed` carries a
        // step_count rather than a step_id, and an act that opened nothing has
        // none either — neither is an error, and neither is worth a row.
        if ($tenantId === null || $documentId === null || $stepId === null) {
            return;
        }

        $declarations = $this->declarations->listForStep($stepId, $tenantId);
        if ($declarations === []) {
            return;
        }

        foreach ($declarations as $declaration) {
            $this->runEffect($tenantId, $documentId, $stepId, $declaration, $data);
        }
    }

    /**
     * Run one declared effect and record the outcome. Never throws.
     *
     * @param array<string, mixed> $declaration
     * @param array<string, mixed> $data
     */
    private function runEffect(int $tenantId, int $documentId, int $stepId, array $declaration, array $data): void
    {
        $kind = (string) ($declaration['effect_kind'] ?? '');
        $effectId = self::intOrNull($declaration['id'] ?? null);
        $eventId = self::intOrNull($data['event_id'] ?? null);

        $record = function (string $status, ?string $detail) use ($tenantId, $documentId, $eventId, $effectId, $kind): void {
            try {
                $this->attempts->append($tenantId, $documentId, [
                    'event_id' => $eventId,
                    'effect_id' => $effectId,
                    'effect_kind' => $kind,
                    'status' => $status,
                    'detail' => $detail,
                ]);
            } catch (\Throwable $e) {
                // If even the RECORD cannot be written there is nowhere left to
                // put the fact, so this is the one place a log line is the whole
                // answer.
                error_log('[RouteEffectRunner] could not record a ' . $status . ' attempt: ' . $e->getMessage());
            }
        };

        $effect = $this->registry->get($kind);
        if ($effect === null) {
            // The state migration 112 deliberately allows: `effect_kind` carries
            // no foreign key, so a step can name a kind whose plugin has been
            // uninstalled. Recorded, and NAMED, rather than skipped in silence.
            $record(RouteEffectStatus::SKIPPED, "no effect is registered for kind '{$kind}'");

            return;
        }

        $context = new RouteEffectContext(
            $tenantId,
            $documentId,
            $stepId,
            $eventId,
            self::intOrNull($data['actor_profile_id'] ?? null),
            (string) ($data['action'] ?? ''),
            self::stringOrNull($data['verdict'] ?? null),
            self::stringOrNull($data['decided'] ?? null),
            is_array($data['recipients'] ?? null) ? array_values($data['recipients']) : [],
            is_array($declaration['effect_config'] ?? null) ? $declaration['effect_config'] : [],
        );

        try {
            $plan = $effect->plan($context);
        } catch (\Throwable $e) {
            $record(RouteEffectStatus::FAILED, 'planning failed: ' . $e->getMessage());

            return;
        }

        if ($plan === null) {
            $record(RouteEffectStatus::SKIPPED, $effect->skipReason($context));

            return;
        }

        $this->perform($tenantId, $plan, $context, $record);
    }

    /**
     * Carry out a plan.
     *
     * The dispatcher takes ONE recipient per call and is itself fail-soft per
     * channel, so a partial outcome is possible and is reported as such: the
     * detail names how many of the audience were reached. A stage that told 38
     * of 40 people has not simply "succeeded", and an operator chasing two
     * missing notifications needs the number to be on the row rather than in a
     * log they would have to know to look for.
     *
     * @param callable(string, ?string): void $record
     */
    private function perform(int $tenantId, RouteEffectPlan $plan, RouteEffectContext $context, callable $record): void
    {
        $sent = 0;
        $lastError = null;

        foreach ($plan->profileIds as $profileId) {
            try {
                $this->notifications->dispatch($tenantId, $profileId, $plan->type, ['data' => $plan->data]);
                $sent++;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $audience = $plan->audienceSize();

        if ($sent === 0) {
            $record(RouteEffectStatus::FAILED, "notified 0 of {$audience}" . ($lastError === null ? '' : ": {$lastError}"));

            return;
        }

        if ($sent < $audience) {
            $record(RouteEffectStatus::FAILED, "notified only {$sent} of {$audience}" . ($lastError === null ? '' : ": {$lastError}"));

            return;
        }

        // SUCCEEDED means handed over, not delivered — the dispatcher enqueues a
        // durable job per channel and owns the retry from there. Claiming
        // delivery would be asserting an outcome this process never observes.
        $record(RouteEffectStatus::SUCCEEDED, "queued for {$audience} recipient(s)");
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
