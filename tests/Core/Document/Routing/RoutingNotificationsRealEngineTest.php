<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RoutingNotifications;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * #1054's SUBSCRIBER, exercised against the real notification stack rather than
 * against a mock of it.
 *
 * The whole claim of the class is that routing does not need a mailer of its own
 * because the platform already has one, so a test that stubbed the dispatcher
 * would be asserting the claim by assuming it. These tests build the actual
 * dispatcher over the actual schema and read the rows back.
 *
 * WHAT IS BEING GUARDED
 * ---------------------
 *  1. THE SUBSCRIBER IS ACTUALLY REACHED. `HookManager::dispatchAsync()` persists
 *     an event and runs NO listeners, and nothing drains the outbox yet — so a
 *     subscriber bound to `document.routed.async` would never fire. The first
 *     test binds through the same `subscribe()` the wiring uses and dispatches
 *     the SYNCHRONOUS name the engine now emits. If routing ever stops emitting
 *     it, this reds.
 *
 *  2. BEING TOLD AND BEING ASKED ARE DIFFERENT MESSAGES. Two notification types,
 *     chosen from the step's satisfaction. One type with a flag inside `data`
 *     could not be muted separately, because the preference layer is keyed on
 *     `(type, channel)` and never opens `data`.
 *
 *  3. THE CHANNEL IS THE TENANT'S AND NEVER THE STEP'S. A tenant override
 *     changes every route at once; nothing in a route payload can influence it.
 *
 *  4. AN EXPLICIT EMPTY SETTING MEANS NOBODY. Asserted because the dispatcher
 *     reads an empty channel list as "use the defaults", so passing one through
 *     would deliver on `in_app` while looking, from the subscriber, as though the
 *     operator's choice had been honoured.
 */
