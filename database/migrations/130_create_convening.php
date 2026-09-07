<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateConvening — DELIBERATIVE BODIES, THEIR MEETINGS, AND THE DECISIONS
 * TAKEN AT THEM.
 *
 * WHY THIS EXISTS
 * ---------------
 * The platform can already circulate a document for sign-off: migration 112
 * built the route, 119 gave a step a VERDICT, and 125 let a step be satisfied by
 * delivery. Every one of those models an INDIVIDUAL answering a step — a person
 * holding an open recipient row says approved or rejected, and the document
 * moves.
 *
 * A very large class of organisations does not decide that way. The thing that
 * approves is a BODY that meets: an agenda is assembled ahead of time, people
 * are invited, the body convenes, it minutes a numbered decision, and THAT
 * decision is what the approval chain was waiting for. Until now every such
 * organisation either drove the approval chain by hand — one member clicking
 * "approve" on behalf of a meeting nothing in the system records — or kept the
 * meeting in a spreadsheet beside the platform, where the two disagree the first
 * time somebody forgets to copy one into the other.
 *
 * WHAT THIS IS NOT
 * ----------------
 * It is NOT a second approval engine, and there is deliberately no code here or
 * beside it that writes to a routing table. A meeting decision reaches a route
 * exactly the way a person does — through {@see \Whity\Core\Document\Routing\DocumentRouter::act()},
 * with a verdict, from a profile the route actually asked. See
 * {@see \Whity\Core\Convening\DecisionRouteBridge}, whose whole design note is
 * about why it holds no privilege the engine does not already grant a recipient.
 *
 * SIX TABLES, AND WHY EACH IS SEPARATE
 * ------------------------------------
 * `convening_bodies` — the standing body. `body_key` is the stable code a
 * decision number is built from and that an integration binds to; `name` is what
 * a person reads and may be renamed without the key changing meaning. `ou_id` is
 * OPTIONAL because a body need not belong to a unit — a cross-cutting body that
 * reports to nobody in particular is the ordinary case, not the exception, and a
 * NOT NULL column would force every deployment to invent a home for it.
 *
 * `convening_body_members` — who sits on it, in what seat, and BETWEEN WHEN AND
 * WHEN. `left_at` rather than a delete, because a decision taken in March was
 * taken by the body as it was constituted in March, and a membership row that is
 * removed makes that unreconstructible. The partial unique index below is on
 * CURRENT membership only, which is what lets a person leave a body and rejoin
 * it later without either row being edited.
 *
 * `meetings` — one sitting. `scheduled_at` and `held_at` are BOTH nullable and
 * both real: a draft agenda has neither, a scheduled meeting has the first, and
 * only a meeting that actually happened has the second. Nothing derives one from
 * the other, because a meeting postponed twice and held on the third date is the
 * ordinary case and a derived `held_at` would quietly record the wrong day.
 *
 * `meeting_agenda_items` — what the body will consider, in order, and OPTIONALLY
 * which document each item is about. The document pointer is what makes this
 * subsystem more than a minute-book: it is the join between "the body decided
 * X" and "this document was awaiting a decision".
 *
 * `meeting_decisions` — what the body concluded about one agenda item, under a
 * human-readable number. Separate from the agenda item because an item is what
 * was PUT to the body and a decision is what came BACK, and a body may consider
 * an item and defer it, which is a decision that leaves the item exactly as it
 * was.
 *
 * `meeting_invitations` — who was asked, and what they said. Separate from
 * membership because being ON a body and being invited to a particular sitting
 * are different facts: a member who joined after the invitations went out was
 * not invited to that sitting, and a member who left is not un-invited from a
 * sitting they attended.
 *
 * WHY `status` IS A COLUMN HERE WHEN `documents` REFUSED ONE
 * ---------------------------------------------------------
 * Migration 108 gave `documents` no status column and derives document state from
 * the append-only `document_route_events`. That is right THERE and would be wrong
 * here, and the difference is worth stating because the two look alike.
 *
 * A document's state is the answer to "where has this got to in a circulation",
 * which is a function of a trail that already exists and that must be able to
 * disagree with nobody. A meeting's status is not derived from anything: `draft`
 * versus `scheduled` is the presence of a date somebody typed, and `held` is a
 * person asserting that the sitting took place. There is no event stream these
 * could be recomputed from, so a status column here is the ONLY record, not a
 * second one — and a materialisation that can disagree with its source is exactly
 * what migration 108 refused. The transitions are policed in
 * {@see \Whity\Core\Convening\MeetingStatus}.
 *
 * DECISION NUMBERS COME FROM `sequence_counters`, NOT FROM A COLUMN HERE
 * ---------------------------------------------------------------------
 * `decision_number` is a stored STRING and there is deliberately no counter
 * column on `convening_bodies` to derive it from. Numbering is allocated through
 * {@see \Whity\Database\SequenceCounters} under a per-body, per-year counter name
 * — core's one implementation of "hand out the next number", already tenant
 * scoped, already cascade-deleted, already the subject of the argument about
 * unique-versus-gapless that a hand-rolled `MAX(seq) + 1` gets wrong under
 * concurrency. See {@see \Whity\Core\Convening\DecisionNumbers}.
 *
 * The uniqueness index is per TENANT rather than per body, because a decision
 * number is quoted in correspondence with the body's key already inside it
 * (`board/2026/14`), and two bodies that could mint the same string would make
 * that quotation ambiguous.
 *
 * WHY A DECISION RECORDS THE ROUTE IT DROVE
 * -----------------------------------------
 * `route_id` / `route_event_id` are nullable and are filled in only when the
 * decision actually moved a document through the routing engine. They are here
 * rather than inferred because the alternative is a record that cannot tell
 * "this decision approved a document and advanced its route" from "this decision
 * approved a document that was not routed to this body, and nothing happened" —
 * and the second one reads, on every screen, exactly like the first. That is the
 * stored-intention-that-silently-does-nothing failure the routing subsystem is
 * written against, and the two columns are what make it visible.
 *
 * They point at the trail; they are not a copy of it. Nothing in this subsystem
 * reads routing state back through them.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down(). The permission GRANTS
 * live in migration 131, mirroring the 108/109 and 112/113 split: this migration
 * puts the three slugs in the catalogue so they exist, and the next one decides
 * who holds them.
 */
