<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Core\Hooks\HookManager;

/**
 * A real {@see HookManager} that also records what was dispatched (WC-713).
 *
 * The delete-path tests need two things a `createMock(HookManager::class)`
 * cannot give at once: listeners that genuinely RUN (so a throwing listener
 * really reaches the handler's catch), and a record of which events fired in
 * which order. So this subclasses the real manager instead of replacing it —
 * `dispatch()` still runs the registered listeners, it just leaves a trail.
 *
 * `dispatchAsync()` is overridden rather than delegated: the production version
 * needs a wired DomainEventStore (and therefore the `domain_events` /
 * `event_outbox` tables), which is far more machinery than these tests need. It
 * captures the one property that matters for the ordering fix — whether a
 * transaction was still open when the async event fired. It must NOT be: an
 * outbox row announcing a deletion is only truthful once that deletion has
 * committed.
 */
final class RecordingHookManager extends HookManager
{
    /**
     * Synchronous events, in dispatch order. Recorded on ENTRY, so an event
     * whose listener then throws still appears — "this hook was reached".
     *
     * @var list<string>
     */
    public array $dispatched = [];

    /**
     * Async events, with the transaction state observed at dispatch time.
     *
     * @var list<array{event: string, inTransaction: bool}>
     */
    public array $async = [];

    private ?PDO $pdo;

    /**
     * @param PDO|null $pdo The connection whose transaction state to sample when
     *                      an async event fires. Null to skip the sampling.
     */
    public function __construct(?PDO $pdo = null)
    {
        parent::__construct();
        $this->pdo = $pdo;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function dispatch(string $eventName, array $data): array
    {
        $this->dispatched[] = $eventName;

        return parent::dispatch($eventName, $data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchAsync(string $eventName, array $payload): void
    {
        $this->async[] = [
            'event' => $eventName,
            'inTransaction' => $this->pdo !== null && $this->pdo->inTransaction(),
        ];
    }

    /**
     * The async event names only, for a terser assertion.
     *
     * @return list<string>
     */
    public function asyncEvents(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['event'],
            $this->async
        );
    }
}
