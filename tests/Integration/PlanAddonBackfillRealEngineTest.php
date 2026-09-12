<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\PlanAddons;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Db\DbBool;
use Whity\Database\Database;

/**
 * Migration 150's backfill: a per-device plan is an add-on, said by the
 * migration rather than by somebody remembering afterwards.
 *
 * WHY A BACKFILL NEEDS A TEST AT ALL. The column defaults to FALSE, which is
 * right for every plan that exists — except the one kind that is an add-on by
 * construction. Leaving those to a manual `UPDATE` after deploy is a step that
 * works the first time and is forgotten every time after, and the state it
 * leaves behind is silent and points the wrong way: the device plan keeps being
 * offered as a TIER, so a tenant with no subscription can buy devices and be let
 * into the product by them, while a tenant who already has a tier is refused the
 * add-on they actually want. Both are the exact bugs the column was added to
 * prevent, restored by the act of adding it.
 *
 * The migration is re-run against a schema that already has plans, which is the
 * situation on every existing deployment and the only one where the backfill has
 * anything to do. It is safe to re-run by construction — `ADD COLUMN IF NOT
 * EXISTS`, `CREATE INDEX IF NOT EXISTS`, and an UPDATE that only touches rows
 * still marked FALSE.
 */
final class PlanAddonBackfillRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
    }

    /**
     * THE ONE THIS EXISTS FOR. A plan priced per device is marked an add-on
     * without anybody being asked to remember.
     */
    public function testAPlanWithAPerDevicePriceIsBackfilledAsAnAddon(): void
    {
        $this->plan(1, 'devices', perDevice: true);
        $this->asBeforeTheMigration();

        PlanAddons::up($this->db);

        self::assertTrue($this->isAddon(1));
    }

    /**
     * AND NOTHING ELSE MOVES. A tier caught by a too-broad backfill would stop
     * being sellable to the tenants who have no subscription — which is every
     * tenant a tier is for.
     */
    public function testATierIsLeftAlone(): void
    {
        $this->plan(2, 'pro', perDevice: false);
        $this->asBeforeTheMigration();

        PlanAddons::up($this->db);

        self::assertFalse($this->isAddon(2));
    }

    /**
     * A PER-SEAT PLAN IS A TIER, not an add-on. Both multiply by a quantity, so
     * a backfill written against "has a quantity" rather than "is per device"
     * would sweep up every seat-based plan and lock those customers out of
     * buying one.
     */
    public function testAPerSeatPlanIsNotAnAddon(): void
    {
        $this->plan(3, 'team', perDevice: false, perSeat: true);
        $this->asBeforeTheMigration();

        PlanAddons::up($this->db);

        self::assertFalse($this->isAddon(3));
    }

    /**
     * A WITHDRAWN PRICE STILL COUNTS. A per-device plan taken off sale is still
     * an add-on — its existing subscribers did not become tier customers because
     * the catalogue moved on, and they are exactly the ones whose fleet keeps
     * changing afterwards.
     */
    public function testAnInactivePerDevicePriceStillMarksItsPlan(): void
    {
        $this->plan(4, 'legacy-devices', perDevice: true, priceActive: false);
        $this->asBeforeTheMigration();

        PlanAddons::up($this->db);

        self::assertTrue($this->isAddon(4));
    }

    /**
     * RUNNING IT TWICE LANDS IN THE SAME PLACE. A migration is re-run by a
     * rebuilt environment and by anything that retries a failed deploy, and one
     * whose second pass differed from its first would make the schema depend on
     * how many times it had been attempted.
     *
     * WHAT THIS DELIBERATELY DOES *NOT* CLAIM — the first draft of this test
     * asserted it and was wrong, which is worth recording: a `false` an operator
     * sets AFTERWARDS is not protected. `WHERE is_addon = FALSE` is exactly the
     * condition such a row matches, so a re-run would flip it back. The guard
     * avoids pointless writes; it is not a record of anyone's intent, and it
     * could not be — nothing in the row distinguishes "still at the default"
     * from "deliberately set to the default".
     *
     * That is safe rather than merely unlikely, because the runner applies only
     * migrations absent from `core_schema_migrations`, so this one runs once per
     * database and an operator's later decision is never revisited. An operator
     * who wants a per-device plan sold as a tier changes the row, not this.
     */
    public function testReRunningLandsInTheSamePlace(): void
    {
        $this->plan(5, 'devices', perDevice: true);
        $this->plan(6, 'pro', perDevice: false);
        $this->asBeforeTheMigration();

        PlanAddons::up($this->db);
        PlanAddons::up($this->db);

        self::assertTrue($this->isAddon(5));
        self::assertFalse($this->isAddon(6));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function plan(
        int $id,
        string $key,
        bool $perDevice,
        bool $perSeat = false,
        bool $priceActive = true,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO plans (id, plan_key, name, is_active) VALUES (:id, :key, :name, :on)'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':key', $key);
        $statement->bindValue(':name', ucfirst($key));
        $statement->bindValue(':on', true, PDO::PARAM_BOOL);
        $statement->execute();

        $price = $this->pdo->prepare(
            'INSERT INTO plan_prices
                (plan_id, currency, unit_amount, billing_period, is_active, is_per_seat, is_per_device)
             VALUES (:plan, :cur, :amount, :period, :active, :seat, :device)'
        );
        $price->bindValue(':plan', $id, PDO::PARAM_INT);
        $price->bindValue(':cur', 'JOD');
        $price->bindValue(':amount', 20000, PDO::PARAM_INT);
        $price->bindValue(':period', 'month');
        $price->bindValue(':active', $priceActive, PDO::PARAM_BOOL);
        $price->bindValue(':seat', $perSeat, PDO::PARAM_BOOL);
        $price->bindValue(':device', $perDevice, PDO::PARAM_BOOL);
        $price->execute();
    }

    /**
     * The state every existing deployment is in the instant before the backfill:
     * the column present and uniformly false, which is what `DEFAULT FALSE`
     * produces for rows that were already there.
     */
    private function asBeforeTheMigration(): void
    {
        $this->pdo->exec('UPDATE plans SET is_addon = FALSE');
    }

    private function isAddon(int $planId): bool
    {
        $statement = $this->pdo->prepare('SELECT is_addon FROM plans WHERE id = :id');
        $statement->bindValue(':id', $planId, PDO::PARAM_INT);
        $statement->execute();

        return DbBool::of($statement->fetchColumn());
    }
}
