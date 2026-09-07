<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Subscription\SubscriptionService;

/**
 * The thing that starts the clock: turning subscriptions into invoices.
 *
 * Everything else in this area — the ledger, reconciliation, dunning, the rails,
 * the screen — was driven by tests and by invoices created in code. This is what
 * makes the platform bill somebody without being asked.
 *
 * RE-RUNNING IS SAFE, AND NOT BECAUSE THIS REMEMBERS ANYTHING. A scheduled run
 * is invoked by a clock, and clocks fire twice: a worker restarted mid-run, a
 * cron overlapping its predecessor, an operator retrying after a failure. The
 * guarantee is the unique index from migration 145 — one invoice per (tenant,
 * plan, period) — so a second run COLLIDES rather than checking first. A
 * check-then-insert has a window in which two runs both read "not billed yet",
 * and that window is open precisely when something is retrying.
 *
 * The collision is caught and counted as `skipped`, not raised. An invoice that
 * already exists is the run working.
 *
 * IT ISSUES, IT DOES NOT COLLECT. The run creates an OPEN invoice and stops.
 * Taking the payment is a separate decision made by a separate mechanism,
 * because whether an unattended charge is even possible is a property of the
 * rail — on CliQ it is not, and a billing run that tried would manufacture a
 * failure per period for a customer who has done nothing wrong.
 *
 * A TENANT WITH NO PRICE IS SKIPPED, LOUDLY. A plan with no active price in the
 * tenant's currency cannot be billed, and the honest outcome is a log line and a
 * count — not a zero-amount invoice, which would look like a settled month, and
 * not an exception, which would stop the run and leave every tenant after it
 * unbilled because one plan was misconfigured.
 *
 * THE PERIOD IS DERIVED FROM `current_period_end`, WHICH IS ALSO ADVANCED. The
 * invoice covers the period that just ENDED, and the subscription moves on to
 * the next one. Both happen in one transaction: a run that invoiced without
 * advancing would invoice the same period again on its next tick and be saved
 * only by the index, and one that advanced without invoicing would skip a month
 * silently, which is the worse direction.
 */
final class SubscriptionBillingRun
{
    /** How many tenants one tick will bill, so a backlog cannot run forever. */
    private const MAX_PER_RUN = 500;

