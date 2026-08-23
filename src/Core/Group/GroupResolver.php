<?php

declare(strict_types=1);

namespace Whity\Core\Group;

use Closure;
use InvalidArgumentException;
use PDO;
use Throwable;
use Whity\Core\Audience\ActiveMemberFilter;
use Whity\Core\Audience\AudiencePreview;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Sdk\Audience\AudienceRuleContext;
use Whity\Sdk\Audience\AudienceRuleResolverInterface;
use Whity\Sdk\Routing\ResolvedRecipient;

/**
 * Turn a rule expression into people — LIVE, every time, with nothing cached
 * anywhere (#999).
 *
 * THE RESOLUTION-COST DECISION, AND WHAT WAS REJECTED
 * --------------------------------------------------
 * "Everyone holding the instructor role, in the Engineering subtree" is one
 * query with a subtree walk in front of it. Asked on every read of every screen
 * that mentions the group, that adds up. Three answers were available and this
 * class implements the first:
 *
 *  1. LIVE, ALWAYS (chosen). Resolution happens when somebody asks, against the
 *     organisation as it stands at that instant. It is the only option
 *     consistent with the argument that produced the whole design: the reason a
 *     group is a rule rather than a list is that a list goes stale silently, and
 *     a cache IS a list with a timestamp on it. Choosing anything else here
 *     would be re-introducing the rejected design one layer down, where it is
 *     harder to see.
 *
 *  2. CACHED, WITH INVALIDATION (rejected). The invalidation surface is not
 *     knowable. A group's answer can change on a membership insert, a role
 *     re-parenting, a unit move, a profile suspension — and, for any
 *     plugin-contributed kind, on a write to a table core has never heard of.
 *     There is no event core could subscribe to that covers `acme:committee`.
 *     A cache that is right for core's kinds and wrong for plugins' is worse
 *     than none, because the wrongness is invisible and arrives with an
 *     install.
 *     The worker-locality hazard is the second half of it: process-level state
 *     is per FrankenPHP worker, so a cache cleared by the request that changed
 *     something stays warm on the other seven — the exact failure PR #701
 *     found, where a revoked permission kept working and read as flaky tests.
 *
 *  3. MATERIALISED ON CHANGE (rejected, harder). A `user_group_members` table
 *     rebuilt by triggers or jobs is option 2 with a longer fuse and a bigger
 *     blast radius: it is the membership table migration 116 refuses, present in
 *     the schema, authoritative-looking, and wrong exactly when somebody is
 *     relying on it.
 *
 * WHAT MAKES LIVE AFFORDABLE IS THE SHAPE OF THE SURFACES, NOT A CACHE
 * -------------------------------------------------------------------
 * No read resolves a group unless somebody asked that group a membership
 * question. In particular `GET /api/user-groups` returns definitions with NO
 * member counts ({@see UserGroupRepository::listForTenant()} says why), so
 * listing forty groups costs one query rather than forty resolutions. A count is
 * one explicit request, for one group, from a screen where somebody wanted it.
 *
 * There is deliberately no per-request memo either. A memo is a cache whose
 * invalidation story is "the request ends", which is honest — but with no caller
 * that resolves the same group twice in one request, it would be a mechanism
 * carrying no load, and the first caller that did want it would silently inherit
 * "the same answer for the rest of this request" as a semantic nobody chose.
 *
 * WHY THE REGISTRY ARRIVES AS A CLOSURE
 * ------------------------------------
 * {@see RoutingRuleRegistry} holds the `group` kind, whose resolver
 * ({@see GroupRuleResolver}) is built on top of THIS class. Constructing the
 * registry therefore needs this, and this needs the registry: a genuine cycle
 * broken at the cheapest point. The closure is called on use, never stored as a
 * resolved reference, so the host may register more kinds (a plugin's, at load
 * time) after this object exists.
 *
 * NESTING IS IMPOSSIBLE RATHER THAN GUARDED
 * -----------------------------------------
 * A group cannot be defined as another group, so there is no cycle to detect and
 * no depth counter here. That is not a check this class performs — it falls out
 * of the types: a group definition must be a kind implementing
 * {@see \Whity\Sdk\Audience\AudienceRuleResolverInterface}, and `group` itself
 * implements only the routing interface. So even a row written by hand with
 * `rule_kind = 'group'` produces a clean refusal naming the kind, not a loop.
 *
 * Composition — "instructors OR teaching assistants" — is deliberately absent
 * rather than approximated by one-deep aliasing. Set composition wants a real
 * vocabulary (`any_of` / `all_of` over sub-expressions, with its own validation
 * and its own preview semantics), and a one-level `group` alias shipped now
 * would be the wrong shape to build that on: it would make "a group containing
 * groups" mean something today and something else later.
 */
