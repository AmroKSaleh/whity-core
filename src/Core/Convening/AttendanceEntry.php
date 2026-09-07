<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * Turning a submitted attendance list into rows, or refusing it.
 *
 * WHY THIS IS NOT IN THE HANDLER
 * ------------------------------
 * Because the payload is a REPLACEMENT and every refusal here is the difference
 * between a stored attendance and a truncated one. The rules are the sort a
 * second caller — a minute importer, a CLI, a plugin running a standing
 * committee — must get identically, and a handler that parsed them inline would
 * be the only place they are written down. The same argument
 * {@see DecisionRecorder} makes about its three steps.
 *
 * IT VALIDATES THE WHOLE SET BEFORE THE CALLER WRITES ANY OF IT. Every entry is
 * parsed and every cross-entry rule checked before {@see parseSet()} returns, so
 * a list whose ninth row names nobody is refused with the stored list untouched
 * rather than half-replaced. {@see AttendanceRepository::replace()} wraps the
 * write in a transaction as well; belt and braces, because the consequence of
 * getting this wrong is a deleted minute.
 *
 * WHAT IT DELIBERATELY DOES NOT CHECK
 * -----------------------------------
 *  - THAT THE PROFILE EXISTS. The foreign key does that, and a pre-check would
 *    be a second answer to the same question that can disagree with the first
 *    under concurrency. What this refuses is a profile id that is not a positive
 *    integer, which the key cannot say anything useful about.
 *  - THAT THE PERSON WAS INVITED. That is the whole point: somebody attends who
 *    was never asked, and refusing them is the failure this feature exists to
 *    fix. Whether they held an invitation comes back on the READ, as a fact
 *    beside their attendance rather than a condition on it.
 *  - THAT THE MEETING HAS BEEN HELD. A status rule, and it lives with the other
 *    status rules — {@see MeetingStatus::canRecordAttendance()}, enforced by
 *    {@see MeetingService::recordAttendance()}.
 *  - THAT SOMEBODY ATTENDED AT ALL. An empty list is a real answer: a sitting
 *    abandoned for want of attendance is a fact the minute-book needs, and a
 *    minimum here would make it unrecordable. The guard against an
 *    ACCIDENTALLY empty submit is in the client — a sourced `fieldArray` will
 *    not submit until it has loaded what is stored — because the server cannot
 *    tell "nobody came" from "the form had not loaded".
 */
final class AttendanceEntry
{
    /**
     * The most people this accepts in one list.
     *
     * A bound rather than a policy: the request-body middleware already caps the
     * payload at 1 MiB, and this exists so that an attendance list which is
     * obviously not one — a loop that appended instead of replacing, a
     * copy-pasted export — is refused with a sentence a person can act on
     * instead of becoming two thousand rows nobody notices. It is generous by
     * design: the largest deliberative bodies this is written for are general
     * assemblies in the low hundreds, and a cap that a real sitting could reach
     * would be a cap that silently loses the tail of a real minute.
     */
    public const MAX_ATTENDEES = 1000;

    /** Longest note one attendance row carries, in bytes. */
    public const NOTE_MAX = 2000;

    /**
     * Parse a submitted `attendees` list.
     *
     * @param mixed $raw The payload's `attendees` value, straight off the wire.
     *
     * @return list<array{profile_id: ?int, attendee_name: ?string, capacity: string, note: ?string}>
     *
     * @throws ConveningRejectedException When the list, or any entry in it, is
     *         not something this can store.
     */
    public static function parseSet(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw ConveningRejectedException::because(
                'attendees must be a list of the people who were present — each one either a '
                . 'profile_id or an attendee_name. Send an empty list to record that nobody attended.'
            );
        }

        if (count($raw) > self::MAX_ATTENDEES) {
            throw ConveningRejectedException::because(
                'That is ' . count($raw) . ' attendees, and this accepts at most '
                . self::MAX_ATTENDEES . ' in one list. A list that long is usually a list that was '
                . 'appended to rather than replaced.'
            );
        }

        $parsed = [];
        // Duplicate detection is per PROFILE only. Two guests who share a typed
        // name are two people and both were in the room; the same profile twice
        // is one person counted twice, which the partial unique index would
        // refuse as a 500 rather than as an explanation.
        $seenProfiles = [];

