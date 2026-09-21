<?php

declare(strict_types=1);

namespace Whity\Database\Seeders;

use DateTimeImmutable;
use PDO;
use Whity\Core\Billing\InvoiceNumberAllocator;
use Whity\Core\Billing\InvoiceRepository;
use Whity\Core\Money\Money;
use Whity\Core\Payment\PaymentEvent;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentLedger;
use Whity\Database\SequenceCounters;

/**
 * A billing dataset that shows what the screens are for.
 *
 * Empty billing screens are honest and useless: a plan list with no plans, an
 * invoice table with no invoices, and a promotions page that cannot show what a
 * promotion IS. This seeds one of each interesting SHAPE, so somebody opening
 * the screens sees the distinctions the design turns on rather than a blank
 * table and a "no data" message.
 *
 * IT INVENTS ITS OWN TENANT, and that is the important safety property. Invoices
 * are financial records: seeding one against a real customer's tenant would put
 * a debt they do not owe on a screen they can open, and a payment they never
 * made in a ledger somebody may reconcile against. So this creates a clearly
 * marked demo tenant and puts every invoice there. Nothing it writes touches an
 * existing tenant's money.
 *
 * THE NAMES ARE DELIBERATELY GENERIC. This file ships in a public repository, so
 * every company, plan and promotion below is invented — no customer's name, no
 * deployment's real pricing, nothing that describes anybody's actual commercial
 * terms.
 *
 * IT IS IDEMPOTENT. Re-running seeds nothing new: the plan catalogue keys, the
 * promotion codes and the demo tenant's slug are all unique, and the invoice
 * numbers come from the same sequence the application uses. Running a seeder
 * twice is ordinary — a developer resetting a screen, a script that retried —
 * and the alternative is a demo dataset that doubles every time somebody looks
 * at it.
 *
 * WHAT EACH ROW IS FOR
 * --------------------
 * Plans: three tiers, so the "no price yet — this plan cannot be sold" state is
 * visible beside two that can. Prices in JOD at three decimal places, because
 * that is the currency this platform bills in and the one an amount rendered by
 * dividing by 100 gets wrong.
 *
 * Promotions: an early bird (no code, capped, expires), a promo code (typed by
 * the customer), a fixed-amount offer, and a RETIRED one — because a campaign
 * that ended is the explanation for a discount somebody is querying, and a list
 * of only live ones cannot give it.
 *
 * Invoices: paid, open, part-paid, overdue and draft. The part-paid one exists
 * because "a payment arrived and the invoice is still open" is the state most
 * likely to be got wrong, and the only way to see it is to have one.
 *
 * Payments: a success, a partial, and a FAILURE carrying the bank's reason —
 * because "why does it say I have not paid" is answered by the attempt that
 * failed, and a history of successes only cannot answer it.
 */
final class BillingDemoSeeder
{
    /** Marked in the name itself, so nobody mistakes it for a customer. */
    private const DEMO_TENANT_NAME = 'Demo Company (billing sample)';
    private const DEMO_TENANT_SLUG = 'billing-demo';

    private const CURRENCY = 'JOD';

