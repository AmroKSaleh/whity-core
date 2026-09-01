<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Sdk\Audience\AudienceRuleContext;

/**
 * Core's one effect kind: tell somebody that a stage was reached (#1032).
 *
 * The worked example #1032 asks for — "send an email at this stage" — expressed
 * the only way this codebase permits an audience to be expressed.
 *
 * THE AUDIENCE IS A RULE, NEVER A LIST
 * ------------------------------------
 * The obvious configuration is `{"profile_ids": [4, 11, 27]}`, and it is exactly
 * the mistake migration 112 and
 * {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface} were both written
 * against: a stored recipient list is resolved once, when the route is authored,
 * and is wrong from the first reorganisation onwards. It omits the person hired
 * last week, the effect still runs, the attempt still records `succeeded`, and
 * nothing anywhere says somebody was skipped.
 *
 * So the audience is declared the same way a routing step's is — a KIND and its
 * config — and resolved at fire time through the very same resolvers, via
 * {@see RoutingRuleRegistry::audienceResolver()}. "Everyone holding the registrar
 * role" means whoever holds it at the instant the stage is reached. A rule kind
 * that a plugin contributed to routing is usable here for free, because it is
 * the same catalogue.
 *
 *     effect_config = {
 *       "audience": {"kind": "role", "config": {"role_id": 7}},
 *       "type": "document.routing.stage_reached"
 *     }
 *
 * WHAT IT DOES NOT DUPLICATE
 * --------------------------
 * {@see RoutingNotifications} already tells a step's OWN recipients that
 * something is waiting for them. This is for everybody else — the registry that
 * wants to know a contract was approved, the archive that wants to know it was
 * filed — which is why the audience is configured rather than taken from the
 * event's recipient list.
 *
 * NOT EVERY ACT. A stage's effects fire when the stage is REACHED, and a `noted`
 * act is somebody adding a comment without moving anything. Firing on that would
 * mail the registry every time a colleague left a note.
 */
final class NotifyEffect implements RouteEffectInterface
{
    /** The notification type used when a declaration names none. */
    public const DEFAULT_TYPE = 'document.routing.stage_reached';

    /** Config key holding the audience rule. */
    private const AUDIENCE = 'audience';

    public function __construct(private readonly RoutingRuleRegistry $rules)
    {
    }

    public function plan(RouteEffectContext $context): ?RouteEffectPlan
    {
        if (!$this->fires($context)) {
            return null;
        }

        $profileIds = $this->audience($context);
        if ($profileIds === []) {
            return null;
        }

        return RouteEffectPlan::notify(
            $profileIds,
            $context->configString('type') ?? self::DEFAULT_TYPE,
            [
                'document_id' => $context->documentId,
                'step_id' => $context->stepId,
                'action' => $context->action,
                // The STEP's conclusion, not one person's vote — see
                // RouteEffectContext. A template that printed `verdict` would
                // announce "approved" on the first of three approvals.
                'decided' => $context->decided,
            ],
        );
    }

    public function skipReason(RouteEffectContext $context): string
    {
        if (!$this->fires($context)) {
            return "the '{$context->action}' act does not reach a stage";
        }

        $audience = $this->audienceRule($context);
        if ($audience === null) {
            return 'no audience rule is configured';
        }
        if ($this->rules->audienceResolver($audience['kind']) === null) {
            // NAMED, because this is the state migration 112 deliberately allows:
            // a kind whose plugin has since been uninstalled. An operator
            // reading "skipped" needs to know it was the missing kind rather
            // than an empty department.
            return "no resolver is registered for audience kind '{$audience['kind']}'";
        }

        return 'the audience rule resolved to nobody';
    }

    /**
     * Whether this act reaches a stage at all.
     *
     * `noted` is excluded because it moves nothing — see the class note. Every
     * other verb in the vocabulary either opens a stage or settles one.
     */
    private function fires(RouteEffectContext $context): bool
    {
        return $context->action !== RouteAction::NOTED;
    }

    /**
     * @return list<int>
     */
    private function audience(RouteEffectContext $context): array
    {
        $audience = $this->audienceRule($context);
        if ($audience === null) {
            return [];
        }

        $resolver = $this->rules->audienceResolver($audience['kind']);
        if ($resolver === null) {
            return [];
        }

        try {
            $resolved = $resolver->resolve(new AudienceRuleContext(
                $context->tenantId,
                $context->actorProfileId,
                null,
                $audience['config'],
            ));
        } catch (\Throwable $e) {
            // Swallowed HERE rather than at the runner, so the attempt is
            // recorded as skipped-with-a-reason rather than failed: a resolver
            // that threw has told us nothing about whether the notification
            // would have worked.
            error_log('[NotifyEffect] audience rule failed to resolve: ' . $e->getMessage());

            return [];
        }

        $ids = [];
        foreach ($resolved as $recipient) {
            // Deduplicated by key: the same person can hold a role through more
            // than one membership, and notifying them twice for one stage is the
            // kind of thing that gets a notification channel muted.
            $ids[$recipient->profileId] = true;
        }

        return array_keys($ids);
    }

    /**
     * The declared audience rule, or null when the declaration has none or is
     * malformed.
     *
     * @return array{kind: string, config: array<string, mixed>}|null
     */
    private function audienceRule(RouteEffectContext $context): ?array
    {
        $audience = $context->config[self::AUDIENCE] ?? null;
        if (!is_array($audience)) {
            return null;
        }

        $kind = $audience['kind'] ?? null;
        if (!is_string($kind) || !RoutingRuleRegistry::isValidKind($kind)) {
            return null;
        }

        $config = $audience['config'] ?? [];

        return ['kind' => $kind, 'config' => is_array($config) ? $config : []];
    }
}
