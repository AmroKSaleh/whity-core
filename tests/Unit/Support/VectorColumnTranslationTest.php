<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * pgvector columns on the SQLite test path (adoption report §6.2).
 *
 * The image now ships the `vector` extension, so a plugin may declare a vector
 * column for similarity search. The test schema builder still has to cope on
 * SQLite, which has neither the type nor extensions — and the failure mode if
 * it does not is disproportionate: a plugin's vector migration would abort the
 * whole schema build, taking every unrelated test in that suite down with it,
 * for a column those tests never read.
 *
 * WHAT IS NOT CLAIMED. TEXT stands in for the storage, because pgvector accepts
 * and emits the literal '[1,2,3]' form and a value therefore round-trips
 * identically on both engines. The DISTANCE OPERATORS (`<->`, `<=>`, `<#>`) are
 * PostgreSQL-only and are NOT emulated. A test that exercises similarity
 * ranking has to run on PostgreSQL, exactly as the dialect suites already do —
 * pretending otherwise would let a ranking bug pass on the engine most
 * developers run locally.
 */
final class VectorColumnTranslationTest extends TestCase
{
    /**
     * `make()`, not `new PDO` + `apply()`. The translation lives in the PDO
     * SUBCLASS that make() returns, so a bare PDO gets none of it and dies on
     * `SERIAL` in migration 001 — before reaching anything this test is about.
     */
    private function sqlite(): PDO
    {
        return SchemaFromMigrations::make();
    }

    /**
     * `query()` returns `PDOStatement|false`, never null, so a nullsafe call is
     * the wrong instrument — it type-checks as unnecessary and still leaves the
     * false case unhandled. Assert the statement instead.
     */
    private function queryOne(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);
        self::assertNotFalse($stmt, "query failed: {$sql}");

        return $stmt;
    }

    /**
     * The translator is reachable only through the schema builder, so this
     * drives it the way a migration would: run the DDL and see what survives.
     */
    public function testAVectorColumnBecomesTextAndStillRoundTrips(): void
    {
        $pdo = $this->sqlite();

        $pdo->exec('CREATE TABLE embeddings (id INTEGER PRIMARY KEY, embedding vector(384))');
        $pdo->exec("INSERT INTO embeddings (id, embedding) VALUES (1, '[1,2,3]')");

        $value = $this->queryOne($pdo, 'SELECT embedding FROM embeddings WHERE id = 1')->fetchColumn();

        // The literal form pgvector itself accepts and emits, so a fixture
        // written for one engine reads back the same on the other.
        self::assertSame('[1,2,3]', $value);
    }

    public function testCreateExtensionIsANoOpRatherThanASyntaxError(): void
    {
        $pdo = $this->sqlite();

        // A plugin's migration opens with this. Before the translation rule it
        // was a hard SQLite syntax error, which aborted the entire schema build
        // rather than the one statement that could not apply.
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');

        self::assertSame('1', (string) $this->queryOne($pdo, 'SELECT 1')->fetchColumn());
    }

    /**
     * The control. `vector` is translated as a TYPE, by its parenthesised
     * dimension — it must not rewrite an ordinary identifier that merely
     * contains the word, or a column called `vector_id` would silently become
     * something else.
     */
    public function testAnIdentifierContainingVectorIsUntouched(): void
    {
        $pdo = $this->sqlite();

        $pdo->exec('CREATE TABLE t (vector_id INTEGER, described_vector TEXT)');
        $pdo->exec("INSERT INTO t (vector_id, described_vector) VALUES (7, 'kept')");

        $row = $this->queryOne($pdo, 'SELECT vector_id, described_vector FROM t')->fetch(PDO::FETCH_ASSOC);

        self::assertSame(['vector_id' => 7, 'described_vector' => 'kept'], $row);
    }
}
