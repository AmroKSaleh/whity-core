<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDocumentRouting (#947 item 3) — the routing engine: a route per
 * document, its ORDERED steps, an APPEND-ONLY event trail, and the recipient
 * rows that are the inbox.
 *
 * WHY THIS EXISTS
 * ---------------
 * Migration 108 gave core documents an identity and immutable bytes. It gave
 * them nothing that says what HAPPENED to one. A document that cannot record
 * who received it, who acted on it and in which order is a file with a title;
 * every approval, sign-off and circulation workflow a plugin might build has to
 * invent that record for itself, and #947 records why that is worse than one
 * shared engine: two append-only audit trails with different immutability
 * guarantees is a divergence nobody can reconcile after the fact.
 *
 * FOUR TABLES, AND WHY THE NAMES CARRY A PREFIX
 * ---------------------------------------------
 * #947 names them `routes`, `route_steps`, the trail and `recipients` — by the
 * role each plays in the design. The tables are spelled `document_routes`,
 * `document_route_steps`, `document_route_events` and
 * `document_route_recipients` because the table namespace is FLAT and shared
 * with every plugin that ships a migration. {@see \Whity\Core\Tenant\TableOwnershipRegistry}
 * exists precisely because plugins claim table names, and a bare `recipients`
 * is a name a notification plugin would reach for on its first day. Migration
 * 059 (`document_templates`, `document_blocks`) and 108 (`document_artifacts`)
 * already set the prefix convention for this subsystem.
 *
 *   document_routes            — one routing of one document. A document may be
 *                                routed more than once over its life (a
 *                                correction is circulated again), so this is
 *                                1..N from `documents`, never a column on it.
 *   document_route_steps       — the ordered plan. Each step names a RULE.
 *   document_route_events      — the trail. Insert-only. The system of record.
 *   document_route_recipients  — the inbox. Who a step actually resolved to,
 *                                and two pointers INTO the trail.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SEMANTIC 1: A STEP NAMES A RULE, NEVER A PERSON
 * ─────────────────────────────────────────────────────────────────────────
 * `document_route_steps` has `rule_kind` + `rule_config` and NO profile column.
 * There is nowhere to store a person, which is the point: the rule is resolved
 * at SEND time, against the organisation as it stands at that moment, so a unit
 * created last week is included automatically.
 *
 * The rejected alternative is not merely worse, it is worse SILENTLY. A stored
 * recipient list omits the new unit, the document still renders, every route
 * step still completes and the run reports success — the failure surfaces weeks
 * later when somebody asks why a department never received something, and by
 * then the list has been copied into a dozen more routes. A rule that resolves
 * to nobody at least resolves to nobody visibly, in a recipient set the trail
 * records as empty.
 *
 * `rule_config` IS JSONB, and that is not a contradiction of the trail's fixed
 * shape below. A rule's parameters are OPEN by construction: core cannot know
 * what an `acme:committee` rule needs to be told, and the only code that does
 * know is the resolver the plugin registered. So the config is validated at
 * write time by that resolver ({@see \Whity\Sdk\Routing\RoutingRuleResolverInterface::validate()})
 * and stored opaquely. The trail's shape, by contrast, is fixed and known to
 * core — which is exactly why it gets columns.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SEMANTIC 2: DISTRIBUTION FANS OUT, IT DOES NOT BLOCK
 * ─────────────────────────────────────────────────────────────────────────
 * There is NO step-level completion state anywhere in this schema — no
 * `steps.completed_at`, no counter of how many recipients have answered. That
 * absence is the enforcement.
 *
 * Instead each recipient row carries `parent_recipient_id`, a self-reference to
 * the recipient row whose action produced it. A recipient acts, and the NEXT
 * step is resolved relative to THEM: their unit, their position in the tree.
 * The chains proceed independently and nothing anywhere can express "step 3 is
 * waiting for step 2 to finish", because there is no row that would hold it.
 *
 * A global barrier — the shape this deliberately cannot express — holds the
 * entire distribution for the slowest participant. One person on leave stops a
 * circular reaching two hundred people who were never waiting on them, and the
 * system reports the whole thing as in progress rather than as ninety-eight
 * per cent delivered.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SEMANTIC 3: THE TRAIL IS APPEND-ONLY
 * ─────────────────────────────────────────────────────────────────────────
 * `document_route_events` has no `updated_at`, no soft-delete column and no
 * mutable field of any kind. Every column is written once at insert, and
 * nothing in `src/` ever issues an UPDATE against this table — the engine
 * RESOLVES a step before appending its event, so the row carries its
 * destination unit from the start rather than being revised once the rule has
 * answered.
 * {@see \Whity\Core\Document\Routing\RouteEventRepository} exposes `append()`
 * and reads, and no update or delete method exists to be called — the same
 * construction {@see \Whity\Core\Document\DocumentArtifactRepository} uses one
 * table over, and for the same reason: a store that offers an UPDATE is a store
 * where somebody eventually calls it.
 *
 * A correction is a NEW event (`noted`), not an edit of an old one. The moment
 * somebody most wants to tidy history — a name misspelled in a note, a forward
 * sent to the wrong unit — is exactly the moment the record must be immutable,
 * because that is the moment its value is being tested.
 *
 * WHY ITS OWN TABLE RATHER THAN `domain_events`
 * ---------------------------------------------
 * Core already has an append-only event log with an outbox relay (migration
 * 066): `domain_events(event_name, aggregate_type, aggregate_id, actor_user_id,
 * payload JSONB, occurred_at)`. Reusing it was the tempting answer and it is
 * the wrong one, for three reasons that are all about what a database can
 * GUARANTEE rather than what a convention can ask for:
 *
 *  1. THE SHAPE CANNOT BE CONSTRAINED. A routing event is (actor, action, from
 *     unit, to unit, note) — five facts, all known to core, three of them
 *     naming other tables. As JSONB keys they are a convention the first buggy
 *     writer breaks silently. As columns they are `NOT NULL`, a `CHECK` on the
 *     action vocabulary, and real foreign keys. The trail is precisely the
 *     thing that must never be quietly wrong, so it is the last place to accept
 *     a shape the engine will not enforce.
 *
 *  2. THERE CAN BE NO FOREIGN KEY. `domain_events.aggregate_id` is
 *     `VARCHAR(191)`, because it addresses every aggregate in the platform. A
 *     trail row could therefore name a document that never existed, or outlive
 *     one that was deleted, and nothing would notice. Here `document_id` is a
 *     real reference with a real `ON DELETE` decision.
 *
 *  3. THE QUERIES ARE THE WRONG SHAPE. #947 item 5's folders — "acted on by
 *     me", "passed through my unit" — are indexed predicates on `actor_profile_id`
 *     and the two OU columns. Over `domain_events` they become JSONB
 *     containment scans against a table whose row count is the SUM of every
 *     subsystem's activity, competing for one shared index with notifications,
 *     sync and audit.
 *
 * WHAT REUSE WOULD HAVE BOUGHT, AND HOW IT IS PAID FOR INSTEAD
 * -----------------------------------------------------------
 * Two real things. First, one append-only surface instead of two — which is
 * #947's own argument for putting routing in core at all, and it would be
 * hypocritical to wave it away. Second, the outbox relay: every routing action
 * would become a subscribable domain event for free, and #947 notes that
 * documents currently emit NOTHING into the spine.
 *
 * Both are paid for without moving the system of record.
 * {@see \Whity\Core\Document\Routing\DocumentRouter} dispatches every appended
 * event through `HookManager::dispatchAsync()`, so the spine and its relay
 * carry routing exactly as they carry everything else. The two cannot disagree
 * because the emission is DERIVED from the insert, in the same call, in one
 * direction only: trail → spine, never spine → trail. That is not two trails;
 * it is one trail and one broadcast. #947's objection is to two records each
 * read as authoritative, and only one of these ever is.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE RECIPIENT ROW HOLDS NO STATE OF ITS OWN
 * ─────────────────────────────────────────────────────────────────────────
 * This is the schema's answer to migration 108's "there is deliberately no
 * `status` column". The inbox needs to know whether an item is open, and the
 * obvious column — `status VARCHAR` — would be a second copy of an answer the
 * trail already gives, free to disagree with it, and it is the copy screens
 * read.
 *
 * So the row carries two FOREIGN KEYS INTO THE TRAIL instead:
 *
 *   created_by_event_id — the event that put this in your inbox (NOT NULL)
 *   closed_by_event_id  — the event that took it out   (NULL = still open)
 *
 * Their values can only ever be trail row ids, so they cannot say anything the
 * trail does not also say. "Awaiting me" is `closed_by_event_id IS NULL`; the
 * item's status as rendered in an inbox is the ACTION of the event that created
 * it (`issued`, `forwarded`, `returned`) — read from the trail through the
 * pointer, never stored again beside it.
 *
 * The rejected alternative here was deriving open-ness with a correlated
 * `NOT EXISTS` against the trail on every read. It is correct and it is the
 * hottest query in the subsystem — it is the inbox, and it is also item 5's
 * "awaiting me" folder — so it would be a full trail scan per document, per
 * page view, forever.
 *
 * These two columns are the ONLY mutable ones in the four tables, and the row
 * they sit on is not claimed to be append-only. It is a PROJECTION: a record of
 * which people a rule actually resolved to, and when, which the trail cannot
 * re-derive afterwards because rules resolve against the organisation as it
 * stood at that instant. Everything else about it is a pointer.
 *
 * WHY THERE IS NO `recipient_id` ON THE EVENT
 * -------------------------------------------
 * The obvious symmetry — an event naming the recipient row it is about — makes
 * the two tables reference each other, and an append-only table cannot be
 * inserted into if it must first know an id that does not exist yet. The
 * dependency runs ONE way: events are insertable knowing only the document, the
 * route, the step and the actor. Which recipient acted is `(route, step,
 * actor)`, which the trail already carries.
 *
 * FOREIGN KEYS, AND THE `ON DELETE` EACH ONE GETS
 * -----------------------------------------------
 * #751 landed because two core tables named a profile with no key, and
 * scripts/ci-undeclared-reference-guard.php now lints core's migrations. Every
 * reference here is enforced and none of the actions is a default:
 *
 *  - `tenant_id → tenants CASCADE` on all four. What every tenant-owned table
 *    does.
 *
 *  - `document_id → documents CASCADE` on all four. The trail is ABOUT the
 *    document; keeping orphan trail rows after the document is gone leaves a
 *    history of a thing that no longer exists, reachable through no
 *    tenant-scoped surface and answering no question anybody can ask. Note this
 *    is not a hole in immutability: `documents` itself is only deleted by its
 *    own tenant's removal, and the repositories here offer no delete path at
 *    all.
 *
 *  - `route_id → document_routes CASCADE`, `step_id → document_route_steps
 *    CASCADE`. Reachable only through the document's own deletion, above.
 *    RESTRICT was considered and rejected: it would make a tenant undeletable
 *    the moment anything was routed, turning a data-protection obligation into
 *    a support ticket.
 *
 *  - `document_route_events.step_id` is NULLABLE and SET NULL. An `issued`
 *    event is about the ROUTE, not about any one step, so the column is absent
 *    rather than pointed somewhere plausible.
 *
 *  - `actor_profile_id → profiles SET NULL`, NULLABLE. The same choice
 *    migration 108 argues for `documents.created_by`, and for the same reason:
 *    a routing trail is an organisational record that outlives the people in
 *    it, and cascading would let one leaver's departure delete other people's
 *    audit history. The pointer IS the personal datum core stores here, so
 *    nulling it is the erasure rather than an evasion of it. The event itself
 *    survives, so "somebody forwarded this on 4 March" stays true.
 *
 *  - `from_ou_id` / `to_ou_id` `→ organizational_units SET NULL`, NULLABLE.
 *    What `memberships.ou_id` (migration 030) and `documents.origin_ou_id`
 *    (108) do: reorganisations dissolve units routinely and must not delete the
 *    history that passed through them.
 *
 *  - `document_route_recipients.profile_id → profiles CASCADE`, NOT NULL —
 *    and this one deliberately DISAGREES with the actor column above. An
 *    inbox row is not history, it is a live assignment addressed to one person;
 *    a nulled profile would leave an item permanently open, addressed to
 *    nobody, that no query could ever close. The person's HISTORY is in the
 *    trail, where it is preserved by SET NULL. Deleting the assignment and
 *    keeping the record of what they did is the correct split.
 *
 *  - `created_by_event_id → document_route_events CASCADE`, NOT NULL;
 *    `closed_by_event_id → document_route_events CASCADE`, NULLABLE. CASCADE
 *    rather than SET NULL on the closer, deliberately: SET NULL would silently
 *    REOPEN a closed item if its event vanished, which is the one direction an
 *    inbox must never move on its own. Events are only ever removed by the
 *    document cascade, which removes these rows too.
 *
 *  - `parent_recipient_id → document_route_recipients CASCADE`, NULLABLE. NULL
 *    at the first step, because nothing preceded it.
 *
 * WHY THE OPEN-ITEM UNIQUENESS IS A PARTIAL INDEX
 * -----------------------------------------------
 * `UNIQUE (route_id, step_id, profile_id) WHERE closed_by_event_id IS NULL`.
 *
 * A plain UNIQUE over the triple would say a person may appear at a step ONCE
 * EVER, which forbids the `returned` flow: a document sent back to whoever
 * forwarded it has to reappear in their inbox, and it must reappear as a NEW
 * row rather than by clearing the old row's `closed_by_event_id` — clearing it
 * would erase the fact that they acted, which is the trail's business and not
 * the inbox's to overwrite. So: at most one OPEN item per person per step, an
 * unbounded history of closed ones. Migration 088 already uses partial unique
 * indexes for the same "NULL changes what uniqueness means" reason.
 *
 * It doubles as the fan-out de-duplicator. Two chains can legitimately reach
 * the same person at the same step; they should see one item, not two, and the
 * trail still records both forwards in full.
 *
 * WHAT IS DELIBERATELY DEFERRED, AND THE SEAM EACH ONE LEAVES
 * -----------------------------------------------------------
 * Three things a node-based flow EDITOR will want are not here. Each is a
 * follow-up rather than an omission, and each has a seam chosen so that landing
 * it is additive rather than a retrofit. Naming them here is the point: the next
 * author should not have to re-litigate whether `document_routes` was meant to
 * be the template.
 *
 *  1. REUSABLE ROUTE TEMPLATES. `document_routes.document_id` is NOT NULL, so a
 *     route is always an INSTANCE — one circulation of one document. A template
 *     is a different record, exactly as `document_templates` is a different
 *     record from `documents`: the thing DESIGNED and the thing that HAPPENED
 *     have different lifetimes, and the trail hangs off the second.
 *
 *     Seam: a `document_route_templates` / `document_route_template_steps` pair,
 *     plus a nullable `template_id` and a `template_name` SNAPSHOT on
 *     `document_routes` — the provenance shape migration 108 already argues for
 *     on `documents`. A template is then a CONSTRUCTOR for steps and appears
 *     nowhere in the trail. Nothing in these four tables changes type or
 *     nullability, which is why the columns are absent now rather than present
 *     and unwritten: a column no code fills is a column a reader cannot
 *     interpret, and the FK cannot be declared before its table exists.
 *
 *  2. BRANCHING. `position` cannot express "if approved go here, if returned go
 *     there" and is not asked to — see its note in up(). This is a different
 *     question from fan-out: `parent_recipient_id` already handles one step
 *     reaching many people, whereas branching is the FLOW choosing among
 *     different NEXT STEPS.
 *
 *     Seam: an edges table (`from_step_id`, `to_step_id`, condition) and one
 *     rewritten method, `RouteStepRepository::findNext()`. The condition
 *     vocabulary is deliberately not guessed at now — it is the thing an editor
 *     constrains, and inventing it before the editor exists is how it ends up
 *     with a verb the editor cannot draw.
 *
 *  3. CONFIGURED EFFECTS ("send an email at this stage"). Not here, and not
 *     merely for scope: an effect declaration with no engine to run it is a
 *     stored intention that silently does nothing, which is the precise failure
 *     class this whole item is written against — something that still renders
 *     and still reports success while doing less than it claims.
 *
 *     The distinction worth preserving is that ROUTING says who must act and
 *     when a step is satisfied, while an EFFECT is a side effect on the world.
 *     "Approve" and "route" are routing; "send an email" is not.
 *
 *     Seam: a sibling registry beside {@see \Whity\Core\Document\Routing\RoutingRuleRegistry}
 *     and a `document_route_step_effects` table — a table rather than a column,
 *     because a step may declare several and their order matters. Note what the
 *     seam must NOT be: widening this table's `action` CHECK. An effect's
 *     outcome has its own fixed shape (which effect, succeeded or failed, how
 *     many attempts) that is not a human act's, and putting two shapes in one
 *     column would give up the exact guarantee the closed vocabulary buys.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateDocumentRouting
{
    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement per table, never a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest
        // re-derive their registries by scanning this source, so every name has
        // to appear literally. Migrations 059 and 108 carry the same note, and
        // spell the keyword in lowercase in prose for the same reason: the
        // schema test scans for the create keyword and would read a capitalised
        // one inside a comment as a real table declaration.
        $db->exec('
            CREATE TABLE IF NOT EXISTS document_routes (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id BIGINT       NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                title       VARCHAR(255) NOT NULL,
                created_by  INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_routes_tenant_id ON document_routes(tenant_id)');
        // "the routes on this document, newest first" — the only list this
        // table serves, entered through the tenant as the predicate guard
        // requires.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_routes_tenant_document
                ON document_routes(tenant_id, document_id, id)'
        );

        // `position` is a 1-based AUTHORING ORDINAL, unique within a route. Read
        // that precisely, because the obvious reading is the one that would make
        // branching a retrofit later:
        //
        //   it is the order steps were LAID OUT, not the depth of a step in the
        //   flow, and it is not the edge structure.
        //
        // Uniqueness is therefore still correct under a branching route — every
        // node in an editor's list has its own distinct ordinal, whichever branch
        // it sits on — whereas a `position` meaning "depth" would break the
        // moment two branches had a step at the same level, and would break by
        // admitting rows the constraint was written to refuse.
        //
        // WHERE "WHAT COMES NEXT" LIVES. In exactly one method,
        // {@see \Whity\Core\Document\Routing\RouteStepRepository::findNext()},
        // with exactly one caller
        // ({@see \Whity\Core\Document\Routing\DocumentRouter::act()}). Today it
        // answers "the next ordinal"; a branching route answers "the outgoing
        // edge whose condition the act matched". Keeping the linear assumption
        // behind one method is what makes an edges table an ADDITION rather than
        // a rewrite — see the deferral notes in the class docblock.
        //
        // `rule_kind` is the canonical key from
        // {@see \Whity\Core\Document\Routing\RoutingRuleRegistry}: bare for
        // core's own (`role`, `role_below_actor`), `plugin:slug` for a
        // registered one. 128 chars matches the key ceiling that registry
        // validates against, so a key that can be declared can always be stored.
        //
        // There is NO foreign key from `rule_kind` to a catalogue table, because
        // the catalogue is CODE (registered at boot by core and by whichever
        // plugins are installed) rather than rows. A step naming a kind whose
        // plugin has since been uninstalled is a real state, and it fails
        // LOUDLY at resolve time with the kind named, which is more useful than
        // a database that refused to store it and a route that could not be
        // read back at all.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_steps (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                route_id    BIGINT       NOT NULL REFERENCES document_routes(id) ON DELETE CASCADE,
                position    INTEGER      NOT NULL,
                rule_kind   VARCHAR(128) NOT NULL,
                rule_config JSONB        NOT NULL DEFAULT '{}'::jsonb,
                label       VARCHAR(255),
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (route_id, position)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_route_steps_tenant_id ON document_route_steps(tenant_id)');
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_steps_tenant_route
                ON document_route_steps(tenant_id, route_id, position)'
        );

        // THE TRAIL. Insert-only: no updated_at, no soft-delete, no mutable
        // column. See the class docblock for why this is a table of its own
        // rather than rows in `domain_events`.
        //
        // `action` is CHECK-constrained to core's vocabulary. A trail whose
        // action column accepts any string is a trail whose readers must handle
        // any string, and the first typo becomes a permanent row nothing renders
        // and nothing can correct. The vocabulary is closed on purpose and lives
        // in {@see \Whity\Core\Document\Routing\RouteAction}: unlike rule KINDS,
        // which plugins extend, what can HAPPEN to a routed document is the
        // engine's own semantics — a plugin adding a sixth verb would be adding
        // a state transition core does not implement.
        //
        // `from_ou_id` is the unit the actor acted FROM, captured at the moment
        // rather than derived on read: people move units, and re-deriving would
        // silently rewrite history for every past row (the same argument
        // migration 108 makes for `documents.origin_ou_id`).
        //
        // `to_ou_id` is the unit the act was directed AT, and it is NULL
        // whenever the act names no single unit. A `role_below_actor` fan-out
        // names the subtree root; a `returned` names the predecessor's unit; a
        // tenant-wide `role` fan-out names none — and inventing one there would
        // make item 5's "passed through my unit" folder report a unit that was
        // never involved, which is worse than the folder being silent.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_events (
                id               BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id        INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id      BIGINT      NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                route_id         BIGINT      NOT NULL REFERENCES document_routes(id) ON DELETE CASCADE,
                step_id          BIGINT      REFERENCES document_route_steps(id) ON DELETE SET NULL,
                actor_profile_id INTEGER     REFERENCES profiles(id) ON DELETE SET NULL,
                action           VARCHAR(32) NOT NULL,
                from_ou_id       INTEGER     REFERENCES organizational_units(id) ON DELETE SET NULL,
                to_ou_id         INTEGER     REFERENCES organizational_units(id) ON DELETE SET NULL,
                note             TEXT,
                occurred_at      TIMESTAMP   NOT NULL DEFAULT NOW(),
                CHECK (action IN ('issued', 'forwarded', 'acknowledged', 'returned', 'noted'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_route_events_tenant_id ON document_route_events(tenant_id)');
        // The trail as a document reads it: oldest first, one document at a
        // time. `id` is ascending and monotonic, so it orders the trail without
        // depending on clock resolution — two events in the same transaction
        // share `occurred_at` to the microsecond and would otherwise come back
        // in an arbitrary order.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_events_tenant_document
                ON document_route_events(tenant_id, document_id, id)'
        );
        // #947 item 5's "acted on by me" folder.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_events_tenant_actor
                ON document_route_events(tenant_id, actor_profile_id, id)'
        );
        // #947 item 5's "passed through my unit" folder. Two columns, two
        // indexes: a composite would only serve a query naming both, and the
        // folder asks about either end of a transition.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_events_tenant_from_ou
                ON document_route_events(tenant_id, from_ou_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_events_tenant_to_ou
                ON document_route_events(tenant_id, to_ou_id)'
        );

        // THE INBOX. See the class docblock: the only mutable column here is
        // `closed_by_event_id`, and its value is a trail row id.
        //
        // `ou_id` is the unit the recipient was reached THROUGH — which is not
        // always their primary unit, since a rule may resolve them through one
        // of several memberships. Captured at resolve time for the same reason
        // every other OU on a record is: it is a fact about what happened.
        $db->exec('
            CREATE TABLE IF NOT EXISTS document_route_recipients (
                id                  BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id           INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id         BIGINT    NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                route_id            BIGINT    NOT NULL REFERENCES document_routes(id) ON DELETE CASCADE,
                step_id             BIGINT    NOT NULL REFERENCES document_route_steps(id) ON DELETE CASCADE,
                profile_id          INTEGER   NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                ou_id               INTEGER   REFERENCES organizational_units(id) ON DELETE SET NULL,
                parent_recipient_id BIGINT    REFERENCES document_route_recipients(id) ON DELETE CASCADE,
                created_by_event_id BIGINT    NOT NULL REFERENCES document_route_events(id) ON DELETE CASCADE,
                closed_by_event_id  BIGINT    REFERENCES document_route_events(id) ON DELETE CASCADE,
                created_at          TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_recipients_tenant_id
                ON document_route_recipients(tenant_id)'
        );
        // THE INBOX QUERY, and #947 item 5's "awaiting me" folder: my open rows,
        // newest first. `closed_by_event_id` is in the index so the open filter
        // is answered from it rather than by visiting the heap.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_recipients_tenant_profile
                ON document_route_recipients(tenant_id, profile_id, closed_by_event_id, id)'
        );
        // "who is on this document's routes", and the visibility disjunct
        // {@see \Whity\Core\Document\DocumentVisibilityPolicy} adds.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_recipients_tenant_document
                ON document_route_recipients(tenant_id, document_id, profile_id)'
        );
        // The de-duplicating, return-permitting uniqueness rule. See the class
        // docblock for why this is partial rather than a plain UNIQUE.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_document_route_recipients_open
                ON document_route_recipients(route_id, step_id, profile_id)
             WHERE closed_by_event_id IS NULL'
        );
    }

    public static function down(Database $db): void
    {
        // Reverse dependency order: recipients name events and steps, events
        // name steps and routes, steps name routes. CASCADE on the DROP covers
        // it on PostgreSQL, but SQLite (the test-schema engine) has no such
        // clause and ordering costs nothing.
        $db->exec('DROP INDEX IF EXISTS uq_document_route_recipients_open');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_recipients_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_recipients_tenant_profile');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_recipients_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_route_recipients CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_document_route_events_tenant_to_ou');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_events_tenant_from_ou');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_events_tenant_actor');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_events_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_events_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_route_events CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_document_route_steps_tenant_route');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_steps_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_route_steps CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_document_routes_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_document_routes_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_routes CASCADE');
    }
}
