<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * The closed vocabulary of VERDICTS a decision step can be answered with
 * (#1014).
 *
 * Two values, CHECK-constrained in migration 119 so the database refuses a
 * third — the same construction {@see RouteAction} has, and closed for the same
 * reason: what a person can DECIDE about a routed document is the engine's own
 * semantics, and a third value would be a state transition core does not
 * implement.
 *
 * A VERDICT IS NOT AN ACTION, AND THAT SEPARATION IS THE POINT
 * -----------------------------------------------------------
 * `document_route_events.action` says HOW THE ACTOR LEFT THE STEP. `verdict`
 * says WHAT THEY DECIDED. They are two columns because they are two facts, and
 * folding the verdict into the action vocabulary would have broken the property
 * that makes that vocabulary worth closing.
 *
 * Every verb in {@see RouteAction} names a fixed routing effect — its docblock
 * documents each one as "what it does to the inbox". Under a QUORUM
 * ({@see RouteQuorum}) an approval cannot have one: the first approval of three
 * closes one inbox row and opens nothing, the third closes one row and opens the
 * next step. An `approved` VERB would therefore be the single member of a closed
 * vocabulary whose effect the verb does not determine, which retires the
 * guarantee quietly rather than out loud.
 *
 * As a verdict it is orthogonal by construction: the act is `acknowledged` in
 * both cases ("I have answered; I choose no destination"), the verdict is what
 * the person said, and where the document goes is DERIVED from the verdict, the
 * step's edges and the quorum — by {@see DocumentRouter}, which is the only
 * place that derivation happens.
 *
 * Migration 119 records the second, independent reason: widening an inline CHECK
 * on the append-only trail is impossible on SQLite without rewriting the table,
 * and that table is the one whose whole value is never being rewritten.
 *
 * NULL IS NOT A THIRD VALUE
 * -------------------------
 * `verdict IS NULL` means "this act said nothing about approval" — every act on
 * a circulation step, every `noted`, every event written before migration 119.
 * It never means "not approved". A reader that treats absence as refusal would
 * invent a rejection for every document ever circulated.
 */
final class RouteVerdict
{
    /**
     * "I authorise this."
     *
     * Once the step's quorum is satisfied the route continues: along the
     * `approved` edge if the author drew one, and otherwise to the next
     * authoring ordinal — #1014's own reading, and what makes a decision step
     * usable in a plain linear route without any edge being authored at all.
     */
    public const APPROVED = 'approved';

    /**
     * "I refuse this."
     *
     * The route goes SOMEWHERE ELSE: along the `rejected` edge if the author
     * drew one, and otherwise NOWHERE — the chain ends. What it never does is
     * fall through to the step an approval would have opened. That fallback is
     * the precise failure #1014 is written against: a rejection that records
     * dissent and lets the document proceed is not approval, and it fails
     * SILENTLY, because every screen still shows a document moving normally.
     */
    public const REJECTED = 'rejected';

    /**
     * Both verdicts, in the order a reader meets them.
     *
     * Must stay in step with the CHECK constraints migration 119 puts on
     * `document_route_events.verdict` and `document_route_edges.verdict`.
     * {@see \Tests\Core\Document\Routing\RouteVerdictVocabularyTest} reads the
     * migration source and asserts all three agree, exactly as
     * {@see \Tests\Core\Document\Routing\RouteActionVocabularyTest} does for the
     * action vocabulary — a value in one and not the others is either a constant
     * the database refuses at insert or a value the schema admits that no reader
     * can render, and the trail is append-only so the second cannot be tidied up
     * afterwards.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::APPROVED, self::REJECTED];
    }

    /** Whether a string is one of the two verdicts. */
    public static function isValid(string $verdict): bool
    {
        return in_array($verdict, self::all(), true);
    }

    /**
     * The ACTION a verdict is carried by.
     *
     * Exactly one, deliberately. A decision step's recipient answers with
     * `acknowledged` — "I have answered; I choose no destination" — and the
     * engine works out the destination from the verdict. `forwarded` is refused
     * on a decision step because it means the ACTOR chose where the document
     * goes, which is the one thing a gate exists to take away from them; letting
     * both through would give every approver a one-click way past the verdict.
     *
     * A METHOD rather than a constant so that every string CONSTANT on this
     * class is a verdict and nothing else — which is what lets the vocabulary
     * test assert completeness by reflection instead of by a hand-kept list.
     */
    public static function carriedBy(): string
    {
        return RouteAction::ACKNOWLEDGED;
    }
}