        foreach ($raw as $index => $entry) {
            // 1-based, because the number in the message is a position in a list
            // a person is looking at, and their list starts at one.
            $position = (int) $index + 1;

            if (!is_array($entry)) {
                throw ConveningRejectedException::because(
                    "Attendee {$position} is not an object. Each entry names one person: "
                    . '{"profile_id": 12} for somebody with an account, or '
                    . '{"attendee_name": "..."} for somebody without one.'
                );
            }

            $profileId = self::profileId($entry, $position);
            $name = self::attendeeName($entry, $position);

            if ($profileId === null && $name === null) {
                throw ConveningRejectedException::because(
                    "Attendee {$position} identifies nobody. Give a profile_id for somebody with an "
                    . 'account, or an attendee_name for a guest who has none — a row with neither '
                    . 'records that somebody unnamed was present, which no minute can use.'
                );
            }

            if ($profileId !== null) {
                if (isset($seenProfiles[$profileId])) {
                    throw ConveningRejectedException::because(
                        "Profile {$profileId} appears twice in this attendance list (at "
                        . $seenProfiles[$profileId] . " and {$position}). One person attended once; "
                        . 'a duplicate would count them twice in every figure taken off this list.'
                    );
                }
                $seenProfiles[$profileId] = $position;
            }

            $parsed[] = [
                'profile_id' => $profileId,
                'attendee_name' => $name,
                'capacity' => self::capacity($entry, $position),
                'note' => self::note($entry, $position),
            ];
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $entry
     *
     * @throws ConveningRejectedException
     */
    private static function profileId(array $entry, int $position): ?int
    {
        $raw = $entry['profile_id'] ?? null;

        // An absent profile and an explicitly null one mean the same thing here:
        // this attendee is identified by name. An empty STRING means it too,
        // because that is what a cleared numeric input submits and refusing it
        // would make the ordinary act of turning a member row into a guest row
        // an error.
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^[1-9]\d*$/', $raw) === 1) {
            return (int) $raw;
        }

        throw ConveningRejectedException::because(
            "Attendee {$position} has a profile_id that is not a profile id. It must be a positive "
            . 'integer, or absent for somebody with no account.'
        );
    }

    /**
     * @param array<mixed> $entry
     *
     * @throws ConveningRejectedException
     */
    private static function attendeeName(array $entry, int $position): ?string
    {
        $raw = $entry['attendee_name'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (!is_string($raw)) {
            throw ConveningRejectedException::because(
                "Attendee {$position} has an attendee_name that is not text."
            );
        }

        $name = trim($raw);
        if ($name === '') {
            return null;
        }

        // Bytes, matching InputLimits: a conservative bound that can never
        // under-reject into a VARCHAR(255) overflow, which surfaces as a 500.
        if (strlen($name) > AttendanceRepository::NAME_MAX) {
            throw ConveningRejectedException::because(
                "Attendee {$position}'s name must be " . AttendanceRepository::NAME_MAX
                . ' bytes or fewer.'
            );
        }

        if (self::hasControlCharacters($name)) {
            throw ConveningRejectedException::because(
                "Attendee {$position}'s name contains control characters. A name is one line of text."
            );
        }

        return $name;
    }

    /**
     * @param array<mixed> $entry
     *
     * @throws ConveningRejectedException
     */
    private static function capacity(array $entry, int $position): string
    {
        $raw = $entry['capacity'] ?? null;

        if ($raw === null || $raw === '') {
            return AttendanceCapacity::DEFAULT;
        }

        if (!is_string($raw) || !AttendanceCapacity::isValid(trim($raw))) {
            throw ConveningRejectedException::because(
                "Attendee {$position} has a capacity outside the vocabulary; expected one of: "
                . implode(', ', AttendanceCapacity::all()) . '.'
            );
        }

        return trim($raw);
    }

    /**
     * @param array<mixed> $entry
     *
     * @throws ConveningRejectedException
     */
    private static function note(array $entry, int $position): ?string
    {
        $raw = $entry['note'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (!is_string($raw)) {
            throw ConveningRejectedException::because(
                "Attendee {$position} has a note that is not text."
            );
        }

        $note = trim($raw);
        if ($note === '') {
            return null;
        }

        if (strlen($note) > self::NOTE_MAX) {
            throw ConveningRejectedException::because(
                "Attendee {$position}'s note must be " . self::NOTE_MAX . ' bytes or fewer.'
            );
        }

        return $note;
    }

    /**
     * C0/C1 control characters, and nothing else.
     *
     * `\p{Cc}` and not `\p{C}`: the wider class includes `\p{Cf}`, the FORMAT
     * characters, and those include U+200F RIGHT-TO-LEFT MARK and U+061C ARABIC
     * LETTER MARK. On a platform whose Arabic support is a requirement rather
     * than a setting, a bidi mark inside a mixed Arabic/Latin name is part of
     * the name — refusing it would reject text that renders correctly
     * everywhere in favour of text that renders backwards.
     *
     * Invalid UTF-8 is caught by the same call: `preg_match` with `/u` returns
     * false rather than 0 on a malformed subject, and false is treated as
     * "refuse" here, because a byte sequence that is not text has no business
     * reaching a column somebody will later quote.
     */
    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/\p{Cc}/u', $value) !== 0;
    }
}
