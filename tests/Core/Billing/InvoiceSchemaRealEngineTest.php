<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * What the billing schema REFUSES.
 *
 * These constraints are not decoration around the real logic — for this table
 * set they ARE the logic, and the reason they live in the database is that
 * every one of them guards against a bug that produces a PLAUSIBLE wrong
 * number. An invoice whose total is a hundred less than its lines, a second
 * invoice carrying the first one's number, a webhook redelivery crediting an
 * account twice: none of these look wrong in a code review, and all of them are
 * found by a customer rather than by us.
 *
 * So each one is tested by trying to store the bad row and asserting the engine
 * throws. A test that only stores good rows would pass against a schema with no
 * constraints at all.
 *
 * FOREIGN KEYS NEED ASKING FOR ON SQLite. The harness does not issue
 * `PRAGMA foreign_keys = ON`, so a test that relies on a foreign key silently
 * passes there while enforcing nothing — which is exactly how a fabricated
 * tenant id got through review on the promotions ledger and failed only on the
 * PostgreSQL dialect shard. {@see self::enableForeignKeys()} turns it on, and
 * the one test that depends on it says so.
 */
final class InvoiceSchemaRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->enableForeignKeys();

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'acme', 'acme')");
    }

    // ── an invoice must add up ───────────────────────────────────────────────

    /**
     * The constraint that catches a total computed by a path that forgot the
     * discount. 1000 - 100 + 0 is 900, and 1000 is refused.
     */
    public function testAnInvoiceWhoseTotalDoesNotAddUpCannotBeStored(): void
    {
        $this->expectException(PDOException::class);
        $this->insertInvoice(['subtotal_minor' => 1000, 'discount_minor' => 100, 'total_minor' => 1000]);
    }

    public function testAnInvoiceThatAddsUpIsStored(): void
    {
        $id = $this->insertInvoice([
            'subtotal_minor' => 1000,
            'discount_minor' => 100,
            'tax_minor' => 144,
            'total_minor' => 1044,
        ]);

        self::assertGreaterThan(0, $id);
    }

    /** A discount bigger than what it discounts is a credit note, not this. */
    public function testADiscountLargerThanTheSubtotalIsRefused(): void
    {
        $this->expectException(PDOException::class);
        $this->insertInvoice([
            'subtotal_minor' => 1000,
            'discount_minor' => 1500,
            'total_minor' => -500,
        ]);
    }

    public function testATaxRateAboveOneHundredPerCentIsRefused(): void
    {
        $this->expectException(PDOException::class);
        $this->insertInvoice(['tax_rate_bp' => 10001]);
    }

    // ── a draft has no number, and everything else has one ───────────────────

    /**
     * The pair that keeps abandoned drafts from punching holes in the
     * sequence. A draft carrying a number has already consumed one.
     */
    public function testADraftCannotCarryANumber(): void
    {
        $this->expectException(PDOException::class);
        $this->insertInvoice(['status' => 'draft', 'number' => 'INV-2026-00001']);
    }

    public function testAnOpenInvoiceMustCarryANumber(): void
    {
        $this->expectException(PDOException::class);
        $this->insertInvoice(['status' => 'open', 'number' => null]);
    }

    public function testAnOpenInvoiceWithANumberIsStored(): void
    {
        self::assertGreaterThan(0, $this->insertInvoice([
            'status' => 'open',
            'number' => 'INV-2026-00001',
        ]));
    }

    public function testAStatusOutsideTheVocabularyIsRefused(): void
    {
        $this->expectException(PDOException::class);
        $this->insertInvoice(['status' => 'issued', 'number' => 'INV-2026-00002']);
    }

    // ── a sequence never issues the same number twice ────────────────────────

    public function testTwoInvoicesInOneSeriesCannotShareANumber(): void
    {
        $this->insertInvoice(['status' => 'open', 'number' => 'INV-2026-00001']);

        $this->expectException(PDOException::class);
        $this->insertInvoice(['status' => 'open', 'number' => 'INV-2026-00001']);
    }

    /**
     * DIFFERENT SERIES MAY REUSE A NUMBER, which is the whole reason the
     * uniqueness is on the pair. A per-tenant numbering deployment gives each
     * tenant its own series, and every one of them starts at 1.
     */
    public function testTwoSeriesMayEachIssueTheSameNumber(): void
    {
        $this->insertInvoice(['status' => 'open', 'number' => 'INV-00001', 'series' => 't1']);

        self::assertGreaterThan(0, $this->insertInvoice([
            'status' => 'open',
            'number' => 'INV-00001',
            'series' => 't2',
        ]));
    }

    /** Many drafts coexist: NULL numbers must not collide with each other. */
    public function testManyDraftsCoexistWithoutNumbers(): void
    {
        $this->insertInvoice([]);
        $this->insertInvoice([]);

        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM invoices'));
    }

    // ── the retention guard ──────────────────────────────────────────────────

    /**
     * DELETING A TENANT THAT HAS INVOICES IS REFUSED. Every other tenant-owned
     * table cascades; this one restricts, because a cascade here is not cleanup
     * but the destruction of a tax record with a statutory retention period,
     * triggered silently by a button labelled "delete tenant".
     *
     * Depends on foreign keys being ENFORCED — see the class docblock. Without
     * {@see self::enableForeignKeys()} this test passes on SQLite while
     * proving nothing.
     */
    public function testATenantWithInvoicesCannotBeDeleted(): void
    {
        $this->insertInvoice(['status' => 'open', 'number' => 'INV-2026-00001']);

        $this->expectException(PDOException::class);
        $this->pdo->exec('DELETE FROM tenants WHERE id = ' . self::TENANT);
    }

    /** A tenant that was never billed still deletes exactly as before. */
    public function testATenantWithNoInvoicesDeletesNormally(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (9, 'unbilled', 'unbilled')");
        $this->pdo->exec('DELETE FROM tenants WHERE id = 9');

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM tenants WHERE id = 9'));
    }

    // ── lines ────────────────────────────────────────────────────────────────

    public function testTwoLinesCannotOccupyOnePosition(): void
    {
        $invoice = $this->insertInvoice([]);
        $this->insertLine($invoice, 0);

        $this->expectException(PDOException::class);
        $this->insertLine($invoice, 0);
    }

    public function testALineWhoseTotalDoesNotAddUpIsRefused(): void
    {
        $invoice = $this->insertInvoice([]);

        $this->expectException(PDOException::class);
        $this->insertLine($invoice, 0, ['subtotal_minor' => 500, 'total_minor' => 999]);
    }

    public function testALineWithNoQuantityIsRefused(): void
    {
        $invoice = $this->insertInvoice([]);

        $this->expectException(PDOException::class);
        $this->insertLine($invoice, 0, ['quantity' => 0]);
    }

    // ── the ledger ───────────────────────────────────────────────────────────

    /**
     * THE IDEMPOTENCY THE WEBHOOK STORY RESTS ON. Every processor redelivers,
     * and the second delivery of one charge must not credit the account twice.
     */
    public function testAReplayedProviderReferenceIsRefused(): void
    {
        $this->insertTransaction(['external_reference' => 'cliq-txn-991']);

        $this->expectException(PDOException::class);
        $this->insertTransaction(['external_reference' => 'cliq-txn-991']);
    }

    /** Two providers may each use the same identifier; they are separate spaces. */
    public function testTwoProvidersMayUseTheSameReference(): void
    {
        $this->insertTransaction(['provider' => 'cliq', 'external_reference' => 'txn-1']);

        self::assertGreaterThan(0, $this->insertTransaction([
            'provider' => 'mock',
            'external_reference' => 'txn-1',
        ]));
    }

    /**
     * Manually keyed movements have no reference and must not be forced to
     * invent one — which a non-partial unique index would do by letting only
     * a single NULL exist.
     */
    public function testManyManualTransactionsCoexistWithoutAReference(): void
    {
        $this->insertTransaction(['external_reference' => null]);
        $this->insertTransaction(['external_reference' => null]);

        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payment_transactions'));
    }

    /** A refund is a negative row, so a balance stays a plain SUM. */
    public function testANegativeAmountIsAllowedBecauseThatIsWhatARefundIs(): void
    {
        self::assertGreaterThan(0, $this->insertTransaction(['amount_minor' => -2500]));
    }

    public function testAZeroAmountIsRefusedBecauseItRecordsNothing(): void
    {
        $this->expectException(PDOException::class);
        $this->insertTransaction(['amount_minor' => 0]);
    }

    public function testAStatusOutsideTheLedgerVocabularyIsRefused(): void
    {
        $this->expectException(PDOException::class);
        $this->insertTransaction(['status' => 'settled']);
    }

    // ── payment methods ──────────────────────────────────────────────────────

    /**
     * Two defaults would mean a charge going to whichever the ORDER BY happened
     * to return, which is the sort of bug that is invisible until it charges
     * the wrong card.
     */
    public function testATenantCannotHaveTwoDefaultMethodsWithOneProvider(): void
    {
        $this->insertMethod(['external_id' => 'pm_1', 'is_default' => true]);

        $this->expectException(PDOException::class);
        $this->insertMethod(['external_id' => 'pm_2', 'is_default' => true]);
    }

    /** One default per PROVIDER, though — the rails are independent. */
    public function testADefaultPerProviderIsAllowed(): void
    {
        $this->insertMethod(['provider' => 'cliq', 'external_id' => 'pm_1', 'is_default' => true]);

        self::assertGreaterThan(0, $this->insertMethod([
            'provider' => 'mock',
            'external_id' => 'pm_2',
            'is_default' => true,
        ]));
    }

    /**
     * A RETIRED DEFAULT DOES NOT BLOCK A NEW ONE. The index is partial on
     * `is_active` precisely so replacing a card is possible without first
     * inventing a state where the tenant has no default at all.
     */
    public function testAnInactiveDefaultDoesNotBlockANewOne(): void
    {
        $this->insertMethod(['external_id' => 'pm_old', 'is_default' => true, 'is_active' => false]);

        self::assertGreaterThan(0, $this->insertMethod([
            'external_id' => 'pm_new',
            'is_default' => true,
        ]));
    }

    public function testOneProviderIdentifierMeansOneMethod(): void
    {
        $this->insertMethod(['external_id' => 'pm_1']);

        $this->expectException(PDOException::class);
        $this->insertMethod(['external_id' => 'pm_1']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function insertInvoice(array $overrides): int
    {
        $row = $overrides + [
            'tenant_id' => self::TENANT,
            'number' => null,
            'series' => 'default',
            'status' => 'draft',
            'currency' => 'JOD',
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'tax_rate_bp' => 0,
        ];

        $columns = implode(', ', array_keys($row));
        $binds = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($row)));

        $statement = $this->pdo->prepare("INSERT INTO invoices ({$columns}) VALUES ({$binds})");
        $statement->execute(array_combine(
            array_map(static fn (string $c): string => ':' . $c, array_keys($row)),
            array_values($row)
        ));

        return $this->scalar('SELECT MAX(id) FROM invoices');
    }

    /** @param array<string, mixed> $overrides */
    private function insertLine(int $invoiceId, int $position, array $overrides = []): void
    {
        $row = $overrides + [
            'invoice_id' => $invoiceId,
            'tenant_id' => self::TENANT,
            'position' => $position,
            'description' => 'A line',
            'quantity' => 1,
            'unit_amount_minor' => 500,
            'subtotal_minor' => 500,
            'discount_minor' => 0,
            'tax_rate_bp' => 0,
            'tax_minor' => 0,
            'total_minor' => 500,
        ];

        $columns = implode(', ', array_keys($row));
        $binds = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($row)));

        $this->pdo->prepare("INSERT INTO invoice_lines ({$columns}) VALUES ({$binds})")
            ->execute(array_combine(
                array_map(static fn (string $c): string => ':' . $c, array_keys($row)),
                array_values($row)
            ));
    }

    /** @param array<string, mixed> $overrides */
    private function insertTransaction(array $overrides): int
    {
        $row = $overrides + [
            'tenant_id' => self::TENANT,
            'provider' => 'cliq',
            'external_reference' => null,
            'status' => 'succeeded',
            'amount_minor' => 5000,
            'currency' => 'JOD',
        ];

        $columns = implode(', ', array_keys($row));
        $binds = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($row)));

        $this->pdo->prepare("INSERT INTO payment_transactions ({$columns}) VALUES ({$binds})")
            ->execute(array_combine(
                array_map(static fn (string $c): string => ':' . $c, array_keys($row)),
                array_values($row)
            ));

        return $this->scalar('SELECT MAX(id) FROM payment_transactions');
    }

    /** @param array<string, mixed> $overrides */
    private function insertMethod(array $overrides): int
    {
        $row = $overrides + [
            'tenant_id' => self::TENANT,
            'provider' => 'cliq',
            'external_id' => 'pm_default',
            'is_default' => false,
            'is_active' => true,
        ];

        $columns = implode(', ', array_keys($row));
        $binds = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($row)));

        $statement = $this->pdo->prepare("INSERT INTO payment_methods ({$columns}) VALUES ({$binds})");
        foreach ($row as $column => $value) {
            $statement->bindValue(
                ':' . $column,
                $value,
                is_bool($value) ? PDO::PARAM_BOOL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)
            );
        }
        $statement->execute();

        return $this->scalar('SELECT MAX(id) FROM payment_methods');
    }

    /**
     * One integer from a query, refusing to guess when the statement did not
     * run. `PDO::query` returns false on failure, and `(int) false` is 0 — a
     * value that reads as "no rows" and would make a count assertion pass
     * against a query that never executed.
     */
    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            self::fail("Query did not run: {$sql}");
        }

        return (int) $statement->fetchColumn();
    }

    /**
     * SQLite enforces foreign keys only when asked, and the harness does not
     * ask. Without this the RESTRICT test above would pass while enforcing
     * nothing — the exact shape of vacuous test this repository keeps finding.
     * PostgreSQL needs no equivalent; it has never had an off switch.
     */
    private function enableForeignKeys(): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
