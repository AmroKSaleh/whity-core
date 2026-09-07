<?php

declare(strict_types=1);

namespace Whity\Core\Events;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;

/**
 * Runs the listeners for events `HookManager::dispatchAsync()` persisted.
 *
 * THE GAP THIS FILLS. `dispatchAsync()` wrote an outbox row and ran nothing.
 * {@see DomainEventStore} already carried the whole relay API — `reserve()`,
 * `markRelayed()`, `fail()`, `reclaimExpired()` — and **nothing called any of
 * it**. So a listener bound to an async event name would have been written,
 * tested in isolation, merged, and silently done nothing in production, while
 * `event_outbox` grew without bound (#1063).
 *
 * That is the "stored intention that silently does nothing" pattern with a
 * persistence layer in front of it, which is worse than the plain version: the
 * row in the table looks like evidence that something happened.
 *
 * ONE EVENT PER CALL, by design. {@see relayNext()} claims one row, runs it, and
 * reports whether there was anything to do. The loop, the sleeping and the
 * recycling belong to the worker that calls it — the same split
 * {@see \Whity\Core\Queue\JobRunner} and `queue:work` already use, and the
 * reason both are testable without a process that runs forever.
 *
 * THE TENANT IS RESTORED BEFORE ANY LISTENER RUNS. An outbox row carries the
 * tenant it was raised in, because the relay claims across all tenants — it is
 * infrastructure, and a per-tenant relay would need a worker per tenant. A
 * listener that read `TenantContext` and got the previous event's tenant, or
 * none, would be a cross-tenant leak arriving through the back door of an
 * ordinary-looking event handler. `DomainEventStore::reserve()`'s own comments
 * promise this restore; this is where it happens.
 *
 * FAILURE IS RETRIED, THEN DEAD-LETTERED. A listener that throws does not lose
 * the event: `fail()` reschedules it after a backoff until `max_attempts`, then
 * marks it `dead` with the error on the row. A dead row is a question for an
 * operator, not a silent drop.
 */
final class EventRelay
{
    private DomainEventStore $store;
    private HookManager $hooks;
    private LoggerInterface $logger;
    private int $backoffSeconds;

    public function __construct(
        DomainEventStore $store,
        HookManager $hooks,
        ?LoggerInterface $logger = null,
        int $backoffSeconds = 30,
    ) {
        $this->store = $store;
        $this->hooks = $hooks;
        $this->logger = $logger ?? new NullLogger();
        $this->backoffSeconds = $backoffSeconds;
    }

    /**
     * Claim and relay one event.
     *
     * @return bool True when an event was claimed (whether or not its listeners
     *              succeeded), false when the outbox had nothing due — which is
     *              what tells a worker to sleep rather than spin.
     */
    public function relayNext(): bool
    {
        $event = $this->store->reserve();
        if ($event === null) {
            return false;
        }

        $eventId = (string) ($event['event_id'] ?? '');
        $eventName = (string) ($event['event_name'] ?? '');
        $tenantId = (int) ($event['tenant_id'] ?? 0);

        if ($eventId === '' || $eventName === '') {
            // A reserved row whose event content is missing cannot be relayed and
            // must not be retried forever: there is nothing a later attempt would
            // find. Dead-lettered immediately, with the reason on the row.
            if ($eventId !== '') {
                $this->store->fail($eventId, PHP_INT_MAX, 0, 0, 'outbox row has no event content');
                $this->logger->error('[events:relay] outbox row has no event content', ['event_id' => $eventId]);
            }

            return true;
        }

        $payload = $event['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        // Restore the tenant the event was raised in BEFORE any listener runs,
        // and put back whatever this process had afterwards — a relay worker
        // walks many tenants in one loop, and leaking one into the next is the
        // failure this whole block exists to prevent.
        $previousTenantId = TenantContext::getTenantId();

        try {
            // reset() before set: `setTenantId()` LOCKS the context, and a locked
            // context refuses further mutation. That lock is deliberate — it
            // stops a plugin or handler switching tenants part-way through a
            // request — and a relay is the legitimate exception: it walks many
            // tenants in one process, one event at a time, and must unlock
            // between them. Resetting rather than adding a bypass keeps the
            // guard exactly as strict for everybody else.
            TenantContext::reset();
            TenantContext::setTenantId($tenantId);
            $this->hooks->dispatch($eventName, $payload);
            $this->store->markRelayed($eventId);

            $this->logger->info('[events:relay] relayed', [
                'event' => $eventName,
                'event_id' => $eventId,
                'tenant_id' => $tenantId,
            ]);
        } catch (\Throwable $e) {
            // \Throwable rather than \Exception: a listener with a type error
            // raises an \Error, and losing the event to a fatal would be the
            // silent drop this class exists to remove.
            $this->store->fail(
                $eventId,
                (int) ($event['attempts'] ?? 1),
                (int) ($event['max_attempts'] ?? 1),
                $this->backoffSeconds,
                $e->getMessage(),
            );

            $this->logger->error('[events:relay] listener failed', [
                'event' => $eventName,
                'event_id' => $eventId,
                'tenant_id' => $tenantId,
                'attempts' => $event['attempts'] ?? null,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Always reset first, for the same lock reason as above: the event's
            // tenant is set and therefore locked by the time we get here.
            TenantContext::reset();
            if ($previousTenantId !== null) {
                TenantContext::setTenantId($previousTenantId);
            }
        }

        return true;
    }

    /**
     * Return lease-expired reservations to pending — a relay worker that died
     * holding an event.
     */
    public function reclaimExpired(int $visibilitySeconds): int
    {
        return $this->store->reclaimExpired($visibilitySeconds);
    }
}
