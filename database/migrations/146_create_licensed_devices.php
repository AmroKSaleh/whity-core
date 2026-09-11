<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateLicensedDevices — physical units that are licensed, activated and paid
 * for, plus the codes that activate them.
 *
 * WHY NOT `devices`
 * -----------------
 * That name is taken, and by something entirely different. `devices` (migration
 * 044) is AUTH: trusted browsers, device credentials, "keep me signed in on
 * this machine" — see `Whity\Auth\DeviceCredentialService` and
 * `DeviceRoleChecker`. A row there is a session's provenance.
 *
 * A row HERE is a piece of hardware with a serial number that somebody pays
 * for. Overloading the word would collide in the schema, in RBAC permission
 * names, in API routes and in the UI, and every future conversation about
 * "devices" would need a disambiguating clause. The prefix is not verbosity;
 * it is the only way the two concepts can coexist.
 *
 * THE THREE TIMESTAMPS EXIST SO BILLING CAN BE A POLICY
 * ----------------------------------------------------
 * "Per device" is not one billing model, it is at least three, and they
 * produce different invoices from identical facts:
 *
 *   - every device PROVISIONED   — the serial was imported; stock on a shelf
 *                                  is billable
 *   - every device ACTIVATED     — a code was redeemed; the customer pays from
 *                                  the moment a unit is put into service
 *   - every device ACTIVE in the period — it was actually used
 *
 * Which one a deployment means is a commercial decision, it differs between
 * customers, and getting it wrong after real money has been billed is
 * expensive to unwind. So this table does not choose. It records
 * `provisioned_at`, `activated_at` and `last_seen_at` as separate facts, and
 * the counting rule lives in configuration where a changed mind costs a
 * setting rather than a migration over historical invoices.
 *
 * `status` is the current state; the timestamps are the history that makes any
 * of the three counts computable after the fact.
 */