final class RoutingNotificationsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const INSTRUCTOR = 30;
    private const TECHNICIAN = 14;

    private PDO $pdo;
    private HookManager $hooks;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->hooks = new HookManager();
        $this->subscriber()->subscribe($this->hooks);
    }

    public function testAnActThatAsksSomebodyForSomethingNotifiesThemAsAwaiting(): void
    {
        $this->hooks->dispatch('document.route_acted', $this->payload([
            ['profile_id' => self::TECHNICIAN, 'step_id' => 7, 'satisfied_by' => RouteSatisfaction::ACT],
        ]));

        self::assertSame(
            [[self::TECHNICIAN, RoutingNotifications::TYPE_AWAITING]],
            $this->notifications()
        );
    }

    public function testSomebodyAtADeliveryStepGetsTheOtherTypeEntirely(): void
    {
        // NOT a variant of the first message. Telling three hundred instructors
        // that a circular "is waiting for you" produces three hundred people
        // hunting for a button that is not there — their item was closed the
        // instant it was created and every act on it is a 422.
        $this->hooks->dispatch('document.route_acted', $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 8, 'satisfied_by' => RouteSatisfaction::DELIVERY],
        ]));

        self::assertSame(
            [[self::INSTRUCTOR, RoutingNotifications::TYPE_DELIVERED]],
            $this->notifications()
        );

        self::assertNotSame(
            RoutingNotifications::TYPE_AWAITING,
            RoutingNotifications::TYPE_DELIVERED,
            'two types, so a person can mute the notifications that are not asking them for anything and '
            . 'keep the ones that are — the preference layer is keyed on (type, channel) and never opens '
            . 'the data payload'
        );
    }

    public function testOneActNotifiesEveryStopOfTheChainWithTheRightTypeEach(): void
    {
        $this->hooks->dispatch('document.route_acted', $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 8, 'satisfied_by' => RouteSatisfaction::DELIVERY],
            ['profile_id' => self::TECHNICIAN, 'step_id' => 9, 'satisfied_by' => RouteSatisfaction::ACT],
        ]));

        self::assertSame(
            [
                [self::INSTRUCTOR, RoutingNotifications::TYPE_DELIVERED],
                [self::TECHNICIAN, RoutingNotifications::TYPE_AWAITING],
            ],
            $this->notifications(),
            'a forward onto a delivery stage reaches two different kinds of person in one act, and each '
            . 'of them has to be told the thing that is true of them'
        );
    }

    public function testTheIssueEventIsSubscribedTooAndNotJustTheActOne(): void
    {
        // A route whose FIRST stage is a delivery stage announces at ISSUE, and
        // that is the pure-broadcast shape. Binding only to `document.route_acted`
        // would leave it silent.
        $this->hooks->dispatch('document.routed', $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 1, 'satisfied_by' => RouteSatisfaction::DELIVERY],
        ]));

        self::assertSame(
            [[self::INSTRUCTOR, RoutingNotifications::TYPE_DELIVERED]],
            $this->notifications()
        );
    }

    public function testTheDefaultIsInAppOnlyAndTheTenantCanAddEmail(): void
    {
        // The default is asserted with NOTHING written to app_settings or
        // tenant_settings, so this is the behaviour a fresh deployment actually
        // gets rather than one the fixture chose.
        $this->hooks->dispatch('document.route_acted', $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 8, 'satisfied_by' => RouteSatisfaction::DELIVERY],
        ]));

        self::assertSame(
            ['in_app'],
            $this->channelsUsed(),
            'routing sent no notifications at all before #1054, so an e-mail default would start '
            . 'sending on every route on every deployment the day it upgrades'
        );

        // And the tenant's own answer wins, for every route at once, without a
        // single step being rewritten. This is the motivating case: a faculty
        // whose instructors never open the app.
        $this->truncateNotifications();
        (new TenantSettingsRepository($this->pdo))->set(
            self::TENANT,
            SettingsRegistry::DOCUMENTS_ROUTING_NOTIFICATION_CHANNELS,
            'in_app,email'
        );

        $this->hooks->dispatch('document.route_acted', $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 8, 'satisfied_by' => RouteSatisfaction::DELIVERY],
        ]));

        self::assertSame(['email', 'in_app'], $this->channelsUsed());
    }

    public function testATenantOverrideBeatsTheGlobalSetting(): void
    {
        (new GlobalSettingsRepository($this->pdo))->set(
            SettingsRegistry::DOCUMENTS_ROUTING_NOTIFICATION_CHANNELS,
            'email'
        );
        (new TenantSettingsRepository($this->pdo))->set(
            self::TENANT,
            SettingsRegistry::DOCUMENTS_ROUTING_NOTIFICATION_CHANNELS,
            'in_app'
        );

        $this->hooks->dispatch('document.route_acted', $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 8, 'satisfied_by' => RouteSatisfaction::DELIVERY],
        ]));

        self::assertSame(['in_app'], $this->channelsUsed());
    }

    public function testAnExplicitlyEmptySettingNotifiesNobodyAtAll(): void
    {
        // The operator turning routing notifications off. It has to be checked
        // BEFORE the dispatcher is called: `NotificationDispatcher` reads an empty
        // channel list as "use the defaults", so handing one through would deliver
        // on `in_app` and read from here as though the setting had been honoured.
        (new TenantSettingsRepository($this->pdo))->set(
            self::TENANT,
            SettingsRegistry::DOCUMENTS_ROUTING_NOTIFICATION_CHANNELS,
            ''
        );

        $this->hooks->dispatch('document.route_acted', $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 8, 'satisfied_by' => RouteSatisfaction::DELIVERY],
        ]));

        self::assertSame([], $this->notifications(), 'no notification row at all, not one nobody reads');
    }

    public function testAnEventThatReachedNobodyNotifiesNobody(): void
    {
        // Every `noted`, and every act that opened nothing.
        $this->hooks->dispatch('document.route_acted', $this->payload([]));

        self::assertSame([], $this->notifications());
    }

    public function testAFailureInsideTheSubscriberNeverEscapesToTheEngine(): void
    {
        // The routing act has already committed by the time this runs. An
        // exception escaping here would turn a successful forward into a 500 for
        // the person who made it, and they would reasonably conclude the document
        // had not moved.
        $broken = new RoutingNotifications(
            $this->dispatcher(),
            // A settings service over a CLOSED connection: every read throws.
            new SettingsService(
                new GlobalSettingsRepository($this->brokenPdo()),
                new TenantSettingsRepository($this->brokenPdo())
            )
        );

        $payload = $this->payload([
            ['profile_id' => self::INSTRUCTOR, 'step_id' => 8, 'satisfied_by' => RouteSatisfaction::DELIVERY],
        ]);

        $returned = $broken->onRoutingEvent($payload, []);

        self::assertSame($payload, $returned, 'and the payload goes back untouched — this hook observes');
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $recipients
     * @return array<string, mixed>
     */
    private function payload(array $recipients): array
    {
        return [
            'tenant_id' => self::TENANT,
            'document_id' => 4471,
            'id' => 4471,
            'route_id' => 12,
            'title' => 'Policy circular',
            'action' => 'forwarded',
            'actor_profile_id' => 10,
            'delivered' => count($recipients),
            'recipients' => $recipients,
        ];
    }

    private function subscriber(): RoutingNotifications
    {
        return new RoutingNotifications(
            $this->dispatcher(),
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            )
        );
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher(
            new NotificationRepository($this->pdo),
            CoreTransports::make(),
            new QueueService(new JobRepository($this->pdo)),
            null,
            null,
            new NotificationPreferenceResolver(new NotificationPreferenceRepository($this->pdo)),
            null
        );
    }

    /**
     * Every notification written, as `[recipient, type]`, oldest first.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function notifications(): array
    {
        $stmt = $this->pdo->prepare('SELECT recipient_profile_id, type FROM notifications ORDER BY id ASC');
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $r): array => [(int) $r['recipient_profile_id'], (string) $r['type']],
            $rows
        );
    }

    /** @return list<string> */
    private function channelsUsed(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT channel FROM notification_deliveries ORDER BY channel ASC'
        );
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): string => (string) $r['channel'], $rows);
    }

    private function truncateNotifications(): void
    {
        $this->pdo->exec('DELETE FROM notification_deliveries');
        $this->pdo->exec('DELETE FROM notifications');
    }

    /** A PDO whose every statement fails, for the fail-soft test. */
    private function brokenPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // No schema at all, so the first settings read throws "no such table".
        return $pdo;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Tenant One') . ') ON CONFLICT DO NOTHING');

        foreach ([[self::TECHNICIAN, 'tech-a'], [self::INSTRUCTOR, 'instructor-a']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, '
                 . $now . ', ' . $now . ')'
            );
        }

        return $pdo;
    }
}
