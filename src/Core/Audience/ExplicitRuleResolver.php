<?php

declare(strict_types=1);

namespace Whity\Core\Audience;

use InvalidArgumentException;
use Whity\Sdk\Audience\AudienceRuleContext;
use Whity\Sdk\Audience\AudienceRuleResolverInterface;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * Core rule kind `explicit` (#999): exactly these named people, and nobody else.
 *
 * WHY THE HAND-PICKED CASE IS A RULE AND NOT AN ESCAPE HATCH
 * ---------------------------------------------------------
 * "The tender committee is Aisha, Omar and Lena" is a real requirement and no
 * computed rule expresses it. The tempting design is to let a group be EITHER a
 * rule or a list — a `kind` column and, beside it, a members table used only
 * when the kind is 'list'.
 *
 * That design costs twice. Every consumer downstream — preview, routing, the
 * permission check, deletion, the picker — has to branch on which sort of group
 * it is holding, and each branch is a place the two can diverge. And it forces
 * the choice onto the ADMIN: they have to know, before they start, whether they
 * are making a computed group or a hand-picked one, and they discover they chose
 * wrong when they want to add "…plus everyone holding the auditor role" and
 * cannot.
 *
 * Making the enumerated case a KIND removes both. A hand-picked group and a
 * computed group are the same object with different innards, so there is one
 * code path, and changing a group's mind is editing one row.
 *
 * THIS IS NOT THE MEMBERSHIP TABLE THE DESIGN REJECTS
 * --------------------------------------------------
 * The distinction is worth being exact about, because the ids do get written
 * down. What migration 116 rejects is ONE ROW PER PERSON PER GROUP: a list that
 * is queried, joined, indexed and separately maintained, and that goes stale in
 * both directions — it misses the instructor hired last week and it keeps the
 * one who left last year.
 *
 * What this stores is one JSONB value on the group's own row, opaque to SQL,
 * joined by nothing, and resolved through the same
 * resolver-then-{@see ActiveMemberFilter} path as every other kind. So the
 * second staleness — people who should no longer be there — is answered
 * structurally: a profile that has left the tenant, or been suspended, drops out
 * of the answer without anybody pruning anything. The first staleness is not
 * answered, and must not be: an explicit group MEANS "these three", and silently
 * adding a fourth would be the bug.
 *
 * CONFIG
 * ------
 *   {"profile_ids": [11, 12, 13]}
 *
 * Profile ids, not emails or names. Same argument
 * {@see \Whity\Core\Document\Routing\RoleRuleResolver} makes for `role_id`: a
 * person changing their name or their address is an ordinary administrative act
 * and must not re-point — or un-point — a group that named them. There is no
 * foreign key (the column is opaque JSONB, for the reasons migration 116
 * records), and none is needed: an id naming a profile that no longer exists, or
 * that never belonged to this tenant, resolves to nobody rather than to somebody
 * else.
 *
 * `ouId` IS ALWAYS NULL, AND THAT IS THE HONEST ANSWER
 * ---------------------------------------------------
 * This rule reached these people BY NAME, through no unit at all. Substituting
 * each person's primary membership would attribute a routing to a department
 * that had nothing to do with it, and would make #947 item 5's "passed through
 * my unit" folder report units nobody chose. Null is what "no unit was involved"
 * looks like, and {@see ResolvedRecipient} exists to be able to say it.
 *
 * A CEILING THAT IS STRUCTURAL, NOT A SETTING
 * -------------------------------------------
 * {@see MAX_MEMBERS} is a constant rather than a tenant-overridable setting, and
 * that is a deliberate exception to the platform's usual "expose it, do not
 * hardcode it" rule. The bound is not a capacity an operator would tune; it is a
 * property of where the value LIVES. The whole list sits in a single JSONB
 * column that every read of the group decodes in one gulp, and a hand-picked
 * list long enough to strain that has stopped being hand-picked — the person
 * wanted a computed rule and reached for the wrong tool.
 *
 * Same argument, in the same subsystem, as
 * {@see \Whity\Api\DocumentRoutingApiHandler}'s note ceiling: a structural
 * property of the storage rather than a knob. There is also a practical reason a
 * setting could not enforce it — {@see validate()} is handed a config and NO
 * TENANT, by a contract shared with routing, so a per-tenant number is not
 * knowable at the point of refusal. A ceiling that could only be enforced
 * sometimes is worse than a fixed one that always is.
 *
 * Stateless — worker-safe, and safe to call repeatedly within one request.
 */
final class ExplicitRuleResolver implements RoutingRuleResolverInterface, AudienceRuleResolverInterface
{
    /**
     * The most people one `explicit` rule may name.
     *
     * 500, matching `documents.routing_max_recipients_per_step`'s default
     * deliberately: that is the number at which #947 decided "this is a
     * distribution" stops being a plausible reading of one step, and the same
     * judgement applies to "somebody typed these in".
     */
    public const MAX_MEMBERS = 500;

    public function label(): string
    {
        return 'Specific people, chosen by name';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function validate(array $config): void
    {
        self::requireProfileIds($config);
    }

    /**
     * @return list<ResolvedRecipient>
     */
    public function resolve(AudienceRuleContext $context): array
    {
        // No query. This rule's answer IS its config — the host still intersects
        // it with the tenant's active memberships before anything acts on it
        // ({@see ActiveMemberFilter}), which is what makes a stale id harmless
        // and is why this method does not need to check the tenant itself.
        return array_map(
            static fn (int $profileId): ResolvedRecipient => new ResolvedRecipient($profileId, null),
            self::requireProfileIds($context->config)
        );
    }

    /**
     * The configured profile ids, or a message the author can act on.
     *
     * Shared by {@see validate()} and {@see resolve()} so the two cannot drift: a
     * config that validated when the group was saved must still be readable when
     * it is resolved, possibly months later and after an upgrade.
     *
     * An EMPTY list is refused rather than accepted as "nobody". A group that
     * names no one resolves to no one, renders as a valid group, and can be
     * addressed by a route that then delivers to nobody and reports success —
     * which is precisely the silent omission this whole design is written
     * against. "Everyone holding a role nobody holds" is a different case and IS
     * allowed: there the emptiness is a fact about the organisation that may
     * change tomorrow, not a fact about the author having filled nothing in.
     *
     * @param array<string, mixed> $config
     * @return list<int>
     *
     * @throws InvalidArgumentException
     */
    private static function requireProfileIds(array $config): array
    {
        $raw = $config['profile_ids'] ?? null;

        if (!is_array($raw) || array_values($raw) !== $raw) {
            throw new InvalidArgumentException(
                "the 'explicit' rule needs a 'profile_ids' array listing the people it names"
            );
        }
        if ($raw === []) {
            throw new InvalidArgumentException(
                "the 'explicit' rule needs at least one entry in 'profile_ids' — a set that names nobody "
                . 'would resolve to nobody and still report success'
            );
        }
        if (count($raw) > self::MAX_MEMBERS) {
            throw new InvalidArgumentException(sprintf(
                "the 'explicit' rule names %d people, over the limit of %d for a hand-picked set. "
                . 'A set that large is better expressed as a rule over roles or units.',
                count($raw),
                self::MAX_MEMBERS,
            ));
        }

        $ids = [];
        foreach ($raw as $entry) {
            // A JSON body decodes `7` as int and `"7"` as string, and a config
            // round-tripped through JSONB can come back either way depending on
            // the driver. Accepting both spellings of the same number is not
            // laxity: refusing one would make a group resolvable on PostgreSQL
            // and broken on the offline SQLite engine, which is the worst
            // possible place for a dialect difference to surface. The same
            // reasoning {@see \Whity\Core\Document\Routing\RoleRuleResolver}
            // records for `role_id`.
            if (is_int($entry) && $entry > 0) {
                $ids[$entry] = true;
                continue;
            }
            if (is_string($entry) && preg_match('/^\d+$/', $entry) === 1 && (int) $entry > 0) {
                $ids[(int) $entry] = true;
                continue;
            }

            throw new InvalidArgumentException(
                "every entry in 'profile_ids' must be a positive whole number naming a profile"
            );
        }

        // De-duplicated here as well as by the host: the host's de-duplication
        // is about two RULES reaching the same person, and this is about one
        // author listing them twice. Reporting a count of 3 for a list of
        // [11, 11, 12] would make a preview disagree with itself.
        return array_map('intval', array_keys($ids));
    }
}
