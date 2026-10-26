<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentBodyRepository;

/**
 * The editable document BODY, and the one rule that makes "draft" mean
 * anything (WHIT-679).
 *
 * WHAT THIS ADDS. Until now a `documents` row had no body: its content lived on
 * the TEMPLATE, and the document's own content was the immutable artifact plus
 * the `variable_data` it was raised with (migration 118). So a document could
 * not be drafted or revised — it existed from the moment it was issued. This
 * puts a FlowContent body in a side table keyed by `document_id`.
 *
 * WHY DRAFT-NESS LIVES HERE AND NOT ON `documents`. Migration 108 refuses a
 * status column on `documents` because routing's state is what its append-only
 * trail says, and a stored copy beside it drifts. That reasoning is kept:
 * nothing is added to `documents` at all.
 *
 *  - no body row            -> an ordinary template-rendered document. Behaves
 *                              exactly as it did before this table existed, and
 *                              needed no backfill.
 *  - body, `issued_at` NULL -> a DRAFT.
 *  - body, `issued_at` set  -> issued; the body is frozen.
 *
 * `issued_at` is a timestamp recording an EVENT that happens once, not a
 * mutable status word: it has two states, it is set once, and nothing has to
 * maintain it.
 *
 * THE ANSWER THAT LOOKS RIGHT AND IS NOT. The tempting no-new-column version is
 * to derive issued-ness from "does this document have an artifact". It fails on
 * a default install: {@see \Whity\Core\Document\DocumentIssuer} records that
 * `documents.render_enabled` defaults to FALSE, so the render container does
 * not exist and NO document can ever have an artifact — every document would
 * read as a permanent draft. Having bytes and having been issued are different
 * facts, and the render tier is optional.
 *
 * WHY THE FREEZE IS A CONDITIONAL UPDATE AND NOT AN `if`. "Read the row, check
 * it is still a draft, then write" is a race: two saves either side of an issue
 * both read "draft" and the later one writes into an issued document. The
 * refusal is therefore a predicate on the UPDATE itself, and these tests assert
 * the REFUSAL (a false return and an unchanged body), not merely that some
 * exception appeared.
 */
