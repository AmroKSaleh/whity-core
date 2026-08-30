<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Core\i18n\ServerLabels;

/**
 * Translated wording for the rule kinds a route step or a group may name (#1044).
 *
 * THE KEY IS DERIVED FROM THE KIND, NOT FROM THE ENGLISH. A kind (`role`,
 * `role_below_actor`) is already a stable slug that no rewording touches, so
 * `routing.rule.kind.role` satisfies the naming rule for free: rewording
 * "Everyone holding a role" cannot orphan the Arabic, because the key never
 * mentioned the words.
 *
 * ONLY CORE KINDS ARE TRANSLATED HERE, and that is a boundary rather than an
 * omission. A plugin's kind is namespaced (`acme:committee`) and its wording
 * belongs to the plugin's own catalogue domain, which this class has no business
 * writing keys into — a colon is not even legal in a key. Plugin labels
 * therefore pass through exactly as declared, which is what they do today.
 *
 * THE ENGLISH BELOW MUST MATCH THE RESOLVERS. It is declared twice — once by the
 * resolver that owns the kind, once here for the catalogue — because the
 * extractor reads text, not method bodies. `RoutingRuleLabelsTest` asserts the
 * two agree for every core kind, so the duplication cannot rot silently.
 *
 * @i18n-keys documents
 *   routing.rule.kind.role = Everyone holding a role
 *   routing.rule.kind.role_below_actor = Everyone holding a role, in my unit and below
 *   routing.rule.kind.explicit = Specific people, chosen by name
 *   routing.rule.kind.group = Everyone in a user group
 */
final class RoutingRuleLabels
{
    /** The catalogue domain these strings live in. */
    public const DOMAIN = 'documents';

    /** Key prefix, shared by every kind. */
    private const KEY_PREFIX = 'routing.rule.kind.';

    /**
     * The core kinds this class translates.
     *
     * Listed rather than derived from the registry on purpose: a kind gains a
     * translation when somebody writes its English into the block above, and a
     * newly registered core kind that nobody has worded yet must keep its
     * declared label rather than silently render a raw key.
     *
     * @var list<string>
     */
    public const CORE_KINDS = [
        RoutingRuleRegistry::KIND_ROLE,
        RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
        RoutingRuleRegistry::KIND_EXPLICIT,
        RoutingRuleRegistry::KIND_GROUP,
    ];

    /** The catalogue key for a core kind, or null if the kind is not one. */
    public static function keyFor(string $kind): ?string
    {
        return in_array($kind, self::CORE_KINDS, true) ? self::KEY_PREFIX . $kind : null;
    }

    /**
     * Localise a catalogue as returned by {@see RoutingRuleRegistry::catalogue()}
     * or {@see RoutingRuleRegistry::audienceCatalogue()}.
     *
     * Shape-preserving: same rows, same order, same fields — only `label` may
     * differ, and only when a translation exists for the caller's language.
     *
     * @param list<array{kind: string, label: string, source: string}> $catalogue
     * @return list<array{kind: string, label: string, source: string}>
     */
    public static function localise(array $catalogue, ServerLabels $labels): array
    {
        return array_map(
            static function (array $entry) use ($labels): array {
                $key = self::keyFor($entry['kind']);

                if ($key === null) {
                    return $entry;
                }

                $entry['label'] = $labels->label(self::DOMAIN, $key, $entry['label']);

                return $entry;
            },
            $catalogue
        );
    }
}
