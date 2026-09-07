<?php

declare(strict_types=1);

namespace Tests\Api;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\BillingApiHandler;
use Whity\Api\PaymentWebhookApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Billing\DunningService;
use Whity\Core\Billing\InvoiceNumberAllocator;
use Whity\Core\Billing\InvoiceRepository;
use Whity\Core\Billing\PaymentReconciler;
use Whity\Core\Money\Money;
use Whity\Core\Payment\Cliq\CliqPaymentProvider;
use Whity\Core\Payment\MockPaymentProvider;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentInstruction;
use Whity\Core\Payment\PaymentLedger;
use Whity\Core\Payment\PaymentProviderRegistry;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Database\SequenceCounters;

/**
 * The tenant-facing billing endpoints and the provider callback.
 *
 * The two things worth testing hardest are the two that would be invisible if
 * wrong: that paying works the same way for every rail (so adding the card
 * provider is a new branch and not a new endpoint), and that a LOCKED tenant
 * can still be un-locked by a payment.
 */
final class BillingApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const ADMIN = 10;
    private const OUTSIDER = 11;

    private const CLIQ_SECRET = 'bank-shared-secret';

    private PDO $pdo;
    private BillingApiHandler $handler;
    private PaymentWebhookApiHandler $webhooks;
    private InvoiceRepository $invoices;
    private PaymentLedger $ledger;
    private PaymentReconciler $reconciler;
    private SubscriptionService $subscriptions;
    private DunningService $dunning;
    private MockPaymentProvider $mock;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'acme', 'acme')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'other', 'other')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'outsider', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (10, 1, 1, 'active', CURRENT_TIMESTAMP),
                (11, 2, 1, 'active', CURRENT_TIMESTAMP)
        ");

        $database = Database::withFactory(fn (): PDO => $this->pdo);
        $database->setMaxLifetimeSeconds(86400);
        $database->setPingIntervalSeconds(86400);
        $database->forceConnect();

        $this->now = new DateTimeImmutable('2026-09-20 12:00:00');
        $this->invoices = new InvoiceRepository($this->pdo);
        $this->ledger = new PaymentLedger($this->pdo);
        $this->reconciler = new PaymentReconciler($this->invoices, $this->ledger, $this->pdo);
        $this->subscriptions = new SubscriptionService(
            new SubscriptionRepository($this->pdo),
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo),
            ),
            fn (): int => $this->now->getTimestamp(),
        );
        $this->dunning = new DunningService($this->invoices, $this->ledger, $this->subscriptions);

        $this->mock = new MockPaymentProvider('mock-secret', fn (): DateTimeImmutable => $this->now);
        $registry = new PaymentProviderRegistry([
            $this->mock,
            new CliqPaymentProvider('WHITY.JO', 'Test Bank', self::CLIQ_SECRET, 'WHT-', fn (): DateTimeImmutable => $this->now),
        ]);

        $roleChecker = new RoleChecker($database, new PermissionRegistry());

        $this->handler = new BillingApiHandler(
            $this->invoices,
            $this->ledger,
            $this->reconciler,
            $registry,
            $roleChecker,
            fn (): DateTimeImmutable => $this->now,
        );
        $this->webhooks = new PaymentWebhookApiHandler(
            $registry,
            $this->reconciler,
            $this->dunning,
            new NullLogger(),
            fn (): DateTimeImmutable => $this->now,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── one endpoint, every rail ─────────────────────────────────────────────

    /**
     * THE REQUIREMENT THAT SHAPES THE ENDPOINT. Two rails, one call, and the
     * client branches on `kind` rather than on which provider it is talking to.
     * A card PSP produces `redirect`, which already exists here.
     */
    public function testOnePayEndpointServesRailsWithDifferentShapes(): void
    {
        $invoiceId = $this->issuedInvoice();

        $viaCliq = $this->json($this->pay($invoiceId, 'cliq'));
        self::assertSame(PaymentInstruction::KIND_TRANSFER, $viaCliq['kind']);
        self::assertNotEmpty($viaCliq['reference']);
        self::assertSame('WHITY.JO', $viaCliq['display']['alias']);

        $other = $this->issuedInvoice();
        $viaMock = $this->json($this->pay($other, 'mock'));
        self::assertSame(PaymentInstruction::KIND_SETTLED, $viaMock['kind']);
    }

    /** Only rails that can actually take money are offered. */
    public function testTheMethodsListOffersOnlyUsableRails(): void
    {
        $rows = $this->json($this->handler->methods($this->as(self::ADMIN, self::TENANT)));
        $names = array_column($rows, 'provider');

        self::assertContains('cliq', $names);
        // And the CliQ row tells a client it cannot renew unattended, which is
        // the difference that decides whether a subscription can auto-renew.
        $cliq = null;
        foreach ($rows as $row) {
            if ($row['provider'] === 'cliq') {
                $cliq = $row;
            }
        }
        self::assertNotNull($cliq, 'the CliQ rail should be on offer');
        self::assertFalse($cliq['supports_unattended_charge']);
        self::assertTrue($cliq['uses_push_transfer']);
    }

    /**
     * THE ATTEMPT IS RECORDED BEFORE THE PAYER IS SENT ANYWHERE. A customer who
     * pays and closes the tab has moved money the platform must already have a
     * row for, or the callback has nothing to match against.
     */
    public function testStartingAPaymentWritesAPendingRowFirst(): void
    {
        $invoiceId = $this->issuedInvoice();
        $body = $this->json($this->pay($invoiceId, 'cliq'));

        $history = $this->ledger->historyFor(self::TENANT, $invoiceId);

        self::assertCount(1, $history);
        self::assertSame('pending', $history[0]['status']);
        self::assertSame($body['reference'], $history[0]['external_reference']);
        // And pending is NOT paid.
        self::assertSame(0, $this->ledger->amountSettledMinor(self::TENANT, $invoiceId));
    }

    /** Asking twice does not open two debts. */
    public function testStartingTheSamePaymentTwiceDoesNotDuplicateTheRow(): void
    {
        $invoiceId = $this->issuedInvoice();

        $first = $this->json($this->pay($invoiceId, 'cliq'));
        $second = $this->json($this->pay($invoiceId, 'cliq'));

        self::assertSame($first['reference'], $second['reference']);
        self::assertCount(1, $this->ledger->historyFor(self::TENANT, $invoiceId));
    }

    public function testAnUnknownRailIsRefusedWithoutLeakingWhy(): void
    {
        $response = $this->pay($this->issuedInvoice(), 'paytabs');

        self::assertSame(422, $response->getStatusCode());
        // Not the provider exception's own message, which can name endpoints
        // and merchant identifiers.
        self::assertStringNotContainsString('registered', (string) $response->getBody());
    }

    public function testAPaidInvoiceCannotBePaidAgain(): void
    {
        $invoiceId = $this->issuedInvoice();
        $this->settle($invoiceId, 5000, 'already-paid');

        $response = $this->pay($invoiceId, 'cliq');

        self::assertSame(409, $response->getStatusCode());
    }

    public function testADraftCannotBePaid(): void
    {
        $draft = $this->invoices->createDraft(self::TENANT, 'JOD');
        $this->invoices->addLine(self::TENANT, $draft, 'Plan', 1, 5000);

        self::assertSame(409, $this->pay($draft, 'cliq')->getStatusCode());
    }

    // ── amounts a client can render ──────────────────────────────────────────

    /**
     * The formatted amount travels WITH the integer, because 5000 JOD is 5.000
     * and a client that divides by 100 shows every customer an amount ten times
     * too large. The client cannot work the precision out for itself.
     */
    public function testAmountsCarryBothMinorUnitsAndTheirOwnPrecision(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000);
        $row = $this->json($this->handler->invoice($this->as(self::ADMIN, self::TENANT), ['id' => (string) $invoiceId]));

        self::assertSame(5000, $row['total_minor']);
        self::assertSame('5.000 JOD', $row['total_formatted']);
        self::assertSame(5000, $row['balance_minor']);
    }

    public function testAnInvoiceCarriesItsLinesAndItsPayments(): void
    {
        $invoiceId = $this->issuedInvoice();
        $this->settle($invoiceId, 2000, 'part-1');

        $row = $this->json($this->handler->invoice($this->as(self::ADMIN, self::TENANT), ['id' => (string) $invoiceId]));

        self::assertCount(1, $row['lines']);
        self::assertCount(1, $row['payments']);
        self::assertSame(2000, $row['amount_paid_minor']);
        self::assertSame(3000, $row['balance_minor']);
    }

    // ── tenancy ──────────────────────────────────────────────────────────────

    /** A tenant sees its own invoices and nobody else's. */
    public function testATenantCannotReadAnotherTenantsInvoice(): void
    {
        $invoiceId = $this->issuedInvoice(tenantId: self::TENANT);

        $response = $this->handler->invoice(
            $this->as(self::OUTSIDER, self::OTHER_TENANT),
            ['id' => (string) $invoiceId]
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testATenantCannotPayAnotherTenantsInvoice(): void
    {
        $invoiceId = $this->issuedInvoice(tenantId: self::TENANT);

        self::assertSame(
            404,
            $this->handler->pay($this->as(self::OUTSIDER, self::OTHER_TENANT), ['id' => (string) $invoiceId])
                ->getStatusCode()
        );
    }

    // ── the callback ─────────────────────────────────────────────────────────

    public function testAVerifiedCallbackSettlesTheInvoice(): void
    {
        $invoiceId = $this->issuedInvoice();
        $reference = $this->json($this->pay($invoiceId, 'cliq'))['reference'];

        $response = $this->webhooks->receive(
            $this->cliqCallback($reference, '5.000', 'BANKTXN-1'),
            ['provider' => 'cliq']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(InvoiceRepository::STATUS_PAID, $this->statusOf($invoiceId));
    }

    /**
     * THE DEADLOCK THIS ENDPOINT EXISTS OUTSIDE THE WALL TO AVOID. A locked
     * tenant's payment confirmation must be accepted — otherwise the payment
     * that would unlock them can never be recorded, and they stay locked
     * forever having paid.
     */
    public function testALockedTenantIsUnlockedByItsOwnPaymentCallback(): void
    {
        $this->subscriptions->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_ACTIVE,
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);

        $invoiceId = $this->issuedInvoice(dueAt: '2026-09-01');
        $reference = $this->json($this->pay($invoiceId, 'cliq'))['reference'];

        $this->dunning->lock(self::TENANT, new DateTimeImmutable('2026-09-15'));
        self::assertFalse($this->subscriptions->decide(self::TENANT, true)->allowed);

        $response = $this->webhooks->receive(
            $this->cliqCallback($reference, '5.000', 'BANKTXN-9'),
            ['provider' => 'cliq']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(
            $this->subscriptions->decide(self::TENANT, true)->allowed,
            'the payment must be able to un-lock the tenant it belongs to'
        );
    }

    /** A forged payload is refused, and told nothing about why. */
    public function testAForgedCallbackIsRefused(): void
    {
        $invoiceId = $this->issuedInvoice();
        $reference = $this->json($this->pay($invoiceId, 'cliq'))['reference'];

        $callback = $this->cliqCallback($reference, '5.000', 'BANKTXN-2');
        $forged = new Request('POST', '/api/payments/webhook/cliq', ['X-Cliq-Signature' => 'nope'], $callback->getBody());

        $response = $this->webhooks->receive($forged, ['provider' => 'cliq']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(InvoiceRepository::STATUS_OPEN, $this->statusOf($invoiceId), 'nothing was settled');
    }

    /**
     * A redelivery answers 200. Answering 409 would make a provider retry
     * forever and eventually disable the endpoint.
     */
    public function testARedeliveredCallbackStillAnswers200AndPaysOnce(): void
    {
        $invoiceId = $this->issuedInvoice();
        $reference = $this->json($this->pay($invoiceId, 'cliq'))['reference'];

        $first = $this->webhooks->receive($this->cliqCallback($reference, '5.000', 'BANKTXN-3'), ['provider' => 'cliq']);
        $second = $this->webhooks->receive($this->cliqCallback($reference, '5.000', 'BANKTXN-3'), ['provider' => 'cliq']);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(5000, $this->ledger->amountSettledMinor(self::TENANT, $invoiceId));
    }

    public function testACallbackForAnUnknownProviderIs404(): void
    {
        $response = $this->webhooks->receive(
            new Request('POST', '/api/payments/webhook/paytabs', [], '{}'),
            ['provider' => 'paytabs']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * An invoice's status, insisting the invoice is there — so a broken setup
     * reports "invoice missing" rather than "offset does not exist on null".
     */
    private function statusOf(int $invoiceId, int $tenantId = self::TENANT): string
    {
        $invoice = $this->invoices->findById($tenantId, $invoiceId);
        self::assertNotNull($invoice, "invoice {$invoiceId} should exist");

        return (string) $invoice['status'];
    }

    private function issuedInvoice(
        int $minor = 5000,
        string $dueAt = '2026-10-01',
        int $tenantId = self::TENANT,
    ): int {
        $draft = $this->invoices->createDraft($tenantId, 'JOD');
        $this->invoices->addLine($tenantId, $draft, 'Professional plan', 1, $minor);

        $allocated = (new InvoiceNumberAllocator(new SequenceCounters($this->pdo)))->allocate(
            $tenantId,
            'INV-{YYYY}-{SEQ:5}',
            InvoiceNumberAllocator::SCOPE_SHARED,
            InvoiceNumberAllocator::RESET_YEARLY,
            new DateTimeImmutable('2026-09-01'),
        );

        $this->invoices->issue(
            $tenantId,
            $draft,
            $allocated['series'],
            $allocated['number'],
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable($dueAt),
            seller: ['name' => 'Whity Operator'],
            buyer: ['name' => 'Acme Ltd'],
        );

        return $draft;
    }

    private function settle(int $invoiceId, int $minor, string $reference): void
    {
        $this->reconciler->apply(
            new \Whity\Core\Payment\PaymentEvent(
                PaymentEventType::Succeeded,
                'mock',
                $reference,
                Money::of($minor, 'JOD'),
                $this->now,
                $invoiceId,
            ),
            $this->now
        );
    }

    private function pay(int $invoiceId, string $provider): \Whity\Core\Response
    {
        return $this->handler->pay(
            $this->as(self::ADMIN, self::TENANT, ['provider' => $provider]),
            ['id' => (string) $invoiceId]
        );
    }

    /** A signed CliQ settlement message. */
    private function cliqCallback(string $reference, string $amount, string $transactionId): Request
    {
        $body = (string) json_encode([
            'transfers' => [[
                'transactionId' => $transactionId,
                'remittanceInformation' => $reference,
                'amount' => $amount,
                'currency' => 'JOD',
                'status' => 'ACSC',
                'valueDate' => '2026-09-20T12:00:00+03:00',
            ]],
        ]);

        return new Request(
            'POST',
            '/api/payments/webhook/cliq',
            [CliqPaymentProvider::SIGNATURE_HEADER => hash_hmac('sha256', $body, self::CLIQ_SECRET)],
            $body
        );
    }

    /** @param array<string, mixed>|null $body */
    private function as(int $profileId, int $tenantId, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $request = new Request('POST', '/api/billing', [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(\Whity\Core\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded['data'] ?? $decoded;
    }
}
