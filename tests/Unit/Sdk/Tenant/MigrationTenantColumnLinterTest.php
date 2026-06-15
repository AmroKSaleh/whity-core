<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Tenant\MigrationTenantColumnLinter;
use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * Teeth for the WC-194 migration linter: a plugin tenant table that ships a
 * `CREATE TABLE` WITHOUT a `tenant_id` column must FAIL the kit, while a
 * correctly columned table, a declared-global table, and a declared
 * transitively-scoped table PASS.
 *
 * These pin the linter against inline SQL fixtures (no new committed plugin —
 * Plugin Repository Hygiene), so a false negative here (an unscoped tenant
 * table slipping through as green) cannot ship.
 */
final class MigrationTenantColumnLinterTest extends TestCase
{
    public function testTenantTableWithTenantIdColumnPasses(): void
    {
        $linter = new MigrationTenantColumnLinter(new TenantTableRegistry());

        $sql = "CREATE TABLE IF NOT EXISTS notes (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            body TEXT NOT NULL
        )";

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    public function testTenantTableMissingTenantIdColumnFails(): void
    {
        // THE TEETH: a tenant-owned-shaped table with no tenant_id column and no
        // sanctioned-exception declaration must be flagged.
        $linter = new MigrationTenantColumnLinter(new TenantTableRegistry());

        $sql = "CREATE TABLE leaky_notes (
            id SERIAL PRIMARY KEY,
            body TEXT NOT NULL
        )";

        $violations = $linter->lintSource($sql, 'm.php');

        self::assertCount(1, $violations, 'A tenant table without tenant_id must FAIL the linter.');
        self::assertSame('leaky_notes', $violations[0]['table']);
        self::assertStringContainsString('tenant_id', $violations[0]['reason']);
    }

    public function testTableDeclaredGlobalIsExempt(): void
    {
        $registry = TenantTableRegistry::for([], ['app_settings' => 'platform-wide settings; no tenant column']);
        $linter = new MigrationTenantColumnLinter($registry);

        $sql = "CREATE TABLE app_settings (id SERIAL PRIMARY KEY, value TEXT)";

        self::assertSame([], $linter->lintSource($sql, 'm.php'), 'A declared-global table needs no tenant_id.');
    }

    public function testTableDeclaredTransitivelyScopedIsExempt(): void
    {
        // A child table scoped via a parent (no tenant_id of its own) is declared
        // in the registry as a documented exception.
        $registry = TenantTableRegistry::for(['note_tags' => 'scoped transitively via notes.note_id']);
        $linter = new MigrationTenantColumnLinter($registry);

        $sql = "CREATE TABLE note_tags (note_id INTEGER NOT NULL, tag TEXT NOT NULL)";

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    public function testNestedReferenceParensDoNotTruncateTheBody(): void
    {
        // The tenant_id column sits AFTER a column with a nested REFERENCES(...)
        // paren — the balanced-paren walk must still see it.
        $linter = new MigrationTenantColumnLinter(new TenantTableRegistry());

        $sql = "CREATE TABLE items (
            id SERIAL PRIMARY KEY,
            owner_id INTEGER NOT NULL REFERENCES users(id),
            tenant_id INTEGER NOT NULL,
            label TEXT
        )";

        self::assertSame([], $linter->lintSource($sql, 'm.php'), 'tenant_id after a REFERENCES() paren must count.');
    }

    public function testTenantIdMentionWithoutAnIntegerTypeDoesNotCount(): void
    {
        // A coincidental mention (e.g. a comment) must not be mistaken for the
        // column declaration — only `tenant_id <int-type>` counts.
        $linter = new MigrationTenantColumnLinter(new TenantTableRegistry());

        $sql = "CREATE TABLE ghost (
            id SERIAL PRIMARY KEY,
            note TEXT
        )";

        self::assertCount(1, $linter->lintSource($sql, 'm.php'));
    }

    public function testMultipleCreateTablesAreEachJudged(): void
    {
        $linter = new MigrationTenantColumnLinter(new TenantTableRegistry());

        $sql = "
            CREATE TABLE good (id SERIAL PRIMARY KEY, tenant_id INTEGER NOT NULL);
            CREATE TABLE bad (id SERIAL PRIMARY KEY, body TEXT);
        ";

        $violations = $linter->lintSource($sql, 'm.php');
        self::assertCount(1, $violations);
        self::assertSame('bad', $violations[0]['table']);
    }
}