class CreateLicensedDevices
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS licensed_devices (
                id BIGSERIAL PRIMARY KEY,

                -- ON DELETE RESTRICT, matching invoices. A licensed device is a
                -- commercial record: something was sold, and deleting the tenant
                -- must not silently take the evidence with it.
                tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,

                -- The manufacturer's identifier, as printed. Stored as given
                -- rather than normalised, because a serial is evidence and the
                -- customer will read it off the unit when they query a bill.
                serial_number VARCHAR(128) NOT NULL,

                -- What a human calls it. Optional: a warehouse import has
                -- serials and nothing else.
                label VARCHAR(160),

                status VARCHAR(24) NOT NULL DEFAULT 'provisioned',

                -- The three facts the billing policy chooses between. See the
                -- class docblock: none of them is 'the' answer.
                provisioned_at TIMESTAMP NOT NULL DEFAULT NOW(),
                activated_at TIMESTAMP,
                last_seen_at TIMESTAMP,
                retired_at TIMESTAMP,

                -- Room for a deployment's own fields without a migration each
                -- time. Deliberately not a place for anything this schema needs
                -- to query or enforce.
                metadata JSONB,

                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_licensed_device_status
                    CHECK (status IN ('provisioned', 'active', 'suspended', 'retired')),

                -- An ACTIVE device must know when it was activated, because the
                -- activation date is what an activation-based invoice is
                -- computed from. Letting the two disagree would make a bill
                -- unexplainable.
                CONSTRAINT chk_licensed_device_active_has_date
                    CHECK (status <> 'active' OR activated_at IS NOT NULL)
            )
        ");

        // A serial identifies a unit WITHIN a tenant. Two customers may legitimately
        // hold hardware with the same manufacturer serial, so this is not global.
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_licensed_devices_tenant_serial
                ON licensed_devices (tenant_id, serial_number)
        ");

        // The query every billing run makes: which of this tenant's devices
        // counted, in this state, during this period.
        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_licensed_devices_tenant_status
                ON licensed_devices (tenant_id, status)
        ");

        // ── activation codes ────────────────────────────────────────────────
        //
        // A code that activates a paid device is a BEARER CREDENTIAL worth
        // money, and per the deployment driving this, an end user or student may
        // redeem one — somebody who has no established relationship with the
        // tenant at the point they type it. Everything below follows from that.

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_activation_codes (
                id BIGSERIAL PRIMARY KEY,

                tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,

                -- GLOBALLY UNIQUE, not unique-per-tenant. A student redeeming a
                -- code off a sticker does not know which tenant they belong to
                -- and must not have to; the code itself has to be sufficient to
                -- identify the tenant, the device and the grant. Scoping it per
                -- tenant would force the redemption endpoint to accept a tenant
                -- hint from an untrusted caller, which is precisely how one
                -- customer's code gets redeemed against another's account.
                code VARCHAR(32) NOT NULL,

                -- A code may be minted FOR a specific unit, or left open and
                -- bound to whichever device redeems it. Both are real: serials
                -- printed at manufacture versus a batch handed to a school.
                licensed_device_id BIGINT REFERENCES licensed_devices(id) ON DELETE CASCADE,

                -- NOT a boolean. 'Single use' is the default and the common
                -- case, but a classroom set of thirty, or an unlimited campus
                -- code that expires at term end, are ordinary requirements — and
                -- a boolean cannot express either. Business logic and marketing
                -- intent are not guessable from here, so this is a number with a
                -- sensible default rather than a decision baked into the type.
                max_redemptions INTEGER NOT NULL DEFAULT 1,
                redemption_count INTEGER NOT NULL DEFAULT 0,

                -- NULL means no expiry. Both are wanted.
                expires_at TIMESTAMP,

                -- Revocation is separate from expiry and from exhaustion: a code
                -- printed on a batch of stickers that went missing must be
                -- killable while still unexpired and unredeemed.
                revoked_at TIMESTAMP,

                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_activation_max_redemptions
                    CHECK (max_redemptions >= 1),

                -- THE INVARIANT THAT MATTERS, enforced by the database rather
                -- than by whichever code path happens to be redeeming. A race
                -- between two students typing the last code of a batch is a
                -- normal Tuesday, and application-level checking loses it.
                CONSTRAINT chk_activation_not_over_redeemed
                    CHECK (redemption_count <= max_redemptions)
            )
        ");

        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_device_activation_codes_code
                ON device_activation_codes (code)
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_device_activation_codes_tenant
                ON device_activation_codes (tenant_id)
        ");

        // ── redemptions ─────────────────────────────────────────────────────
        //
        // WHY A SEPARATE TABLE rather than a counter alone. `redemption_count`
        // answers "how many", which is all the invariant above needs. It cannot
        // answer "who, when, from where" — and those are the questions asked
        // when a customer disputes a bill, when a code leaks, or when the same
        // classroom set appears to have been activated twice. A bearer
        // credential without a redemption log is one nobody can investigate.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_activation_redemptions (
                id BIGSERIAL PRIMARY KEY,

                tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
                activation_code_id BIGINT NOT NULL
                    REFERENCES device_activation_codes(id) ON DELETE RESTRICT,
                licensed_device_id BIGINT NOT NULL
                    REFERENCES licensed_devices(id) ON DELETE RESTRICT,

                -- Who redeemed it, when known. NULL is expected and legitimate:
                -- the whole point is that an end user or student may redeem
                -- without an account, and recording a null is more honest than
                -- inventing an actor.
                redeemed_by_user_id BIGINT,

                -- Kept for abuse investigation, not for identification.
                redeemed_from_ip VARCHAR(64),

                redeemed_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_device_redemptions_code
                ON device_activation_redemptions (activation_code_id)
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_device_redemptions_tenant
                ON device_activation_redemptions (tenant_id, redeemed_at)
        ");
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        // Reverse dependency order.
        $pdo->exec('DROP TABLE IF EXISTS device_activation_redemptions');
        $pdo->exec('DROP TABLE IF EXISTS device_activation_codes');
        $pdo->exec('DROP TABLE IF EXISTS licensed_devices');
    }
}