final class DocumentBodyRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private DocumentBodyRepository $bodies;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");

        // Real profile rows, because `document_bodies.updated_by` carries a
        // foreign key and PostgreSQL enforces it. Inventing ids here passed on
        // SQLite (pragma off) and failed on the dialect shard — the exact split
        // this suite exists to catch, so the fixture is made honest rather than
        // the column made nullable-in-practice.
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash) VALUES
                (77, 'Drafter', 'not-a-real-hash-fixture-value-00000000'),
                (99, 'Reviser', 'not-a-real-hash-fixture-value-11111111')"
        );
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->bodies = new DocumentBodyRepository($this->pdo);
    }

    /**
     * A representative FlowContent body, including the inline runs flow gained
     * in WHIT-681 — the body shape is reused rather than reinvented, so the
     * round trip has to carry marks as well as text.
     *
     * @return list<array<string, mixed>>
     */
    private function sampleBody(): array
    {
        return [
            ['type' => 'heading', 'level' => 1, 'text' => 'Notice of award'],
            [
                'type' => 'paragraph',
                'text' => 'The committee has agreed.',
                'runs' => [
                    ['text' => 'The committee has '],
                    ['text' => 'agreed', 'bold' => true],
                    ['text' => '.'],
                ],
            ],
        ];
    }

    /** Insert a bare document record and return its id. */
    private function makeDocument(int $tenantId, string $title = 'Doc'): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO documents (tenant_id, template_name, title, created_by, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $statement->execute([$tenantId, 'Letter', $title, null]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── the body round trip ─────────────────────────────────────────────────

    public function testABodyRoundTripsByValueIncludingInlineRuns(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);

        self::assertTrue($this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), 77));

        $row = $this->bodies->find(self::TENANT_A, $documentId);
        self::assertNotNull($row);
        // assertEquals, not assertSame: Postgres jsonb round-trips by VALUE and
        // reorders object keys, while SQLite stores the column as TEXT and
        // preserves them. Comparing the raw JSON passes on SQLite and reds the
        // dialect shard — migration 118's docblock records paying for exactly
        // that.
        self::assertEquals($this->sampleBody(), $row['content'], 'the body must round-trip by value');
        self::assertSame(77, $row['updated_by']);
    }

    public function testADocumentWithNoBodyIsNotADraftAndReadsAsNull(): void
    {
        // The unchanged case, and the reason no backfill was needed: every
        // document that existed before this table has no row here.
        $documentId = $this->makeDocument(self::TENANT_A);

        self::assertNull($this->bodies->find(self::TENANT_A, $documentId));
        self::assertFalse($this->bodies->isDraft(self::TENANT_A, $documentId));
    }

    public function testANewBodyIsADraft(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);

        self::assertTrue($this->bodies->isDraft(self::TENANT_A, $documentId));
        self::assertNull($this->bodies->find(self::TENANT_A, $documentId)['issued_at']);
    }

    public function testADraftCanBeRevisedRepeatedly(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);

        $revised = [['type' => 'paragraph', 'text' => 'Rewritten.']];
        self::assertTrue($this->bodies->save(self::TENANT_A, $documentId, $revised, 99));

        $row = $this->bodies->find(self::TENANT_A, $documentId);
        self::assertEquals($revised, $row['content']);
        self::assertSame(99, $row['updated_by']);
    }

    // ── issuing freezes the body ────────────────────────────────────────────

    public function testIssuingSetsIssuedAtAndEndsDraftness(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);

        self::assertTrue($this->bodies->issue(self::TENANT_A, $documentId));

        self::assertFalse($this->bodies->isDraft(self::TENANT_A, $documentId));
        self::assertNotNull($this->bodies->find(self::TENANT_A, $documentId)['issued_at']);
    }

    public function testIssuingTwiceIsRefusedRatherThanMovingTheTimestamp(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);
        $this->bodies->issue(self::TENANT_A, $documentId);
        $issuedAt = $this->bodies->find(self::TENANT_A, $documentId)['issued_at'];

        self::assertFalse($this->bodies->issue(self::TENANT_A, $documentId));
        self::assertSame(
            $issuedAt,
            $this->bodies->find(self::TENANT_A, $documentId)['issued_at'],
            'a second issue must not move the timestamp that records the first'
        );
    }

    public function testIssuingADocumentWithNoBodyIsRefused(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);

        self::assertFalse($this->bodies->issue(self::TENANT_A, $documentId));
    }

    public function testAnIssuedBodyCannotBeEdited(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);
        $this->bodies->issue(self::TENANT_A, $documentId);

        self::assertFalse(
            $this->bodies->save(self::TENANT_A, $documentId, [['type' => 'paragraph', 'text' => 'Tampered.']], 99),
            'saving into an issued body must be refused'
        );
        self::assertEquals(
            $this->sampleBody(),
            $this->bodies->find(self::TENANT_A, $documentId)['content'],
            'a refused save must leave the issued body byte-for-byte as it was'
        );
    }

    // ── tenant isolation ────────────────────────────────────────────────────

    public function testABodyIsTenantIsolatedForReads(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);

        self::assertNull($this->bodies->find(self::TENANT_B, $documentId));
        self::assertFalse($this->bodies->isDraft(self::TENANT_B, $documentId));
    }

    public function testTenantBCannotWriteOrIssueTenantAsBody(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);

        self::assertFalse($this->bodies->save(self::TENANT_B, $documentId, [['type' => 'paragraph', 'text' => 'x']], null));
        self::assertFalse($this->bodies->issue(self::TENANT_B, $documentId));

        // And A's body is untouched by B's attempts.
        $row = $this->bodies->find(self::TENANT_A, $documentId);
        self::assertEquals($this->sampleBody(), $row['content']);
        self::assertNull($row['issued_at']);
    }

    public function testTenantBCannotCREATEABodyOnTenantAsDocument(): void
    {
        // The case a `WHERE tenant_id = ?` on the UPDATE alone would miss: there
        // is no row yet, so the write is an INSERT and has nothing to filter.
        // The tenant must be checked against the DOCUMENT.
        $documentId = $this->makeDocument(self::TENANT_A);

        self::assertFalse($this->bodies->save(self::TENANT_B, $documentId, $this->sampleBody(), null));
        self::assertNull($this->bodies->find(self::TENANT_A, $documentId), 'no body may exist on A s document');
        self::assertNull($this->bodies->find(self::TENANT_B, $documentId));
    }

    // ── lifecycle ───────────────────────────────────────────────────────────

    public function testDeletingTheDocumentRemovesItsBody(): void
    {
        $documentId = $this->makeDocument(self::TENANT_A);
        $this->bodies->save(self::TENANT_A, $documentId, $this->sampleBody(), null);

        // SQLite enforces foreign keys only with the pragma ON, and the suite
        // leaves it off. The CASCADE below is a database guarantee that simply
        // does not fire without it, so it is turned on for this connection
        // rather than the assertion being skipped: otherwise this test measures
        // the real thing on PostgreSQL and measures NOTHING on SQLite while
        // reporting green. Same treatment, for the same reason, as
        // RouteTemplateInstantiationRealEngineTest.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        $this->pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$documentId]);

        self::assertNull($this->bodies->find(self::TENANT_A, $documentId));
    }
}
