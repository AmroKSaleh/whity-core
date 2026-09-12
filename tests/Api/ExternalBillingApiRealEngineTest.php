<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ExternalBillingApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Billing\External\AccessRecorder;
use Whity\Core\Billing\External\AccessSnapshot;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\EventLedger;
use Whity\Core\Billing\External\WebhookVerifier;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Request;

/**
 * Buying access, and — far more importantly — not getting it any other way.
 *
 * EVERY TEST HERE IS ABOUT A CLAIM SOMEBODY ELSE MADE. A query string that says
 * the payment succeeded. A notification body that says a subscription is active.
 * A signature. A replay. The product's job is to believe none of them and go and
 * ask, and these are the tests that fail if it ever stops.
 *
 * The billing service is faked rather than called, because what is under test is
 * what THIS side does with an answer — including the answers a live service
 * would only produce during an outage.
 */
final class ExternalBillingApiRealEngineTest extends TestCase
{
    private const SECRET = 'whsec_test_only_not_a_real_secret';
    private const TENANT = 1;
    private const ADMIN = 10;

    private PDO $pdo;
    private ExternalBillingApiHandler $handler;
    private FakeBillingPortal $portal;
    private SubscriptionService $subscriptions;
    private ArraySharedStore $store;
    private int $now = 1789185988;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a-customer', 'a-customer')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (10, 1, 1, 'active', CURRENT_TIMESTAMP)
        ");
        foreach ([CorePermissions::BILLING_PAY, CorePermissions::BILLING_VIEW] as $permission) {
            $this->grant(1, $permission);
        }

