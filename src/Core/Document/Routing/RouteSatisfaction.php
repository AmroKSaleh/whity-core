<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * The closed vocabulary of WHAT SETTLES A ROUTE STEP (#1054).
 *
 * Two values, CHECK-constrained in migration 124 so the database refuses a
 * third — the same construction {@see RouteAction} and {@see RouteVerdict} have,
 * and closed for the same reason: what can settle a stage of a route is the
 * ENGINE's own semantics, and a third value would be a state transition core
 * does not implement.
 *
 * THE QUESTION THIS ANSWERS, AND THE ONE IT DOES NOT
 * --------------------------------------------------
 * `satisfied_by` says WHETHER AN ANSWER IS REQUIRED AT ALL. `decision` says WHAT
 * AN ANSWER MUST CONTAIN once one is. They are two columns because they are two
 * facts about a stage, and the one combination that means nothing — a stage that
 * demands a verdict from people it never asks — is refused at authoring time
 * rather than left to produce a quorum nobody can satisfy.
 *
 * WHY IT IS NOT A SIXTH ACTION VERB
 * ---------------------------------
 * A `delivered` verb would have to be added to the inline CHECK on
 * `document_route_events.action`, which migration 119 established is impossible
 * on SQLite — the offline/desktop engine and the engine
 * {@see \Tests\Support\SchemaFromMigrations} builds the test schema on — without
 * rebuilding the one table whose whole value is that it is never rewritten.
 *
 * And reusing {@see RouteAction::NOTED} would be worse than impossible: `noted`
 * means A PERSON NOTED THIS, so a system close spelled that way would make an
 * append-only audit log assert something a human did not do. A plausible-but-
 * false row is the worst possible content for that table, and it could never be
 * corrected, because the trail has no update path.
 *
 * WHY IT IS ON THE STEP AND NOT ON THE EVENT
 * ------------------------------------------
 * Migration 119 put `verdict` on the event because a verdict is something an
 * ACTOR said. This is not: it is a property of the STAGE, decided by whoever
 * drew the route.
 *
 * The decisive form of that argument is mechanical. ONE trail event performs BOTH
 * kinds of close — the forward a person makes closes their own row because they
 * ACTED, and in the same transaction closes the rows it opens at a delivery stage
 * because those people were TOLD. A flag on the event could not say which rows it
 * meant. On the step it needs no per-row marker at all: every row at a
 * {@see DELIVERY} stage is delivery-closed by construction.
 *
 * NO CHANNEL LIVES HERE
 * ---------------------
 * {@see DELIVERY} says *these people are told, and are not asked to act*. It does
 * not say e-mail, in-app or SMS. That is operator configuration — it resolves
 * through `documents.routing_notification_channels` and the per-profile
 * notification preferences — and putting it on the step would mean re-authoring
 * every route in a tenant to change a transport.
 */
final class RouteSatisfaction
{
    /**
     * A PERSON MUST ACT. The stage is settled by one of
     * {@see RouteAction::recipientActions()} — every step authored before
     * migration 124, and every step that does not say otherwise.
     *
     * The recipient rows it opens stay OPEN until their holder acts, which is
     * what puts the document in "Awaiting me" and in the #881 inbox and keeps it
     * there until there is something to show for it.
     */
    public const ACT = 'act';

    /**
     * THE PEOPLE HERE ARE TOLD. Reaching the stage IS the whole of it.
     *
     * The rule resolves, one recipient row is opened per person so that WHO WAS
     * TOLD is recorded as durably as who acted, and each row is closed
     * immediately by the very event that created it. Nothing is ever awaited:
     *
     *  - "Awaiting me" and the routing inbox derive from OPEN rows, so a stage
     *    resolving to every instructor in a faculty leaves no phantom item in
     *    hundreds of inboxes that no act could clear;
     *  - "Acted on by me" derives from the trail's `actor_profile_id`, and those
     *    people appear there nowhere, because they did not act.
     *
     * THE DOCUMENT DOES NOT STOP HERE, and that is what "non-blocking" has to
     * mean to be safe. Nobody at this stage will forward it, so a delivery stage
     * in the middle of a route that merely closed its own rows would silently
     * strand every chain that reached it — the exact class of failure this
     * subsystem is written against, since every screen would show a document that
     * had travelled normally. {@see DocumentRouter} therefore continues past it in
     * the same act, resolved from the same actor, and repeats for as many
     * consecutive delivery stages as the author drew.
     *
     * A DELIVERY STAGE CAN NEVER BE A DECISION STAGE. A quorum is counted over
     * the recipient rows one act opened; here they are all closed the instant
     * they exist, so the gate could never be answered by anyone. It is refused at
     * authoring time — see {@see DocumentRouter::validateSteps()} — rather than
     * stored as a stage that looks like an approval and can never be given one.
     */
    public const DELIVERY = 'delivery';

    /**
     * Both values, in the order a reader meets them.
     *
     * Must stay in step with the CHECK constraints migration 124 puts on
     * `document_route_steps.satisfied_by` and
     * `document_route_template_steps.satisfied_by`.
     * {@see \Tests\Core\Document\Routing\RouteSatisfactionVocabularyTest} reads
     * the migration source and asserts all three agree, exactly as
     * {@see \Tests\Core\Document\Routing\RouteVerdictVocabularyTest} does for the
     * verdict vocabulary — a value in one and not the others is either a constant
     * the database refuses at insert or a value the schema admits that no reader
     * can render.
     *
     * The TEMPLATE side matters as much as the engine side: a design that can
     * express a satisfaction the engine cannot run is a design that saves
     * cleanly, renders cleanly, and does something else when it is finally
     * applied.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ACT, self::DELIVERY];
    }

    /** Whether a string is one of the two. */
    public static function isValid(string $satisfaction): bool
    {
        return in_array($satisfaction, self::all(), true);
    }

    /**
     * The value a step that says nothing gets.
     *
     * A METHOD rather than a fourth constant so that every string CONSTANT on
     * this class is a member of the vocabulary and nothing else — which is what
     * lets the vocabulary test assert completeness by reflection instead of by a
     * hand-kept list, exactly as {@see RouteVerdict::carriedBy()} does.
     */
    public static function fallback(): string
    {
        return self::ACT;
    }

    /**
     * Whether a stage is settled by delivering to its people rather than by
     * their acting.
     *
     * A tolerant read: anything outside the vocabulary answers `false`, which is
     * the SAFE direction. A stage whose stored value is somehow foreign then
     * behaves as an ordinary one — a document that visibly waits for somebody —
     * rather than as a delivery stage that closes every row and moves on, which
     * would be the engine deciding on the strength of a value it could not read.
     */
    public static function isDelivery(mixed $satisfiedBy): bool
    {
        return $satisfiedBy === self::DELIVERY;
    }
}
