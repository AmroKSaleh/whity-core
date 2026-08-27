<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * A form's lifecycle state, and the only transitions between them (migration
 * 127).
 *
 * Three states, and the interesting one is `archived`.
 *
 *   `draft`      Being authored. Fields may be added, reordered, deleted. NOT
 *                submittable — a half-written form that accepted submissions
 *                would collect answers to questions its author was still
 *                changing, and there would be no way afterwards to tell which
 *                answers were given to which question.
 *   `published`  Live. Submittable. Fields may still be edited (see below).
 *   `archived`   Retired. NOT submittable. Everything already submitted stays
 *                exactly where it is.
 *
 * WHY THERE IS NO `deleted`, AND WHY ARCHIVE IS NOT A SOFT DELETE
 * ----------------------------------------------------------------
 * A form is what somebody's submission was an answer TO. Destroying it makes
 * every submission against it unreadable — the answers survive in
 * `form_submissions.data`, keyed by `field_key`, and nothing is left to say what
 * those keys meant. Archiving is the operation that actually gets asked for
 * ("stop accepting these") and it costs nothing, so the destructive one is not
 * offered. The API has no DELETE for a form, exactly as
 * {@see \Whity\Api\TimeWindowsApiHandler} has none for a period, and for the
 * same reason.
 *
 * WHY `archived` IS REVERSIBLE AND `draft` IS NOT REACHABLE AGAIN
 * ---------------------------------------------------------------
 * Archiving is a decision people take back — a form retired at the end of a
 * cycle is wanted again at the start of the next one — so `archived → published`
 * is allowed and re-publishing bumps the version.
 *
 * `published → draft` is refused, and that is the load-bearing refusal. Once a
 * submission exists, a form that could return to draft could have its questions
 * rewritten underneath answers already given, with the form's own state saying
 * "not live" while the evidence says otherwise. Someone who wants to rework a
 * live form archives it, which stops new submissions without pretending the old
 * ones were made against something else.
 *
 * WHAT PUBLISHING ACTUALLY GUARANTEES — AND WHAT IT DOES NOT
 * ----------------------------------------------------------
 * Publishing increments `forms.version`, and every submission stamps the version
 * it was made against. That is an HONEST but PARTIAL guarantee and it is worth
 * being exact about which: `form_fields` rows are edited in place, so the stamp
 * does not let anybody reconstruct the field list as it stood at version 3. What
 * it gives is the ability to SEE that a submission was answered against a
 * different version than the one now on screen, so a reader knows they are
 * looking at drift rather than at a bug. A point-in-time field snapshot is a
 * real feature and a larger one; the version column is the seam it attaches to.
 *
 * Stateless — worker-safe.
 */
final class FormStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    /**
     * The allowed transitions, keyed by the state being left.
     *
     * Declared as data rather than as a chain of `if`s so the whole policy is
     * readable in one place and testable without driving the API. An empty list
     * would mean a terminal state; there is none, which is itself the statement
     * that nothing here is irreversible.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::DRAFT => [self::PUBLISHED, self::ARCHIVED],
        // Deliberately no route back to DRAFT — see the class docblock.
        self::PUBLISHED => [self::ARCHIVED],
        self::ARCHIVED => [self::PUBLISHED],
    ];

    /**
     * Static vocabulary only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DRAFT, self::PUBLISHED, self::ARCHIVED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Whether a form in this state accepts submissions.
     *
     * The single authority on the question. A handler that re-derived it as
     * `!== 'draft'` would silently start accepting submissions to archived forms
     * the day a fourth state is added.
     */
    public static function acceptsSubmissions(string $status): bool
    {
        return $status === self::PUBLISHED;
    }

    /**
     * Whether the form's FIELDS may still be added, edited, reordered or removed
     * in this state.
     *
     * A published form is still editable — a typo in a label has to be fixable
     * without retiring the form — but an archived one is not: its fields are the
     * only remaining explanation of what its submissions answered, and editing
     * them rewrites that explanation after the fact.
     */
    public static function allowsFieldEditing(string $status): bool
    {
        return $status === self::DRAFT || $status === self::PUBLISHED;
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * The states reachable from this one, for a client rendering the controls.
     *
     * @return list<string>
     */
    public static function transitionsFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }
}