        // A plan on both sides, joined by name, priced with the handle the
        // billing service knows it by.
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, created_at, updated_at)
                          VALUES (1, 'pro', 'Pro', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, external_ref, created_at, updated_at)
                          VALUES (1, 'JOD', 15000, 'month', false, true, 'price_01ABC', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        // An ADD-ON: bought beside a tier, never instead of one.
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, is_addon, created_at, updated_at)
                          VALUES (2, 'devices', 'Devices', true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_per_device, is_active, external_ref, created_at, updated_at)
                          VALUES (2, 'JOD', 20000, 'month', false, true, true, 'price_DEV', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->subscriptions = new SubscriptionService(
            new SubscriptionRepository($this->pdo),
            $settings,
        );
        $this->portal = new FakeBillingPortal();
        $this->store = new ArraySharedStore();

        $this->handler = new ExternalBillingApiHandler(
            $this->portal,
            new AccessRecorder($this->subscriptions, new PlanRepository($this->pdo), new NullLogger()),
            new EventLedger($this->store),
            new WebhookVerifier(self::SECRET, 300, fn (): int => $this->now),
            $settings,
            new RoleChecker($database, new PermissionRegistry()),
            $this->pdo,
            'https://app.example.test',
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── the signature is the credential ─────────────────────────────────────

    /**
     * THE TEST THIS ENDPOINT EXISTS BEHIND. The route is unauthenticated by
     * necessity, so a forged body reaching state would mean anyone who found the
     * URL could hand themselves a paid subscription.
     */
    public function testANotificationWithAWrongSignatureIsRefusedAndChangesNothing(): void
    {
        $body = $this->subscriptionEvent('evt_1', 'active', true);

        $response = $this->handler->webhook(new Request('POST', '/api/v1/billing/webhook', [
            'X-Pay-Signature' => 'sha256=' . str_repeat('0', 64),
            'X-Pay-Timestamp' => (string) $this->now,
            'X-Pay-Event-Id' => 'evt_1',
        ], $body));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->portal->accessCalls, 'a refused body must not even be looked up');
        self::assertNull($this->statusOf(self::TENANT));
    }

    /** An unsigned delivery is refused for the same reason, with no secret involved. */
    public function testANotificationWithNoSignatureAtAllIsRefused(): void
    {
        $response = $this->handler->webhook(new Request('POST', '/api/v1/billing/webhook', [
            'X-Pay-Event-Id' => 'evt_1',
        ], $this->subscriptionEvent('evt_1', 'active', true)));

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->statusOf(self::TENANT));
    }

    /**
     * A CAPTURED DELIVERY CANNOT BE REPLAYED FOREVER. The timestamp is inside
     * the signed payload, so an attacker who moves it invalidates the signature
     * — which is what makes checking it worth anything.
     */
    public function testACorrectlySignedButStaleDeliveryIsRefused(): void
    {
        $body = $this->subscriptionEvent('evt_stale', 'active', true);
        $stale = $this->now - 4000;

        $response = $this->handler->webhook($this->signed($body, 'evt_stale', $stale));

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->statusOf(self::TENANT));
    }

    // ── delivered twice, applied once ───────────────────────────────────────

    /**
     * Deliveries repeat by design — a response that timed out is re-sent with
     * the SAME event id. Applying twice must be indistinguishable from applying
     * once, and the ledger is what makes it so.
     */
    public function testAReplayedEventIsAcceptedButActedOnOnlyOnce(): void
    {
        $body = $this->subscriptionEvent('evt_dup', 'active', true);

        $first = $this->handler->webhook($this->signed($body, 'evt_dup'));
        $second = $this->handler->webhook($this->signed($body, 'evt_dup'));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode(), 'a replay is a success, not an error');
        self::assertSame(1, $this->portal->accessCalls, 'the second delivery must not re-ask');
        self::assertTrue($this->claimIsHeld('evt_dup'), 'the claim is held, so a third delivery is dropped too');
    }

    /**
     * A TRANSIENT FAILURE MUST NOT SILENCE THE RETRY. The claim is taken before
     * the work; if the work then fails in a way that could succeed later, the
     * claim has to go back — otherwise the sender retries faithfully, this side
     * recognises the id, drops it, and the tenant's access is never corrected.
     */
    public function testAnEventThatFailedTransientlyCanBeRetried(): void
    {
        $body = $this->subscriptionEvent('evt_retry', 'active', true);
        $this->portal->access = new AccessSnapshot(
            'tenant-1',
            true,
            'pro',
            'active',
            '2026-10-12T00:00:00+00:00',
            false,
            'sub_01'
        );
        $this->portal->failWith = BillingPortalException::unreachable('down');

        $first = $this->handler->webhook($this->signed($body, 'evt_retry'));
        self::assertSame(503, $first->getStatusCode(), 'the sender must be asked to try again');
        self::assertFalse($this->claimIsHeld('evt_retry'), 'the claim must have been released');

        $this->portal->failWith = null;
        $second = $this->handler->webhook($this->signed($body, 'evt_retry'));

        self::assertSame(200, $second->getStatusCode());
        self::assertSame(SubscriptionService::STATUS_ACTIVE, $this->statusOf(self::TENANT));
    }

    // ── what access follows from which answer ───────────────────────────────

    public function testAccessIsGrantedWhenTheServiceSaysActive(): void
    {
        $this->deliver('evt_a', 'active', true);

        self::assertSame(SubscriptionService::STATUS_ACTIVE, $this->statusOf(self::TENANT));
        self::assertTrue($this->subscriptions->decide(self::TENANT, true)->allowed);
    }

    /**
     * PAST_DUE KEEPS ACCESS, ON PURPOSE. A card that expired over a weekend must
     * not lock a paying customer out mid-sentence while dunning still has days
     * of retries left. The billing service decides that; this side must not
     * quietly re-derive a stricter rule.
     */
    public function testAccessIsKeptWhileTheServiceSaysPastDue(): void
    {
        $this->deliver('evt_a', 'active', true);
        $this->deliver('evt_b', 'past_due', true);

        self::assertSame(SubscriptionService::STATUS_PAST_DUE, $this->statusOf(self::TENANT));
        self::assertTrue(
            $this->subscriptions->decide(self::TENANT, true)->allowed,
            'a tenant in dunning is still a paying customer'
        );
    }

    public function testAccessIsRevokedWhenTheServiceSaysExpired(): void
    {
        $this->deliver('evt_a', 'active', true);
        $this->deliver('evt_b', 'expired', false);

        self::assertSame(SubscriptionService::STATUS_EXPIRED, $this->statusOf(self::TENANT));

        // block_all so the decision is unambiguous: the default enforcement mode
        // is a separate operator choice, and this test is about access, not
        // about how strictly a deployment reacts to losing it.
        $this->subscriptions->setSubscription(self::TENANT, ['enforcement_mode' => 'block_all']);
        self::assertFalse($this->subscriptions->decide(self::TENANT, false)->allowed);
    }

    /**
     * THE TWO STATUS TABLES MUST NOT BE ALLOWED TO DISAGREE. If the service ever
     * reports a word whose local meaning contradicts `has_access`, the answer
     * wins — otherwise a policy change on their side silently becomes a wrong
     * decision on ours.
     */
    public function testTheAccessAnswerWinsOverAContradictoryStatusWord(): void
    {
        // Says "canceled", which reads as no access locally, but answers yes.
        $this->deliver('evt_odd', 'canceled', true);

        self::assertTrue(
            $this->subscriptions->decide(self::TENANT, true)->allowed,
            'has_access is the authority, not the status word'
        );
    }

    /** A word this deployment has never heard of must not crash or grant blindly. */
    public function testAnUnrecognisedStatusFallsBackToTheAccessAnswer(): void
    {
        $this->deliver('evt_weird', 'quantum_superposition', false);

        self::assertSame(SubscriptionService::STATUS_EXPIRED, $this->statusOf(self::TENANT));
    }

    /** Paying assigns the plan, which is what actually unlocks entitlements. */
    public function testPayingAssignsThePlanTheEntitlementsHangOff(): void
    {
        $this->deliver('evt_a', 'active', true, planCode: 'pro');

        $statement = $this->pdo->query('SELECT plan_id FROM tenant_plan WHERE tenant_id = 1');
        self::assertNotFalse($statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    /** An event about something that is not a tenant of ours is ignored, not guessed at. */
    public function testAnEventAboutAnUnknownSubjectIsAcknowledgedAndIgnored(): void
    {
        $body = json_encode([
            'id' => 'evt_other',
            'type' => 'subscription.updated',
            'data' => ['subscription' => ['customer' => ['external_id' => 'user-9000']]],
        ], JSON_THROW_ON_ERROR);

        $response = $this->handler->webhook($this->signed($body, 'evt_other'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->portal->accessCalls);
        self::assertNull($this->statusOf(self::TENANT));
    }

    /** Payment events describe money, which is not the question being asked. */
    public function testAPaymentEventIsAcknowledgedWithoutTouchingAccess(): void
    {
        $body = json_encode([
            'id' => 'evt_pay',
            'type' => 'payment.succeeded',
            'data' => ['subscription' => ['customer' => ['external_id' => 'tenant-1']]],
        ], JSON_THROW_ON_ERROR);

        $response = $this->handler->webhook($this->signed($body, 'evt_pay'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->portal->accessCalls);
        self::assertNull($this->statusOf(self::TENANT));
    }

    // ── the return URL is not evidence ──────────────────────────────────────

    /**
     * THE FORGERY THIS WHOLE HANDLER IS SHAPED AROUND. Anyone can type
     * `?checkout=…&status=completed` into an address bar. A product that grants
     * access on the strength of it is giving its paid tier to anyone who reads
     * the documentation.
     */
    public function testAForgedCompletedStatusOnTheReturnGrantsNothing(): void
    {
        $this->portal->access = AccessSnapshot::none('tenant-1');

        $response = $this->handler->returnFrom(
            $this->asTenant('GET', '/api/v1/billing/return?checkout=cs_forged&status=completed')
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertFalse($data['has_access'], 'the query string must not grant anything');
        self::assertNull($this->statusOf(self::TENANT), 'and must not write a subscription');
    }

    /**
     * NEVER PAID IS NOT LAPSED, AND THE DIFFERENCE IS A LOCKOUT.
     *
     * `decide()` never blocks a tenant with no recorded status — that is what
     * keeps free-tier and self-hosted tenants working. Recording "no access" for
     * a tenant the billing service has simply never heard of would collapse that
     * into "lapsed", and the wall WOULD block them. The trigger is as small as
     * someone opening the billing page, because the return handler records what
     * it reads.
     *
     * This is asserted through the wall rather than on the column, because the
     * column is an implementation detail and the lockout is the actual harm.
     */
    public function testATenantWhoNeverPaidIsNotWalledByVisitingTheBillingPage(): void
    {
        $this->portal->access = AccessSnapshot::none('tenant-1');

        $this->handler->returnFrom(
            $this->asTenant('GET', '/api/v1/billing/return?checkout=cs_x&status=completed')
        );

        // block_all would be the harshest reading if a status HAD been written.
        $this->subscriptions->setSubscription(self::TENANT, ['enforcement_mode' => 'block_all']);

        self::assertTrue(
            $this->subscriptions->decide(self::TENANT, true)->allowed,
            'a tenant who never subscribed must not be locked out by reading their own billing page'
        );
    }

    /**
     * But a tenant who DID have a subscription and no longer does is revoked.
     * The same empty answer means something different once a relationship
     * existed, and collapsing the two in the other direction would let a deleted
     * subscription keep its access forever.
     */
    public function testATenantWhoseSubscriptionVanishedIsRevoked(): void
    {
        $this->deliver('evt_a', 'active', true);
        self::assertSame(SubscriptionService::STATUS_ACTIVE, $this->statusOf(self::TENANT));

        $this->portal->access = AccessSnapshot::none('tenant-1');
        $this->handler->returnFrom($this->asTenant('GET', '/api/v1/billing/return'));

        self::assertSame(SubscriptionService::STATUS_EXPIRED, $this->statusOf(self::TENANT));
    }

    /**
     * The mirror image: the service says paid, the query string says failed, and
     * the tenant is let in. Whoever reached the return URL, the answer comes
     * from the same place.
     */
    public function testAccessIsGrantedOnReturnEvenWhenTheQueryStringSaysFailed(): void
    {
        $this->portal->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', '2026-10-12T00:00:00+00:00');

        $response = $this->handler->returnFrom(
            $this->asTenant('GET', '/api/v1/billing/return?checkout=cs_real&status=failed')
        );

        self::assertTrue($this->json($response)['has_access']);
        self::assertSame(SubscriptionService::STATUS_ACTIVE, $this->statusOf(self::TENANT));
    }

    // ── sending someone to pay ──────────────────────────────────────────────

    public function testCheckoutReturnsADestinationAndNeverTheReference(): void
    {
        $response = $this->handler->checkout(
            $this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'pro'])
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame('https://pay.example.test/checkout/cs_x', $data['url']);
        self::assertArrayNotHasKey('reference', $data, 'the reference is a capability token');
        self::assertSame('tenant-1', $this->portal->lastSubject);
        self::assertSame('price_01ABC', $this->portal->lastPrice, 'the plan resolved to its handle');
    }

    /**
     * PAYING TWICE FOR THE SAME MONTH IS THE FAILURE THIS GUARDS.
     *
     * Buying again opens a SECOND subscription beside the first — the billing
     * service has no notion that this customer already has one — so it charges
     * again, renews both, and leaves two live subscriptions where the product
     * reads one. The customer pays twice a month until somebody notices.
     *
     * Asserted at the ENDPOINT, not by checking a button is hidden: anyone can
     * POST this, and the screen that draws the button is the one thing certain
     * to be stale the moment a payment lands.
     */
    public function testATenantThatAlreadyHasAccessCannotBuyAgain(): void
    {
        $this->portal->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', '2026-10-12T00:00:00+00:00');

        $response = $this->handler->checkout(
            $this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'pro'])
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->portal->lastPrice, 'no checkout may be opened at all');
    }

    /**
     * AND A FAILURE TO ASK IS NOT A LICENCE TO CHARGE. If the billing service
     * cannot be reached, the honest answer is "I do not know whether they have
     * paid" — and the cost of guessing wrong in one direction is a double
     * charge, in the other a retry.
     */
    public function testCheckoutRefusesWhenItCannotTellWhetherTheyAlreadyPaid(): void
    {
        $this->portal->failWith = BillingPortalException::unreachable('down');

        $response = $this->handler->checkout(
            $this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'pro'])
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($this->portal->lastPrice, 'nothing may be opened on a guess');
    }

    /**
     * AN ADD-ON IS NOT A WAY INTO THE PRODUCT.
     *
     * Devices are sold BESIDE a subscription, never instead of one. Without this
     * a tenant with no tier could buy twenty dinars of devices and be let in by
     * them — an account nobody had sold a tier for, paying the wrong price for
     * the wrong thing, and looking exactly like a legitimate customer.
     */
    public function testAnAddOnCannotBeBoughtWithoutASubscription(): void
    {
        // No access: the default snapshot is "never heard of them".
        $response = $this->handler->checkout(
            $this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'devices'])
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->portal->lastPrice, 'no checkout may be opened at all');
    }

    /**
     * AND THE GUARD THAT STOPS A SECOND TIER MUST NOT STOP AN ADD-ON. They have
     * opposite preconditions, so a single "already has access → refuse" would
     * make add-ons unsellable to exactly the tenants allowed to buy them.
     */
    public function testASubscribedTenantCanBuyAnAddOn(): void
    {
        $this->portal->access = new AccessSnapshot('tenant-1', true, 'pro', 'active', '2026-10-12T00:00:00+00:00');

        $response = $this->handler->checkout(
            $this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'devices'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('price_DEV', $this->portal->lastPrice);
    }

    /** The catalogue says which kind each plan is, or no screen can separate them. */
    public function testThePlanlistSaysWhichPlansAreAddOns(): void
    {
        $response = $this->handler->plans($this->asTenant('GET', '/api/v1/billing/plans'));

        $rows = json_decode((string) $response->getBody(), true)['data'];
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['plan_key']] = $row['is_addon'];
        }

        self::assertFalse($byKey['pro'] ?? null, 'a tier is not an add-on');
        self::assertTrue($byKey['devices'] ?? null, 'devices are an add-on');
    }

    // ── what they have paid ─────────────────────────────────────────────────

    /**
     * A TENANT BILLED EXTERNALLY HAS NO LOCAL INVOICE, by design — the local run
     * stands down so nobody is charged twice. Without this the billing screen
     * showed an empty table to somebody who had just paid: accurate about our
     * records, a lie about their money.
     */
    public function testPaymentHistoryComesFromTheBillingService(): void
    {
        $this->portal->receipts = [
            new \Whity\Core\Billing\External\Receipt(
                'INV-202609-00003',
                'paid',
                15000,
                'JOD',
                '2026-09-12T14:11:33+00:00',
                '2026-09-12T14:10:00+00:00'
            ),
        ];

        $response = $this->handler->receipts($this->asTenant('GET', '/api/v1/billing/receipts'));

        self::assertSame(200, $response->getStatusCode());
        $rows = json_decode((string) $response->getBody(), true)['data'];
        self::assertCount(1, $rows);
        self::assertSame('INV-202609-00003', $rows[0]['number']);
        self::assertSame('paid', $rows[0]['status']);
        self::assertSame(15000, $rows[0]['total_minor'], 'minor units, never divided');
        self::assertSame('JOD', $rows[0]['currency']);
    }

    /** Nothing paid is an empty list, not an error to show a customer. */
    public function testNoPaymentHistoryIsAnEmptyListRatherThanAFailure(): void
    {
        $response = $this->handler->receipts($this->asTenant('GET', '/api/v1/billing/receipts'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], json_decode((string) $response->getBody(), true)['data']);
    }

    // ── more seats, or fewer ────────────────────────────────────────────────

    /**
     * THE SUBSCRIPTION COMES FROM OUR RECORD, NEVER FROM THE REQUEST. A caller
     * naming a subscription id would be naming somebody else's the moment they
     * guessed one, and the billing service cannot know they are not entitled.
     */
    public function testChangingQuantityUsesTheTenantsOwnSubscription(): void
    {
        $this->deliver('evt_a', 'active', true);

        $response = $this->handler->changeQuantity(
            $this->asTenant('POST', '/api/v1/billing/quantity', ['quantity' => 5])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5, $this->portal->lastQuantity);
    }

    /** A tenant with no subscription has nothing to resize. */
    public function testChangingQuantityWithNoSubscriptionIsRefused(): void
    {
        $response = $this->handler->changeQuantity(
            $this->asTenant('POST', '/api/v1/billing/quantity', ['quantity' => 5])
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->portal->lastQuantity);
    }

    /** Zero seats is not a downgrade, it is a cancellation by another name. */
    public function testQuantityBelowOneIsRefused(): void
    {
        $this->deliver('evt_a', 'active', true);

        $response = $this->handler->changeQuantity(
            $this->asTenant('POST', '/api/v1/billing/quantity', ['quantity' => 0])
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->portal->lastQuantity);
    }

    /** A plan this deployment does not sell on these terms cannot be bought. */
    public function testCheckoutRefusesAPlanWithNoPriceHandle(): void
    {
        $response = $this->handler->checkout(
            $this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'nonexistent'])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * CLEARING AN ENVIRONMENT VARIABLE MUST NOT REVOKE A PAYING CUSTOMER.
     *
     * With no billing service configured the portal answers, truthfully, "no
     * relationship" — and for a tenant who already has one that would read as
     * "and now it is gone". The return handler therefore reads nothing and
     * writes nothing when there is no service to ask.
     */
    public function testAnUnconfiguredDeploymentDoesNotRevokeAnExistingSubscription(): void
    {
        $this->deliver('evt_a', 'active', true);

        $unconfigured = new ExternalBillingApiHandler(
            new \Whity\Core\Billing\External\NullBillingPortal(),
            new AccessRecorder($this->subscriptions, new PlanRepository($this->pdo), new NullLogger()),
            new EventLedger($this->store),
            new WebhookVerifier(self::SECRET, 300, fn (): int => $this->now),
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo),
            ),
            new RoleChecker(
                Database::withFactory(fn (): PDO => $this->pdo),
                new PermissionRegistry()
            ),
            $this->pdo,
            'https://app.example.test',
            new NullLogger(),
        );

        $response = $unconfigured->returnFrom($this->asTenant('GET', '/api/v1/billing/return?checkout=cs_x'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            SubscriptionService::STATUS_ACTIVE,
            $this->statusOf(self::TENANT),
            'the subscription must survive the billing service being unconfigured'
        );
    }

    /** An outage at the billing service is a 503, not a 500 and not a silent grant. */
    public function testAnUnreachableBillingServiceIsReportedAsTemporary(): void
    {
        $this->portal->failWith = BillingPortalException::unreachable('timed out');

        $response = $this->handler->checkout(
            $this->asTenant('POST', '/api/v1/billing/checkout', ['plan_key' => 'pro'])
        );

        self::assertSame(503, $response->getStatusCode());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function deliver(string $eventId, string $status, bool $hasAccess, string $planCode = 'pro'): void
    {
        $this->portal->access = new AccessSnapshot(
            'tenant-1',
            $hasAccess,
            $planCode,
            $status,
            '2026-10-12T00:00:00+00:00',
            false,
            'sub_01'
        );

        $response = $this->handler->webhook(
            $this->signed($this->subscriptionEvent($eventId, $status, $hasAccess), $eventId)
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function subscriptionEvent(string $eventId, string $status, bool $grants): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => 'subscription.updated',
            'data' => ['subscription' => [
                'id' => 'sub_01',
                'status' => $status,
                'grants_access' => $grants,
                'customer' => ['external_id' => 'tenant-1'],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function signed(string $body, string $eventId, ?int $timestamp = null): Request
    {
        $ts = (string) ($timestamp ?? $this->now);

        return new Request('POST', '/api/v1/billing/webhook', [
            'X-Pay-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET),
            'X-Pay-Timestamp' => $ts,
            'X-Pay-Event-Id' => $eventId,
        ], $body);
    }

    /** @param array<string, mixed>|null $body */
    private function asTenant(string $method, string $path, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $request = new Request($method, $path, [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::ADMIN];

        return $request;
    }

    private function statusOf(int $tenantId): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM tenant_plan WHERE tenant_id = :t');
        $statement->execute([':t' => $tenantId]);
        $status = $statement->fetchColumn();

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Whether this event id is still claimed.
     *
     * Deliberately a BOOLEAN, not the counter. The ledger counts every delivery
     * it sees, so a replayed event reaches 2 — while the property under test is
     * that only the FIRST caller was allowed to do the work, which is what
     * `accessCalls` measures. Asserting the raw count here would pin the
     * counter's arithmetic rather than the guarantee, and would have to be
     * rewritten every time the mechanism changed.
     */
    private function claimIsHeld(string $eventId): bool
    {
        return $this->store->count('billing:event:' . $eventId) > 0;
    }

    /** @return array<string, mixed> */
    private function json(\Whity\Sdk\Http\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded['data'] ?? $decoded;
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $select = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $select->execute([$permission]);
        $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, (int) $select->fetchColumn()]);
    }
}
