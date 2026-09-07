<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddPublicFormLinks — the OPT-IN door that lets somebody with no account at all
 * fill in one of a tenant's forms.
 *
 * WHAT THIS IS FOR
 * ----------------
 * Migration 127 built a form a tenant MEMBER fills in: every route in the
 * subsystem is gated on `forms:submit`, `forms:read` or `forms:manage`, and all
 * three are permissions inside one organisation. The case that has no home there
 * is the one where the person answering is not in the organisation and never
 * will be — an external applicant filing a research request, a supplier
 * declaring something, a member of the public applying for a permit. Today that
 * person is handed a spreadsheet by email, which is the exact failure migration
 * 127's docblock opens by describing, one audience further out.
 *
 * So a form may be OPENED. Not every form, not by default, and not by anybody:
 * `public_enabled` is NOT NULL DEFAULT FALSE, and the only way it becomes true
 * is a deliberate `POST /api/v1/forms/{id}/public-link` from a caller holding
 * `forms:manage`. A migration that added this as an opt-OUT would have opened
 * every form in every install the moment it ran.
 *
 * WHY THE SLUG IS 64 RANDOM HEX CHARACTERS AND NOT `forms/41`
 * -----------------------------------------------------------
 * This is the whole security posture of the feature and it belongs in the schema
 * rather than in a service, because the column width is what makes the shorter
 * answer impossible to store later.
 *
 * The public endpoints have NO other credential. There is no session, no token
 * header, no tenant header — by construction, because the caller has no account.
 * The slug is therefore the ONLY thing standing between a stranger and the form,
 * and everything the endpoint will and will not disclose is downstream of how
 * hard the slug is to guess.
 *
 * A sequential id, or a slug derived from `form_key`, is a form ANYBODY CAN
 * ENUMERATE: `/api/v1/public/forms/1`, `/2`, `/3` walks the install, and
 * `/public/forms/staff-grievance` walks it faster because the names are
 * guessable without any walking at all. Enumeration here is not merely "you can
 * find the forms" — it is a census of which organisations use this install, what
 * they collect, and what their internal processes are called, assembled by
 * anybody with curl.
 *
 * {@see \Whity\Core\Form\PublicFormLink} mints 32 bytes from `random_bytes()`
 * hex-encoded to 64 characters — 256 bits, the same strength
 * `DocumentQrService::TOKEN_BYTES` and `InvitationService::TOKEN_BYTES` already
 * use, and deliberately not a third number. At that width the expected number of
 * guesses to hit ANY live slug is astronomically beyond what a rate-limited HTTP
 * endpoint could serve, which is what makes it defensible for the public render
 * to answer 200 for a real slug and 404 for an unknown one: the distinction is
 * only an oracle if the namespace is walkable, and this one is not.
 *
 * `VARCHAR(64)` is the exact encoded width rather than a round number, so the
 * column and {@see \Whity\Core\Form\PublicFormLink::SLUG_BYTES} agree instead of
 * one silently truncating what the other minted.
 *
 * WHY THE UNIQUE INDEX IS GLOBAL AND NOT PER-TENANT
 * --------------------------------------------------
 * Every other uniqueness rule on `forms` is `(tenant_id, …)`, and departing from
 * that needs a reason. Here is the reason: the public lookup HAS NO TENANT TO
 * BIND. That is not an oversight in the query, it is the feature — the tenant
 * must be resolved FROM the slug, never from a header or a host the anonymous
 * caller controls, because anything the caller supplies is a value the caller
 * chose. A `UNIQUE (tenant_id, public_slug)` index permits two tenants to hold
 * the same slug, and the moment they do, "which form does this slug name" has
 * two answers and the lookup picks one — a cross-tenant read decided by a query
 * plan.
 *
 * A global partial unique index is STRICTLY STRONGER than the per-tenant one:
 * anything it permits, the per-tenant rule permits too. So this satisfies the
 * per-tenant requirement and additionally makes the anonymous lookup total.
 *
 * PARTIAL — `WHERE public_slug IS NOT NULL` — because a null is the ordinary
 * state of almost every row in the table and PostgreSQL would treat each of them
 * as distinct anyway; the predicate says so out loud and keeps the index the size
 * of the opened forms rather than the size of `forms`.
 *
 * WHY A WINDOW, AND WHY IT IS TWO NULLABLE COLUMNS RATHER THAN A BOOLEAN
 * ----------------------------------------------------------------------
 * `public_opens_at` / `public_closes_at` exist because the realistic use is a
 * DEADLINE: applications open on the 1st and close on the 30th, and the person
 * who published the link is not going to be awake at midnight to revoke it. A
 * feature that can only be closed by hand is a feature that stays open.
 *
 * Both are NULLABLE and null means "no boundary on this side", which is the
 * ordinary case and must not require a sentinel date. A pair of far-future /
 * far-past defaults would make "unbounded" a value somebody has to recognise,
 * and would be wrong the day somebody's real deadline is in 2099.
 *
 * The window governs SUBMISSION only. A form outside its window still RENDERS
 * and says so ({@see \Whity\Api\PublicFormsApiHandler}) — a poster printed in
 * March must still explain itself in November rather than 404ing, which is the
 * same argument `FormsApiHandler::render()` makes about an archived form. The
 * HARD closes — `public_enabled = FALSE`, and any status other than `published`
 * — are the ones that collapse to the generic 404.
 *
 * WHY THE ENABLING IS RECORDED
 * -----------------------------
 * `public_enabled_at` and `public_enabled_by_profile_id` record who opened the
 * door and when. Opening a form to the entire internet is the single most
 * consequential thing `forms:manage` can do, and "who did this" is the first
 * question anybody asks afterwards. `ON DELETE SET NULL` matches
 * `created_by_profile_id` above it: a profile being removed must not take the
 * form with it, and a null there reads as "the person who did this is gone",
 * which is true.
 *
 * They are cleared when the link is closed, so the pair always describes the
 * CURRENT opening rather than the last one that ever happened — a stale
 * "enabled by Fatima on the 3rd" beside `public_enabled = false` would be a row
 * that reads as open and is not. The audit trail of past openings is
 * `audit_log`'s job, not this table's.
 *
 * NO NEW TABLE, so {@see \Whity\Core\Tenant\TenantOwnedTables} and
 * {@see \Whity\Core\Tenant\CoreTables} gain no entry — `forms` is already
 * registered in both. TenantOwnedTables' note on `forms` IS amended, because
 * this migration introduces the subsystem's first read with no tenant predicate
 * ({@see \Whity\Core\Form\FormRepository::findByPublicSlug()}), and a registry
 * that did not record the exception would make it look like an oversight.
 *
 * Idempotent (ADD COLUMN IF NOT EXISTS / CREATE INDEX IF NOT EXISTS) and
 * reversible via down(). It alters ONE table and creates none.
 */
