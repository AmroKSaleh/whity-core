<?php

declare(strict_types=1);

namespace Whity\Core\Document\RouteTemplate;

/**
 * The vocabulary a route TEMPLATE is authored in (#1027).
 *
 * WHY THIS CLASS EXISTS, AND WHEN IT SHOULD STOP EXISTING
 * -------------------------------------------------------
 * Every value here is MIRRORED from #1014's engine-side declarations —
 * `Whity\Core\Document\Routing\RouteVerdict` and
 * `Whity\Core\Document\Routing\RouteQuorum` — and this class exists for exactly
 * one reason: #1014 and #1027 were built concurrently on separate branches, and
 * an authoring surface cannot reference classes that are not on its branch yet.
 *
 * It is a MIRROR, never a second opinion. The moment both are merged, the right
 * change is to delete the constants below and have the callers reference
 * `RouteVerdict::all()` and `RouteQuorum::all()` directly. That is filed, not
 * hoped for.
 *
 * Until then the mirroring is checked by CI rather than by whoever remembers:
 * {@see \Tests\Unit\Core\Document\RouteTemplate\RouteTemplateVocabularyTest}
 * reads the migration SOURCE for both the template tables and the engine tables
 * and fails the moment the two CHECK constraints disagree. It is written to skip
 * — not pass — while #1014's migration is absent from the branch, so it starts
 * policing the day the two meet instead of reporting green on a comparison it
 * never made.
 *
 * WHY AN AUTHORING VOCABULARY IS WORTH PINNING AT ALL
 * ---------------------------------------------------
 * A template is a CONSTRUCTOR for route steps. If it can express a verdict the
 * engine cannot route on, or a quorum the engine cannot count, then the editor
 * can author a design that saves cleanly, renders cleanly, and does something
 * different from what it draws when it is finally run. That is the failure class
 * the whole routing subsystem is written against, reached by the one door nobody
 * was watching.
 */
final class RouteTemplateContract
{
    /**
     * "I authorise this." Mirrors `RouteVerdict::APPROVED`.
     *
     * On a step with no `approved` edge the route continues to the next
     * authoring ordinal — which is why a plain linear template needs no edges at
     * all. See migration 119's docblock.
     */
    public const VERDICT_APPROVED = 'approved';

    /**
     * "I refuse this." Mirrors `RouteVerdict::REJECTED`.
     *
     * On a step with no `rejected` edge the chain ENDS. It never falls through to
     * the step an approval would have opened; #1014 records that fallback as the
     * precise failure it is written against.
     */
    public const VERDICT_REJECTED = 'rejected';

    /** Everyone in the cohort must approve. Mirrors `RouteQuorum::ALL`. */
    public const QUORUM_ALL = 'all';

    /** One approval carries the step. Mirrors `RouteQuorum::ANY`. */
    public const QUORUM_ANY = 'any';

    /** More than half the cohort. Mirrors `RouteQuorum::MAJORITY`. */
    public const QUORUM_MAJORITY = 'majority';

    /**
     * The settings key a step with no explicit quorum defers to.
     *
     * Named in `RouteQuorum`'s docblock as "the validator for the
     * `documents.routing_approval_quorum` setting", and OWNED by #1014 —
     * `SettingsRegistry` does not know this key on this branch, so it cannot yet
     * be written and {@see \Whity\Core\Settings\SettingsService::effective()}
     * returns nothing for it.
     *
     * That is why it is read by key rather than referenced as a registry
     * constant, and why {@see DEFAULT_QUORUM} exists: the read is written so that
     * the day #1014 lands the key, every template surface starts honouring it
     * with no further change. Until then every step resolves to the same default
     * #1014 chose, which is the behaviour a tenant would get anyway before
     * anybody overrode it.
     */
    public const SETTING_APPROVAL_QUORUM = 'documents.routing_approval_quorum';

    /**
     * The quorum a step falls back to when neither the step nor the tenant says.
     *
     * `all`, not `any`, and the argument is `RouteQuorum`'s rather than a second
     * one: too few approvals is a SILENT authority failure that surfaces years
     * later in an audit, and too many is a LOUD one that surfaces the same
     * afternoon in a complaint. A default protects the deployment where nobody
     * thought about this, so it is the one that fails loudly. For the ordinary
     * single-approver step the two are indistinguishable.
     */
    public const DEFAULT_QUORUM = self::QUORUM_ALL;

    /**
     * Both verdicts, in the order a reader meets them.
     *
     * @return list<string>
     */
    public static function verdicts(): array
    {
        return [self::VERDICT_APPROVED, self::VERDICT_REJECTED];
    }

    /**
     * Every quorum, in the order a reader meets them.
     *
     * @return list<string>
     */
    public static function quorums(): array
    {
        return [self::QUORUM_ALL, self::QUORUM_ANY, self::QUORUM_MAJORITY];
    }

    public static function isVerdict(string $verdict): bool
    {
        return in_array($verdict, self::verdicts(), true);
    }

    public static function isQuorum(string $quorum): bool
    {
        return in_array($quorum, self::quorums(), true);
    }
}
