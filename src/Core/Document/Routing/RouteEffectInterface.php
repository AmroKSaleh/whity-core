<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * One kind of thing a routing stage can DO to the world (#1032).
 *
 * The sibling of {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface}: a rule
 * kind answers WHO a stage reaches, an effect kind answers WHAT ELSE happens
 * when it is reached. Migration 112 draws the line and this file keeps it —
 * "approve" and "forward" are routing; "send an email" is not, and no effect
 * can settle, skip or redirect a step.
 *
 * IT PLANS. IT DOES NOT ACT.
 * --------------------------
 * {@see plan()} returns a {@see RouteEffectPlan} — a value describing what
 * should happen — and {@see RouteEffectRunner} performs it. An effect therefore
 * cannot block a request, cannot open a transaction, and can be asserted
 * against directly in a test. See {@see RouteEffectPlan} for the full argument.
 *
 * RETURNING NULL IS A REAL ANSWER
 * -------------------------------
 * "There is nobody to notify" and "this act is not the one I care about" are
 * ordinary outcomes, not failures. An effect says so by returning null, and the
 * runner records a `skipped` attempt with the reason — which is the difference
 * between a stage that decided to do nothing and a stage that silently did
 * nothing. Migration 112 refused to ship this whole feature without that
 * distinction.
 *
 * WHY THIS IS NOT IN THE SDK YET
 * ------------------------------
 * The same ordering {@see \Whity\Core\Inbox\InboxSourceRegistry} chose, for the
 * same reason. {@see RouteEffectPlan} today describes exactly one shape of
 * outcome, because notification is what the platform can actually perform; the
 * first effect kind that is genuinely different will add a field to it. A
 * contract vendored into every device host and version-pinned cannot quietly
 * gain one, so publishing now would publish a contract that then has to break.
 *
 * Promoting it later is a move rather than a redesign: this interface and its
 * two value objects go to `Whity\Sdk\Routing`, the registry gains a namespacing
 * `register()` beside `registerCoreEffects()`, and the plugin loader gains the
 * same optional-interface block every other contribution point already has —
 * all of which {@see RouteEffectRegistry} is already shaped for.
 */
interface RouteEffectInterface
{
    /**
     * The stage's intent, resolved against this particular act.
     *
     * @return RouteEffectPlan|null Null when there is nothing to do. Say why by
     *         returning null from {@see skipReason()}'s perspective — the runner
     *         asks for a reason so the recorded attempt is legible.
     */
    public function plan(RouteEffectContext $context): ?RouteEffectPlan;

    /**
     * Why {@see plan()} returned null, in words for an operator reading the
     * attempt log.
     *
     * Asked separately rather than returned alongside the plan so the common
     * path stays a plain nullable return. It is only ever called after a null,
     * and "no reason given" is itself recorded — an effect that declines
     * silently is the thing this feature exists to make impossible.
     */
    public function skipReason(RouteEffectContext $context): string;
}
