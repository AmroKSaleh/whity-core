<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Billing\External;

use PHPUnit\Framework\TestCase;
use Whity\Core\Billing\External\BillingActor;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\BillingTransport;
use Whity\Core\Billing\External\HttpBillingPortal;

/**
 * What the portal actually puts on the wire.
 *
 * ── The gap this fills ─────────────────────────────────────────────────────
 *
 * There was no test here at all, and that showed up the honest way: mutation
 * testing removed the actor header from two calls and nothing failed. The
 * portal ACCEPTED an actor and could have dropped it on the floor, which is the
 * precise failure the attribution seam exists to prevent — the call succeeds,
 * the change happens, and only the audit line on somebody else's dashboard is
 * wrong. Nobody reads that until they need it.
 *
 * The idempotency key had the same exposure and the same absence of a test. It
 * is the thing standing between a retried tier move and a customer charged
 * twice, and nothing checked that it left the building.
 *
 * So these assert on the HEADERS HANDED TO THE TRANSPORT, not on the call
 * having been made. "It was sent" and "it arrived in the request" are different
 * claims, and only the second one is worth anything.
 */
final class HttpBillingPortalRequestTest extends TestCase
{
    private RecordingTransport $transport;
    private HttpBillingPortal $portal;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->portal = new HttpBillingPortal(
            $this->transport,
            'https://pay.example.test',
            'test-key'
        );
    }

    // ── The actor ───────────────────────────────────────────────────────────

    public function testAQuantityChangeCarriesItsActor(): void
    {
        $this->portal->changeQuantity(BillingActor::job('device-quantity-sync'), 'sub_1', 12);

        self::assertSame('job:device-quantity-sync', $this->transport->headers['X-Pay-Actor'] ?? null);
    }

    public function testAPlanChangeCarriesItsActor(): void
    {
        $this->portal->changePlan(BillingActor::person(412), 'sub_1', 'price_1');

        self::assertSame('profile:412', $this->transport->headers['X-Pay-Actor'] ?? null);
    }

    public function testACheckoutCarriesItsActor(): void
    {
        // A checkout reply needs BOTH a url and a reference or the portal
        // refuses it — correctly: there is nowhere to send the payer otherwise.
        $this->transport->body = '{"url":"https://pay.example.test/c/1","reference":"cs_1"}';

        $this->portal->startCheckout(
            BillingActor::person(412),
            'tenant-7',
            'price_1',
            'https://app.example.test/return'
        );

        self::assertSame('profile:412', $this->transport->headers['X-Pay-Actor'] ?? null);
    }

    // ── The idempotency key, which had the same silent exposure ─────────────

    /**
     * A RETRY THAT LOSES ITS KEY IS A SECOND CHARGE. The key is what makes
     * repeating a tier move safe; if it never reaches the request, the safety is
     * theoretical and the failure only appears on somebody's invoice.
     */
    public function testAPlanChangeCarriesItsIdempotencyKey(): void
    {
        $this->portal->changePlan(
            BillingActor::person(412),
            'sub_1',
            'price_1',
            BillingPortal::PRORATION_NONE,
            false,
            'tier-move:7:1:2'
        );

        self::assertSame('tier-move:7:1:2', $this->transport->headers['Idempotency-Key'] ?? null);
    }

    /** Both ride together; adding one must not have displaced the other. */
    public function testTheActorAndTheKeyBothSurvive(): void
    {
        $this->portal->changePlan(
            BillingActor::person(412),
            'sub_1',
            'price_1',
            BillingPortal::PRORATION_NONE,
            false,
            'tier-move:7:1:2'
        );

        self::assertSame('profile:412', $this->transport->headers['X-Pay-Actor'] ?? null);
        self::assertSame('tier-move:7:1:2', $this->transport->headers['Idempotency-Key'] ?? null);
    }

    public function testNoKeyMeansNoHeaderRatherThanAnEmptyOne(): void
    {
        $this->portal->changePlan(BillingActor::person(412), 'sub_1', 'price_1');

        self::assertArrayNotHasKey('Idempotency-Key', $this->transport->headers);
    }

    // ── The credential is not displaceable ──────────────────────────────────

    /**
     * A PER-CALL HEADER MUST NOT BE ABLE TO REPLACE THE CREDENTIAL. The merge is
     * written to refuse an overwrite; this is the test that says so, because the
     * consequence of getting it wrong is an unauthenticated request that reads
     * as a refusal rather than as a bug.
     */
    public function testTheAuthorizationHeaderSurvivesEveryCall(): void
    {
        $this->portal->changeQuantity(BillingActor::job('device-quantity-sync'), 'sub_1', 12);

        self::assertSame('Bearer test-key', $this->transport->headers['Authorization'] ?? null);
    }

    public function testTheRequestGoesWhereItShould(): void
    {
        $this->portal->changeQuantity(BillingActor::job('device-quantity-sync'), 'sub_1', 12);

        self::assertSame('POST', $this->transport->method);
        self::assertSame('https://pay.example.test/v1/subscriptions/sub_1/quantity', $this->transport->url);
    }
}

/**
 * A transport that answers blandly and remembers what it was asked to send.
 *
 * The portal's job here is building a request; what comes back only has to be
 * parseable enough not to throw, so the assertions can be about the request.
 */
final class RecordingTransport implements BillingTransport
{
    public string $method = '';
    public string $url = '';
    /** @var array<string, string> */
    public array $headers = [];
    public ?string $sentBody = null;
    public string $body = '{}';

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->sentBody = $body;

        return ['status' => 200, 'body' => $this->body];
    }
}
