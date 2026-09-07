<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;
use PDO;

/**
 * Invoices and their lines.
 *
 * THE SNAPSHOT RULE, RESTATED WHERE IT IS ENFORCED. Migration 142 explains why
 * an invoice copies its amounts, tax treatment and both parties' details rather
 * than joining for them; this is the class that has to keep that promise. So
 * {@see self::issue()} takes the seller and buyer details as arguments and
 * writes them onto the row. It does not look them up. A repository that reached
 * for the tenant's current name here would silently undo the whole design.
 *
 * TOTALS ARE COMPUTED FROM LINES, NEVER SET DIRECTLY. The database CHECK insists
 * an invoice adds up, and the only way to be sure it does is to have one place
 * that does the arithmetic. {@see self::recalculate()} is that place, and every
 * mutation of a line runs through it.
 *
 * DRAFTS ARE MUTABLE, ISSUED INVOICES ARE NOT. Past draft, the only permitted
 * transitions are to paid, void or uncollectible — never an edit. An issued
 * invoice is evidence, and evidence that can be edited is not evidence. A
 * correction is a credit note, which is a new document.
 */
final class InvoiceRepository
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';
    public const STATUS_UNCOLLECTIBLE = 'uncollectible';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * A new draft. No number, because a draft that took one and was abandoned
     * would punch a hole in the sequence.
     */
    public function createDraft(
        int $tenantId,
        string $currency,
        ?int $profileId = null,
        ?int $planId = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO invoices (tenant_id, profile_id, currency, status, plan_id,
                                   period_start, period_end, created_at, updated_at)
             VALUES (:tenant_id, :profile_id, :currency, :status, :plan_id,
                     :period_start, :period_end, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':profile_id', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':currency', strtoupper($currency));
        $statement->bindValue(':status', self::STATUS_DRAFT);
        $statement->bindValue(':plan_id', $planId, $planId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':period_start', $periodStart, $periodStart === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':period_end', $periodEnd, $periodEnd === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Add a line and recompute the invoice.
     *
     * `$description` IS A SNAPSHOT. "Professional plan, 12 seats, March 2026"
     * has to keep saying that after the plan is renamed, re-tiered or withdrawn.
     *
     * @throws InvoiceStateException When the invoice is no longer a draft.
     */
    public function addLine(
        int $tenantId,
        int $invoiceId,
        string $description,
        int $quantity,
        int $unitAmountMinor,
        int $taxRateBp = 0,
        int $discountMinor = 0,
    ): int {
        $this->assertDraft($tenantId, $invoiceId);

        if ($quantity < 1) {
            throw new InvoiceStateException('A line needs a quantity of at least one.');
        }

        $subtotal = $quantity * $unitAmountMinor;

        if ($discountMinor > $subtotal) {
            throw new InvoiceStateException(
                'A line discount cannot exceed what it discounts; that would be a credit note.'
            );
        }

        // Tax is computed on the DISCOUNTED amount, which is what every tax
        // authority means by a taxable base: a discount reduces the
        // consideration, so it reduces the tax with it. Taxing the undiscounted
        // figure overcharges the customer on their own promotion.
        $taxable = $subtotal - $discountMinor;
        $tax = intdiv($taxable * $taxRateBp + 5000, 10000);

        $position = $this->nextPosition($invoiceId);

        $statement = $this->pdo->prepare(
            'INSERT INTO invoice_lines (invoice_id, tenant_id, position, description, quantity,
                                        unit_amount_minor, subtotal_minor, discount_minor,
                                        tax_rate_bp, tax_minor, total_minor, created_at)
             VALUES (:invoice_id, :tenant_id, :position, :description, :quantity,
                     :unit_amount, :subtotal, :discount, :tax_rate, :tax, :total, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        $statement->execute([
            ':invoice_id' => $invoiceId,
            ':tenant_id' => $tenantId,
            ':position' => $position,
            ':description' => $description,
            ':quantity' => $quantity,
            ':unit_amount' => $unitAmountMinor,
            ':subtotal' => $subtotal,
            ':discount' => $discountMinor,
            ':tax_rate' => $taxRateBp,
            ':tax' => $tax,
            ':total' => $taxable + $tax,
        ]);

        $lineId = (int) $statement->fetchColumn();
        $this->recalculate($tenantId, $invoiceId);

        return $lineId;
    }

    /**
     * Recompute the invoice from its lines.
     *
     * ONE PLACE DOES THE ARITHMETIC. The database CHECK insists the invoice adds
     * up, and having a single writer of the totals is what makes that
     * achievable rather than a constraint everything trips over.
     */
    public function recalculate(int $tenantId, int $invoiceId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(subtotal_minor), 0) AS subtotal,
                    COALESCE(SUM(discount_minor), 0) AS discount,
                    COALESCE(SUM(tax_minor), 0) AS tax
               FROM invoice_lines
              WHERE tenant_id = :tenant_id AND invoice_id = :invoice_id'
        );
        $statement->execute([':tenant_id' => $tenantId, ':invoice_id' => $invoiceId]);
        /** @var array<string, mixed> $sums */
        $sums = $statement->fetch(PDO::FETCH_ASSOC) ?: ['subtotal' => 0, 'discount' => 0, 'tax' => 0];

        $subtotal = (int) $sums['subtotal'];
        $discount = (int) $sums['discount'];
        $tax = (int) $sums['tax'];

        $update = $this->pdo->prepare(
            'UPDATE invoices
                SET subtotal_minor = :subtotal,
                    discount_minor = :discount,
                    tax_minor = :tax,
                    total_minor = :total,
                    updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :invoice_id'
        );
        $update->execute([
            ':subtotal' => $subtotal,
            ':discount' => $discount,
            ':tax' => $tax,
            ':total' => $subtotal - $discount + $tax,
            ':tenant_id' => $tenantId,
            ':invoice_id' => $invoiceId,
        ]);
    }

    /**
     * Draft → open, taking a number and freezing both parties' details.
     *
     * THE SNAPSHOT ARGUMENTS ARE NOT OPTIONAL CONVENIENCE. They are the
     * mechanism: passing them in is what stops this class from reaching for the
     * tenant's current name, which is the one thing that would quietly undo the
     * design. See migration 142.
     *
     * @param array{name?: string, address?: string, tax_id?: string} $seller
     * @param array{name?: string, address?: string, tax_id?: string} $buyer
     *
     * @throws InvoiceStateException When the invoice is not a draft, or is empty.
     */
    public function issue(
        int $tenantId,
        int $invoiceId,
        string $series,
        string $number,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $dueAt,
        array $seller = [],
        array $buyer = [],
        int $taxRateBp = 0,
        string $taxLabel = '',
        bool $taxInclusive = false,
    ): void {
        $invoice = $this->findById($tenantId, $invoiceId);

        if ($invoice === null) {
            throw new InvoiceStateException("No such invoice: {$invoiceId}.");
        }

        if ($invoice['status'] !== self::STATUS_DRAFT) {
            throw new InvoiceStateException(
                "Invoice {$invoiceId} is already {$invoice['status']} and cannot be issued again. "
                . 'An issued invoice is evidence; a correction is a credit note.'
            );
        }

        if ($invoice['total_minor'] === 0 && $this->lineCount($tenantId, $invoiceId) === 0) {
            // An invoice for nothing, with nothing on it, is a draft somebody
            // forgot to fill in. Issuing it burns a sequence number on a
            // document that says nothing.
            throw new InvoiceStateException(
                "Invoice {$invoiceId} has no lines. Refusing to issue it and spend a number "
                . 'on a document with nothing on it.'
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE invoices
                SET status = :status,
                    series = :series,
                    number = :number,
                    issued_at = :issued_at,
                    due_at = :due_at,
                    seller_name = :seller_name,
                    seller_address = :seller_address,
                    seller_tax_id = :seller_tax_id,
                    buyer_name = :buyer_name,
                    buyer_address = :buyer_address,
                    buyer_tax_id = :buyer_tax_id,
                    tax_rate_bp = :tax_rate_bp,
                    tax_label = :tax_label,
                    tax_inclusive = :tax_inclusive,
                    updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :invoice_id AND status = :draft'
        );
        $statement->bindValue(':status', self::STATUS_OPEN);
        $statement->bindValue(':series', $series);
        $statement->bindValue(':number', $number);
        $statement->bindValue(':issued_at', $issuedAt->format('Y-m-d H:i:s'));
        $statement->bindValue(':due_at', $dueAt->format('Y-m-d H:i:s'));
        $statement->bindValue(':seller_name', (string) ($seller['name'] ?? ''));
        $statement->bindValue(':seller_address', $seller['address'] ?? null);
        $statement->bindValue(':seller_tax_id', $seller['tax_id'] ?? null);
        $statement->bindValue(':buyer_name', (string) ($buyer['name'] ?? ''));
        $statement->bindValue(':buyer_address', $buyer['address'] ?? null);
        $statement->bindValue(':buyer_tax_id', $buyer['tax_id'] ?? null);
        $statement->bindValue(':tax_rate_bp', $taxRateBp, PDO::PARAM_INT);
        $statement->bindValue(':tax_label', $taxLabel);
        $statement->bindValue(':tax_inclusive', $taxInclusive, PDO::PARAM_BOOL);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':invoice_id', $invoiceId, PDO::PARAM_INT);
        $statement->bindValue(':draft', self::STATUS_DRAFT);
        $statement->execute();
    }

    /**
     * Mark an open invoice paid.
     *
     * The CALLER decides whether it is paid, by comparing the settled total
     * against what is owed — this only records the conclusion. Splitting it
     * that way keeps the "how much has arrived" question in one place
     * ({@see \Whity\Core\Payment\PaymentLedger}) rather than two.
     */
    public function markPaid(int $tenantId, int $invoiceId, DateTimeImmutable $paidAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE invoices
                SET status = :paid, paid_at = :paid_at, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :invoice_id AND status = :open'
        );
        $statement->execute([
            ':paid' => self::STATUS_PAID,
            ':paid_at' => $paidAt->format('Y-m-d H:i:s'),
            ':tenant_id' => $tenantId,
            ':invoice_id' => $invoiceId,
            ':open' => self::STATUS_OPEN,
        ]);
    }

    /**
     * Void an invoice, keeping the row and its number.
     *
     * A cancelled invoice that vanishes leaves a hole in the sequence that
     * looks exactly like a lost one.
     */
    public function void(int $tenantId, int $invoiceId, string $reason, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE invoices
                SET status = :void, void_reason = :reason, voided_at = :at,
                    updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :invoice_id AND status <> :paid'
        );
        $statement->execute([
            ':void' => self::STATUS_VOID,
            ':reason' => $reason,
            ':at' => $at->format('Y-m-d H:i:s'),
            ':tenant_id' => $tenantId,
            ':invoice_id' => $invoiceId,
            ':paid' => self::STATUS_PAID,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $tenantId, int $invoiceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM invoices WHERE tenant_id = :tenant_id AND id = :invoice_id'
        );
        $statement->execute([':tenant_id' => $tenantId, ':invoice_id' => $invoiceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * An invoice by the number a customer quotes, which is how a support
     * conversation starts.
     *
     * @return array<string, mixed>|null
     */
    public function findByNumber(int $tenantId, string $number): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM invoices WHERE tenant_id = :tenant_id AND number = :number'
        );
        $statement->execute([':tenant_id' => $tenantId, ':number' => $number]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * A tenant's invoices, newest first. Drafts included: a tenant admin
     * building next month's bill needs to see it.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM invoices
              WHERE tenant_id = :tenant_id
              ORDER BY COALESCE(issued_at, created_at) DESC, id DESC
              LIMIT :limit'
        );
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min($limit, 500)), PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * The open invoices that fell due before a moment — what dunning walks.
     *
     * @return list<array<string, mixed>>
     */
    public function overdueOpen(DateTimeImmutable $asOf, int $limit = 500): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM invoices
              WHERE status = :open AND due_at IS NOT NULL AND due_at <= :as_of
              ORDER BY due_at ASC, id ASC
              LIMIT :limit'
        );
        $statement->bindValue(':open', self::STATUS_OPEN);
        $statement->bindValue(':as_of', $asOf->format('Y-m-d H:i:s'));
        $statement->bindValue(':limit', max(1, min($limit, 1000)), PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /** @return list<array<string, mixed>> */
    public function linesFor(int $tenantId, int $invoiceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM invoice_lines
              WHERE tenant_id = :tenant_id AND invoice_id = :invoice_id
              ORDER BY position ASC, id ASC'
        );
        $statement->execute([':tenant_id' => $tenantId, ':invoice_id' => $invoiceId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'position' => (int) $row['position'],
            'description' => (string) $row['description'],
            'quantity' => (int) $row['quantity'],
            'unit_amount_minor' => (int) $row['unit_amount_minor'],
            'subtotal_minor' => (int) $row['subtotal_minor'],
            'discount_minor' => (int) $row['discount_minor'],
            'tax_rate_bp' => (int) $row['tax_rate_bp'],
            'tax_minor' => (int) $row['tax_minor'],
            'total_minor' => (int) $row['total_minor'],
        ], $rows);
    }

    /** @throws InvoiceStateException */
    private function assertDraft(int $tenantId, int $invoiceId): void
    {
        $invoice = $this->findById($tenantId, $invoiceId);

        if ($invoice === null) {
            throw new InvoiceStateException("No such invoice: {$invoiceId}.");
        }

        if ($invoice['status'] !== self::STATUS_DRAFT) {
            throw new InvoiceStateException(
                "Invoice {$invoiceId} is {$invoice['status']} and cannot be edited. An issued "
                . 'invoice is evidence; a correction is a credit note, which is a new document.'
            );
        }
    }

    private function nextPosition(int $invoiceId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM invoice_lines WHERE invoice_id = :invoice_id'
        );
        $statement->execute([':invoice_id' => $invoiceId]);

        return (int) $statement->fetchColumn();
    }

    private function lineCount(int $tenantId, int $invoiceId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM invoice_lines WHERE tenant_id = :tenant_id AND invoice_id = :invoice_id'
        );
        $statement->execute([':tenant_id' => $tenantId, ':invoice_id' => $invoiceId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Integers as integers and booleans as booleans, on both engines.
     *
     * PostgreSQL hands back 'f' for false when fetches are stringified, and
     * `(bool) 'f'` is TRUE in PHP — the trap that makes one engine disagree
     * with the other about a flag while every test on the first one passes.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        foreach ([
            'id', 'tenant_id', 'profile_id', 'plan_id', 'promotion_id',
            'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'tax_rate_bp',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }

        if (array_key_exists('tax_inclusive', $row)) {
            $row['tax_inclusive'] = self::toBool($row['tax_inclusive']);
        }

        return $row;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['f', 'false', '0', ''], true);
    }
}
