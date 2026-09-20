<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Affiliate;

use DateTimeImmutable;
use Whity\Core\Affiliate\CombinedPaymentSources;
use Whity\Core\Affiliate\ReferredPayment;
use Whity\Core\Affiliate\ReferredPaymentSource;
use Whity\Core\Affiliate\ReferredPaymentSourceException;
use PHPUnit\Framework\TestCase;

/**
 * Reading both places a referred workspace's money can be, as one history.
 *
 * ── The case this exists for ───────────────────────────────────────────────
 *
 * A workspace that started on locally raised invoices and later moved to the
 * billing service has real revenue in both, earned by the same referrer. Picking
 * one source per deployment — or per tenant, from whichever flag says who bills
 * them — would stop paying at the moment of the move, and the only person
 * positioned to notice is the affiliate reconciling their own statement.
 *
 * ── And the rule that reads backwards until you know why ───────────────────
 *
 * When one source fails, this REFUSES THE WHOLE HISTORY rather than returning
 * what it managed to read. That is the opposite of the usual instinct, and the
 * reason is the earning window: the sweep opens it from the oldest payment it is
 * handed, and writes that date once, permanently. Hand it half a history and it
 * freezes the window on a date that is merely the oldest thing that was
 * reachable — and no later, healthy sweep will ever correct it.
 *
 * Nothing is lost by waiting. The next successful pass re-reads everything and
 * pays it all.
 */
final class CombinedPaymentSourcesTest extends TestCase
{
    public function testPaymentsFromBothSourcesAreReturnedTogether(): void
    {
        $combined = new CombinedPaymentSources(
            new StubSource([$this->payment('INV-1', ReferredPayment::SOURCE_INVOICE)]),
            new StubSource([$this->payment('EXT-1', ReferredPayment::SOURCE_EXTERNAL)]),
        );

        $references = array_map(
            static fn (ReferredPayment $p): string => $p->reference,
            $combined->paymentsFor(1)
        );

        self::assertSame(['INV-1', 'EXT-1'], $references);
    }

    /**
     * A WORKSPACE THAT MOVED BETWEEN BILLING SYSTEMS KEEPS EARNING ACROSS THE
     * MOVE. Local invoices from before, receipts from after, one referrer, one
     * continuous history.
     */
    public function testAWorkspaceThatMovedBillingSystemsKeepsItsWholeHistory(): void
    {
        $combined = new CombinedPaymentSources(
            new StubSource([
                $this->payment('INV-1', ReferredPayment::SOURCE_INVOICE, '2026-01-01'),
                $this->payment('INV-2', ReferredPayment::SOURCE_INVOICE, '2026-02-01'),
            ]),
            new StubSource([
                $this->payment('EXT-1', ReferredPayment::SOURCE_EXTERNAL, '2026-03-01'),
            ]),
        );

        self::assertCount(3, $combined->paymentsFor(1));
    }

    /** Every source is asked about the same workspace. */
    public function testEverySourceIsAskedAboutTheSameWorkspace(): void
    {
        $local = new StubSource([]);
        $external = new StubSource([]);

        (new CombinedPaymentSources($local, $external))->paymentsFor(42);

        self::assertSame([42], $local->asked);
        self::assertSame([42], $external->asked);
    }

    public function testNoSourcesMeansNoPayments(): void
    {
        self::assertSame([], (new CombinedPaymentSources())->paymentsFor(1));
    }

    public function testSourcesWithNothingToSayProduceNothing(): void
    {
        $combined = new CombinedPaymentSources(new StubSource([]), new StubSource([]));

        self::assertSame([], $combined->paymentsFor(1));
    }

    // ── When one source is down ─────────────────────────────────────────────

    /**
     * A PARTIAL HISTORY IS REFUSED, NOT RETURNED. See the class docblock: the
     * earning window is frozen from the oldest payment, once, so a gap now is a
     * wrong window for the life of the referral.
     */
    public function testOneFailingSourceRefusesTheWholeHistory(): void
    {
        $combined = new CombinedPaymentSources(
            new StubSource([$this->payment('INV-1', ReferredPayment::SOURCE_INVOICE)]),
            new FailingSource(),
        );

        $this->expectException(ReferredPaymentSourceException::class);
        $combined->paymentsFor(1);
    }

    /** Whichever order they are in. */
    public function testAFailingSourceFirstAlsoRefusesTheWholeHistory(): void
    {
        $combined = new CombinedPaymentSources(
            new FailingSource(),
            new StubSource([$this->payment('EXT-1', ReferredPayment::SOURCE_EXTERNAL)]),
        );

        $this->expectException(ReferredPaymentSourceException::class);
        $combined->paymentsFor(1);
    }

    /**
     * EVERY SOURCE IS STILL TRIED BEFORE GIVING UP, so an operator sees each one
     * that is down rather than only the first. Stopping at the first failure
     * would let one unreachable service hide that a second was unreachable too —
     * which turns one fix into two outages.
     */
    public function testASourceAfterAFailingOneIsStillAsked(): void
    {
        $after = new StubSource([]);
        $combined = new CombinedPaymentSources(new FailingSource(), $after);

        try {
            $combined->paymentsFor(1);
            self::fail('The combined source should have refused an incomplete history.');
        } catch (ReferredPaymentSourceException) {
            // Expected. The assertion is about what happened on the way.
        }

        self::assertSame([1], $after->asked, 'The second source was asked before the refusal.');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function payment(string $reference, string $source, string $paidAt = '2026-03-01'): ReferredPayment
    {
        return new ReferredPayment(
            $source,
            $reference,
            15000,
            'JOD',
            new DateTimeImmutable($paidAt),
        );
    }
}

/** A source with a fixed answer, which records who it was asked about. */
final class StubSource implements ReferredPaymentSource
{
    /** @var list<int> */
    public array $asked = [];

    /** @param list<ReferredPayment> $payments */
    public function __construct(private readonly array $payments)
    {
    }

    /** @return list<ReferredPayment> */
    public function paymentsFor(int $tenantId): array
    {
        $this->asked[] = $tenantId;

        return $this->payments;
    }
}

/** A source that cannot answer at all. */
final class FailingSource implements ReferredPaymentSource
{
    /** @return list<ReferredPayment> */
    public function paymentsFor(int $tenantId): array
    {
        throw new ReferredPaymentSourceException('This source is down.');
    }
}
