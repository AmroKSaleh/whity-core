<?php

declare(strict_types=1);

namespace Tests\Core\Db;

use PHPUnit\Framework\TestCase;
use Whity\Core\Db\DbBoolScanner;

/**
 * Cover for the CI guard behind #891.
 *
 * Two halves, and the second matters as much as the first: a guard that only
 * proves it FIRES has not shown it is usable. Most of these tests assert that
 * it stays SILENT on shapes that are correct — request bodies, existence
 * probes, non-boolean columns — because that is what decides whether the guard
 * survives contact with the codebase or gets muted.
 */
final class DbBoolScannerTest extends TestCase
{
    private function scanner(): DbBoolScanner
    {
        return new DbBoolScanner(['is_primary', 'enabled', 'verified', 'auto_provision']);
    }

    /** @param list<array{file: string, line: int, snippet: string, reason: string}> $violations */
    private static function snippets(array $violations): string
    {
        return implode(' | ', array_column($violations, 'snippet'));
    }

    // ==================== It fires ====================

    public function testFlagsABareCastOnABooleanColumn(): void
    {
        $source = <<<'PHP'
        <?php
        function toPublic(array $row): array
        {
            return ['isPrimary' => (bool) $row['is_primary']];
        }
        PHP;

        $violations = $this->scanner()->scanSource($source);

        self::assertCount(1, $violations, self::snippets($violations));
        self::assertSame(4, $violations[0]['line']);
        self::assertStringContainsString('is_primary', $violations[0]['snippet']);
    }

    /** The exact shape of RolesApiHandler's instance: cast wrapping a coalesce. */
    public function testFlagsACastWrappedInParenthesesWithACoalesce(): void
    {
        $source = <<<'PHP'
        <?php
        $out = ['isPrimary' => (bool)($row['is_primary'] ?? false)];
        PHP;

        self::assertCount(1, $this->scanner()->scanSource($source));
    }

    /** A row arriving as a PARAMETER has no assignment to inspect — still flagged. */
    public function testFlagsARowReceivedAsAParameter(): void
    {
        $source = <<<'PHP'
        <?php
        final class Mapper
        {
            public function map(array $record): bool
            {
                return (bool) $record['verified'];
            }
        }
        PHP;

        self::assertCount(1, $this->scanner()->scanSource($source));
    }

    public function testFlagsAFetchWhoseStatementProjectsABoolean(): void
    {
        $source = <<<'PHP'
        <?php
        $stmt = $db->prepare('SELECT EXISTS (SELECT 1 FROM t WHERE x = :x) AS present');
        $stmt->execute([':x' => 1]);
        return (bool) $stmt->fetchColumn();
        PHP;

        $violations = $this->scanner()->scanSource($source);

        self::assertCount(1, $violations, self::snippets($violations));
        self::assertStringContainsString('fetchColumn', $violations[0]['snippet']);
    }

    // ==================== It stays quiet ====================

    public function testIgnoresAColumnThatIsNotABoolean(): void
    {
        $source = <<<'PHP'
        <?php
        $flags = ['dryRun' => (bool) $row['dry_run'], 'force' => (bool) $row['force']];
        PHP;

        self::assertSame([], $this->scanner()->scanSource($source));
    }

    public function testIgnoresARequestBodyEvenWhenTheKeyIsABooleanColumn(): void
    {
        $source = <<<'PHP'
        <?php
        $body = JsonBody::parsed($request);
        $enabled = (bool) $body['enabled'];
        PHP;

        self::assertSame([], $this->scanner()->scanSource($source), 'a JSON body is not a database read');
    }

    public function testIgnoresAJsonDecodedBody(): void
    {
        $source = <<<'PHP'
        <?php
        $payload = json_decode($request->getBody(), true);
        $enabled = (bool) $payload['enabled'];
        PHP;

        self::assertSame([], $this->scanner()->scanSource($source));
    }

