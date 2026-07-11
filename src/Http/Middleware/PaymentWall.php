<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Subscription\SubscriptionDecision;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * The payment wall (WC-billing).
 *
 * Runs AFTER EnforceTenantIsolation (so TenantContext is populated) and asks
 * {@see SubscriptionService::decide()} whether a LAPSED tenant's request may
 * proceed. On a block it short-circuits with 402 Payment Required + an
 * `X-Subscription-Status` header (and an optional `Link` to the billing page);
 * on a warn it lets the request through but stamps the same status header so the
 * UI can nudge; otherwise it is transparent.
 *
 * Never-block invariants (all belt-and-suspenders on top of the ones baked into
 * SubscriptionService::decide):
 *   - Public / unauthenticated routes: TenantContext is unresolved (null) → pass.
 *   - The SYSTEM tenant (0): pass — the operator is never walled.
 *   - The billing / subscription-management routes ($exemptPrefixes): pass, so an
 *     admin can always reach the page to pay / upgrade even when lapsed. Payment
 *     can happen externally, so the upgrade path must never be behind the wall.
 *
 * A master `$enabled` flag (env BILLING_WALL_ENABLED) makes the whole layer a
 * pass-through — an operator emergency off switch. Worker-safe: holds only
 * boot-scoped config; all state is read per request from the subscription store.
 */
final class PaymentWall
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private SubscriptionService $subscriptions;
    private bool $enabled;
    /** @var list<string> Path prefixes (full versioned, e.g. /api/v1/subscription) never walled. */
    private array $exemptPrefixes;
    private ?string $billingUrl;
    private LoggerInterface $logger;

    /**
     * @param list<string> $exemptPrefixes Billing/subscription-management route prefixes always allowed.
     * @param string|null  $billingUrl     Optional deployment billing URL for the 402 `Link` header.
     */
    public function __construct(
        SubscriptionService $subscriptions,
        bool $enabled = true,
        array $exemptPrefixes = [],
        ?string $billingUrl = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->subscriptions = $subscriptions;
        $this->enabled = $enabled;
        $this->exemptPrefixes = array_values($exemptPrefixes);
        $this->billingUrl = ($billingUrl !== null && $billingUrl !== '') ? $billingUrl : null;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response
    {
        if (!$this->enabled) {
            return $next($request);
        }

        $tenantId = TenantContext::getTenantId();
        // Public / unauthenticated (tenant unresolved) or the system tenant → never wall.
        if ($tenantId === null || $tenantId === SubscriptionService::SYSTEM_TENANT_ID) {
            return $next($request);
        }
        // Billing / subscription-management routes are always reachable so an admin
        // can pay / upgrade even when the tenant is lapsed.
        if ($this->isExempt($request)) {
            return $next($request);
        }

        $isWrite = in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true);
        $decision = $this->subscriptions->decide($tenantId, $isWrite);

        if (!$decision->allowed) {
            $this->logBlock($request, $tenantId, $decision);

            return Response::error('Payment Required', 402, ['status' => $decision->status])
                ->withHeaders($this->blockHeaders($decision));
        }

        $response = $next($request);

        // Allowed-but-lapsed → stamp an advisory header so the UI can nudge.
        if ($decision->warn && $decision->status !== null) {
            return $response->withHeaders(['X-Subscription-Status' => $decision->status]);
        }

        return $response;
    }

    private function isExempt(Request $request): bool
    {
        if ($this->exemptPrefixes === []) {
            return false;
        }
        $path = parse_url($request->getPath(), PHP_URL_PATH);
        $path = is_string($path) ? $path : $request->getPath();

        foreach ($this->exemptPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function blockHeaders(SubscriptionDecision $decision): array
    {
        $headers = [];
        if ($decision->status !== null) {
            $headers['X-Subscription-Status'] = $decision->status;
        }
        if ($this->billingUrl !== null) {
            $headers['Link'] = '<' . $this->billingUrl . '>; rel="payment"';
        }

        return $headers;
    }

    private function logBlock(Request $request, int $tenantId, SubscriptionDecision $decision): void
    {
        $path = parse_url($request->getPath(), PHP_URL_PATH);

        $this->logger->warning('Payment wall blocked request', [
            'event'     => 'payment_wall.blocked',
            'tenant_id' => $tenantId,
            'status'    => $decision->status,
            'mode'      => $decision->mode,
            'method'    => $request->getMethod(),
            'path'      => is_string($path) ? $path : $request->getPath(),
        ]);
    }
}
