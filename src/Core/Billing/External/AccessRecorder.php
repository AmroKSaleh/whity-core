<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Subscription\SubscriptionException;
use Whity\Core\Subscription\SubscriptionService;

/**
 * Writes the billing service's answer into the state the payment wall reads.
 *
 * NO SECOND MECHANISM. whity-core already has a wall: `tenant_plan` carries a
 * status and {@see SubscriptionService::decide()} turns it into allow / warn /
 * block. That machinery is finished and tested. The only thing it lacked was
 * something authoritative to write the status, which is all this does.
 *
 * ── The part that is easy to get wrong ──────────────────────────────────────
 *
 * `decide()` derives access from the status word, through its own table of which
 * words are current. The billing service derives `has_access` from ITS table.
 * Two tables, maintained by two teams, consulted about the same tenant — and the
 * moment they disagree, the product does something the customer was not told.
 *
 * So the status written here is chosen so that the LOCAL reading of it agrees
 * with `has_access`, which is the authority. Normally that is simply the status
 * the service reported, because the vocabularies happen to match. When it is not
 * — an unrecognised word, or a word whose local meaning contradicts the answer —
 * `has_access` wins and a status that expresses it is written instead.
 *
 * That is not defensive padding. `past_due` is the case it exists for: it grants
 * access deliberately, so that a card which expired over a weekend does not lock
 * a paying customer out while dunning still has days of retries left. If that
 * policy is ever tuned on their side, this keeps agreeing with them rather than
 * enforcing a copy of the old rule.
 *
 * ── What is deliberately NOT written ────────────────────────────────────────
 *
 * `grace_until` is left exactly as found. Setting it from `access_until` would
 * lock a `past_due` tenant at the end of their period — the precise customer the
 * grace is for, at the precise moment it is meant to help them.
 *
 * `plan_id` is never cleared, only ever set. A tenant whose subscription lapsed
 * keeps the record of what they had; the wall is what stops them using it.
 * Clearing it would silently strip entitlements by a route nobody would think to
 * look at, and would lose the only evidence of what they were entitled to.
 */