    /**
     * The five existence probes in src/Auth look identical to the flagged shape
     * above; what separates them is the SQL. `SELECT 1` projects a literal no
     * driver spells two ways.
     */
    public function testIgnoresAnExistenceProbe(): void
    {
        $source = <<<'PHP'
        <?php
        $stmt = $db->prepare('SELECT 1 FROM memberships WHERE profile_id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
        PHP;

        self::assertSame([], $this->scanner()->scanSource($source));
    }

    public function testIgnoresACountProbe(): void
    {
        $source = <<<'PHP'
        <?php
        $stmt = $db->prepare('SELECT COUNT(*) FROM memberships WHERE tenant_id = ?');
        $stmt->execute([$t]);
        return (bool) $stmt->fetchColumn();
        PHP;

        self::assertSame([], $this->scanner()->scanSource($source));
    }

    public function testIgnoresANonLiteralSubscriptKey(): void
    {
        $source = <<<'PHP'
        <?php
        $value = (bool) $row[$key];
        PHP;

        self::assertSame([], $this->scanner()->scanSource($source));
    }

    // ==================== The escape hatch ====================

    public function testAnAnnotationWithAReasonSuppressesTheFlag(): void
    {
        $source = <<<'PHP'
        <?php
        // @db-bool-ignore: write path, $data is the caller's API payload.
        $literal = ((bool) $data['enabled']) ? 'TRUE' : 'FALSE';
        PHP;

        self::assertSame([], $this->scanner()->scanSource($source));
    }

    public function testAnAnnotationWithoutAReasonIsItselfReported(): void
    {
        $source = <<<'PHP'
        <?php
        // @db-bool-ignore:
        $value = (bool) $row['is_primary'];
        PHP;

        $violations = $this->scanner()->scanSource($source);

        self::assertNotSame([], $violations, 'a bare tag must not silence the guard');
        self::assertStringContainsString(
            'carries no reason',
            implode(' ', array_column($violations, 'reason'))
        );
    }

    /**
     * A name assigned from BOTH a request and a statement in one file is
     * ambiguous, and the scanner resolves ambiguity by reporting rather than
     * excusing — the direction that cannot hide a real defect.
     */
    public function testAnAmbiguousVariableIsFlagged(): void
    {
        $source = <<<'PHP'
        <?php
        function a($request) { $row = json_decode($request->getBody(), true); return $row; }
        function b($stmt) { $row = $stmt->fetch(PDO::FETCH_ASSOC); return (bool) $row['is_primary']; }
        PHP;

        self::assertCount(1, $this->scanner()->scanSource($source));
    }

    // ==================== Schema derivation ====================

    public function testLearnsBooleanColumnsFromMigrationSource(): void
    {
        $migration = <<<'SQL'
        CREATE TABLE IF NOT EXISTS widgets (
            id SERIAL PRIMARY KEY,
            is_shiny BOOLEAN NOT NULL DEFAULT false,
            label VARCHAR(64) NOT NULL
        );
        ALTER TABLE widgets ADD COLUMN IF NOT EXISTS is_boxed BOOLEAN DEFAULT true;
        SQL;

        $columns = DbBoolScanner::booleanColumnsIn($migration);

        self::assertContains('is_shiny', $columns);
        self::assertContains('is_boxed', $columns);
        self::assertNotContains('label', $columns);
        self::assertNotContains('column', $columns, 'the ADD COLUMN keyword is not a column name');
    }

    /** The real migrations must yield the columns #891 was actually about. */
    public function testRealMigrationsYieldTheKnownBooleanColumns(): void
    {
        $scanner = DbBoolScanner::fromMigrations();

        $source = <<<'PHP'
        <?php
        $a = (bool) $row['is_primary'];
        $b = (bool) $row['two_factor_enabled'];
        $c = (bool) $row['deceased'];
        PHP;

        self::assertCount(3, $scanner->scanSource($source));
    }
}
