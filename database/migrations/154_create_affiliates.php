<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateAffiliates — who referred a customer, what they earned, and what we owe.
 *
 * Four tables, and the third one is the product. `affiliates` and
 * `affiliate_referrals` are bookkeeping; `affiliate_commissions` is a LEDGER of
 * money owed to people outside the company, and its job is to answer "what do we
 * owe this person, and can we prove why" — after a refund, after a downgrade,
 * after a customer churns mid-window.
 *
 * ── Money out, which nothing here has done before ──────────────────────────
 *
 * Every existing money table records what a customer owes US. These record what
 * WE owe somebody else, which fails differently: an over-accrual is money paid
 * out that cannot be recalled, and an under-accrual is an affiliate who stops
 * promoting the product and tells people why. So the ledger is append-only in
 * spirit — a commission is never edited into a different amount, it is reversed
 * by a second row — and every entry names the payment it came from.
 *
 * ── Classification ─────────────────────────────────────────────────────────
 *
 * `affiliate_referrals` carries `tenant_id` and is TENANT-OWNED: it says which
 * workspace a referrer brought, which is tenant data.
 *
 * The other three deliberately carry NO `tenant_id`. An affiliate, a commission
 * and a payout are about a party outside any tenant, always queried by affiliate
 * and never by tenant — so a tenant predicate on them would be noise that every
 * query had to annotate its way around, which is how a guard stops meaning
 * anything. Which workspace generated a commission is one join through
 * `affiliate_referrals`, where the predicate belongs. This follows `plans` and
 * `promotions`, which are operator catalogues in neither registry.
 */
