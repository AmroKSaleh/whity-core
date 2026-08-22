<?php

declare(strict_types=1);

namespace Tests\Core\Db;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Db\DbBool;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\i18n\Language;

/**
 * Real-engine cover for #891: booleans read back through the code paths that
 * used a bare `(bool)` cast, on the engine the platform actually ships against.
 *
 * WHY THIS FILE RUNS TWICE. `SchemaFromMigrations::make()` builds on in-memory
 * SQLite normally and on real PostgreSQL when `PHPUNIT_PG_DSN` is set, and the
 * two engines return a `BOOLEAN` column DIFFERENTLY — which is the entire
 * defect. A green SQLite run proves nothing on its own here, so CI's postgres
 * job is the one that matters and this file is written to pass on both without
 * branching on the driver.
 *
 * EVERY ASSERTION BELOW CHECKS THE FALSE CASE. The failure mode is "everything
 * reports true", so a test that only asserts the true case is green with the
 * bug fully present.
 */
final class DbBooleanRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
    }

    /** Which engine this run is exercising — reported in failure messages. */
    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** `PDO::query()` widens to `false` on failure; narrow it once, loudly. */
    private function query(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, "query failed: {$sql}");

        return $stmt;
    }

    // ==================== The representation itself ====================

    /**
     * Pin what the engine hands back for a `BOOLEAN`, and prove the canonical
     * coercion maps it both ways.
     *
     * This is the executable half of the STRINGIFY_FETCHES decision (see
     * CHANGELOG): production deliberately does NOT pin
     * `PDO::ATTR_STRINGIFY_FETCHES`, on the grounds that the code is correct
     * under either value. That is a claim, and this is where it is checked
     * rather than asserted in prose — if some future driver or PHP version
     * starts returning a spelling `DbBool` does not understand, this fails.
     */
    public function testTheEngineRepresentationRoundTripsBothWays(): void
    {
        $this->pdo->exec('CREATE TABLE bool_probe (id INTEGER PRIMARY KEY, flag BOOLEAN)');
        $this->pdo->exec('INSERT INTO bool_probe (id, flag) VALUES (1, true), (2, false)');

        $rows = $this->query('SELECT id, flag FROM bool_probe ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        $trueValue = $rows[0]['flag'];
        $falseValue = $rows[1]['flag'];

        self::assertTrue(
            DbBool::of($trueValue),
            sprintf('[%s] true came back as %s', $this->driver(), var_export($trueValue, true))
        );
        self::assertFalse(
            DbBool::of($falseValue),
            sprintf('[%s] FALSE came back as %s and must not read as true', $this->driver(), var_export($falseValue, true))
        );

        // And the two must be distinguishable at all — a driver that collapsed
        // them would make every assertion in this file vacuous.
        self::assertNotSame(
            DbBool::of($trueValue),
            DbBool::of($falseValue),
            sprintf('[%s] true and false became the same value', $this->driver())
        );
    }

    // ==================== Language::fromRow (`languages.enabled`) ====================

    public function testADisabledLanguageReadsAsDisabled(): void
    {
        $this->pdo->exec("INSERT INTO languages (code, name, enabled) VALUES ('zz', 'Test Off', false)");

        $row = $this->query("SELECT * FROM languages WHERE code = 'zz'")->fetch(PDO::FETCH_ASSOC);

        $language = Language::fromRow($row);

        self::assertFalse(
            $language->enabled,
            sprintf('[%s] a language stored with enabled=false must not read as enabled', $this->driver())
        );
    }

    public function testAnEnabledLanguageStillReadsAsEnabled(): void
    {
        $this->pdo->exec("INSERT INTO languages (code, name, enabled) VALUES ('zy', 'Test On', true)");

        $row = $this->query("SELECT * FROM languages WHERE code = 'zy'")->fetch(PDO::FETCH_ASSOC);

        self::assertTrue(Language::fromRow($row)->enabled);
    }

    // ============ RelationRepository (`relationship_types.is_symmetric`) ============

    /**
     * `findType()` read `$row['symmetric']` while its SELECT projects
     * `is_symmetric`, so the key was never present and the `?? false` default
     * answered for every row: EVERY relationship type reported asymmetric,
     * including Spouse and Sibling. Found by the #891 sweep, fixed with it.
     *
     * The bug is invisible from the false side, so this asserts the TRUE case —
     * the mirror of the rest of this file, and the reason both directions are
     * checked rather than a house style of one.
     */
    public function testFindTypeReportsASymmetricTypeAsSymmetric(): void
    {
        $spouseId = $this->typeIdNamed('Spouse');

        $type = (new RelationRepository($this->pdo))->findType($spouseId);

        self::assertNotNull($type);
        self::assertTrue(
            $type['symmetric'],
            sprintf('[%s] Spouse is seeded is_symmetric=true and must read as symmetric', $this->driver())
        );
    }

    public function testFindTypeReportsAnAsymmetricTypeAsAsymmetric(): void
    {
        $parentId = $this->typeIdNamed('Parent');

        $type = (new RelationRepository($this->pdo))->findType($parentId);

        self::assertNotNull($type);
        self::assertFalse(
            $type['symmetric'],
            sprintf('[%s] Parent is seeded is_symmetric=false', $this->driver())
        );
    }

    /** listTypes() always read the right key; pinned so the two cannot drift apart again. */
    public function testListTypesAndFindTypeAgreeOnEveryType(): void
    {
        $repository = new RelationRepository($this->pdo);

        foreach ($repository->listTypes() as $listed) {
            $found = $repository->findType($listed['id']);

            self::assertNotNull($found);
            self::assertSame(
                $listed['symmetric'],
                $found['symmetric'],
                sprintf('[%s] listTypes() and findType() disagree on "%s"', $this->driver(), $listed['name'])
            );
        }
    }

    private function typeIdNamed(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM relationship_types WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();

        self::assertNotFalse($id, "the migrations seed a '{$name}' relationship type");

        return (int) $id;
    }
}
