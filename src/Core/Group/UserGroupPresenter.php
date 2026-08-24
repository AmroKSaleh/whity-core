<?php

declare(strict_types=1);

namespace Whity\Core\Group;

use Whity\Core\Audience\AudiencePreview;
use Whity\Sdk\Routing\ResolvedRecipient;

/**
 * Row-to-API shaping for user groups (#999).
 *
 * One place that decides what a group looks like on the wire, so the list, the
 * single read, the create response and the update response cannot disagree about
 * it — the same job {@see \Whity\Core\Document\Routing\RoutingPresenter} does for
 * routing.
 *
 * NO MEMBERSHIP FIELD ON A GROUP, EVER
 * ------------------------------------
 * There is no `members`, no `member_count` and no `member_ids` here, and their
 * absence is the API's half of the design. A group's membership is not a
 * property of the group — it is a question asked of the organisation at a moment
 * in time, relative to whoever is asking, and it is answered by
 * {@see preview()} on request. A `member_count` on the list row would make every
 * render of every list resolve every rule, and would put a number that changes
 * by the minute in a payload clients cache.
 *
 * `rule_config` is passed through verbatim as a freeform object. Core cannot
 * know what an `acme:committee` config contains — only the resolver the plugin
 * registered does — so shaping it here would mean guessing, and a guess that
 * dropped a key would silently change what a group means when it was read back
 * and saved again.
 *
 * Static-only.
 */
final class UserGroupPresenter
{
    private function __construct()
    {
    }

    /**
     * One group as the API renders it.
     *
     * @param array<string, mixed> $group A normalized `user_groups` row.
     * @return array<string, mixed>
     */
    public static function group(array $group): array
    {
        return [
            'id' => (int) $group['id'],
            'tenant_id' => (int) $group['tenant_id'],
            'name' => (string) $group['name'],
            'description' => $group['description'] !== null ? (string) $group['description'] : null,
            'rule_kind' => (string) $group['rule_kind'],
            'rule_config' => is_array($group['rule_config']) ? $group['rule_config'] : [],
            'created_by' => $group['created_by'] !== null ? (int) $group['created_by'] : null,
            'created_at' => (string) $group['created_at'],
            'updated_at' => (string) $group['updated_at'],
        ];
    }

    /**
     * A preview as the API renders it: a count, a bounded sample, and who it was
     * resolved for.
     *
     * `truncated` is stated rather than left to the client to derive from
     * `total > count(sample)`. A client that got that inference wrong would
     * present ten people as the whole membership, which is the single misreading
     * this shape exists to prevent.
     *
     * `resolved_for` is present on every preview, not only on the actor-relative
     * kinds. Whether a kind is relative is the resolver's business and not
     * something core can ask it, and a field that appeared only sometimes would
     * be a field clients stop reading.
     *
     * `display_name` on a sample row is filled in by the caller when the reader
     * holds `users:read`, and is null otherwise — see
     * {@see \Whity\Api\UserGroupsApiHandler}. It is nullable rather than omitted
     * so the shape is one shape: a client renders an id when there is no name,
     * instead of branching on which flavour of payload it received.
     *
     * @param array<int, string> $displayNames profile id => name, for the sample only.
     * @return array<string, mixed>
     */
    public static function preview(AudiencePreview $preview, array $displayNames): array
    {
        return [
            'total' => $preview->total,
            'truncated' => $preview->truncated(),
            'sample_size' => $preview->sampleSize,
            'sample' => array_map(
                static fn (ResolvedRecipient $r): array => [
                    'profile_id' => $r->profileId,
                    'ou_id' => $r->ouId,
                    'display_name' => $displayNames[$r->profileId] ?? null,
                ],
                $preview->sample
            ),
            'resolved_for' => [
                'profile_id' => $preview->resolvedForProfileId,
                'ou_id' => $preview->resolvedForOuId,
            ],
        ];
    }
}