class CreateAffiliates
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        // ── Who can refer ───────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS affiliates (
                id BIGSERIAL PRIMARY KEY,

                -- What goes in the link. Case-insensitively unique, because a
                -- referrer will write it on a slide and somebody will type it
                -- back in the wrong case.
                code VARCHAR(64) NOT NULL,

                name VARCHAR(255) NOT NULL,

                -- The person, when they have an account here. Nullable: an
                -- affiliate is often an outside partner who never signs in, and
                -- requiring an account would mean creating one nobody uses.
                -- ON DELETE SET NULL — deleting a profile must not destroy the
                -- record of money owed to them.
                profile_id BIGINT REFERENCES profiles(id) ON DELETE SET NULL,

                -- Where to reach them about a payout. Kept even when profile_id
                -- is set: the person who signs in is not always the person who
                -- gets paid.
                email VARCHAR(255),

                -- BASIS POINTS, not a percent. 2000 = 20%. Integer arithmetic
                -- all the way, mirroring invoices.tax_rate_bp, because a rate
                -- stored as a float is a rounding argument with somebody about
                -- their own money.
                commission_bp INTEGER NOT NULL,

                -- How long after their first payment a referred customer keeps
                -- earning. Per affiliate, because it is a term in a deal.
                window_months INTEGER NOT NULL DEFAULT 12,

                -- The discount the code ALSO grants, when it grants one. Most
                -- affiliate codes do; some are commission-only. Nullable rather
                -- than two kinds of affiliate.
                -- ON DELETE SET NULL: retiring a promotion must not delete the
                -- affiliate or their earnings.
                promotion_id BIGINT REFERENCES promotions(id) ON DELETE SET NULL,

                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        // Case-insensitive uniqueness on the code. A functional index rather
        // than a plain one: 'SPRING26' and 'spring26' must be the same code, or
        // two affiliates end up sharing a link and the commission goes to
        // whichever row was found first.
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_affiliates_code ON affiliates (LOWER(code))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_affiliates_active ON affiliates (is_active)');

        // ── Who they brought ────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS affiliate_referrals (
                id BIGSERIAL PRIMARY KEY,

                affiliate_id BIGINT NOT NULL REFERENCES affiliates(id) ON DELETE RESTRICT,

                -- ON DELETE RESTRICT, matching invoices: a referral is a
                -- commercial record, and deleting the workspace must not
                -- silently take the evidence of what somebody earned with it.
                tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,

                referred_at TIMESTAMP NOT NULL DEFAULT NOW(),

                -- THE WINDOW STARTS AT THE FIRST PAYMENT, not at signup. A
                -- referrer whose customer takes two months to convert should get
                -- twelve months of revenue, not ten. Null until they pay, which
                -- is also how 'referred but never converted' is expressed.
                first_paid_at TIMESTAMP,

                -- Computed when first_paid_at is set, then never moved. Stored
                -- rather than derived so a later change to the affiliate's
                -- window_months cannot silently re-open or close a window that
                -- both parties already agreed.
                window_ends_at TIMESTAMP,

                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

                -- ONE REFERRER PER WORKSPACE, FOR GOOD. Enforced by the schema
                -- rather than by whoever writes the attribution code: two
                -- affiliates claiming the same customer is not a conflict
                -- anybody notices until both are being paid for it.
                --
                -- A table constraint, so it sits after every column: placed
                -- mid-list it is a syntax error on both engines, which is how
                -- this was found.
                CONSTRAINT uq_affiliate_referrals_tenant UNIQUE (tenant_id)
            )
        ");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_affiliate_referrals_affiliate ON affiliate_referrals (affiliate_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_affiliate_referrals_tenant ON affiliate_referrals (tenant_id)');

        // ── What we paid ────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS affiliate_payouts (
                id BIGSERIAL PRIMARY KEY,

                affiliate_id BIGINT NOT NULL REFERENCES affiliates(id) ON DELETE RESTRICT,

                total_minor BIGINT NOT NULL,
                currency VARCHAR(3) NOT NULL,

                -- draft while it is being assembled, paid once the money has
                -- actually moved. There is no 'sent' between them, because
                -- nothing here sends money — a human does, and then says so.
                status VARCHAR(16) NOT NULL DEFAULT 'draft',

                -- The bank or transfer reference. Free text on purpose: this is
                -- whatever the person who made the payment can quote back when
                -- an affiliate asks where their money went.
                reference VARCHAR(255),

                paid_at TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_affiliate_payouts_affiliate ON affiliate_payouts (affiliate_id, status)');

        // ── What they earned ────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS affiliate_commissions (
                id BIGSERIAL PRIMARY KEY,

                referral_id BIGINT NOT NULL REFERENCES affiliate_referrals(id) ON DELETE RESTRICT,
                affiliate_id BIGINT NOT NULL REFERENCES affiliates(id) ON DELETE RESTRICT,

                -- WHICH PAYMENT THIS CAME FROM. 'invoice' for one we raised,
                -- 'external' for one the billing service did. Together with
                -- source_ref this is what makes the accrual sweep idempotent:
                -- re-running it cannot pay for the same payment twice.
                -- WIDE ENOUGH FOR THE REVERSAL MARKER. At VARCHAR(16) the value
                -- 'external:reversal' (17 characters) was rejected by PostgreSQL
                -- and silently accepted by SQLite, so every clawback failed in
                -- production and passed in the local suite. Caught by the
                -- dual-engine gate, not by reading.
                source VARCHAR(32) NOT NULL,
                source_ref VARCHAR(128) NOT NULL,

                -- What the rate was applied to: the invoice AFTER discounts and
                -- EXCLUDING tax. Tax is not income and is not ours to share.
                base_minor BIGINT NOT NULL,

                -- COPIED, NEVER JOINED FOR. The affiliate's rate today is not
                -- the rate this commission was earned at, and an earnings report
                -- that silently rewrites itself when somebody renegotiates is
                -- not a report.
                rate_bp INTEGER NOT NULL,

                amount_minor BIGINT NOT NULL,
                currency VARCHAR(3) NOT NULL,

                -- accrued -> approved -> paid, with reversed as the terminal
                -- state for a clawback. A reversal is a NEW row with a negative
                -- amount rather than an edit, so a balance stays a SUM and the
                -- history of what happened survives.
                status VARCHAR(16) NOT NULL DEFAULT 'accrued',

                -- When the customer paid, not when we noticed. A sweep that runs
                -- late must not move an earning into the wrong month.
                occurred_at TIMESTAMP NOT NULL,

                -- ON DELETE SET NULL: discarding a draft payout returns its
                -- commissions to the unpaid pool rather than orphaning them.
                -- Without the key, deleting a payout leaves rows pointing at an
                -- id that no longer resolves and nothing refuses it — which the
                -- reference guard caught before this shipped.
                payout_id BIGINT REFERENCES affiliate_payouts(id) ON DELETE SET NULL,

                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

                -- ONE COMMISSION PER PAYMENT. The accrual sweep re-reads a
                -- customer's whole payment history every run, so this is what
                -- stops the second run paying for the first run's work. A
                -- reversal is distinguished by source, not by a second row with
                -- the same key.
                CONSTRAINT uq_affiliate_commissions_source UNIQUE (referral_id, source, source_ref)
            )
        ");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_affiliate_commissions_affiliate ON affiliate_commissions (affiliate_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_affiliate_commissions_payout ON affiliate_commissions (payout_id)');
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        // Dropped in dependency order. A table holding money owed to somebody is
        // not dropped casually — this exists so a test environment can be rebuilt,
        // not as a routine operation.
        $pdo->exec('DROP TABLE IF EXISTS affiliate_commissions');
        $pdo->exec('DROP TABLE IF EXISTS affiliate_payouts');
        $pdo->exec('DROP TABLE IF EXISTS affiliate_referrals');
        $pdo->exec('DROP TABLE IF EXISTS affiliates');
    }
}
