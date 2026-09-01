<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What an effect is told about the routing act that woke it (#1032).
 *
 * Everything here comes from the event payload the router broadcast AFTER its
 * transaction committed — never from a fresh query. That is not an efficiency
 * choice: by the time a listener runs, a delivery step's recipient rows are
 * already CLOSED, so an effect that re-queried "who is this step waiting on"
 * would correctly find nobody and correctly do nothing.
 * {@see RoutingNotifications} takes its recipients from the payload for exactly
 * this reason, and an effect that reached for the database instead would be a
 * second answer to a question the router has already answered.
 *
 * NO DATABASE HANDLE, and none is coming. An effect declares WHAT should
 * happen; {@see RouteEffectRunner} performs it. The same split
 * {@see \Whity\Sdk\Routing\RoutingRuleContext} makes for rule resolvers, for
 * the same reason: a resolver that can reach the database is a resolver whose
 * tenant scoping nobody can review, and this one additionally runs
 * synchronously inside a request that has already succeeded.
 */
final class RouteEffectContext
{
    /**
     * @param int                       $tenantId    The tenant the act happened in.
     * @param int                       $documentId  The document being routed.
     * @param int|null                  $stepId      The step whose effects these are.
     * @param int|null                  $eventId     The route event that fired this.
     * @param int|null                  $actorProfileId Who acted. Null for a
     *        system-initiated act, and passed through to an audience rule so
     *        `role_below_actor` means what it says.
     * @param string                    $action      The routing verb; one of {@see RouteAction}.
     * @param string|null               $verdict     What this person said, when the step demanded a verdict.
     * @param string|null               $decided     What the STEP concluded — null while a
     *        quorum is still short. Kept apart from `$verdict` for the reason
     *        the router states: an effect watching only the first would fire on
     *        the first of three required approvals.
     * @param list<mixed>               $recipients Whom the act reached, as the
     *        router reported them. Typed as loosely as it is CHECKED: this
     *        arrives out of a hook payload that any listener earlier in the
     *        chain could have reshaped, so the guards in
     *        {@see recipientProfileIds()} are load-bearing rather than dead code
     *        a stricter signature would invite somebody to delete.
     * @param array<string, mixed>      $config      The effect's own stored `effect_config`.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $documentId,
        public readonly ?int $stepId,
        public readonly ?int $eventId,
        public readonly ?int $actorProfileId,
        public readonly string $action,
        public readonly ?string $verdict,
        public readonly ?string $decided,
        public readonly array $recipients,
        public readonly array $config,
    ) {
    }

    /**
     * The profile ids the act reached, deduplicated and in payload order.
     *
     * Offered because it is what nearly every effect wants and because reading
     * it out of the raw recipient rows correctly — they carry `profile_id`
     * among several other keys, and the same person can appear on more than one
     * row — is the kind of small loop each effect would otherwise write
     * slightly differently.
     *
     * @return list<int>
     */
    public function recipientProfileIds(): array
    {
        $ids = [];
        foreach ($this->recipients as $recipient) {
            $id = is_array($recipient) ? ($recipient['profile_id'] ?? null) : null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int) $id] = true;
            }
        }

        // array_keys() on an int-keyed map already gives ints — PHP coerces a
        // numeric string key to int on write, which is why the loop above can
        // accept either spelling and still deduplicate correctly.
        return array_keys($ids);
    }

    /** A string out of the effect's own configuration, or null. */
    public function configString(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