final class AddPublicFormLinks
{
    public static function up(Database $db): void
    {
        // One ADD COLUMN statement per column rather than PostgreSQL's
        // multi-column extension: SchemaFromMigrations splits those for the
        // SQLite path, and a migration that only works because a translation
        // layer rewrites it is a migration whose behaviour depends on the
        // engine reading it.
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS public_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS public_slug VARCHAR(64)');
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS public_opens_at TIMESTAMP');
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS public_closes_at TIMESTAMP');
        $db->exec('ALTER TABLE forms ADD COLUMN IF NOT EXISTS public_enabled_at TIMESTAMP');
        $db->exec(
            'ALTER TABLE forms ADD COLUMN IF NOT EXISTS public_enabled_by_profile_id INTEGER
                 REFERENCES profiles(id) ON DELETE SET NULL'
        );

        // THE index of this migration. Global, unique, partial — see the class
        // docblock for why it is not `(tenant_id, public_slug)`. It is also the
        // ONLY index the anonymous read uses: the public lookup is
        // `WHERE public_slug = :slug` with nothing else to narrow it, so this
        // index is both the correctness constraint and the access path.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_forms_public_slug
                 ON forms(public_slug) WHERE public_slug IS NOT NULL'
        );

        // "Which of this tenant's forms are open to the public" — the question
        // the builder asks and the one an administrator asks when they want to
        // know what their organisation has exposed. Starts from `tenant_id`
        // because every tenant-scoped read binds one.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_forms_tenant_public_enabled
                 ON forms(tenant_id, public_enabled)'
        );
    }

    public static function down(Database $db): void
    {
        // Indexes before their columns: dropping the column would take the index
        // with it on PostgreSQL, but saying so explicitly keeps the down path
        // readable on an engine where it would not.
        $db->exec('DROP INDEX IF EXISTS idx_forms_tenant_public_enabled');
        $db->exec('DROP INDEX IF EXISTS idx_forms_public_slug');

        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS public_enabled_by_profile_id');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS public_enabled_at');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS public_closes_at');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS public_opens_at');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS public_slug');
        $db->exec('ALTER TABLE forms DROP COLUMN IF EXISTS public_enabled');
    }
}
