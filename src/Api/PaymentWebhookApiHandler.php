<?php

declare(strict_types=1);

namespace Whity\Api;

use DateTimeImmutable;
use Whity\Core\Billing\DunningService;
use Whity\Core\Billing\PaymentReconciler;
use Whity\Core\Payment\PaymentProviderException;
use Whity\Core\Payment\PaymentProviderRegistry;
use Whity\Core\Payment\WebhookVerificationException;
use Whity\Core\Request;
use Whity\Core\Response;
use Psr\Log\LoggerInterface;

/**
 * Where a provider tells us money moved.
 *
 *   POST /api/payments/webhook/{provider}
 *
 * THIS ENDPOINT MUST NOT BE BEHIND THE PAYMENT WALL, and the reason is a
 * deadlock rather than a preference. The wall returns 402 for a tenant that has
 * not paid. If it also guarded this route, a locked tenant's payment
 * confirmation would be refused — so the payment that would unlock them can
 * never be recorded, and they stay locked forever having paid. The route is
 * registered outside the wall deliberately, and this comment is here because
 * the mistake looks like tightening security.
 *
 * IT IS ALSO UNAUTHENTICATED, necessarily: a bank cannot hold a session. What
 * makes it safe is the signature, checked inside
 * {@see \Whity\Core\Payment\PaymentProviderAdapter::translateWebhook()} before
 * anything is parsed — and, structurally, the fact that there is no way to get
 * events out of a payload without that check having happened.
 *
 * IT ANSWERS 200 TO A DUPLICATE. Providers retry until they get a success, and
 * a redelivery is not an error — it is the system working. Answering 409 would
 * make a provider retry forever and eventually disable the endpoint.
 *
 * IT ANSWERS 200 TO A CALLBACK IT DOES NOT ACT ON, for the same reason:
 * heartbeats and event types we ignore are not failures.
 *
 * IT ANSWERS 400 TO AN UNVERIFIABLE PAYLOAD, and deliberately not 500. A 500
 * invites the provider to retry a forgery indefinitely; a 400 tells it, and
 * anybody probing the endpoint, that the payload was rejected on its merits.
 */
final class PaymentWebhookApiHandler
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentReconciler $reconciler,
        private readonly DunningService $dunning,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function receive(Request $request, array $params): Response
    {
        $providerName = (string) ($params['provider'] ?? '');

        // `get()`, not `getUsable()`. A callback must reach its adapter whether
        // or not the rail is currently OFFERED to customers: payments started
        // before an operator switched a rail off still settle, and refusing
        // them would lose money that has already moved.
        if (!$this->providers->has($providerName)) {
            return Response::error('Unknown payment provider', 404);
        }

        $adapter = $this->providers->get($providerName);
        $rawBody = $request->getBody();

        try {
            // Verification happens in here, first, and cannot be skipped.
            $events = $adapter->translateWebhook($rawBody, $request->getHeaders());
        } catch (WebhookVerificationException) {
            // Nothing about WHY is returned: telling an attacker which part of
            // their forgery failed is a free oracle. The log carries the detail.
            $this->logger->warning('Rejected an unverifiable payment callback', [
                'provider' => $providerName,
                'bytes' => strlen($rawBody),
            ]);

            return Response::error('Callback could not be verified', 400);
        } catch (PaymentProviderException $e) {
            $this->logger->error('A verified payment callback could not be understood', [
                'provider' => $providerName,
                'reason' => $e->getMessage(),
            ]);

            // 422, not 500: the payload is authentic and we cannot read it,
            // which is our problem to fix and not something to retry forever.
            return Response::error('Callback could not be understood', 422);
        }

        $now = $this->now();
        $applied = 0;
        $settledTenants = [];

        foreach ($events as $event) {
            $result = $this->reconciler->apply($event, $now);

            if ($result['settled'] === true && $result['invoice_id'] !== null) {
                $applied++;
                $settledTenants[] = $this->tenantOf($result);
            }
        }

        // A payment may be what un-locks somebody, so this is checked here
        // rather than left to the next dunning run — a customer who pays should
        // not wait until tomorrow's cron to get back in.
        foreach (array_unique(array_filter($settledTenants)) as $tenantId) {
            if ($this->dunning->restoreIfNothingOverdue($tenantId, $now)) {
                $this->logger->info('Restored access after payment', ['tenant_id' => $tenantId]);
            }
        }

        // Always 200 for an authentic payload — including one that changed
        // nothing. See the class docblock.
        return Response::json([
            'data' => ['received' => count($events), 'settled' => $applied],
        ]);
    }

    /**
     * @param array{outcome: string, invoice_id: ?int, settled: bool} $result
     */
    private function tenantOf(array $result): ?int
    {
        return $result['invoice_id'] === null
            ? null
            : $this->reconciler->tenantForInvoice($result['invoice_id']);
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
