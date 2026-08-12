<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Sql;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Sql\Upsert;

/**
 * The construction contract of {@see Upsert}: what statement it builds, and
 * which mistakes it refuses to build at all.
 *
 * The behaviour that must be identical on PostgreSQL and SQLite is pinned in
 * {@see \Tests\Integration\PortableUpsertDualEngineTest}. This file runs on
 * SQLite alone and reads the generated SQL directly, because the property that
 * matters most here — that a tenant-scoped upsert can never end up with an
 * unscoped conflict target — is a property of the TEXT, and asserting it on the
 * text is stronger than inferring it from a passing insert.
 */
final class UpsertTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE acme_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                client_uuid TEXT NOT NULL,
                name TEXT,
                status TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE UNIQUE INDEX idx_acme_items_tenant_uuid ON acme_items(tenant_id, client_uuid)'
        );
    }

    /**
     * `PDO::query()` is typed `PDOStatement|false`; chaining off it reports a
     * confusing "call on false" rather than naming the statement that failed.
     */
    private function countItems(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM acme_items');
        self::assertNotFalse($stmt, 'Could not count acme_items.');

        return (int) $stmt->fetchColumn();
    }

    // ==================== the tenant key cannot be left out ====================

    /**
     * THE POINT OF THE CLASS. `ON CONFLICT (client_uuid)` where the intent was
     * `ON CONFLICT (tenant_id, client_uuid)` turns an upsert into cross-tenant
     * data loss: tenant B's insert finds tenant A's row and overwrites it. The
     * tenant column is prepended to the conflict target by the method, so the
     * caller has no way to produce the unscoped form.
     */
    public function testTenantScopedAlwaysPutsTheTenantColumnInTheConflictTarget(): void
    {
        Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['client_uuid' => 'u-1', 'name' => 'first'],
            ['client_uuid'],
            null,
            []
        );

        // Read the statement the same call builds, and assert on its text.
        $sql = Upsert::buildSql(
            'acme_items',
            ['tenant_id', 'client_uuid', 'name'],
            ['tenant_id', 'client_uuid'],
            ['name']
        );

        self::assertStringContainsString('ON CONFLICT ("tenant_id", "client_uuid")', $sql);
    }

    public function testTenantColumnInValuesIsRefusedRatherThanSilentlyPreferred(): void
    {
        // If both were accepted, one of them would win silently and the row
        // could be written under a tenant the caller did not name.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tenant_id/');

        Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['tenant_id' => 9, 'client_uuid' => 'u-1'],
            ['client_uuid']
        );
    }

    public function testTenantColumnInConflictTargetIsRefusedAsRedundant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['client_uuid' => 'u-1'],
            ['tenant_id', 'client_uuid']
        );
    }

    // ==================== construction refusals ====================

    public function testAnEmptyConflictTargetIsRefused(): void
    {
        // Without a conflict target the statement is a plain INSERT that fails
        // on the second call — an upsert in name only.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/conflict/i');

        Upsert::unscoped($this->pdo, 'acme_items', ['name' => 'x'], []);
    }

    public function testAConflictColumnTheInsertDoesNotSupplyIsRefused(): void
    {
        // Such a target can never match, so the statement would behave as a
        // plain INSERT and duplicate rows — silently, until the unique index
        // that does exist finally rejects one.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not among the inserted columns/');

        Upsert::unscoped($this->pdo, 'acme_items', ['name' => 'x'], ['client_uuid']);
    }

    public function testAnUpdateColumnWithNoProposedValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no proposed value/');

        Upsert::unscoped(
            $this->pdo,
            'acme_items',
            ['client_uuid' => 'u-1'],
            ['client_uuid'],
            ['name']
        );
    }

    /**
     * @dataProvider malformedIdentifiers
     */
    public function testMalformedIdentifiersAreRefused(string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Upsert::buildSql($table, ['a'], ['a'], ['a']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedIdentifiers(): array
    {
        return [
            'quote injection' => ['acme_items" ; DROP TABLE acme_items; --'],
            'schema-qualified' => ['public.acme_items'],
            'empty' => [''],
        ];
    }

    // ==================== generated statement ====================

    public function testUpdateColumnsDefaultToEverythingInserted(): void
    {
        $sql = Upsert::buildSql('acme_items', ['tenant_id', 'client_uuid', 'name'], ['tenant_id'], ['name']);

        self::assertStringContainsString('DO UPDATE SET "name" = excluded."name"', $sql);
        self::assertStringContainsString('RETURNING *', $sql);
    }

    public function testAnEmptyUpdateListBecomesDoNothing(): void
    {
        $sql = Upsert::buildSql('acme_items', ['tenant_id', 'client_uuid'], ['tenant_id', 'client_uuid'], []);

        self::assertStringContainsString('DO NOTHING', $sql);
        self::assertStringNotContainsString('DO UPDATE', $sql);
    }

    public function testOmittingReturningOmitsTheClause(): void
    {
        $sql = Upsert::buildSql('acme_items', ['tenant_id'], ['tenant_id'], ['tenant_id'], []);

        self::assertStringNotContainsString('RETURNING', $sql);
    }

    public function testReservedWordColumnsAreQuoted(): void
    {
        // `order` and `value` are reserved or semi-reserved; an unquoted build
        // would produce a syntax error on one engine and not the other.
        $sql = Upsert::buildSql('acme_items', ['tenant_id', 'order'], ['tenant_id'], ['order']);

        self::assertStringContainsString('"order"', $sql);
    }

    // ==================== execution ====================

    public function testInsertThenUpdateOnTheSameKey(): void
    {
        $first = Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['client_uuid' => 'u-1', 'name' => 'first', 'status' => 'active'],
            ['client_uuid']
        );
        self::assertIsArray($first);
        self::assertSame('first', $first['name']);

        $second = Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['client_uuid' => 'u-1', 'name' => 'second', 'status' => 'active'],
            ['client_uuid']
        );
        self::assertIsArray($second);
        self::assertSame('second', $second['name']);
        self::assertSame(
            $first['id'],
            $second['id'],
            'The conflict must have updated the SAME row, not inserted a second.'
        );

        self::assertSame(1, $this->countItems());
    }

    /**
     * The same client_uuid under a DIFFERENT tenant is a DIFFERENT row. This is
     * the behaviour an unscoped conflict target would silently destroy.
     */
    public function testTheSameKeyUnderAnotherTenantIsASeparateRow(): void
    {
        Upsert::tenantScoped($this->pdo, 'acme_items', 7, ['client_uuid' => 'u-1', 'name' => 'seven'], ['client_uuid']);
        Upsert::tenantScoped($this->pdo, 'acme_items', 8, ['client_uuid' => 'u-1', 'name' => 'eight'], ['client_uuid']);

        self::assertSame(2, $this->countItems());

        $stmt = $this->pdo->prepare('SELECT name FROM acme_items WHERE tenant_id = :t AND client_uuid = :u');
        $stmt->execute([':t' => 7, ':u' => 'u-1']);
        self::assertSame('seven', $stmt->fetchColumn(), "Tenant 7's row must be untouched by tenant 8's write.");
    }

    /**
     * The documented trap: with DO NOTHING, a conflict returns NO row on either
     * engine, so null means "already there" — not "failed".
     */
    public function testDoNothingReturnsNullWhenTheConflictFires(): void
    {
        $inserted = Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['client_uuid' => 'u-1', 'name' => 'first'],
            ['client_uuid'],
            []
        );
        self::assertIsArray($inserted, 'The first call inserts and returns the row.');

        $skipped = Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['client_uuid' => 'u-1', 'name' => 'ignored'],
            ['client_uuid'],
            []
        );
        self::assertNull($skipped, 'A DO NOTHING that skips returns nothing.');

        $stmt = $this->pdo->prepare('SELECT name FROM acme_items WHERE tenant_id = :t AND client_uuid = :u');
        $stmt->execute([':t' => 7, ':u' => 'u-1']);
        self::assertSame('first', $stmt->fetchColumn(), 'And leaves the existing row alone.');
    }

    public function testNullsAndBooleansBind(): void
    {
        $row = Upsert::tenantScoped(
            $this->pdo,
            'acme_items',
            7,
            ['client_uuid' => 'u-1', 'name' => null, 'status' => true],
            ['client_uuid']
        );

        self::assertIsArray($row);
        self::assertNull($row['name']);
        // Bound as an integer, not PARAM_BOOL: SQLite has no boolean type and
        // PDO's pgsql driver renders a bound bool in a form BOOLEAN rejects.
        self::assertSame(1, (int) $row['status']);
    }
}