final class AccessRecorder
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanRepository $plans,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Record one authoritative answer against one tenant.
     *
     * @return bool Whether the tenant may use paid features — the same answer
     *              that was written, returned so a caller need not read it back.
     */
    public function record(int $tenantId, AccessSnapshot $snapshot): bool
    {
        // The operator is never subscribed and never walled; SubscriptionService
        // refuses it outright, so asking would only produce an exception.
        if ($tenantId === SubscriptionService::SYSTEM_TENANT_ID) {
            return true;
        }

        // ── NEVER PAID IS NOT THE SAME AS LAPSED ────────────────────────────
        //
        // `decide()` treats a tenant with NO recorded status as "not billed" and
        // never blocks them. That is what keeps free-tier and self-hosted
        // tenants working, and it is a different state from "had a subscription
        // and lost it", which the wall does enforce.
        //
        // Writing a lapsed status for a tenant the billing service has simply
        // never heard of would collapse those two states and WALL A TENANT WHO
        // WAS NOT WALLED BEFORE — triggered by nothing more than someone opening
        // the billing page, since the return handler records what it reads.
        //
        // So an answer carrying no relationship at all is only recorded against
        // a tenant who already has one, where it genuinely means "and now it is
        // gone". Otherwise nothing is written and the tenant stays exactly as
        // unbilled as they were.
        if ($this->isNoRelationship($snapshot) && !$this->hasRecordedSubscription($tenantId)) {
            return false;
        }

        $status = $this->statusAgreeingWith($snapshot);

        try {
            $this->subscriptions->setSubscription($tenantId, [
                'status' => $status,
                'current_period_end' => $snapshot->accessUntil,
                'external_ref' => $snapshot->subscriptionRef,
            ]);
        } catch (SubscriptionException $e) {
            // A status this deployment does not know cannot be written, and
            // failing silently here would leave the wall reading a stale answer
            // for as long as nobody noticed.
            $this->logger->error('Could not record billing access for a tenant', [
                'tenant_id' => $tenantId,
                'reason' => $e->getMessage(),
            ]);

            return $snapshot->hasAccess;
        }

        $this->applyPlan($tenantId, $snapshot);

        return $snapshot->hasAccess;
    }

    /**
     * Whether this answer describes no subscription at all, rather than a
     * subscription that has ended.
     *
     * The billing service answers 200 with everything null for a subject it has
     * never transacted with — the ordinary state of a tenant before their first
     * purchase. An ENDED subscription looks different: it carries a status word,
     * and usually a subscription handle.
     */
    private function isNoRelationship(AccessSnapshot $snapshot): bool
    {
        return !$snapshot->hasAccess
            && $snapshot->status === null
            && $snapshot->subscriptionRef === null;
    }

    private function hasRecordedSubscription(int $tenantId): bool
    {
        $current = $this->subscriptions->getSubscription($tenantId) ?? [];

        return ($current['status'] ?? null) !== null
            || ($current['external_ref'] ?? null) !== null;
    }

    /**
     * A local status word whose local meaning is the authoritative answer.
     *
     * Prefers the reported word so a human sees the real reason on screen, and
     * substitutes one only when keeping it would make the wall disagree with the
     * service.
     */
    private function statusAgreeingWith(AccessSnapshot $snapshot): string
    {
        $reported = $snapshot->status;
        $known = is_string($reported) && in_array($reported, SubscriptionService::STATUSES, true);

        if (!$known) {
            if ($reported !== null) {
                // Worth a line: it means the other side grew a status word we do
                // not have, and somebody should decide what it means here rather
                // than leaving this fallback to do it forever.
                $this->logger->info('Billing service reported an unrecognised subscription status', [
                    'status' => $reported,
                    'has_access' => $snapshot->hasAccess,
                ]);
            }

            return $snapshot->hasAccess
                ? SubscriptionService::STATUS_ACTIVE
                : SubscriptionService::STATUS_EXPIRED;
        }

        $grantsLocally = in_array($reported, [
            SubscriptionService::STATUS_ACTIVE,
            SubscriptionService::STATUS_TRIALING,
            // past_due grants locally too — lenient while grace_until is unset,
            // which is exactly how it is left here.
            SubscriptionService::STATUS_PAST_DUE,
        ], true);

        if ($grantsLocally === $snapshot->hasAccess) {
            return $reported;
        }

        // They disagree. The service is the authority on whether this customer
        // may use what they paid for, so its answer is what gets written — and
        // the disagreement is recorded, because it means one of the two tables
        // has moved and somebody needs to know which.
        $this->logger->warning('Billing status and access answer disagree; recording the access answer', [
            'reported_status' => $reported,
            'has_access' => $snapshot->hasAccess,
        ]);

        return $snapshot->hasAccess
            ? SubscriptionService::STATUS_ACTIVE
            : SubscriptionService::STATUS_EXPIRED;
    }

    /**
     * Point the tenant at the plan they are paying for, so entitlements follow.
     *
     * The join is by NAME: the billing service's plan code against
     * `plans.plan_key`. A code with no matching plan leaves the assignment
     * untouched — an operator has added a plan on one side and not the other,
     * and downgrading a paying tenant because of a naming mismatch would be a
     * worse answer than leaving them where they are and saying so.
     */
    private function applyPlan(int $tenantId, AccessSnapshot $snapshot): void
    {
        if ($snapshot->planCode === null) {
            return;
        }

        $plan = $this->plans->findByKey($snapshot->planCode);
        if ($plan === null) {
            $this->logger->warning('Billing service named a plan this deployment does not have', [
                'tenant_id' => $tenantId,
                'plan_key' => $snapshot->planCode,
            ]);

            return;
        }

        $current = $this->plans->getTenantPlan($tenantId);
        if (($current['plan_id'] ?? null) === (int) $plan['id']) {
            return;
        }

        // assigned_by is null: nobody in this deployment made this decision — the
        // tenant did, by paying. Recording an operator id would name someone who
        // was not involved.
        $this->plans->setTenantPlan($tenantId, (int) $plan['id'], null);
    }
}
