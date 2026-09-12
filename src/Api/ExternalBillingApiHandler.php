<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Auth\RoleChecker;
use Whity\Core\Billing\External\AccessRecorder;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\BillingSubject;
use Whity\Core\Billing\External\EventLedger;
use Whity\Core\Billing\External\WebhookVerifier;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Buying a subscription, and finding out whether one was bought.
 *
 * Three routes, and they are not alike.
 *
 * `checkout` and `returnFrom` are ordinary authenticated tenant actions.
 * `webhook` has no user behind it at all: the sender is a server that holds no
 * session and never will, so the signature is the credential — which is why it
 * is verified over the raw bytes before the payload is so much as decoded.
 *
 * ── The rule that shapes all three ──────────────────────────────────────────
 *
 * NOTHING THE CALLER SAYS GRANTS ACCESS. Not the `status` on the return URL,
 * which arrives in a browser the payer controls and which anyone can type. Not
 * the body of a notification, even a correctly signed one. Both are treated as
 * the same thing: a hint about WHICH tenant to go and ask about. The answer
 * always comes from asking the billing service directly.
 *
 * That costs one HTTP call per event and buys two things. Out-of-order delivery
 * stops mattering, because there is no ordering to get wrong — every event
 * results in "what is true now". And the notification payload never becomes a
 * write path, so the blast radius of a leaked signing secret is someone being
 * able to make us re-read our own state.
 */
final class ExternalBillingApiHandler
{
    /** Answers a signed sender understands: 2xx is "delivered", anything else retries. */
    private const ACK = ['received' => true];