    public function __construct(
        private readonly PDO $pdo,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceNumberAllocator $numbers,
        private readonly SubscriptionService $subscriptions,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Bill every subscription whose period has ended.
     *
     * @return array{invoiced: int, skipped: int, unpriced: int}
     */
    public function run(DateTimeImmutable $now): array
    {
        $invoiced = 0;
        $skipped = 0;
        $unpriced = 0;

        foreach ($this->dueSubscriptions($now) as $subscription) {
            $tenantId = (int) $subscription['tenant_id'];

            try {
                $outcome = $this->billOne($subscription, $now);
            } catch (\PDOException $e) {
                // The uniqueness index did its job: this period is already
                // invoiced. Counted, not raised — a run that stopped here would
                // leave every tenant after it unbilled.
                $this->logger->info('Subscription period already invoiced', [
                    'tenant_id' => $tenantId,
                    'sqlstate' => $e->getCode(),
                ]);
                $skipped++;
                continue;
            } catch (\Throwable $e) {
                // One tenant's misconfiguration must not stop the others.
                $this->logger->error('Could not bill a subscription', [
                    'tenant_id' => $tenantId,
                    'reason' => $e->getMessage(),
                ]);
                $skipped++;
                continue;
            }

            match ($outcome) {
                'invoiced' => $invoiced++,
                'unpriced' => $unpriced++,
                default => $skipped++,
            };
        }

        return ['invoiced' => $invoiced, 'skipped' => $skipped, 'unpriced' => $unpriced];
    }

    /**
     * One subscription.
     *
     * @param array<string, mixed> $subscription
     *
     * @return 'invoiced'|'unpriced'|'skipped'
     */
    private function billOne(array $subscription, DateTimeImmutable $now): string
    {
        $tenantId = (int) $subscription['tenant_id'];
        $planId = isset($subscription['plan_id']) ? (int) $subscription['plan_id'] : 0;

        if ($planId === 0) {
            // Subscribed to nothing. Not an error — a tenant can be `active`
            // with no plan on a sovereign deployment that bills out of band.
            return 'skipped';
        }

        $periodEnd = $this->periodEnd($subscription);
        if ($periodEnd === null) {
            return 'skipped';
        }

        $price = $this->activePriceFor($tenantId, $planId);
        if ($price === null) {
            $this->logger->warning('A subscription is due but its plan has no active price', [
                'tenant_id' => $tenantId,
                'plan_id' => $planId,
            ]);

            return 'unpriced';
        }

        $period = (string) $price['billing_period'];
        $periodStart = $this->previousPeriodStart($periodEnd, $period);
        $nextPeriodEnd = $this->advance($periodEnd, $period);

        // ONE TRANSACTION. Invoicing without advancing would re-bill the same
        // period next tick; advancing without invoicing would skip a month
        // silently, which is the worse of the two.
        $this->pdo->beginTransaction();

        try {
            $draft = $this->invoices->createDraft(
                $tenantId,
                (string) $price['currency'],
                planId: $planId,
                periodStart: $periodStart->format('Y-m-d'),
                periodEnd: $periodEnd->format('Y-m-d'),
            );

            $this->invoices->addLine(
                $tenantId,
                $draft,
                $this->lineDescription($subscription, $periodStart, $periodEnd),
                $this->quantityFor($tenantId, $price),
                (int) $price['unit_amount'],
                $this->taxRateFor($tenantId),
            );

            $allocated = $this->numbers->allocate(
                $tenantId,
                $this->setting($tenantId, SettingsRegistry::BILLING_INVOICE_NUMBER_FORMAT, 'INV-{YYYY}-{SEQ:5}'),
                $this->setting($tenantId, SettingsRegistry::BILLING_INVOICE_NUMBER_SCOPE, InvoiceNumberAllocator::SCOPE_SHARED),
                $this->setting($tenantId, SettingsRegistry::BILLING_INVOICE_NUMBER_RESET, InvoiceNumberAllocator::RESET_YEARLY),
                $now,
            );

            $this->invoices->issue(
                $tenantId,
                $draft,
                $allocated['series'],
                $allocated['number'],
                $now,
                $now->modify('+' . $this->paymentTermsDays($tenantId) . ' days'),
                seller: $this->sellerFor($tenantId),
                buyer: $this->buyerFor($tenantId),
                taxRateBp: $this->taxRateFor($tenantId),
                taxLabel: $this->setting($tenantId, SettingsRegistry::BILLING_TAX_LABEL, ''),
                taxInclusive: $this->setting($tenantId, SettingsRegistry::BILLING_TAX_INCLUSIVE, 'false') === 'true',
            );

            $this->subscriptions->setSubscription($tenantId, [
                'current_period_end' => $nextPeriodEnd->format('Y-m-d H:i:s'),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return 'invoiced';
    }

    /**
     * Subscriptions whose period has ended and which are still being billed.
     *
     * CANCELED AND EXPIRED ARE EXCLUDED. Billing somebody who has left is the
     * single most damaging thing this run could do, and it is the one that
     * would go unnoticed longest — the invoice looks exactly like every other.
     *
     * `past_due` is INCLUDED, deliberately. A tenant who has not paid last
     * month still owes this month; stopping the meter because they are behind
     * would quietly forgive the debt the dunning machine is chasing them for.
     *
     * @return list<array<string, mixed>>
     *
     * @tenant-guard-ignore: the billing run has no tenant context by design —
     * it is the job that FINDS which tenants are due. Every write that follows
     * binds the tenant this returns.
     */
    private function dueSubscriptions(DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tenant_id, plan_id, status, current_period_end
               FROM tenant_plan
              WHERE status IN (:trialing, :active, :past_due)
                AND current_period_end IS NOT NULL
                AND current_period_end <= :now
                AND tenant_id <> :system
              ORDER BY current_period_end ASC, tenant_id ASC
              LIMIT :limit'
        );
        $statement->bindValue(':trialing', SubscriptionService::STATUS_TRIALING);
        $statement->bindValue(':active', SubscriptionService::STATUS_ACTIVE);
        $statement->bindValue(':past_due', SubscriptionService::STATUS_PAST_DUE);
        $statement->bindValue(':now', $now->format('Y-m-d H:i:s'));
        // The operator's own tenant is never billed by the operator.
        $statement->bindValue(':system', SubscriptionService::SYSTEM_TENANT_ID, PDO::PARAM_INT);
        $statement->bindValue(':limit', self::MAX_PER_RUN, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * The plan's active price in the tenant's billing currency.
     *
     * NO FALLBACK TO ANOTHER CURRENCY. A plan priced only in dollars cannot
     * bill a tenant configured in dinars, and quietly charging them the dollar
     * figure labelled JOD would be wrong by roughly a factor of one and a half
     * — an error that looks like a price rise rather than a bug.
     *
     * @return array<string, mixed>|null
     */
    private function activePriceFor(int $tenantId, int $planId): ?array
    {
        $currency = strtoupper($this->setting($tenantId, SettingsRegistry::BILLING_DEFAULT_CURRENCY, 'JOD'));

        $statement = $this->pdo->prepare(
            'SELECT * FROM plan_prices
              WHERE plan_id = :plan_id AND currency = :currency AND is_active = :on
              ORDER BY is_per_seat ASC, id ASC
              LIMIT 1'
        );
        $statement->bindValue(':plan_id', $planId, PDO::PARAM_INT);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':on', true, PDO::PARAM_BOOL);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * How many units to bill: seats for a per-seat price, one otherwise.
     *
     * Counted at INVOICE time rather than continuously, because a per-seat
     * price bills for the seats in use when the period closed — a tenant that
     * added ten people on the last day pays for them, and one that removed ten
     * on the first day does not keep paying.
     *
     * @param array<string, mixed> $price
     */
    private function quantityFor(int $tenantId, array $price): int
    {
        if (!$this->isTrue($price['is_per_seat'] ?? false)) {
            return 1;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT profile_id) FROM memberships
              WHERE tenant_id = :tenant_id AND status = :active'
        );
        $statement->execute([':tenant_id' => $tenantId, ':active' => 'active']);

        // At least one: a tenant with no active members still has a
        // subscription, and a zero-quantity line is refused by the schema.
        return max(1, (int) $statement->fetchColumn());
    }

    /** @param array<string, mixed> $subscription */
    private function lineDescription(
        array $subscription,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): string {
        $planName = $this->planName((int) ($subscription['plan_id'] ?? 0));

        // A SNAPSHOT, written once and never joined for again. "Professional
        // plan, March 2026" has to keep saying that after the plan is renamed.
        return sprintf(
            '%s — %s to %s',
            $planName,
            $periodStart->format('j M Y'),
            $periodEnd->format('j M Y'),
        );
    }

    private function planName(int $planId): string
    {
        if ($planId === 0) {
            return 'Subscription';
        }

        $statement = $this->pdo->prepare('SELECT name FROM plans WHERE id = :id');
        $statement->execute([':id' => $planId]);
        $name = $statement->fetchColumn();

        return is_string($name) && $name !== '' ? $name : 'Subscription';
    }

    /** @param array<string, mixed> $subscription */
    private function periodEnd(array $subscription): ?DateTimeImmutable
    {
        $raw = $subscription['current_period_end'] ?? null;

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function advance(DateTimeImmutable $from, string $billingPeriod): DateTimeImmutable
    {
        return match ($billingPeriod) {
            'year' => $from->modify('+1 year'),
            // A one-off price has no next period; the subscription stops
            // renewing rather than being billed again next month.
            'once' => $from->modify('+100 years'),
            default => $from->modify('+1 month'),
        };
    }

    private function previousPeriodStart(DateTimeImmutable $end, string $billingPeriod): DateTimeImmutable
    {
        return match ($billingPeriod) {
            'year' => $end->modify('-1 year'),
            'once' => $end,
            default => $end->modify('-1 month'),
        };
    }

    /** @return array{name?: string, address?: string, tax_id?: string} */
    private function sellerFor(int $tenantId): array
    {
        return array_filter([
            'name' => $this->setting($tenantId, SettingsRegistry::BILLING_SELLER_NAME, ''),
            'address' => $this->setting($tenantId, SettingsRegistry::BILLING_SELLER_ADDRESS, ''),
            'tax_id' => $this->setting($tenantId, SettingsRegistry::BILLING_SELLER_TAX_ID, ''),
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * The buyer, read ONCE here and then frozen onto the invoice.
     *
     * This is the only place the tenant's current name is consulted, and it is
     * consulted at issue time on purpose — the invoice keeps what it was given.
     *
     * @return array{name?: string, address?: string, tax_id?: string}
     */
    private function buyerFor(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT name FROM tenants WHERE id = :id');
        $statement->execute([':id' => $tenantId]);
        $name = $statement->fetchColumn();

        return is_string($name) && $name !== '' ? ['name' => $name] : [];
    }

    private function taxRateFor(int $tenantId): int
    {
        return (int) $this->setting($tenantId, SettingsRegistry::BILLING_TAX_RATE_BP, '0');
    }

    private function paymentTermsDays(int $tenantId): int
    {
        return max(0, (int) $this->setting($tenantId, SettingsRegistry::BILLING_PAYMENT_TERMS_DAYS, '14'));
    }

    /**
     * A setting as it applies to THIS tenant: its override, else the global
     * default, else the registry's.
     *
     * The three-layer resolution is the whole reason these are settings rather
     * than constants — a white-label deployment has tenants in different tax
     * jurisdictions, invoicing under different names.
     */
    private function setting(int $tenantId, string $key, string $fallback): string
    {
        $effective = $this->settings->effective($tenantId);
        $value = $effective[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return SettingsRegistry::defaultFor($key) ?: $fallback;
    }

    /** PostgreSQL hands back 'f' for false, and `(bool) 'f'` is TRUE in PHP. */
    private function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['f', 'false', '0', ''], true);
    }
}