final class CreateConvening
{
    /**
     * The three slugs this migration introduces.
     *
     * Descriptions are written for somebody reading a permission picker, so they
     * say what the permission LETS A PERSON DO rather than restating the slug.
     *
     * THREE rather than a read/write pair, because RECORDING A DECISION is not a
     * write like the others. Assembling an agenda, moving a date and sending
     * invitations are secretarial acts an organisation hands to whoever runs the
     * calendar. Minuting what the body concluded is the act that can advance or
     * reject somebody's document, and an institution will want it held by fewer
     * people. Each of the three is a permission somebody would revoke separately,
     * which is the test for whether a slug is a real capability or a second name
     * for an existing one.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        CorePermissions::CONVENING_READ =>
            'See the tenant\'s convening bodies, their meetings, agendas and recorded decisions',
        CorePermissions::CONVENING_MANAGE =>
            'Create and change convening bodies and their membership, build agendas, schedule '
            . 'meetings and send invitations',
        CorePermissions::CONVENING_DECIDE =>
            'Record a body\'s decision on an agenda item — the act that can approve or reject a '
            . 'document awaiting that body',
    ];

    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement per table, never a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest re-derive
        // their registries by scanning this source, so every name has to appear
        // literally. Migrations 059, 108, 112, 114, 116, 118, 120 and 126 carry
        // the same note, and spell the keyword hyphenated in prose for the same
        // reason: the schema test scans the raw file text for it and would read a
        // plain one inside a comment as a real table declaration.
        //
        // `body_key` (not `key`) dodges the reserved word across the PostgreSQL
        // and SQLite-test engines — the same dodge `ou_types.type_key`,
        // `tag_groups.group_key` and `time_window_types.type_key` make.
        //
        // `name` is TEXT holding a JSON object of locale => label, not a
        // VARCHAR: this platform's Arabic/RTL requirement is not a display
        // setting, it is that a body HAS two names and both are the real one.
        // A second column per locale would need a migration for the third
        // language; a JSON object needs none. It is TEXT rather than JSONB
        // because it is only ever read whole and handed to a client, and nothing
        // queries inside it — JSONB would buy an index nobody would use, at the
        // cost of a type SQLite has to be told to fake.
        $db->exec("
            CREATE TABLE IF NOT EXISTS convening_bodies (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                body_key    VARCHAR(128) NOT NULL,
                name        TEXT         NOT NULL,
                ou_id       INTEGER      REFERENCES organizational_units(id) ON DELETE SET NULL,
                description TEXT,
                is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, body_key)
            )
        ");

        // The unique constraint's own index serves key lookup. This one exists
        // for the TENANT PREDICATE and for the ordinary list, which is "this
        // tenant's bodies, active ones first".
        $db->exec('CREATE INDEX IF NOT EXISTS idx_convening_bodies_tenant_id ON convening_bodies(tenant_id)');
        // "Which bodies belong to this unit?" — the read a unit's page makes.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_convening_bodies_tenant_ou
                ON convening_bodies(tenant_id, ou_id)'
        );

        // `member_role` is a three-value vocabulary and the CHECK says so. It is
        // a SEAT, not a permission: holding the chair of a body grants nothing
        // in RBAC, and every act in this subsystem is gated by the slugs above.
        // The seat matters because a body's decision has to be answered to the
        // routing engine by SOMEBODY the route asked, and the chair is the first
        // candidate — see DecisionRouteBridge.
        //
        // `left_at` NULL means "still a member". A departure is recorded, never
        // deleted: a decision taken in March was taken by the body as it was
        // then, and a removed row makes that unreconstructible.
        $db->exec("
            CREATE TABLE IF NOT EXISTS convening_body_members (
                id          BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id   INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                body_id     BIGINT      NOT NULL REFERENCES convening_bodies(id) ON DELETE CASCADE,
                profile_id  INTEGER     NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                member_role VARCHAR(16) NOT NULL DEFAULT 'member',
                joined_at   TIMESTAMP   NOT NULL DEFAULT NOW(),
                left_at     TIMESTAMP,
                CHECK (member_role IN ('chair', 'secretary', 'member'))
            )
        ");

        // ONE CURRENT SEAT PER PERSON PER BODY, and past seats unconstrained. A
        // plain UNIQUE (body_id, profile_id) would refuse the rejoin case
        // outright; a partial index refuses only the thing that is actually
        // wrong, which is holding two open seats at once. Migration 112 uses the
        // same construction on `document_route_recipients` for the same reason.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_convening_body_members_current
                ON convening_body_members(body_id, profile_id) WHERE left_at IS NULL'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_convening_body_members_tenant_body
                ON convening_body_members(tenant_id, body_id)'
        );
        // "Which bodies does this person sit on?" — the read that answers
        // "should I be invited to anything", entered through the tenant.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_convening_body_members_tenant_profile
                ON convening_body_members(tenant_id, profile_id)'
        );

        // `meeting_number` is per BODY, allocated from `sequence_counters` like
        // the decision number, and unique within the body rather than the
        // tenant: two bodies each holding their fourteenth meeting is the
        // ordinary case, and a tenant-wide sequence would make one body's
        // numbering jump every time another body met.
        //
        // `status` is the ONLY record of where a sitting has got to — see the
        // class docblock for why that is a column here and was refused on
        // `documents`. `cancelled` is in the vocabulary because a sitting that
        // was called off is a fact the minute-book needs; deleting the row would
        // take its agenda with it.
        $db->exec("
            CREATE TABLE IF NOT EXISTS meetings (
                id                    BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                body_id               BIGINT       NOT NULL REFERENCES convening_bodies(id) ON DELETE CASCADE,
                meeting_number        INTEGER      NOT NULL,
                title                 TEXT         NOT NULL,
                scheduled_at          TIMESTAMP,
                held_at               TIMESTAMP,
                location              VARCHAR(255),
                status                VARCHAR(16)  NOT NULL DEFAULT 'draft',
                created_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, body_id, meeting_number),
                CHECK (status IN ('draft', 'scheduled', 'held', 'cancelled'))
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_meetings_tenant_id ON meetings(tenant_id)');
        // THE LIST READ: "this body's meetings, most recent first". `id`
        // descending rather than a date, because a draft has no date at all and
        // ordering on a nullable column puts every draft in one indistinct heap.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meetings_tenant_body
                ON meetings(tenant_id, body_id, id)'
        );
        // "What is coming up?" — the calendar read, which is the one place a
        // null `scheduled_at` is being deliberately excluded.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meetings_tenant_scheduled
                ON meetings(tenant_id, scheduled_at)'
        );

        // `position` is a 1-based ordinal, unique within the meeting: it is what
        // an agenda IS. Reordering rewrites positions, which is why the
        // uniqueness is deferred through a two-phase write in
        // {@see \Whity\Core\Convening\AgendaRepository::reorder()} rather than
        // relaxed here — a unique constraint that has to be dropped to let the
        // ordinary edit work is not a constraint.
        //
        // `document_id` is NULLABLE and is the join to the rest of the platform:
        // an item may be a discussion with no paperwork, or it may BE a document
        // that is waiting for this body. ON DELETE SET NULL rather than CASCADE
        // — deleting a document must never silently remove an item a body
        // actually considered, because the minute would then read as though the
        // meeting never discussed it.
        $db->exec('
            CREATE TABLE IF NOT EXISTS meeting_agenda_items (
                id          BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id   INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                meeting_id  BIGINT    NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
                position    INTEGER   NOT NULL,
                title       TEXT      NOT NULL,
                document_id BIGINT    REFERENCES documents(id) ON DELETE SET NULL,
                notes       TEXT,
                created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (meeting_id, position)
            )
        ');

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_agenda_items_tenant_meeting
                ON meeting_agenda_items(tenant_id, meeting_id, position)'
        );
        // THE REVERSE READ: "which meetings has this document been before?",
        // which is what a document's own page asks. Without it that question is
        // a scan of every agenda item in the tenant.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_agenda_items_tenant_document
                ON meeting_agenda_items(tenant_id, document_id)'
        );

        // `verdict` carries a THIRD value the routing engine does not have:
        // `deferred`. That is the whole reason it is a separate vocabulary from
        // {@see \Whity\Core\Document\Routing\RouteVerdict} rather than a reuse of
        // it. A body that defers has decided something — the minute says so, and
        // the number is spent — but it has decided nothing the routing engine can
        // act on, and mapping it onto either of the engine's two values would
        // either advance a document nobody approved or reject one nobody refused.
        //
        // `decision_number` is unique per TENANT: the string already contains the
        // body's key, so two bodies minting the same one would make a quoted
        // reference ambiguous. See the class docblock.
        $db->exec("
            CREATE TABLE IF NOT EXISTS meeting_decisions (
                id                     BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id              INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                meeting_id             BIGINT      NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
                agenda_item_id         BIGINT      NOT NULL REFERENCES meeting_agenda_items(id) ON DELETE CASCADE,
                decision_number        VARCHAR(64) NOT NULL,
                verdict                VARCHAR(16) NOT NULL,
                rationale              TEXT,
                decided_at             TIMESTAMP   NOT NULL DEFAULT NOW(),
                recorded_by_profile_id INTEGER     REFERENCES profiles(id) ON DELETE SET NULL,
                route_id               BIGINT      REFERENCES document_routes(id) ON DELETE SET NULL,
                route_event_id         BIGINT      REFERENCES document_route_events(id) ON DELETE SET NULL,
                UNIQUE (tenant_id, decision_number),
                CHECK (verdict IN ('approved', 'rejected', 'deferred'))
            )
        ");

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_decisions_tenant_meeting
                ON meeting_decisions(tenant_id, meeting_id, id)'
        );
        // "What did the body conclude about this item?" — one row in the
        // ordinary case, more than one only where a body revisited an item at a
        // later sitting, which is why this is not a unique index.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_decisions_tenant_item
                ON meeting_decisions(tenant_id, agenda_item_id)'
        );

        // `status` starts at `invited` and the two timestamps are separate facts:
        // `sent_at` is when the invitation went out and `responded_at` is when
        // the person answered. NULL on the second is not "declined" — it is "has
        // not said", which is a different thing to report to a chair counting
        // heads before a sitting.
        //
        // UNIQUE (meeting_id, profile_id): inviting somebody twice to one sitting
        // is not a second invitation, it is the same one, and a duplicate row
        // would double-count them in every attendance figure.
        $db->exec("
            CREATE TABLE IF NOT EXISTS meeting_invitations (
                id           BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id    INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                meeting_id   BIGINT      NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
                profile_id   INTEGER     NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                status       VARCHAR(16) NOT NULL DEFAULT 'invited',
                sent_at      TIMESTAMP,
                responded_at TIMESTAMP,
                UNIQUE (meeting_id, profile_id),
                CHECK (status IN ('invited', 'accepted', 'declined', 'tentative'))
            )
        ");

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_invitations_tenant_meeting
                ON meeting_invitations(tenant_id, meeting_id)'
        );
        // "What am I invited to?" — the read a person's own screen makes,
        // entered through the tenant as the predicate guard requires.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_meeting_invitations_tenant_profile
                ON meeting_invitations(tenant_id, profile_id)'
        );

        foreach (self::PERMISSIONS as $name => $description) {
            // Migration 013 seeds the whole CorePermissions list, so on a fresh
            // install these rows already exist by the time this runs; the insert
            // is here so the migration stands on its own against a database whose
            // catalogue drifted, and it can never overwrite a human-written
            // description (ON CONFLICT DO NOTHING).
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }
    }

    public static function down(Database $db): void
    {
        // Children before parents: each holds a foreign key into the one after
        // it. CASCADE on the DROP covers it on PostgreSQL, but SQLite (the
        // test-schema engine) has no such clause, and ordering costs nothing.
        $db->exec('DROP INDEX IF EXISTS idx_meeting_invitations_tenant_profile');
        $db->exec('DROP INDEX IF EXISTS idx_meeting_invitations_tenant_meeting');
        $db->exec('DROP TABLE IF EXISTS meeting_invitations CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_meeting_decisions_tenant_item');
        $db->exec('DROP INDEX IF EXISTS idx_meeting_decisions_tenant_meeting');
        $db->exec('DROP TABLE IF EXISTS meeting_decisions CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_meeting_agenda_items_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_meeting_agenda_items_tenant_meeting');
        $db->exec('DROP TABLE IF EXISTS meeting_agenda_items CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_meetings_tenant_scheduled');
        $db->exec('DROP INDEX IF EXISTS idx_meetings_tenant_body');
        $db->exec('DROP INDEX IF EXISTS idx_meetings_tenant_id');
        $db->exec('DROP TABLE IF EXISTS meetings CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_convening_body_members_tenant_profile');
        $db->exec('DROP INDEX IF EXISTS idx_convening_body_members_tenant_body');
        $db->exec('DROP INDEX IF EXISTS uq_convening_body_members_current');
        $db->exec('DROP TABLE IF EXISTS convening_body_members CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_convening_bodies_tenant_ou');
        $db->exec('DROP INDEX IF EXISTS idx_convening_bodies_tenant_id');
        $db->exec('DROP TABLE IF EXISTS convening_bodies CASCADE');

        // THE COUNTERS THIS SUBSYSTEM OPENED, which live in a table this
        // migration does not own and therefore does not drop. Left behind, they
        // are rows named after tables that no longer exist — and a re-applied
        // migration would resume numbering from where the dropped data stopped,
        // so a rolled-back-and-reinstalled deployment would mint its first
        // meeting as number 7. Removed by NAME PREFIX rather than by tenant,
        // because a down() is a schema act and every tenant's convening counters
        // go with the schema.
        //
        // Scoped by prefix and nothing else: `LIKE 'convening:%'` cannot reach a
        // counter another subsystem opened, since every name here is written by
        // MeetingRepository::counterName() or DecisionNumbers::counterName().
        $db->query("DELETE FROM sequence_counters WHERE name LIKE 'convening:%'");

        // Only when nothing else holds it. Migration 013 seeded the catalogue and
        // owns its removal; this clause is the safety net for a database where
        // 013's catalogue step did not run. Migration 131 owns the GRANTS and
        // takes them back itself.
        foreach (array_keys(self::PERMISSIONS) as $slug) {
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $slug]
            );
        }
    }
}
