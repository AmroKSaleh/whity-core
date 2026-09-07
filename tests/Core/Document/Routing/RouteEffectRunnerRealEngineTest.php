<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Routing\RouteEffectAttemptRepository;
use Whity\Core\Document\Routing\RouteEffectContext;
use Whity\Core\Document\Routing\RouteEffectInterface;
use Whity\Core\Document\Routing\RouteEffectPlan;
use Whity\Core\Document\Routing\RouteEffectRegistry;
use Whity\Core\Document\Routing\RouteEffectRunner;
use Whity\Core\Document\Routing\RouteEffectStatus;
use Whity\Core\Document\Routing\RouteStepEffectRepository;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;

/**
 * The engine migration 112 refused to ship the declaration without (#1032).
 *
 * The property under test is ONE SENTENCE: **every path ends in a recorded
 * attempt.** Migration 112's whole argument for deferring this feature was that
 * "a stored intention that silently does nothing" still renders and still
 * reports success — so an effect that quietly declines, an effect whose plugin
 * was uninstalled, and an effect that threw must each leave a row somebody can
 * read. Each test below is one of those paths.
 *
 * Real engine, because the sibling {@see RoutingNotificationsRealEngineTest} is:
 * {@see NotificationDispatcher} is final and the honest way to exercise a
 * fail-soft handler is against a real one. It also means the migration's own
 * CHECK constraint is enforcing the vocabulary while these run.
 */
final class RouteEffectRunnerRealEngineTest extends TestCase
{
    private const TENANT = 3;
    private const DOCUMENT = 41;
    private const STEP = 77;

    /** A real trail entry, so an attempt can point at the act that caused it. */
    private const EVENT = 5150;

    /** The source the test registry registers under; kinds come back namespaced. */
    private const PLUGIN_SOURCE = 'tests';

    private PDO $pdo;
    private RouteEffectRegistry $registry;
    private RouteStepEffectRepository $declarations;
    private RouteEffectAttemptRepository $attempts;
    private RouteEffectRunner $runner;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();

        $this->registry = new RouteEffectRegistry();
        $this->declarations = new RouteStepEffectRepository($this->pdo);
        $this->attempts = new RouteEffectAttemptRepository($this->pdo);