    public function __construct(
        private readonly PDO $pdo,
        /** Injected so a test can place the dataset relative to a fixed today. */
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @return list<string> Human-readable notes on what was seeded.
     */
    public function seed(): array
    {
        $notes = [];
        $now = $this->now();

        $planIds = $this->seedPlans($notes);
        $this->seedPromotions($now, $notes);

        $tenantId = $this->demoTenant($notes);

        $this->seedSubscription($tenantId, $planIds['professional'] ?? null, $now);
        $this->seedInvoices($tenantId, $planIds['professional'] ?? null, $now, $notes);

        return $notes;
    }

    // ── the catalogue ────────────────────────────────────────────────────────

    /**
     * @param list<string> $notes
     *
     * @return array<string, int>
     */
    private function seedPlans(array &$notes): array
    {
        $plans = [
            // key, name, description, prices [amount in MINOR units, period, per-seat]
            ['starter', 'Starter', 'For a small team finding its feet.', [[15000, 'month', false]]],
            ['professional', 'Professional', 'Per-seat, for a growing organisation.', [
                [8000, 'month', true],
                [80000, 'year', true],
            ]],
            // DELIBERATELY UNPRICED, so the "this plan cannot be sold" state is
            // visible on the screen beside two that can.
            ['enterprise', 'Enterprise', 'Negotiated. Priced per agreement.', []],
        ];

        $ids = [];

        foreach ($plans as [$key, $name, $description, $prices]) {
            $ids[$key] = $this->upsertPlan($key, $name, $description);

            foreach ($prices as [$amount, $period, $perSeat]) {
                $this->upsertPrice($ids[$key], $amount, $period, $perSeat);
            }
        }

        $notes[] = sprintf('Plans: %d tiers (one deliberately unpriced).', count($ids));

        return $ids;
    }

    private function upsertPlan(string $key, string $name, string $description): int
    {
        $existing = $this->pdo->prepare('SELECT id FROM plans WHERE plan_key = :key');
        $existing->execute([':key' => $key]);
        $found = $existing->fetchColumn();

        if ($found !== false) {
            return (int) $found;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO plans (plan_key, name, description, is_active, created_at, updated_at)
             VALUES (:key, :name, :description, :active, NOW(), NOW())
             RETURNING id'
        );
        $insert->bindValue(':key', $key);
        $insert->bindValue(':name', $name);
        $insert->bindValue(':description', $description);
        $insert->bindValue(':active', true, PDO::PARAM_BOOL);
        $insert->execute();

        return (int) $insert->fetchColumn();
    }

    private function upsertPrice(int $planId, int $amount, string $period, bool $perSeat): void
    {
        // The schema permits one live price per (plan, currency, period,
        // per-seat-ness, per-device-ness), so this asks the same question before
        // writing rather than relying on an error.
        //
        // `is_per_device` HAS TO BE IN THE QUESTION even though this seeder only
        // ever writes false: migration 148 made it part of what "the same terms"
        // means, and a per-device price for this plan shares `is_per_seat =
        // false` with the flat one. Without the predicate that row answers this
        // question, and the demo stack comes up silently missing a price.
        $existing = $this->pdo->prepare(
            'SELECT id FROM plan_prices
              WHERE plan_id = :plan AND currency = :currency
                AND billing_period = :period AND is_per_seat = :per_seat
                AND is_per_device = :per_device'
        );
        $existing->bindValue(':per_device', false, PDO::PARAM_BOOL);
        $existing->bindValue(':plan', $planId, PDO::PARAM_INT);
        $existing->bindValue(':currency', self::CURRENCY);
        $existing->bindValue(':period', $period);
        $existing->bindValue(':per_seat', $perSeat, PDO::PARAM_BOOL);
        $existing->execute();

        if ($existing->fetchColumn() !== false) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at)
             VALUES (:plan, :currency, :amount, :period, :per_seat, :active, NOW(), NOW())'
        );
        $insert->bindValue(':plan', $planId, PDO::PARAM_INT);
        $insert->bindValue(':currency', self::CURRENCY);
        $insert->bindValue(':amount', $amount, PDO::PARAM_INT);
        $insert->bindValue(':period', $period);
        $insert->bindValue(':per_seat', $perSeat, PDO::PARAM_BOOL);
        $insert->bindValue(':active', true, PDO::PARAM_BOOL);
        $insert->execute();
    }

    // ── promotions ───────────────────────────────────────────────────────────

    /** @param list<string> $notes */
    private function seedPromotions(DateTimeImmutable $now, array &$notes): void
    {
        $promotions = [
            // name, code, percent, amount, starts, ends, cap, active
            [
                'Founding customers', null, 30, null,
                $now->modify('-2 months'), $now->modify('+1 month'), 50, true,
            ],
            ['Autumn campaign', 'AUTUMN25', 25, null, null, $now->modify('+6 weeks'), null, true],
            // A FIXED amount, so the screen shows both kinds side by side and the
            // "a percentage has no currency" distinction is visible.
            ['Migration credit', 'MOVEIN', null, 20000, null, null, 100, true],
            // RETIRED, because a campaign that ended is the explanation for a
            // discount somebody is querying.
            ['Launch week (ended)', 'LAUNCH', 50, null, $now->modify('-6 months'), $now->modify('-5 months'), null, false],
        ];

        $seeded = 0;

        foreach ($promotions as [$name, $code, $percent, $amount, $starts, $ends, $cap, $active]) {
            if ($code !== null && $this->promotionCodeExists($code)) {
                continue;
            }
            if ($code === null && $this->promotionNameExists($name)) {
                continue;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO promotions (name, code, percent_off, amount_off, currency, starts_at, ends_at,
                                         max_redemptions, max_redemptions_per_tenant, is_active, created_at, updated_at)
                 VALUES (:name, :code, :percent, :amount, :currency, :starts, :ends,
                         :cap, :per_tenant, :active, NOW(), NOW())'
            );
            $insert->bindValue(':name', $name);
            $insert->bindValue(':code', $code, $code === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':percent', $percent, $percent === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->bindValue(':amount', $amount, $amount === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            // A percentage has no currency; a fixed amount is meaningless
            // without one. The CHECK in migration 141 enforces exactly this.
            $insert->bindValue(':currency', $amount === null ? null : self::CURRENCY, $amount === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':starts', $starts?->format('Y-m-d H:i:s'), $starts === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':ends', $ends?->format('Y-m-d H:i:s'), $ends === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':cap', $cap, $cap === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->bindValue(':per_tenant', 1, PDO::PARAM_INT);
            $insert->bindValue(':active', $active, PDO::PARAM_BOOL);
            $insert->execute();

            $seeded++;
        }

        $notes[] = sprintf(
            'Promotions: %d seeded (an early bird, two codes, one retired).',
            $seeded
        );
    }

    private function promotionCodeExists(string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM promotions WHERE code = :code');
        $statement->execute([':code' => strtoupper($code)]);

        return $statement->fetchColumn() !== false;
    }

    private function promotionNameExists(string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM promotions WHERE name = :name');
        $statement->execute([':name' => $name]);

        return $statement->fetchColumn() !== false;
    }

    // ── the demo tenant, and its money ───────────────────────────────────────

    /**
     * The tenant every invoice below belongs to.
     *
     * Created rather than borrowed. See the class docblock: seeding an invoice
     * against a real customer would put a debt they do not owe on a screen they
     * can open.
     *
     * @param list<string> $notes
     */
    private function demoTenant(array &$notes): int
    {
        $existing = $this->pdo->prepare('SELECT id FROM tenants WHERE slug = :slug');
        $existing->execute([':slug' => self::DEMO_TENANT_SLUG]);
        $found = $existing->fetchColumn();

        if ($found !== false) {
            $notes[] = 'Demo tenant already present; invoices seeded against it.';

            return (int) $found;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id'
        );
        $insert->execute([':name' => self::DEMO_TENANT_NAME, ':slug' => self::DEMO_TENANT_SLUG]);

        $id = (int) $insert->fetchColumn();
        $notes[] = sprintf('Demo tenant "%s" created (id %d).', self::DEMO_TENANT_NAME, $id);

        return $id;
    }

    private function seedSubscription(int $tenantId, ?int $planId, DateTimeImmutable $now): void
    {
        if ($planId === null) {
            return;
        }

        $existing = $this->pdo->prepare('SELECT 1 FROM tenant_plan WHERE tenant_id = :tenant');
        $existing->execute([':tenant' => $tenantId]);

        if ($existing->fetchColumn() !== false) {
            return;
        }

        // A period end in the FUTURE, so the billing run does not immediately
        // invoice the demo tenant again the first time somebody schedules it.
        $insert = $this->pdo->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, status, current_period_end, assigned_at)
             VALUES (:tenant, :plan, :status, :period_end, NOW())'
        );
        $insert->execute([
            ':tenant' => $tenantId,
            ':plan' => $planId,
            ':status' => 'active',
            ':period_end' => $now->modify('+3 weeks')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * One invoice of every shape worth seeing.
     *
     * @param list<string> $notes
     */
    private function seedInvoices(int $tenantId, ?int $planId, DateTimeImmutable $now, array &$notes): void
    {
        $invoices = new InvoiceRepository($this->pdo);
        $ledger = new PaymentLedger($this->pdo);
        $numbers = new InvoiceNumberAllocator(new SequenceCounters($this->pdo));

        if ($invoices->listForTenant($tenantId) !== []) {
            $notes[] = 'Invoices already present for the demo tenant; none added.';

            return;
        }

        $seller = ['name' => 'Whity (demo seller)', 'tax_id' => 'DEMO-000'];
        $buyer = ['name' => self::DEMO_TENANT_NAME, 'tax_id' => 'DEMO-111'];

        // 1. PAID — the ordinary happy case.
        $paid = $this->issue(
            $invoices, $numbers, $tenantId, $planId,
            'Professional plan — ' . $now->modify('-2 months')->format('F Y'),
            3, 8000, $now->modify('-2 months'), $now->modify('-6 weeks'), $seller, $buyer
        );
        $this->record($ledger, $tenantId, $paid, 24000, PaymentEventType::Succeeded, 'demo-paid-1', $now->modify('-7 weeks'));
        $invoices->markPaid($tenantId, $paid, $now->modify('-7 weeks'));

        // 2. PART-PAID — the state most likely to be got wrong, and the only way
        // to see it is to have one. The money is recorded; the invoice is open.
        $partial = $this->issue(
            $invoices, $numbers, $tenantId, $planId,
            'Professional plan — ' . $now->modify('-1 month')->format('F Y'),
            3, 8000, $now->modify('-1 month'), $now->modify('+1 week'), $seller, $buyer
        );
        $this->record($ledger, $tenantId, $partial, 10000, PaymentEventType::Succeeded, 'demo-partial-1', $now->modify('-3 weeks'));

        // 3. OVERDUE, with a FAILED attempt carrying the bank's reason —
        // because "why does it say I have not paid" is answered by the attempt
        // that failed, never by its absence.
        $overdue = $this->issue(
            $invoices, $numbers, $tenantId, $planId,
            'Professional plan — ' . $now->modify('-3 months')->format('F Y'),
            3, 8000, $now->modify('-3 months'), $now->modify('-10 days'), $seller, $buyer
        );
        $this->record(
            $ledger, $tenantId, $overdue, 24000, PaymentEventType::Failed, 'demo-failed-1',
            $now->modify('-9 days'), 'Insufficient funds'
        );

        // 4. OPEN, nothing paid yet — what a new bill looks like.
        $this->issue(
            $invoices, $numbers, $tenantId, $planId,
            'Professional plan — ' . $now->format('F Y'),
            3, 8000, $now, $now->modify('+2 weeks'), $seller, $buyer
        );

        // 5. A DRAFT — no number, because a draft that took one and was
        // abandoned would punch a hole in the sequence.
        $draft = $invoices->createDraft($tenantId, self::CURRENCY);
        $invoices->addLine($tenantId, $draft, 'Additional storage (draft, not yet issued)', 1, 5000);

        $notes[] = 'Invoices: paid, part-paid, overdue (with a failed attempt), open, and a draft.';
    }

    /**
     * @param array{name?: string, address?: string, tax_id?: string} $seller
     * @param array{name?: string, address?: string, tax_id?: string} $buyer
     */
    private function issue(
        InvoiceRepository $invoices,
        InvoiceNumberAllocator $numbers,
        int $tenantId,
        ?int $planId,
        string $description,
        int $quantity,
        int $unitAmount,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $dueAt,
        array $seller,
        array $buyer,
    ): int {
        $draft = $invoices->createDraft($tenantId, self::CURRENCY, planId: $planId);
        $invoices->addLine($tenantId, $draft, $description, $quantity, $unitAmount);

        $allocated = $numbers->allocate(
            $tenantId,
            'INV-{YYYY}-{SEQ:5}',
            InvoiceNumberAllocator::SCOPE_SHARED,
            InvoiceNumberAllocator::RESET_YEARLY,
            $issuedAt,
        );

        $invoices->issue(
            $tenantId,
            $draft,
            $allocated['series'],
            $allocated['number'],
            $issuedAt,
            $dueAt,
            seller: $seller,
            buyer: $buyer,
        );

        return $draft;
    }

    private function record(
        PaymentLedger $ledger,
        int $tenantId,
        int $invoiceId,
        int $amount,
        PaymentEventType $type,
        string $reference,
        DateTimeImmutable $at,
        ?string $failureReason = null,
    ): void {
        $ledger->record(
            new PaymentEvent(
                $type,
                'mock',
                $reference,
                Money::of($amount, self::CURRENCY),
                $at,
                $invoiceId,
                $tenantId,
                $failureReason,
            ),
            $tenantId,
            $invoiceId,
        );
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            /** @var DateTimeImmutable $moment */
            $moment = ($this->clock)();

            return $moment;
        }

        return new DateTimeImmutable();
    }
}