final class GroupResolver
{
    /** @var Closure(): RoutingRuleRegistry */
    private Closure $registry;

    /**
     * @param Closure(): RoutingRuleRegistry $registry Deferred: see the class docblock.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly UserGroupRepository $groups,
        Closure $registry,
    ) {
        $this->registry = $registry;
    }

    /**
     * Refuse a definition this instance cannot resolve, at AUTHORING time.
     *
     * Called before a group is created or updated, so a malformed definition is a
     * 422 the author can fix rather than a group that silently resolves to nobody
     * months later. Three distinct refusals, because they need three different
     * fixes:
     *
     *  - the kind is not a well-formed kind at all (a typo, or a client
     *    concatenating a namespace by hand);
     *  - nothing on this instance provides it (a plugin that is not installed, or
     *    is disabled);
     *  - it IS provided, but only as a routing rule — it needs a document to
     *    answer, so it cannot define a group.
     *
     * The third is the one worth naming precisely, because it is the case an
     * author cannot diagnose from the outside: the kind appears in
     * `GET /api/routing-rules` and not in `GET /api/group-rules`, and without a
     * message saying why, the difference looks like a bug.
     *
     * @param array<string, mixed> $config
     *
     * @throws GroupRejectedException
     */
    public function validateExpression(string $kind, array $config): void
    {
        $resolver = $this->audienceResolverOrRefuse($kind);

        try {
            $resolver->validate($config);
        } catch (InvalidArgumentException $e) {
            // The rule is telling the author its config is unusable, in words
            // written for them — which on a plugin kind is text the plugin wrote.
            // See {@see GroupRejectedException} for why that has to travel in a
            // field of its own rather than as a throwable message.
            throw GroupRejectedException::because($e->getMessage());
        }
    }

    /**
     * The people a stored group resolves to, right now.
     *
     * @return list<ResolvedRecipient>
     *
     * @throws GroupRejectedException When the group is gone, or its rule cannot
     *         be resolved on this instance.
     */
    public function resolveGroup(int $tenantId, int $groupId, ?int $actorProfileId, ?int $actorOuId): array
    {
        $group = $this->groups->findById($groupId, $tenantId);
        if ($group === null) {
            // Named, and loudly. A route step whose group has been deleted must
            // fail rather than resolve to nobody: silently reaching zero people
            // and reporting success is the one outcome this design exists to
            // prevent, and it is indistinguishable from a rule that legitimately
            // matched no one.
            throw GroupRejectedException::because(sprintf(
                'user group %d does not exist in this tenant. It may have been deleted after the rule '
                . 'naming it was written.',
                $groupId,
            ));
        }

        /** @var array<string, mixed> $config */
        $config = is_array($group['rule_config']) ? $group['rule_config'] : [];

        return $this->resolveExpression(
            $tenantId,
            (string) $group['rule_kind'],
            $config,
            $actorProfileId,
            $actorOuId,
        );
    }

    /**
     * The people an arbitrary rule expression resolves to, right now.
     *
     * Used directly by the preview of an UNSAVED definition — an author checking
     * a rule before committing to it — and indirectly by everything else.
     *
     * The answer is always passed through {@see ActiveMemberFilter}, so what
     * comes back is people who are active members of this tenant and nobody
     * else. A resolver returns SUGGESTIONS; this is where they stop being
     * suggestions.
     *
     * @param array<string, mixed> $config
     * @return list<ResolvedRecipient>
     *
     * @throws GroupRejectedException
     */
    public function resolveExpression(
        int $tenantId,
        string $kind,
        array $config,
        ?int $actorProfileId,
        ?int $actorOuId,
    ): array {
        $resolver = $this->audienceResolverOrRefuse($kind);

        $context = new AudienceRuleContext(
            tenantId: $tenantId,
            actorProfileId: $actorProfileId,
            actorOuId: $actorOuId,
            config: $config,
        );

        try {
            $resolved = $resolver->resolve($context);
        } catch (InvalidArgumentException $e) {
            // The rule is telling the caller its config is unusable, in words
            // written for them. Same treatment as authoring-time validation —
            // reachable when a definition saved under an older version of a
            // plugin no longer satisfies its own validator.
            throw GroupRejectedException::because($e->getMessage());
        } catch (Throwable $e) {
            // A resolver failing at RUN time is code misbehaving, not a message
            // for the caller — so its text is logged and withheld, and the
            // caller is told which rule could not be resolved, which is what
            // they can act on. Identical posture to
            // {@see \Whity\Core\Document\Routing\DocumentRouter::resolveStep()}.
            error_log("[GroupResolver] rule '{$kind}' failed to resolve: " . $e->getMessage());

            throw GroupRejectedException::because(sprintf(
                "the rule '%s' could not be resolved.",
                $kind,
            ));
        }

        return ActiveMemberFilter::apply($this->db, $tenantId, $resolved);
    }

