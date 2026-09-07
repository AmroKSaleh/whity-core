<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateInvoices — the billing record, its lines, and the payments against it.
 *
 * AN INVOICE IS EVIDENCE, NOT A VIEW. That is the whole design, and every
 * decision below follows from it. The obvious implementation stores an invoice
 * as a set of foreign keys — tenant, plan, price, promotion — and renders it by
 * joining. It looks correct, it passes every test written on the day it ships,
 * and it is wrong in a way that only shows up later: when the tenant renames
 * itself, the plan's price rises, the VAT rate changes or a promotion is
 * retired, LAST YEAR'S INVOICE SILENTLY REWRITES ITSELF. A document that says
 * something different depending on when it is opened is not evidence of
 * anything, and this is the one class of billing bug that is unrecoverable
 * after the fact: the original numbers are simply gone.
 *
 * So the amounts, the tax rate, the tax label, the line descriptions, and both
 * parties' names, addresses and tax registrations are all COPIED onto the
 * invoice when it is issued. The `plan_id` and `promotion_id` columns are kept
 * for tracing — "which campaign was this" — and are deliberately not what
 * anything reads to render the document. `ON DELETE SET NULL` on both says so
 * out loud: losing the trace must not lose the invoice.
 *
 * A DRAFT HAS NO NUMBER
 * ---------------------
 * `number` is NULL until the invoice is issued, and a CHECK enforces the pair.
 * This is not tidiness. Numbering is meant to be sequential, and the usual way
 * a sequence acquires holes is a draft that takes a number and is then
 * abandoned or edited into nothing. Allocating at issue means the only window
 * in which a number can be lost is between the allocation and the commit of the
 * single statement that stores it.
 *
 * That is a narrow window, not a closed one. {@see \Whity\Database\SequenceCounters}
 * is explicit that it yields numbers that are unique and monotonic but NOT
 * gapless, because gaplessness is not a database property — a crash between
 * allocating and committing leaves a hole no constraint can prevent. Where a
 * tax authority requires gapless numbering, the answer is the reconciliation
 * report that finds holes, not a claim in a docblock that there are none.
 *
 * `series` IS THE NAME OF THE SEQUENCE, and (series, number) is unique. One
 * index then enforces the only claim numbering actually makes — a sequence
 * never issues the same number twice — under BOTH numbering scopes. A platform
 * that invoices as a single legal seller uses one series for everybody; a
 * white-label deployment where each tenant is its own seller gives each tenant
 * its own series. The scope is a setting; the constraint does not have to know
 * which was chosen, because in both cases the series names the counter the
 * number came from.
 *
 * TAX IS A RATE IN BASIS POINTS, SNAPSHOT PER LINE
 * ------------------------------------------------
 * 15% is 1500 and 7.5% is 750, so a rate is an integer and never a float. It
 * lives on the LINE as well as the invoice because mixed-rate invoices are
 * ordinary — a zero-rated export line beside a standard-rated support line —
 * and an invoice-level rate alone makes those unrepresentable.
 *
 * `subtotal_minor` IS ALWAYS NET OF TAX, including when the operator quotes
 * tax-inclusive prices. That single convention is what lets one arithmetic
 * invariant hold on every row:
 *
 *     total = subtotal - discount + tax
 *
 * which is a CHECK, so an invoice that does not add up cannot be stored. It is
 * worth having as a constraint rather than a test because the bug it catches —
 * a total computed by a path that forgot the discount — produces a plausible
 * number, and a plausible wrong number on an invoice is found by a customer,
 * not by us.
 *
 * WHAT IS DELIBERATELY NOT STORED
 * -------------------------------
 * There is no `amount_paid` column. It is the sum of this invoice's
 * settled transactions (migration 143), and a stored copy is a second source of
 * truth that drifts the first time one is recorded by a path that forgot to
 * update it — a drift that reads as "the customer says they paid and our system
 * says they did not". The repository derives it in a subquery, the way the
 * promotions listing derives `redemption_count`.
 *
 * WHY tenant_id IS `ON DELETE RESTRICT` HERE, AND CASCADE EVERYWHERE ELSE
 * ----------------------------------------------------------------------
 * Deleting a tenant currently deletes everything that references it. For an
 * invoice that is not cleanup, it is the destruction of a tax record — six
 * years' retention in Saudi Arabia, seven to ten across most of the EU — and it
 * would happen silently, on the operator's own instance, in response to a
 * button labelled "delete tenant". RESTRICT makes the database refuse instead.
 * A tenant that has never been billed still deletes exactly as before, because
 * it has no invoices; the refusal arrives precisely when there is financial
 * history to lose, which is when somebody should be asked.
 */
