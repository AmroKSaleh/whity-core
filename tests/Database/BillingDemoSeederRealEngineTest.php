<?php

declare(strict_types=1);

namespace Tests\Database;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\SeedCommand;
use Whity\Core\Billing\InvoiceRepository;
use Whity\Core\Payment\PaymentLedger;
use Whity\Database\Seeders\BillingDemoSeeder;

/**
 * The billing demo dataset.
 *
 * A seeder is a strange thing to test until you notice what this one writes:
 * INVOICES. A demo dataset that put a debt on a real customer's screen, or that
 * doubled every time somebody re-ran it, would be a data problem rather than a
 * cosmetic one — so the two properties asserted hardest here are that it invents
 * its own tenant and that running it twice changes nothing.
 */
final class BillingDemoSeederRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BillingDemoSeeder $seeder;
    private InvoiceRepository $invoices;
    private PaymentLedger $ledger;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        // A stand-in for a REAL customer, so the isolation claim is testable.
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'A Real Customer', 'real-customer')");

        $this->seeder = new BillingDemoSeeder(
            $this->pdo,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08 12:00:00'),
        );
        $this->invoices = new InvoiceRepository($this->pdo);
        $this->ledger = new PaymentLedger($this->pdo);
    }

    // ── the safety property ──────────────────────────────────────────────────

    /**
     * THE ONE THAT MATTERS. An invoice against a real customer is a debt they do
     * not owe, on a screen they can open, in a ledger somebody may reconcile.
     */
    public function testNoExistingTenantIsGivenAnInvoice(): void
    {
        $this->seeder->seed();

        self::assertSame([], $this->invoices->listForTenant(1), 'the real customer must be untouched');
        self::assertSame(0, $this->ledger->amountSettledMinor(1, 0));
    }

    public function testItCreatesItsOwnTenant(): void
    {
        $this->seeder->seed();

        $statement = $this->pdo->query("SELECT name FROM tenants WHERE slug = 'billing-demo'");
        self::assertNotFalse($statement);
        $name = $statement->fetchColumn();

        self::assertIsString($name);
        // Marked in the name itself, so nobody mistakes it for a customer.
        self::assertStringContainsString('Demo', $name);
    }

    // ── idempotence ──────────────────────────────────────────────────────────

    /**
     * Re-running a seeder is ordinary — a developer resetting a screen, a
     * script that retried. The alternative is a demo dataset that doubles every
     * time somebody looks at it, and invoice numbers that march away from
     * whatever the sequence thinks it has issued.
     */
    public function testRunningItTwiceSeedsNothingNew(): void
    {
        $this->seeder->seed();
        $before = $this->counts();

        $this->seeder->seed();

        self::assertSame($before, $this->counts());
    }

    // ── every shape the screens distinguish ──────────────────────────────────

    /**
     * The point of the dataset: one invoice per state, so the screen shows the
     * distinctions the design turns on rather than five rows of the same thing.
     */
    public function testThereIsOneInvoiceOfEveryShape(): void
    {
        $this->seeder->seed();

        $tenantId = $this->demoTenantId();
        $byStatus = [];

        foreach ($this->invoices->listForTenant($tenantId) as $invoice) {
            $byStatus[(string) $invoice['status']] = ($byStatus[(string) $invoice['status']] ?? 0) + 1;
        }

        self::assertArrayHasKey('paid', $byStatus);
        self::assertArrayHasKey('open', $byStatus);
        self::assertArrayHasKey('draft', $byStatus);
        self::assertGreaterThanOrEqual(3, $byStatus['open'], 'open, part-paid and overdue are all open');
    }

    /**
     * A PART-PAID invoice is the state most likely to be got wrong, and the only
     * way to see it on a screen is to have one: money recorded, invoice still
     * open, a balance that is neither zero nor the full amount.
     */
    public function testOneInvoiceIsPartPaidAndStillOpen(): void
    {
        $this->seeder->seed();
        $tenantId = $this->demoTenantId();

        $partPaid = null;
        foreach ($this->invoices->listForTenant($tenantId) as $invoice) {
            $settled = $this->ledger->amountSettledMinor($tenantId, (int) $invoice['id']);
            if ($invoice['status'] === 'open' && $settled > 0 && $settled < (int) $invoice['total_minor']) {
                $partPaid = $invoice;
            }
        }

        self::assertNotNull($partPaid, 'no part-paid invoice, so that state cannot be seen on the screen');
    }

    /**
     * A FAILED attempt with the bank's reason, because "why does it say I have
     * not paid" is answered by the attempt that failed and never by its absence.
     */
    public function testThereIsAFailedPaymentCarryingItsReason(): void
    {
        $this->seeder->seed();
        $tenantId = $this->demoTenantId();

        $reasons = [];
        foreach ($this->invoices->listForTenant($tenantId) as $invoice) {
            foreach ($this->ledger->historyFor($tenantId, (int) $invoice['id']) as $payment) {
                if ($payment['status'] === 'failed') {
                    $reasons[] = $payment['failure_reason'];
                }
            }
        }

        self::assertNotEmpty($reasons, 'no failed attempt, so the screen cannot explain an unpaid invoice');
        self::assertNotNull($reasons[0]);
    }

    /**
     * A plan with no price shows the "cannot be sold" state beside two that can.
     */
    public function testOnePlanIsDeliberatelyUnpriced(): void
    {
        $this->seeder->seed();

        $statement = $this->pdo->query(
            'SELECT count(*) FROM plans p WHERE NOT EXISTS (SELECT 1 FROM plan_prices pp WHERE pp.plan_id = p.id)'
        );
        self::assertNotFalse($statement);

        self::assertSame(1, (int) $statement->fetchColumn());
    }

    /**
     * Both promotion kinds and a retired one, because a campaign that ended is
     * the explanation for a discount somebody is querying.
     */
    public function testPromotionsCoverEveryShapeTheScreenDraws(): void
    {
        $this->seeder->seed();

        $row = $this->pdo->query(
            'SELECT
               count(*) FILTER (WHERE code IS NULL) AS automatic,
               count(*) FILTER (WHERE code IS NOT NULL) AS coded,
               count(*) FILTER (WHERE amount_off IS NOT NULL) AS fixed,
               count(*) FILTER (WHERE NOT is_active) AS retired
             FROM promotions'
        );

        if ($row === false) {
            self::markTestSkipped('FILTER is unsupported on this engine build');
        }

        /** @var array<string, mixed> $counts */
        $counts = $row->fetch(PDO::FETCH_ASSOC);

        self::assertGreaterThan(0, (int) $counts['automatic'], 'an early bird has no code');
        self::assertGreaterThan(0, (int) $counts['coded']);
        self::assertGreaterThan(0, (int) $counts['fixed'], 'a fixed amount reads differently from a percentage');
        self::assertGreaterThan(0, (int) $counts['retired']);
    }

    /** Amounts are in JOD, the currency whose three decimal places started all this. */
    public function testAmountsAreInTheCurrencyThePlatformBillsIn(): void
    {
        $this->seeder->seed();

        $statement = $this->pdo->query("SELECT count(*) FROM plan_prices WHERE currency <> 'JOD'");
        self::assertNotFalse($statement);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    /**
     * The flag is its own, and does not ride another. Demo CONTENT on a flag
     * meaning something else is the regression the document demo already
     * recorded — and it matters more here, because this writes money.
     */
    public function testTheDatasetHasItsOwnFlag(): void
    {
        self::assertTrue(SeedCommand::wantsBillingDemo(['--with-billing-demo']));
        self::assertFalse(SeedCommand::wantsBillingDemo(['--with-fixtures']));
        self::assertFalse(SeedCommand::wantsBillingDemo(['--with-document-demo']));
        self::assertFalse(SeedCommand::wantsBillingDemo([]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function demoTenantId(): int
    {
        $statement = $this->pdo->query("SELECT id FROM tenants WHERE slug = 'billing-demo'");
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (['tenants', 'plans', 'plan_prices', 'promotions', 'invoices', 'invoice_lines', 'payment_transactions'] as $table) {
            $statement = $this->pdo->query("SELECT count(*) FROM {$table}");
            self::assertNotFalse($statement);
            $counts[$table] = (int) $statement->fetchColumn();
        }

        return $counts;
    }
}
