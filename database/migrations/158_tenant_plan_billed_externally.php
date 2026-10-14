<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * TenantPlanBilledExternally — one engine bills a tenant, never two.
 *
 * ── The failure this closes ────────────────────────────────────────────────
 *
 * This platform ships a billing engine (src/Core/Billing) AND can be pointed at
 * an external billing service (src/Core/Billing/External). A deployment can run
 * both: the service collects from some tenants while the local engine still
 * invoices others, which is exactly what a migration between them looks like
 * from the inside. Nothing structurally prevented both acting on ONE tenant.
 *
 * ── Why the existing guard is not enough on its own ────────────────────────
 *
 * SubscriptionBillingRun already skips a tenant whose `external_ref` is set, so
 * a tenant with a LIVE external subscription is safe today. That guard keys on
 * "is there currently an external subscription", which is a different question
 * from "whose tenant is this". It cannot see a tenant the service OWNS but has
 * never billed — somebody mid-signup, or on a free tier over there — because
 * such a tenant has no external reference to be recognised by. If that tenant
 * also has a local tenant_plan row left from before the migration, the local
 * run bills them while the service considers them its own.
 *
 * ── A boolean, not a second reference ──────────────────────────────────────
 *
 * It answers ownership and nothing else. Making it a second nullable reference
 * would invite the two columns to disagree, and then "which one wins" becomes a
 * rule somebody has to remember. AccessRecorder writes it from the billing
 * service's own answer on every reconciliation, so it converges rather than
 * drifting.
 *
 * ── Default false, deliberately ────────────────────────────────────────────
 *
 * Every existing row was billed by this platform, so false is the only honest
 * value for them. It also means a deployment with no billing service — the
 * common case for self-hosted installs — is untouched: nothing ever sets this,
 * the local engine behaves exactly as before, and the column costs one byte.
 *
 * Crucially, a billing service that does not publish an owner leaves this false
 * too. See AccessSnapshot::fromPayload: "not said" must not mean "stop billing",
 * or an older service would silently halt a deployment's invoicing with no
 * symptom anybody could trace to a missing JSON key.
 */
class TenantPlanBilledExternally
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('
            ALTER TABLE tenant_plan
                ADD COLUMN IF NOT EXISTS billed_externally BOOLEAN NOT NULL DEFAULT FALSE
        ');

        /*
         * Read by the billing run's sweep, which already filters on status and
         * period end. Partial, because the rows worth finding quickly are the
         * ones to EXCLUDE and they are the minority on any deployment — a full
         * index over a column that is false almost everywhere earns nothing.
         */
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_tenant_plan_billed_externally
                ON tenant_plan(tenant_id) WHERE billed_externally
        ');
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec('DROP INDEX IF EXISTS idx_tenant_plan_billed_externally');
        $pdo->exec('ALTER TABLE tenant_plan DROP COLUMN IF EXISTS billed_externally');
    }
}