    /**
     * A count and a bounded sample of what an expression resolves to.
     *
     * {@see AudiencePreview} carries the argument for this shape. In short: the
     * count is exact and the sample is small, because a screen that renders a
     * thousand people has rebuilt the problem the rule exists to avoid.
     *
     * The sample is the LOWEST profile ids rather than the first the resolver
     * happened to return. Resolvers make no ordering promise — core's read
     * `memberships` with no ORDER BY — so two identical previews could otherwise
     * show different faces, which reads as "the group changed" on the one screen
     * where that must not be implied.
     *
     * @param array<string, mixed> $config
     *
     * @throws GroupRejectedException
     */
    public function preview(
        int $tenantId,
        string $kind,
        array $config,
        ?int $actorProfileId,
        ?int $actorOuId,
        int $sampleSize,
    ): AudiencePreview {
        $members = $this->resolveExpression($tenantId, $kind, $config, $actorProfileId, $actorOuId);

        usort(
            $members,
            static fn (ResolvedRecipient $a, ResolvedRecipient $b): int => $a->profileId <=> $b->profileId
        );

        return new AudiencePreview(
            total: count($members),
            sample: array_slice($members, 0, max(1, $sampleSize)),
            sampleSize: max(1, $sampleSize),
            resolvedForProfileId: $actorProfileId,
            resolvedForOuId: $actorOuId,
        );
    }

    /**
     * A count and sample for a STORED group.
     *
     * @throws GroupRejectedException
     */
    public function previewGroup(
        int $tenantId,
        int $groupId,
        ?int $actorProfileId,
        ?int $actorOuId,
        int $sampleSize,
    ): AudiencePreview {
        $group = $this->groups->findById($groupId, $tenantId);
        if ($group === null) {
            throw GroupRejectedException::because(sprintf(
                'user group %d does not exist in this tenant.',
                $groupId,
            ));
        }

        /** @var array<string, mixed> $config */
        $config = is_array($group['rule_config']) ? $group['rule_config'] : [];

        return $this->preview(
            $tenantId,
            (string) $group['rule_kind'],
            $config,
            $actorProfileId,
            $actorOuId,
            $sampleSize,
        );
    }

    /**
     * The audience resolver for a kind, or a refusal that says which of the three
     * things went wrong.
     *
     * @throws GroupRejectedException
     */
    private function audienceResolverOrRefuse(string $kind): AudienceRuleResolverInterface
    {
        if (!RoutingRuleRegistry::isValidKind($kind)) {
            throw GroupRejectedException::because(sprintf(
                "'%s' is not a well-formed rule kind. Kinds are lowercase words, optionally prefixed with "
                . 'the plugin that supplies them.',
                $kind,
            ));
        }

        $registry = ($this->registry)();

        $resolver = $registry->audienceResolver($kind);
        if ($resolver !== null) {
            return $resolver;
        }

        if ($kind === RoutingRuleRegistry::KIND_GROUP) {
            // The one refusal an author is most likely to hit and least likely
            // to understand, so it says what to do instead rather than only what
            // is wrong.
            throw GroupRejectedException::because(
                'a user group cannot be defined as another user group. Point the rule at what the other '
                . 'group points at, or define this one directly.'
            );
        }

        if ($registry->has($kind)) {
            throw GroupRejectedException::because(sprintf(
                "the rule '%s' can only be used as a step in a document route — it needs a document to "
                . 'answer, so it cannot define a user group.',
                $kind,
            ));
        }

        throw GroupRejectedException::because(sprintf(
            "nothing on this instance provides the rule '%s'. The plugin that supplied it may have been "
            . 'removed or disabled.',
            $kind,
        ));
    }
}