        $this->runner = new RouteEffectRunner(
            $this->registry,
            $this->declarations,
            $this->attempts,
            new NotificationDispatcher(
                new NotificationRepository($this->pdo),
                CoreTransports::make(),
                new QueueService(new JobRepository($this->pdo)),
                null,
                null,
                new NotificationPreferenceResolver(new NotificationPreferenceRepository($this->pdo)),
                null
            )
        );
    }

    public function testAnUnregisteredKindIsRecordedAndNamed(): void
    {
        // The state migration 112 deliberately allows: `effect_kind` carries no
        // foreign key, so a step can name a kind whose plugin has since been
        // uninstalled. An operator reading "skipped" must be able to tell that
        // from an empty department.
        $this->declare('acme:post_to_ledger');

        $this->runner->onRoutingEvent($this->payload(), []);

        $attempt = $this->onlyAttempt();
        self::assertSame(RouteEffectStatus::SKIPPED, $attempt['status']);
        self::assertStringContainsString("no effect is registered for kind 'acme:post_to_ledger'", (string) $attempt['detail']);
        self::assertSame('acme:post_to_ledger', $attempt['effect_kind']);
    }

    public function testAnEffectThatDeclinesIsRecordedWithItsOwnReason(): void
    {
        // "Nobody to notify" is an ordinary outcome, not a failure — and not
        // silence either. The reason comes from the EFFECT, because only it
        // knows whether the audience was empty or the act was uninteresting.
        $this->registerEffect('quiet', null, 'the audience rule resolved to nobody');
        $this->declare('quiet');

        $this->runner->onRoutingEvent($this->payload(), []);

        $attempt = $this->onlyAttempt();
        self::assertSame(RouteEffectStatus::SKIPPED, $attempt['status']);
        self::assertSame('the audience rule resolved to nobody', $attempt['detail']);
    }

    public function testAnEffectThatThrowsWhilePlanningIsRecordedAsFailed(): void
    {
        // Failed, not skipped: a resolver that blew up has told us nothing about
        // whether the work would have succeeded, and recording it as "skipped"
        // would file a fault under "nothing to do".
        $this->registerThrowingEffect('broken');
        $this->declare('broken');

        $this->runner->onRoutingEvent($this->payload(), []);

        $attempt = $this->onlyAttempt();
        self::assertSame(RouteEffectStatus::FAILED, $attempt['status']);
        self::assertStringContainsString('planning failed', (string) $attempt['detail']);
    }

    public function testASuccessfulEffectRecordsWhatItQueued(): void
    {
        $this->registerEffect('tell_registry', RouteEffectPlan::notify([901, 902], 'document.routing.stage_reached'));
        $this->declare('tell_registry');

        $this->runner->onRoutingEvent($this->payload(), []);

        $attempt = $this->onlyAttempt();
        self::assertSame(RouteEffectStatus::SUCCEEDED, $attempt['status']);
        // "queued", not "delivered" — the dispatcher enqueues a durable job per
        // channel and owns the retry, so claiming delivery would assert an
        // outcome this process never observes.
        self::assertStringContainsString('queued for 2 recipient(s)', (string) $attempt['detail']);
    }

    public function testTheAttemptPointsAtTheActThatCausedIt(): void
    {
        // Without this an attempt can only say "this document sent mail at
        // 14:02"; with it, it says which approval caused it. That is the
        // difference between a log and an audit trail.
        $this->registerEffect('tell_registry', RouteEffectPlan::notify([901], 'x'));
        $effectId = $this->declare('tell_registry');

        $this->runner->onRoutingEvent($this->payload(['event_id' => self::EVENT]), []);

        $attempt = $this->onlyAttempt();
        self::assertSame(self::EVENT, $attempt['event_id']);
        self::assertSame($effectId, $attempt['effect_id']);
        self::assertSame(self::DOCUMENT, $attempt['document_id']);
    }

    public function testOneEffectFailingDoesNotStopTheNext(): void
    {
        // Declared order is the point of the table, and a stage that says
        // "notify the registry, then the archive" must still reach the archive
        // when the registry's rule is broken.
        $this->registerThrowingEffect('broken');
        $this->registerEffect('tell_archive', RouteEffectPlan::notify([903], 'x'));
        $this->declare('broken', 0);
        $this->declare('tell_archive', 1);

        $this->runner->onRoutingEvent($this->payload(), []);

        $attempts = $this->attempts->listForDocument(self::DOCUMENT, self::TENANT);
        self::assertCount(2, $attempts);
        self::assertSame(RouteEffectStatus::FAILED, $attempts[0]['status']);
        self::assertSame(RouteEffectStatus::SUCCEEDED, $attempts[1]['status']);
    }

    public function testAnActThatReachesNoStepRecordsNothing(): void
    {
        // `document.routed` carries a step_count rather than a step_id, and an
        // act that opened nothing has none. Neither is an error and neither is
        // worth a row — an attempt log padded with "nothing to do" entries for
        // every act is one nobody reads.
        $this->registerEffect('tell_registry', RouteEffectPlan::notify([901], 'x'));
        $this->declare('tell_registry');

        $payload = $this->payload();
        unset($payload['step_id']);
        $this->runner->onRoutingEvent($payload, []);

        self::assertSame([], $this->attempts->listForDocument(self::DOCUMENT, self::TENANT));
    }

    public function testTheHandlerReturnsThePayloadUnchanged(): void
    {
        // A listener that rewrote the payload would be editing what every later
        // listener sees — including RoutingNotifications, which reads its
        // recipients out of it.
        $this->registerEffect('tell_registry', RouteEffectPlan::notify([901], 'x'));
        $this->declare('tell_registry');

        $payload = $this->payload();

        self::assertSame($payload, $this->runner->onRoutingEvent($payload, []));
    }

    public function testDeclarationsRunInTheirDeclaredOrder(): void
    {
        $this->registerEffect('first', RouteEffectPlan::notify([901], 'a'));
        $this->registerEffect('second', RouteEffectPlan::notify([902], 'b'));
        // Inserted in the reverse of their positions, so a repository that
        // ordered by id rather than by position would pass by accident.
        $this->declare('second', 1);
        $this->declare('first', 0);

        $this->runner->onRoutingEvent($this->payload(), []);

        $kinds = array_column($this->attempts->listForDocument(self::DOCUMENT, self::TENANT), 'effect_kind');
        self::assertSame(['tests:first', 'tests:second'], $kinds);
    }

    /** @return array<string, mixed> */
    private function onlyAttempt(): array
    {
        $attempts = $this->attempts->listForDocument(self::DOCUMENT, self::TENANT);
        self::assertCount(1, $attempts, 'exactly one attempt should have been recorded');

        return $attempts[0];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'tenant_id' => self::TENANT,
            'document_id' => self::DOCUMENT,
            'step_id' => self::STEP,
            'event_id' => null,
            'actor_profile_id' => 900,
            'action' => 'forwarded',
            'verdict' => null,
            'decided' => null,
            'recipients' => [],
        ];
    }

    /**
     * Declare an effect on the step, under the kind the registry will actually
     * answer to.
     *
     * A plugin's kinds are namespaced by the registry from the loader-supplied
     * source, never from anything the plugin returns — so `quiet` registered by
     * `tests` is addressed as `tests:quiet`, and a declaration naming the bare
     * slug finds nothing. Spelled out here because getting it wrong is silent:
     * every effect would simply record as skipped-because-unregistered.
     */
    private function declare(string $slug, int $position = 0): int
    {
        $kind = str_contains($slug, ':') ? $slug : self::PLUGIN_SOURCE . ':' . $slug;

        return $this->declarations->create(self::TENANT, self::STEP, $position, $kind);
    }

    private function registerEffect(string $slug, ?RouteEffectPlan $plan, string $reason = 'no reason given'): void
    {
        $this->registry->register(self::PLUGIN_SOURCE, [$slug => new class ($plan, $reason) implements RouteEffectInterface {
            public function __construct(private readonly ?RouteEffectPlan $plan, private readonly string $reason)
            {
            }

            public function plan(RouteEffectContext $context): ?RouteEffectPlan
            {
                return $this->plan;
            }

            public function skipReason(RouteEffectContext $context): string
            {
                return $this->reason;
            }
        }]);
    }

    private function registerThrowingEffect(string $slug): void
    {
        $this->registry->register(self::PLUGIN_SOURCE, [$slug => new class implements RouteEffectInterface {
            public function plan(RouteEffectContext $context): ?RouteEffectPlan
            {
                throw new \RuntimeException('the audience service is down');
            }

            public function skipReason(RouteEffectContext $context): string
            {
                return 'unreachable';
            }
        }]);
    }

    /**
     * Every row the foreign keys require, including the ones two layers down.
     *
     * PostgreSQL enforces these and SQLite does not, so what is seeded here is
     * the whole difference between a test that proves something and one that
     * passes on the wrong engine. Three of these were added only after the real
     * engine refused them: the AUDIENCE profiles (`notifications.recipient_profile_id`),
     * the ACTOR, and a real route EVENT for the attempt to point at.
     *
     * The failure was instructive rather than annoying — the runner recorded
     * `failed` with the driver's own message, which is exactly what it is
     * supposed to do when a notification cannot be written. The defect was in
     * the fixture, and the engine said so.
     */
    private function seed(): void
    {
        $this->pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::TENANT . ", 'effects', 'effects') ON CONFLICT DO NOTHING");

        // The actor and the audience. `notifications` references a profile, so
        // an unseeded recipient turns every dispatch into a foreign-key error
        // that the runner correctly reports as a failed effect.
        foreach ([900, 901, 902, 903] as $profileId) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash) VALUES ({$profileId}, 'Profile {$profileId}', 'x')"
                . ' ON CONFLICT DO NOTHING'
            );
        }

        $this->pdo->exec(
            'INSERT INTO documents (id, tenant_id, template_name, title, created_at) VALUES ('
            . self::DOCUMENT . ', ' . self::TENANT . ", '', 'Effects document', NOW()) ON CONFLICT DO NOTHING"
        );
        $this->pdo->exec(
            'INSERT INTO document_routes (id, tenant_id, document_id, title, created_at) VALUES (1, '
            . self::TENANT . ', ' . self::DOCUMENT . ", 'Route', NOW()) ON CONFLICT DO NOTHING"
        );
        $this->pdo->exec(
            'INSERT INTO document_route_steps (id, tenant_id, route_id, position, rule_kind, created_at) VALUES ('
            . self::STEP . ', ' . self::TENANT . ", 1, 0, 'role', NOW()) ON CONFLICT DO NOTHING"
        );
        // A real trail entry, so the attempt's `event_id` points at something.
        // Using an invented id would have tested nothing on the engine that
        // checks and would have failed on it — which is what happened.
        $this->pdo->exec(
            'INSERT INTO document_route_events (id, tenant_id, document_id, route_id, step_id, actor_profile_id, action, occurred_at)'
            . ' VALUES (' . self::EVENT . ', ' . self::TENANT . ', ' . self::DOCUMENT . ', 1, ' . self::STEP
            . ", 900, 'forwarded', NOW()) ON CONFLICT DO NOTHING"
        );
    }
}