    public function __construct(
        private readonly BillingPortal $portal,
        private readonly AccessRecorder $recorder,
        private readonly EventLedger $ledger,
        private readonly WebhookVerifier $verifier,
        private readonly SettingsService $settings,
        private readonly RoleChecker $roleChecker,
        private readonly PDO $pdo,
        private readonly string $appUrl,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // ── the tenant-facing surface ───────────────────────────────────────────

    /**
     * POST /api/v1/billing/checkout — somewhere to send this tenant to pay.
     *
     * The client names a PLAN, never a price on the billing service. Letting a
     * caller pass the other side's price identifier straight through would make
     * "which plan am I buying" a decision taken in the browser, and a tenant
     * could name the cheapest price for the most expensive plan.
     */
    public function checkout(Request $request): Response
    {
        $tenantId = $this->requireTenant($request, CorePermissions::BILLING_PAY);
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        if (!$this->portal->isConfigured()) {
            return Response::error('This deployment does not sell subscriptions.', 404);
        }

        $body = JsonBody::parsed($request);
        $planKey = $body['plan_key'] ?? null;
        if (!is_string($planKey) || trim($planKey) === '') {
            return Response::error('Name the plan to buy with plan_key.', 422);
        }

        $interval = $body['billing_period'] ?? 'month';
        $interval = is_string($interval) && $interval !== '' ? $interval : 'month';

        $priceRef = $this->externalPriceRef($tenantId, trim($planKey), $interval);
        if ($priceRef === null) {
            return Response::error('That plan cannot be bought on these terms.', 422);
        }

        try {
            $handoff = $this->portal->startCheckout(
                BillingSubject::forTenant($tenantId),
                $priceRef,
                $this->appUrl . '/billing/return',
                $this->appUrl . '/billing/plans',
            );
        } catch (BillingPortalException $e) {
            return $this->portalFailure($e, 'start a checkout', ['tenant_id' => $tenantId]);
        }

        // The reference is a capability token and is deliberately NOT returned:
        // the browser needs the destination, and nothing else here has to reach
        // it. The return redirect carries the reference back when it is needed.
        return Response::json(['data' => ['url' => $handoff->url]]);
    }

    /**
     * GET /api/v1/billing/return — the payer is back. Go and ask what happened.
     *
     * THE QUERY STRING IS EVIDENCE OF NOTHING. `?status=completed` can be typed
     * into an address bar by anyone who has read the documentation. It is used
     * for one thing — deciding which message to render — and even that is taken
     * from the billing service rather than the URL whenever it can be.
     */
    public function returnFrom(Request $request): Response
    {
        $tenantId = $this->requireTenant($request, CorePermissions::BILLING_VIEW);
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        // A DEPLOYMENT THAT SELLS NOTHING MUST NOT REVOKE ANYBODY. With no
        // billing service configured the portal answers, truthfully, "no
        // relationship" — and for a tenant who already has one recorded that
        // would read as "and now it is gone", silently walling a paying customer
        // because somebody cleared an environment variable. There is nothing to
        // confirm here, so nothing is read and nothing is written.
        if (!$this->portal->isConfigured()) {
            return Response::error('This deployment does not sell subscriptions.', 404);
        }

        $query = self::queryParams($request);
        $reference = $query['checkout'] ?? '';

        $checkoutStatus = null;
        // No second isConfigured() check — the guard above already returned.
        if ($reference !== '') {
            try {
                $checkoutStatus = $this->portal->checkoutStatus($reference);
            } catch (BillingPortalException $e) {
                // Not fatal. This only chooses wording; the access read below is
                // what decides anything, and it is asked independently.
                $this->logger->info('Could not read a checkout status on return', [
                    'tenant_id' => $tenantId,
                    'reason' => $e->reason,
                ]);
            }
        }

        try {
            $snapshot = $this->portal->accessFor(BillingSubject::forTenant($tenantId));
        } catch (BillingPortalException $e) {
            return $this->portalFailure($e, 'read access on return', ['tenant_id' => $tenantId]);
        }

        $this->recorder->record($tenantId, $snapshot);

        return Response::json(['data' => [
            'has_access' => $snapshot->hasAccess,
            'status' => $snapshot->status,
            'plan' => $snapshot->planCode,
            'access_until' => $snapshot->accessUntil,
            'cancel_at_period_end' => $snapshot->cancelAtPeriodEnd,
            // From the billing service when it could be reached, null otherwise
            // — never the query string's copy of it.
            'checkout_status' => $checkoutStatus,
        ]]);
    }

    // ── the machine-facing surface ──────────────────────────────────────────

    /**
     * POST /api/v1/billing/webhook — a notification that something changed.
     *
     * Exempt from user authentication because there is no user: a billing
     * service cannot hold a session. What makes it safe is the signature, and
     * the fact that the payload is never a write path — see the class docblock.
     *
     * ANSWERS 200 FOR ANYTHING IT HAS DELIBERATELY IGNORED, including events it
     * does not care about and subjects that are not tenants. A non-2xx tells the
     * sender to retry, and retrying will not make an event we chose to skip
     * become interesting — it would just burn the delivery budget that a real
     * failure needs.
     */
    public function webhook(Request $request): Response
    {
        // RAW BYTES, FIRST, BEFORE ANY PARSING. Re-encoding a decoded payload
        // reorders keys and changes escaping, so the bytes signed stop being the
        // bytes verified and every honest delivery fails.
        $raw = $request->getBody();

        if (!$this->verifier->verify(
            $raw,
            (string) ($request->getHeader('X-Pay-Signature') ?? ''),
            (string) ($request->getHeader('X-Pay-Timestamp') ?? ''),
        )) {
            // No detail. A verifier that explained which part failed would be a
            // tool for forging the next attempt.
            $this->logger->warning('Rejected an unverified billing notification', [
                'bytes' => strlen($raw),
            ]);

            return Response::error('Unauthorized', 401);
        }

        $eventId = (string) ($request->getHeader('X-Pay-Event-Id') ?? '');
        $payload = json_decode($raw, true);
        $type = is_array($payload) && is_string($payload['type'] ?? null) ? $payload['type'] : '';

        if (!$this->ledger->claim($eventId, $type)) {
            // Already seen, or unidentifiable. Either way this is a success from
            // the sender's point of view: the event has been dealt with.
            return Response::json(self::ACK);
        }

        // Only subscription changes matter. Payment events describe money, which
        // is not what whity-core is asking about — and acting on one would mean
        // deciding for ourselves what a payment implies about access, which is
        // exactly the reasoning the billing service owns.
        if ($type !== 'subscription.updated') {
            return Response::json(self::ACK);
        }

        $subjectRef = self::subjectFrom($payload);
        if ($subjectRef === null) {
            // A subscription event naming nobody. Nothing to act on, and
            // nothing a retry would improve.
            return Response::json(self::ACK);
        }

        $tenantId = BillingSubject::tenantIdFrom($subjectRef);
        if ($tenantId === null) {
            // A subject that is not a tenant of this deployment. Once seats or a
            // tenant's own customers can pay, this will be an ordinary event
            // about something else.
            return Response::json(self::ACK);
        }

        try {
            $snapshot = $this->portal->accessFor($subjectRef);
        } catch (BillingPortalException $e) {
            if ($e->isTransient()) {
                // Put the id back, or the retry we are about to ask for would be
                // recognised as a duplicate and dropped — and this tenant's
                // access would never be corrected.
                $this->ledger->release($eventId);

                return Response::error('Try again shortly.', 503);
            }

            $this->logger->error('A billing notification could not be acted on', [
                'tenant_id' => $tenantId,
                'reason' => $e->reason,
            ]);

            return Response::json(self::ACK);
        }

        $this->recorder->record($tenantId, $snapshot);

        return Response::json(self::ACK);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The billing service's handle for a plan on these terms, in the tenant's
     * currency, or null when this deployment does not sell it that way.
     */
    private function externalPriceRef(int $tenantId, string $planKey, string $interval): ?string
    {
        $currency = strtoupper($this->setting(
            $tenantId,
            SettingsRegistry::BILLING_DEFAULT_CURRENCY,
            'JOD'
        ));

        $statement = $this->pdo->prepare(
            'SELECT pp.external_ref
               FROM plan_prices pp
               JOIN plans p ON p.id = pp.plan_id
              WHERE p.plan_key = :plan_key
                AND pp.currency = :currency
                AND pp.billing_period = :billing_period
                AND pp.is_active = :on
                AND pp.external_ref IS NOT NULL
              ORDER BY pp.id ASC
              LIMIT 1'
        );
        $statement->bindValue(':plan_key', $planKey);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':billing_period', $interval);
        $statement->bindValue(':on', true, PDO::PARAM_BOOL);
        $statement->execute();

        $ref = $statement->fetchColumn();

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    private function setting(int $tenantId, string $key, string $fallback): string
    {
        $value = $this->settings->effective($tenantId)[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return SettingsRegistry::defaultFor($key) ?: $fallback;
    }

    /**
     * The subject a `subscription.updated` event is about.
     *
     * @param mixed $payload
     */
    private static function subjectFrom(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        $ref = $payload['data']['subscription']['customer']['external_id'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /**
     * Map a portal failure onto an answer, without leaking its text.
     *
     * @param array<string, mixed> $context
     */
    private function portalFailure(BillingPortalException $e, string $doing, array $context): Response
    {
        $this->logger->error('Could not ' . $doing, $context + ['reason' => $e->reason]);

        return match ($e->reason) {
            BillingPortalException::REASON_NOT_CONFIGURED
                => Response::error('This deployment does not sell subscriptions.', 404),
            BillingPortalException::REASON_UNREACHABLE
                => Response::error('Billing is temporarily unavailable. Please try again shortly.', 503),
            default
                => Response::error('Billing could not complete that request.', 502),
        };
    }

    /**
     * Permission + tenant, or the refusal to return.
     *
     * Re-checked here even though the router is given the same permission: a
     * route registered without one, or with the wrong one, would otherwise be a
     * silent hole, and this is the surface where that would be most expensive.
     * Mirrors {@see BillingApiHandler::authorize()}.
     *
     * @return int|Response
     */
    private function requireTenant(Request $request, string $permission): int|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return $tenantId;
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), path last — the precedence the other handlers here use.
     *
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }
}
