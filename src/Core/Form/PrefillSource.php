<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * The vocabulary of places a field may auto-populate itself FROM (migration
 * 127, `form_fields.prefill_source`).
 *
 * A prefill source names a RULE for reaching the submitting person's own saved
 * information. It never names a person and it never stores a value — the value
 * is resolved server-side, at render time, against whoever is actually looking.
 * That is the same argument `document_route_steps.rule_kind` makes about
 * audiences, applied to values: a form authored in March and filled in in
 * November must show November's details, and a stored copy would show March's,
 * silently, while still rendering.
 *
 * DECLARED VERSUS BACKED — READ THIS BEFORE ADDING A SOURCE
 * ---------------------------------------------------------
 * Two of the five sources below are DECLARED but NOT BACKED, and saying so out
 * loud in code is the whole reason this class exists rather than a bare array of
 * strings.
 *
 * `profile.display_name`, `profile.email` and `profile.ou` resolve against real
 * columns that exist in this schema today:
 *
 *   profile.display_name  →  profiles.display_name                (migration 028)
 *   profile.email         →  profile_emails.email                 (migration 029)
 *   profile.ou            →  organizational_units.name, reached
 *                            through memberships.ou_id            (migrations 005/030)
 *
 * `profile.phone` and `profile.job_title` do NOT. There is no phone column and
 * no job-title column anywhere in whity-core's schema — not on `profiles`
 * (which is global and carries display name, credentials and 2FA state and
 * nothing else), not on `persons`, not on `memberships`. This was verified
 * against every migration rather than assumed.
 *
 * They are declared anyway, and the alternative was worse. An author building a
 * form wants "phone" in the picker; if the platform simply omits it, the author
 * adds a plain text field and every submitter retypes a number the organisation
 * may one day store. Declaring the source keeps the seam visible and gives the
 * builder something honest to say — {@see isBacked()} is what the render path
 * and the field editor both ask, so an unbacked source shows up as "no stored
 * value in this install" AT AUTHORING TIME rather than as a blank box the
 * submitter discovers.
 *
 * The failure mode this avoids is the one the codebase keeps writing against: a
 * feature that renders, reports success, and quietly does nothing. An unbacked
 * source resolves to null — the field is simply not pre-filled, which is the
 * correct behaviour — and the platform SAYS it did so instead of pretending.
 *
 * When a contact-details store lands, backing these two is a one-line change to
 * {@see PrefillResolver} plus flipping their entry here; nothing that consumes
 * the vocabulary has to move.
 *
 * Stateless — worker-safe.
 */
final class PrefillSource
{
    public const DISPLAY_NAME = 'profile.display_name';
    public const EMAIL = 'profile.email';
    public const PHONE = 'profile.phone';
    public const OU = 'profile.ou';

    /**
     * The unit's ID, for a field that STORES a reference rather than prose.
     *
     * `profile.ou` resolves to a name, which is right for a text field and
     * useless for an `ou_ref`: a picker seeded with 'Department of Civil
     * Engineering' matches no option and silently selects nothing, so the
     * person is shown an empty required field the system could have filled.
     * Same fact, two representations, because two kinds of field need it.
     */
    public const OU_ID = 'profile.ou_id';
    public const JOB_TITLE = 'profile.job_title';

    /**
     * Every declared source, mapped to whether a column exists in this schema to
     * resolve it from.
     *
     * `false` is not a TODO marker — it is a fact this class publishes, and the
     * builder and the render endpoint both read it. See the class docblock.
     *
     * @var array<string, bool>
     */
    private const SOURCES = [
        self::DISPLAY_NAME => true,
        self::EMAIL => true,
        self::OU => true,
        self::OU_ID => true,
        // Declared, unbacked: no column in whity-core's schema holds either.
        self::PHONE => false,
        self::JOB_TITLE => false,
    ];

    /**
     * Static vocabulary only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Every declared source, in the order a field editor should offer them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::SOURCES);
    }

    /**
     * Only the sources that resolve to a real value in this install.
     *
     * @return list<string>
     */
    public static function backed(): array
    {
        return array_values(array_keys(array_filter(self::SOURCES)));
    }

    public static function isValid(string $source): bool
    {
        return array_key_exists($source, self::SOURCES);
    }

    /**
     * Whether a column exists in this schema to resolve this source from.
     *
     * A declared-but-unbacked source is accepted on a field (refusing it would
     * make the picker lie about what the platform knows about) and resolves to
     * null. Callers surface the distinction rather than swallowing it.
     */
    public static function isBacked(string $source): bool
    {
        return self::SOURCES[$source] ?? false;
    }

    /**
     * A short, caller-facing explanation for a source that will never resolve,
     * or null when the source is backed.
     *
     * Written for the person AUTHORING the form — the one who can act on it by
     * choosing a different source — not for an operator reading a log.
     */
    public static function unbackedReason(string $source): ?string
    {
        if (!self::isValid($source)) {
            return 'This install does not recognise that prefill source.';
        }

        if (self::isBacked($source)) {
            return null;
        }

        return 'Nothing in this install stores that detail yet, so the field will start empty.';
    }
}