final class CreateInvoices
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id             BIGSERIAL     NOT NULL PRIMARY KEY,

                -- RESTRICT, not CASCADE. See the class docblock: this is a tax
                -- record with a statutory retention period, and the tenant
                -- delete endpoint is expected to check before it asks.
                tenant_id      INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,

                -- OPTIONAL SUBUSER SCOPE. Almost every invoice is addressed to
                -- the tenant, and this is NULL. It exists because billing an
                -- individual member is a stated requirement and retrofitting a
                -- scope column onto a table of issued invoices means deciding
                -- what the existing rows meant — a question with no good
                -- answer once real invoices are in it.
                --
                -- SET NULL rather than cascade: the invoice is the tenant's
                -- either way, and a departing employee must not take a tax
                -- record with them.
                profile_id     INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,

                -- NULL while draft; allocated at issue. The CHECK below pairs
                -- the two so neither can drift from the other.
                number         VARCHAR(64),

                -- The name of the sequence the number came from. (series,
                -- number) is unique, which is the whole claim numbering makes.
                series         VARCHAR(64)   NOT NULL DEFAULT 'default',

                -- draft -> open -> paid, with void and uncollectible as the
                -- two ways an open invoice ends without being paid. 'open'
                -- rather than 'issued' because it is the word the rest of the
                -- billing world uses for 'sent, unpaid, still owed', and a
                -- status vocabulary that differs from every integration we will
                -- ever write is a translation layer nobody maintains.
                --
                -- Voiding keeps the row and its number: a cancelled invoice
                -- that vanishes leaves a hole that looks like a lost one.
                status         VARCHAR(16)   NOT NULL DEFAULT 'draft',

                currency       VARCHAR(3)    NOT NULL,

                -- Minor units, always. NET of tax even when prices are quoted
                -- tax-inclusive, so the invariant CHECK holds either way.
                subtotal_minor BIGINT        NOT NULL DEFAULT 0,
                discount_minor BIGINT        NOT NULL DEFAULT 0,
                tax_minor      BIGINT        NOT NULL DEFAULT 0,
                total_minor    BIGINT        NOT NULL DEFAULT 0,

                -- Snapshots of the tax treatment as it stood at issue. A rate
                -- change next year must not restate this invoice.
                tax_rate_bp    INTEGER       NOT NULL DEFAULT 0,
                tax_label      VARCHAR(32)   NOT NULL DEFAULT '',
                tax_inclusive  BOOLEAN       NOT NULL DEFAULT FALSE,

                -- Both parties as they were. The seller comes from settings,
                -- the buyer from the tenant; neither is joined at render time.
                seller_name    VARCHAR(255)  NOT NULL DEFAULT '',
                seller_address TEXT,
                seller_tax_id  VARCHAR(64),
                buyer_name     VARCHAR(255)  NOT NULL DEFAULT '',
                buyer_address  TEXT,
                buyer_tax_id   VARCHAR(64),

                -- Trace only. Nothing renders from these, and SET NULL says
                -- losing the trace must not lose the invoice.
                plan_id        BIGINT        REFERENCES plans(id) ON DELETE SET NULL,
                promotion_id   BIGINT        REFERENCES promotions(id) ON DELETE SET NULL,

                -- What period this invoice covers, which is not when it was
                -- issued and not when it is due.
                period_start   DATE,
                period_end     DATE,

                issued_at      TIMESTAMP,
                due_at         TIMESTAMP,
                paid_at        TIMESTAMP,
                voided_at      TIMESTAMP,
                void_reason    TEXT,

                -- Free text that prints on the document (a PO number, payment
                -- instructions, a note about the period).
                notes          TEXT,

                created_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP     NOT NULL DEFAULT NOW(),

                CONSTRAINT invoices_status CHECK (
                    status IN ('draft', 'open', 'paid', 'void', 'uncollectible')
                ),

                -- A draft has no number and anything past draft has one. The
                -- pair is the mechanism that keeps abandoned drafts from
                -- punching holes in the sequence, so it is enforced rather than
                -- left as a convention for every write path to remember.
                CONSTRAINT invoices_number_matches_status CHECK (
                    (status = 'draft' AND number IS NULL)
                    OR
                    (status <> 'draft' AND number IS NOT NULL)
                ),

                -- An invoice that does not add up cannot be stored. The bug
                -- this catches produces a plausible number, and a plausible
                -- wrong number is found by the customer rather than by us.
                CONSTRAINT invoices_totals_add_up CHECK (
                    total_minor = subtotal_minor - discount_minor + tax_minor
                ),

                CONSTRAINT invoices_amounts_non_negative CHECK (
                    subtotal_minor >= 0 AND discount_minor >= 0 AND tax_minor >= 0
                ),

                -- A discount larger than what is being discounted would make
                -- the total negative, which is a credit note and not this.
                CONSTRAINT invoices_discount_within_subtotal CHECK (
                    discount_minor <= subtotal_minor
                ),

                CONSTRAINT invoices_tax_rate_range CHECK (
                    tax_rate_bp >= 0 AND tax_rate_bp <= 10000
                )
            )
        ");

        /*
         * The one claim numbering makes: a sequence never issues the same
         * number twice. PARTIAL, because every draft has a NULL number and
         * NULLs must not collide with each other.
         *
         * This holds under both numbering scopes without knowing which is in
         * use, because `series` names the counter the number was allocated
         * from — one shared series for a single-seller platform, one series per
         * tenant where each tenant is its own legal seller.
         */
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_series_number
                 ON invoices(series, number)
              WHERE number IS NOT NULL'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_invoices_tenant_status ON invoices(tenant_id, status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_invoices_tenant_issued ON invoices(tenant_id, issued_at)');

        /*
         * The lines.
         *
         * `description` is a SNAPSHOT, not a join to the plan. "Professional
         * plan, 12 seats, March 2026" has to keep saying that after the plan is
         * renamed, re-tiered or withdrawn.
         */
        $db->exec("
            CREATE TABLE IF NOT EXISTS invoice_lines (
                id                BIGSERIAL    NOT NULL PRIMARY KEY,
                invoice_id        BIGINT       NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,

                -- Denormalised from the parent so the tenant-predicate guard
                -- polices a line read directly instead of trusting a join —
                -- the same trade `document_artifacts` and `entity_tags` make.
                tenant_id         INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,

                -- The order the operator arranged them in. Rendering by `id`
                -- would be right by accident until the first edited draft.
                position          INTEGER      NOT NULL DEFAULT 0,

                description       VARCHAR(500) NOT NULL,
                quantity          INTEGER      NOT NULL DEFAULT 1,
                unit_amount_minor BIGINT       NOT NULL DEFAULT 0,

                subtotal_minor    BIGINT       NOT NULL DEFAULT 0,
                discount_minor    BIGINT       NOT NULL DEFAULT 0,

                -- Per line, because mixed-rate invoices are ordinary: a
                -- zero-rated export line beside a standard-rated support line.
                tax_rate_bp       INTEGER      NOT NULL DEFAULT 0,
                tax_minor         BIGINT       NOT NULL DEFAULT 0,
                total_minor       BIGINT       NOT NULL DEFAULT 0,

                created_at        TIMESTAMP    NOT NULL DEFAULT NOW(),

                CONSTRAINT invoice_lines_quantity_positive CHECK (quantity > 0),
                CONSTRAINT invoice_lines_totals_add_up CHECK (
                    total_minor = subtotal_minor - discount_minor + tax_minor
                ),
                CONSTRAINT invoice_lines_discount_within_subtotal CHECK (
                    discount_minor <= subtotal_minor
                ),
                CONSTRAINT invoice_lines_tax_rate_range CHECK (
                    tax_rate_bp >= 0 AND tax_rate_bp <= 10000
                )
            )
        ");

        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_invoice_lines_position
                 ON invoice_lines(invoice_id, position)'
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_invoice_lines_tenant ON invoice_lines(tenant_id)');
    }

    public static function down(Database $db): void
    {
        // Dropping these destroys the billing history outright. Reversible in
        // the schema sense only, exactly as `sequence_counters` (092) is: the
        // rows are authoritative, not derived, and nothing reconstructs them.
        $db->exec('DROP TABLE IF EXISTS invoice_lines CASCADE');
        $db->exec('DROP TABLE IF EXISTS invoices CASCADE');
    }
}
