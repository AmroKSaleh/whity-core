<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Schema;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Schema\MigrationSchema;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Schema\SchemaInspector;

/**
 * The contract of {@see SchemaInspector} that does NOT depend on which engine
 * is underneath: identifier validation, the refusal to guess at an unsupported
 * driver, and the declaration semantics of add/drop column.
 *
 * The behaviour that MUST be identical on PostgreSQL and SQLite — the entire
 * point of the class — is pinned separately in
 * {@see \Tests\Integration\SchemaInspectorDualEngineTest}, which the CI matrix
 * runs against both engines. This file runs on SQLite alone and stays fast.
 */
final class SchemaInspectorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE acme_items (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT)');
    }

    // ==================== identifier validation ====================

    /**
     * Identifiers cannot be bound as parameters, so they reach SQL by
     * interpolation. The shape check is what makes that safe — and it must
     * refuse rather than sanitise, because a silently-rewritten table name
     * would operate on the wrong table.
     *
     * @dataProvider malformedIdentifiers
     */
    public function testMalformedIdentifiersAreRefused(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SchemaInspector::tableExists($this->pdo, $identifier);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedIdentifiers(): array
    {
        return [
            'empty' => [''],
            'quote injection' => ['acme_items"; DROP TABLE acme_items; --'],
            'schema-qualified' => ['public.acme_items'],
            'leading digit' => ['1items'],
            'backtick quoted' => ['`acme_items`'],
            'space' => ['acme items'],
            'hyphen' => ['acme-items'],
            'over 63 chars' => [str_repeat('a', 64)],
        ];
    }

    public function testIdentifierAtTheLengthLimitIsAccepted(): void
    {
        // 63 characters is the PostgreSQL limit and therefore the bound; the
        // 64th is the first rejection. Off-by-one here would refuse a legal name.
        self::assertFalse(SchemaInspector::tableExists($this->pdo, str_repeat('a', 63)));
    }

    public function testColumnIdentifierIsValidatedToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/column identifier/');

        SchemaInspector::columnExists($this->pdo, 'acme_items', 'name; DROP TABLE acme_items');
    }

    // ==================== unsupported driver ====================

    public function testUnsupportedDriverIsRefusedLoudly(): void
    {
        // A confident wrong answer from a guessed catalogue query corrupts a
        // schema; there is no safe fallback, so the refusal is the feature.
        $pdo = new class ('sqlite::memory:') extends PDO {
            #[\ReturnTypeWillChange]
            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mysql/');

        SchemaInspector::tableExists($pdo, 'acme_items');
    }

    // ==================== predicates ====================

    public function testTableExistsIsCaseInsensitive(): void
    {
        self::assertTrue(SchemaInspector::tableExists($this->pdo, 'acme_items'));
        self::assertTrue(SchemaInspector::tableExists($this->pdo, 'ACME_ITEMS'));
        self::assertFalse(SchemaInspector::tableExists($this->pdo, 'acme_absent'));
    }

    public function testColumnExistsOnAMissingTableIsFalseNotAnError(): void
    {
        // The caller is asking "must I add this column?"; "there is no table"
        // is a different question, answered by tableExists().
        self::assertFalse(SchemaInspector::columnExists($this->pdo, 'acme_absent', 'name'));
    }

    public function testIndexExistsSeesAnIndexAndNotItsTable(): void
    {
        $this->pdo->exec('CREATE INDEX idx_acme_items_tenant ON acme_items(tenant_id)');

        self::assertTrue(SchemaInspector::indexExists($this->pdo, 'idx_acme_items_tenant'));
        // A table is not an index, and vice versa — the type discriminator has
        // to be part of every catalogue query or the answers cross over.
        self::assertFalse(SchemaInspector::indexExists($this->pdo, 'acme_items'));
        self::assertFalse(SchemaInspector::tableExists($this->pdo, 'idx_acme_items_tenant'));
    }

    public function testViewIsNotATable(): void
    {
        // A migration guarded by tableExists() is about to run CREATE/ALTER
        // TABLE, and neither succeeds against a view.
        $this->pdo->exec('CREATE VIEW acme_view AS SELECT id FROM acme_items');

        self::assertFalse(SchemaInspector::tableExists($this->pdo, 'acme_view'));
    }

    public function testColumnsReturnsLowercasedNamesInDeclarationOrder(): void
    {
        self::assertSame(['id', 'tenant_id', 'name'], SchemaInspector::columns($this->pdo, 'acme_items'));
        self::assertSame([], SchemaInspector::columns($this->pdo, 'acme_absent'));
    }

    // ==================== declaration semantics ====================

    public function testAddColumnIfMissingAddsOnceAndIsThenANoOp(): void
    {
        self::assertTrue(
            SchemaInspector::addColumnIfMissing($this->pdo, 'acme_items', 'archived_at', 'TIMESTAMP NULL'),
            'First call adds the column and says so.'
        );
        self::assertTrue(SchemaInspector::columnExists($this->pdo, 'acme_items', 'archived_at'));

        self::assertFalse(
            SchemaInspector::addColumnIfMissing($this->pdo, 'acme_items', 'archived_at', 'TIMESTAMP NULL'),
            'A re-run must be a no-op — that is what makes the migration idempotent.'
        );
    }

    public function testAddColumnIfMissingRefusesAMissingTable(): void
    {
        // Swallowing this would leave the plugin querying a column nothing
        // created, and the failure would surface far from its cause.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/acme_absent/');

        SchemaInspector::addColumnIfMissing($this->pdo, 'acme_absent', 'note', 'TEXT');
    }

    public function testAddColumnIfMissingRefusesAnEmptyDefinition(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SchemaInspector::addColumnIfMissing($this->pdo, 'acme_items', 'note', '   ');
    }

    public function testDropColumnIfExistsDropsOnceAndIsThenANoOp(): void
    {
        self::assertTrue(SchemaInspector::dropColumnIfExists($this->pdo, 'acme_items', 'name'));
        self::assertFalse(SchemaInspector::columnExists($this->pdo, 'acme_items', 'name'));

        self::assertFalse(
            SchemaInspector::dropColumnIfExists($this->pdo, 'acme_items', 'name'),
            'Reversal must tolerate an already-reverted state.'
        );
    }

    public function testDropColumnIfExistsToleratesAMissingTable(): void
    {
        // down() runs in reverse order; a table a later-reverted migration
        // already dropped being gone is the expected state, not a failure.
        self::assertFalse(SchemaInspector::dropColumnIfExists($this->pdo, 'acme_absent', 'name'));
    }

    public function testReservedWordIdentifiersSurviveQuoting(): void
    {
        // `order` and `check` are reserved words; the helper quotes, so a
        // legitimate (if unwise) column name still works.
        $this->pdo->exec('CREATE TABLE acme_orders (id INTEGER PRIMARY KEY)');

        self::assertTrue(SchemaInspector::addColumnIfMissing($this->pdo, 'acme_orders', 'order', 'INTEGER'));
        self::assertTrue(SchemaInspector::columnExists($this->pdo, 'acme_orders', 'order'));
    }

    // ==================== the trait ====================

    public function testTraitForwardsToTheInspector(): void
    {
        $migration = new class implements MigrationInterface {
            use MigrationSchema;

            public function up(\PDO $pdo): void
            {
                $this->addColumnIfMissing($pdo, 'acme_items', 'sync_state', "VARCHAR(16) NOT NULL DEFAULT 'clean'");
            }

            public function down(\PDO $pdo): void
            {
                $this->dropColumnIfExists($pdo, 'acme_items', 'sync_state');
            }

            /** @return list<string> */
            public function probe(\PDO $pdo): array
            {
                return [
                    $this->tableExists($pdo, 'acme_items') ? 'table' : '-',
                    $this->columnExists($pdo, 'acme_items', 'sync_state') ? 'column' : '-',
                    $this->indexExists($pdo, 'nope') ? 'index' : '-',
                    implode(',', $this->tableColumns($pdo, 'acme_items')),
                ];
            }
        };

        $migration->up($this->pdo);
        self::assertSame(
            ['table', 'column', '-', 'id,tenant_id,name,sync_state'],
            $migration->probe($this->pdo)
        );

        // The reversal contract the SDK requires of every migration.
        $migration->down($this->pdo);
        self::assertFalse(SchemaInspector::columnExists($this->pdo, 'acme_items', 'sync_state'));

        // And up() is re-runnable, which is the property the trait exists for.
        $migration->up($this->pdo);
        $migration->up($this->pdo);
        self::assertTrue(SchemaInspector::columnExists($this->pdo, 'acme_items', 'sync_state'));
    }
}
