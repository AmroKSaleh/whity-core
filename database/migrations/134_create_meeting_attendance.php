<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateMeetingAttendance — WHO ACTUALLY TURNED UP, as a fact of its own.
 *
 * WHY THIS IS NOT A COLUMN ON `meeting_invitations`
 * -------------------------------------------------
 * Migration 130 put invitations in their own table and
 * {@see \Whity\Core\Convening\InvitationStatus} says in as many words that there
 * is no `attended` value, because "what somebody said before the sitting" and
 * "who was in the room" are different facts that disagree constantly. That
 * docblock then guessed that attendance, if it ever came, would be "a second
 * fact on the row". This migration is the discovery that it cannot be, and the
 * reason is a person, not a schema preference:
 *
 *   SOMEBODY ATTENDS WHO WAS NEVER INVITED. A co-opted expert, a substitute
 *   sent by a member who could not come, a secretariat officer taking the
 *   minute, an observer from another unit. None of them holds an invitation
 *   row, and attendance expressed as a column on an invitation has nowhere to
 *   put them at all. The only way to record such a person would be to first
 *   INVENT an invitation — a row with a `sent_at` for a message nobody sent —
 *   which corrupts the one record a chair uses to ask "who did we ask, and who
 *   never answered", and double-counts that person in every invitation figure
 *   on every screen.
 *
 * A separate table also makes the second half of the invitation docblock's
 * argument STRUCTURAL rather than a rule somebody has to remember. An
 * acceptance is a PREDICTION and an attendance is a RECORD; with two tables,
 * there is no statement anywhere that can write the second over the first. On
 * one row there would be — the first `UPDATE ... SET status = 'attended'`
 * somebody writes in a hurry, and the planning record a chair worked from is
 * gone with no trace that it ever said anything else.
 *
 * WHAT IS DELIBERATELY *NOT* HERE
 * -------------------------------
 * NO ABSENCE ROWS. This table records presence only. "Who was absent" is
 * derived — the invited set minus the attended set — and storing it as well
 * would give one fact two homes that can disagree, which is the same objection
 * migration 108 makes to a materialised document status. A person's APOLOGY is
 * already a fact the platform holds: it is `declined` on their invitation.
 *
 * NO QUORUM. There is no quorum column, no quorum rule, no minimum, and nothing
 * in this subsystem computes one. A body's quorum rule lives in that body's
 * constitution — it is "half the voting members plus one, excluding vacancies",
 * or "three of the five faculty representatives", or a rule with proxies in it —
 * and a platform that invented a number here would produce a count that LOOKS
 * like a quorum check on every screen it appeared on while checking nothing.
 * What the API reports is what it counted, said in those words. See
 * {@see \Whity\Api\MeetingsApiHandler::attendance()}.
 *
 * `profile_id` IS NULLABLE, AND `attendee_name` IS WHY
 * ---------------------------------------------------
 * A guest from outside the institution has no profile, and requiring one would
 * mean either refusing to record them or creating an account for somebody who
 * will never sign in — a real person invented in the identity system to satisfy
 * a foreign key. So a row identifies its attendee EITHER by profile OR by a
 * typed name, and the check constraint below requires one of the two. A row
 * with neither identifies nobody and is the only shape refused.
 *
 * ON DELETE SET NULL on the profile, not CASCADE: deleting a person's account
 * must never silently remove them from a minute that records their presence at
 * a sitting where a decision was taken. What survives is the row and its
 * `attendee_name`, which is why the application writes the name it knew for
 * anybody it could name.
 *
 * `capacity` DESCRIBES, IT DOES NOT AUTHORIZE
 * -------------------------------------------
 * Three values — `member`, `substitute`, `guest` — and nothing in the platform
 * branches on them. They exist because an attendance list on which a substitute
 * is indistinguishable from a member is a list that cannot answer the question
 * anybody actually asks it afterwards. They are NOT a permission, NOT a vote
 * weight, and NOT an input to any count that claims to be a quorum. Compare
 * `convening_body_members.member_role`, which is a SEAT and equally grants
 * nothing.
 *
 * ATTENDANCE IS RECORDED AT OR AFTER HOLD, AND THAT RULE IS NOT HERE
 * ------------------------------------------------------------------
 * There is no CHECK tying a row to the meeting's status, because a check cannot
 * see the parent row and a trigger would put the rule in a place no reader of
 * this subsystem's PHP would find it. The rule lives in
 * {@see \Whity\Core\Convening\MeetingStatus::canRecordAttendance()} and is
 * enforced by {@see \Whity\Core\Convening\MeetingService::recordAttendance()},
 * beside every other transition rule this subsystem has.
 *
 * NO NEW PERMISSION SLUG. Recording attendance is a secretarial act of exactly
 * the kind migration 130 describes `convening:manage` as covering — building an
 * agenda, moving a date, sending invitations — and reading it is
 * `convening:read` like every other read here. A fourth slug would ship held by
 * nobody until an operator discovered it, for a capability no institution would
 * grant separately from "runs the calendar".
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
final class CreateMeetingAttendance
{
    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement, never a loop over an
        // interpolated name — TenantOwnedTablesTest and CoreTablesTest re-derive
        // their registries by scanning this source, so the name has to appear
        // literally. Migrations 059, 108, 112, 114, 116, 118, 120, 126 and 130
        // carry the same note, and spell the keyword hyphenated in prose for the
        // same reason: the schema test scans the raw file text for it and would
        // read a plain one inside a comment as a real table declaration.
        //
        // `meeting_attendees` (plural attendee) rather than `meeting_attendance`,
        // matching `meeting_invitations` / `meeting_decisions`: one row is one
        // person who was there, and a table named for a mass noun reads as though
        // it held one row per meeting.
        //
        // `recorded_at` is stamped by the column rather than supplied, unlike
        // `held_at` and `decided_at`. It is not a fact about the SITTING — it is
        // when a secretary typed this list, which is provenance for the record
        // and is the one timestamp here the server genuinely owns. The moment the
        // attendance describes is the meeting's own `held_at`.
        $db->exec("
            CREATE TABLE IF NOT EXISTS meeting_attendees (
                id                     BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                meeting_id             BIGINT       NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
                profile_id             INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                attendee_name          VARCHAR(255),
                capacity               VARCHAR(16)  NOT NULL DEFAULT 'member',
                note                   TEXT,
                recorded_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                recorded_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                CHECK (capacity IN ('member', 'substitute', 'guest')),
                CHECK (profile_id IS NOT NULL OR attendee_name IS NOT NULL)
            )
        ");

        // ONE ROW PER KNOWN PERSON PER SITTING, and named guests unconstrained.
        //
        // PARTIAL, on `profile_id IS NOT NULL`, for two reasons. The first is
        // that a plain UNIQUE (meeting_id, profile_id) would lean on the engines
        // agreeing about whether two NULLs collide — they happen to agree today
        // (both treat NULLs as distinct) and PostgreSQL 15 added a switch that
        // makes the opposite reading available, so an invariant resting on the
        // default is an invariant resting on a setting. The second is that the
        // partial form says what is actually wrong: a person counted twice. Two
        // guests who share a name are two people and both were in the room.
        //
        // Migration 130 uses the same construction for `uq_convening_body_members_current`.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_meeting_attendees_profile
                ON meeting_attendees(meeting_id, profile_id) WHERE profile_id IS NOT NULL'
        );

        // THE LIST READ: "who attended this sitting", entered through the tenant
        // as the predicate guard requires.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_attendees_tenant_meeting
                ON meeting_attendees(tenant_id, meeting_id, id)'
        );
        // "Which sittings has this person actually attended?" — the read behind
        // an attendance record for one member, which is the question a body asks
        // when a seat has been empty for a year.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_attendees_tenant_profile
                ON meeting_attendees(tenant_id, profile_id)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_meeting_attendees_tenant_profile');
        $db->exec('DROP INDEX IF EXISTS idx_meeting_attendees_tenant_meeting');
        $db->exec('DROP INDEX IF EXISTS uq_meeting_attendees_profile');
        $db->exec('DROP TABLE IF EXISTS meeting_attendees CASCADE');
    }
}
