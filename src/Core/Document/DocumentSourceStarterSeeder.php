<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Psr\Log\LoggerInterface;
use Whity\Core\Tenant\TenantProvisioningStep;

/**
 * The editor's starters (editor M4b): ready templates and label sheets a
 * tenant can open and adapt, so a printed document never starts from a blank
 * page. They replace the old designer's canvas starters, the same set plus a
 * letter and a certificate: Letter, Invoice, Exam sheet, Production note,
 * Shipping labels and Certificate.
 *
 * Each is seeded once per tenant (`starter_key`, which nothing but this class
 * writes), as a system document every member can see, and never updated
 * afterwards, so a tenant's changes to a starter stay theirs. They are drafts:
 * a tenant publishes the ones it uses.
 *
 * Fields the record type can't provide are the template's own
 * (`template.<key>`), filled from a CSV or left for the person printing.
 * Company and Today come from their providers; the letterhead carries the
 * tenant's name, address and tax number.
 */
final class DocumentSourceStarterSeeder implements TenantProvisioningStep
{
    private int $ids = 0;

    public function __construct(
        private readonly DocumentSourceRepository $sources,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function provisionTenant(int $tenantId, string $tenantName): void
    {
        $this->seedForTenant($tenantId);
    }

    /**
     * Add the starters a tenant doesn't have yet.
     *
     * Provisioning a tenant must not fail on its starters, so by default a
     * failure is logged and the tenant goes on without them. A migration
     * passes `$strict`: there a failure has to stop the upgrade, or every
     * workspace ends up with no starters and nobody is told (WHIT-803).
     *
     * @return int How many starters were added.
     */
    public function seedForTenant(int $tenantId, bool $strict = false): int
    {
        $added = 0;
        try {
            $existing = $this->sources->starterKeys($tenantId);
            foreach ($this->starters() as $key => $starter) {
                if (isset($existing[$key])) {
                    continue;
                }
                $this->sources->create($tenantId, $starter + [
                    'lang'         => 'en',
                    'default_view' => 'page',
                    'scope'        => DocumentAccessPolicy::SCOPE_SYSTEM,
                    'created_by'   => null,
                    'starter_key'  => $key,
                ]);
                $added++;
            }
        } catch (\Throwable $e) {
            if ($strict) {
                throw new \RuntimeException("Seeding the editor starters failed for tenant {$tenantId}: " . $e->getMessage(), 0, $e);
            }
            $this->logger?->error('Seeding the editor starters failed', [
                'event'     => 'documents.source_starter_seed_failed',
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
        }

        return $added;
    }

    /**
     * The starters by key. Example data follows the design system's (Example Bakery and friends).
     *
     * @return array<string, array{kind: string, title: string, page: array<string, mixed>,
     *                             own_fields: list<array<string, string>>, blocks: list<array<string, mixed>>}>
     */
    public function starters(): array
    {
        $this->ids = 0;
        $a4 = ['size' => 'A4', 'marginMm' => 16, 'letterhead' => true];

        return [
            'editor.letter' => [
                'kind' => 'letter',
                'title' => 'Letter',
                'page' => $a4,
                'own_fields' => [
                    ['key' => 'recipient', 'label' => 'Recipient', 'sample' => 'Example Clinic'],
                    ['key' => 'recipient_address', 'label' => 'Recipient address', 'sample' => 'Rainbow Street 21, Amman'],
                    ['key' => 'subject', 'label' => 'Subject', 'sample' => 'Your order for November'],
                ],
                'blocks' => [
                    $this->para($this->field('recipient', 'Recipient'), ['type' => 'break'], $this->field('recipient_address', 'Recipient address')),
                    $this->para($this->field('subject', 'Subject', true)),
                    $this->para($this->text('Dear '), $this->field('recipient', 'Recipient'), $this->text(',')),
                    $this->para($this->text('Thank you for your order. This letter confirms what we agreed and when we will deliver.')),
                    $this->para($this->text('Please reply to this letter if anything needs to change before then.')),
                    $this->para($this->text('Kind regards,')),
                    ['id' => $this->id(), 'type' => 'signature', 'signers' => [['name' => [$this->companyField('company.name', 'Company name')], 'role' => [$this->text('Manager')]]], 'date' => [], 'stamp' => false],
                ],
            ],
            'editor.invoice' => [
                'kind' => 'template',
                'title' => 'Invoice',
                'page' => $a4,
                'own_fields' => [
                    ['key' => 'invoice_no', 'label' => 'Invoice number', 'sample' => 'INV-1042'],
                    ['key' => 'bill_to', 'label' => 'Bill to', 'sample' => 'Example Clinic'],
                    ['key' => 'due_date', 'label' => 'Due date', 'sample' => '30 November 2026'],
                    ['key' => 'total', 'label' => 'Total', 'sample' => '1,250.00'],
                ],
                'blocks' => [
                    $this->para($this->text('Invoice ', true), $this->field('invoice_no', 'Invoice number', true)),
                    $this->para($this->text('Bill to: '), $this->field('bill_to', 'Bill to'), ['type' => 'break'], $this->text('Due: '), $this->field('due_date', 'Due date')),
                    $this->table(
                        ['Item', 'Qty', 'Price', 'Amount'],
                        [['Sourdough loaves', '50', '4.00', '200.00'], ['Pastry tray', '10', '105.00', '1,050.00']],
                    ),
                    $this->para($this->text('Total ', true), $this->field('total', 'Total', true)),
                    ['id' => $this->id(), 'type' => 'callout', 'kind' => 'note', 'content' => [$this->text('Payment within 30 days. Thank you for your business.')]],
                ],
            ],
            'editor.exam' => [
                'kind' => 'template',
                'title' => 'Exam sheet',
                'page' => ['size' => 'A4', 'marginMm' => 16, 'letterhead' => false],
                'own_fields' => [
                    ['key' => 'exam_title', 'label' => 'Exam title', 'sample' => 'Midterm examination'],
                    ['key' => 'subject', 'label' => 'Subject', 'sample' => 'Mathematics'],
                    ['key' => 'duration', 'label' => 'Duration', 'sample' => '60 minutes'],
                ],
                'blocks' => [
                    ['id' => $this->id(), 'type' => 'heading', 'level' => 1, 'content' => [$this->field('exam_title', 'Exam title')]],
                    $this->para($this->field('subject', 'Subject'), $this->text(' · '), $this->field('duration', 'Duration')),
                    ['id' => $this->id(), 'type' => 'signature', 'signers' => [['name' => [$this->text('Student name')], 'role' => [$this->text('Class')]]], 'date' => [], 'stamp' => false],
                    ['id' => $this->id(), 'type' => 'divider'],
                    ['id' => $this->id(), 'type' => 'number', 'level' => 0, 'content' => [$this->text('Solve for x: 2x + 3 = 11.')]],
                    ['id' => $this->id(), 'type' => 'number', 'level' => 0, 'content' => [$this->text('A tray holds 12 loaves. How many trays hold 150 loaves?')]],
                    ['id' => $this->id(), 'type' => 'math', 'tex' => 'a^2 + b^2 = c^2'],
                    ['id' => $this->id(), 'type' => 'number', 'level' => 0, 'content' => [$this->text('Use the formula above to find c when a = 3 and b = 4.')]],
                ],
            ],
            'editor.production-note' => [
                'kind' => 'template',
                'title' => 'Production note',
                'page' => $a4,
                'own_fields' => [
                    ['key' => 'order_no', 'label' => 'Order number', 'sample' => 'WO-5567'],
                    ['key' => 'product', 'label' => 'Product', 'sample' => 'Sourdough loaf'],
                    ['key' => 'quantity', 'label' => 'Quantity', 'sample' => '250'],
                ],
                'blocks' => [
                    $this->para($this->text('Order ', true), $this->field('order_no', 'Order number', true)),
                    $this->table(['Product', 'Quantity', 'Ready by'], [[[$this->field('product', 'Product')], [$this->field('quantity', 'Quantity')], [$this->companyField('today', 'Today')]]]),
                    ['id' => $this->id(), 'type' => 'checklist', 'level' => 0, 'checked' => false, 'content' => [$this->text('Ingredients checked')]],
                    ['id' => $this->id(), 'type' => 'checklist', 'level' => 0, 'checked' => false, 'content' => [$this->text('Ovens booked')]],
                    ['id' => $this->id(), 'type' => 'checklist', 'level' => 0, 'checked' => false, 'content' => [$this->text('Packed and labelled')]],
                    ['id' => $this->id(), 'type' => 'barcode', 'symbology' => 'code128', 'value' => [$this->field('order_no', 'Order number')], 'showText' => true],
                ],
            ],
            'editor.shipping-labels' => [
                'kind' => 'label',
                'title' => 'Shipping labels',
                'page' => [
                    'size' => 'A4',
                    'marginMm' => 16,
                    'letterhead' => false,
                    // Avery L7165: 2 × 4 labels of 99.1 × 67.7 mm.
                    'labels' => ['columns' => 2, 'rows' => 4, 'size' => ['widthMm' => 99.1, 'heightMm' => 67.7], 'gapMm' => 2.5, 'gapYMm' => 0],
                ],
                'own_fields' => [
                    ['key' => 'ship_to', 'label' => 'Ship to', 'sample' => 'Example Clinic'],
                    ['key' => 'address', 'label' => 'Address', 'sample' => 'Rainbow Street 21, Amman'],
                    ['key' => 'tracking', 'label' => 'Tracking number', 'sample' => 'TRK000123456'],
                ],
                'blocks' => [
                    $this->para($this->text('From '), $this->companyField('company.name', 'Company name')),
                    $this->para($this->field('ship_to', 'Ship to', true), ['type' => 'break'], $this->field('address', 'Address')),
                    ['id' => $this->id(), 'type' => 'barcode', 'symbology' => 'code128', 'value' => [$this->field('tracking', 'Tracking number')], 'showText' => true, 'heightMm' => 12],
                ],
            ],
            'editor.certificate' => [
                'kind' => 'label',
                'title' => 'Certificate',
                'page' => [
                    'size' => 'A4',
                    'marginMm' => 16,
                    'letterhead' => false,
                    'labels' => ['columns' => 1, 'rows' => 1, 'size' => ['widthMm' => 190, 'heightMm' => 277], 'gapMm' => 0],
                ],
                'own_fields' => [
                    ['key' => 'recipient', 'label' => 'Recipient', 'sample' => 'Lina Haddad'],
                    ['key' => 'course', 'label' => 'Course', 'sample' => 'Food safety, level 2'],
                ],
                'blocks' => [
                    ['id' => $this->id(), 'type' => 'heading', 'level' => 1, 'content' => [$this->text('Certificate of completion')]],
                    $this->para($this->text('This certifies that')),
                    ['id' => $this->id(), 'type' => 'heading', 'level' => 2, 'content' => [$this->field('recipient', 'Recipient')]],
                    $this->para($this->text('has completed '), $this->field('course', 'Course'), $this->text('.')),
                    ['id' => $this->id(), 'type' => 'signature', 'signers' => [['name' => [$this->companyField('company.name', 'Company name')], 'role' => [$this->text('Trainer')]]], 'date' => [$this->companyField('today', 'Today')], 'stamp' => false],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> ...$content
     * @return array<string, mixed>
     */
    private function para(array ...$content): array
    {
        return ['id' => $this->id(), 'type' => 'paragraph', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function text(string $text, bool $bold = false): array
    {
        return ['type' => 'text', 'text' => $text] + ($bold ? ['marks' => [['type' => 'bold']]] : []);
    }

    /**
     * A template's own field.
     *
     * @return array<string, mixed>
     */
    private function field(string $key, string $label, bool $bold = false): array
    {
        return ['type' => 'field', 'path' => 'template.' . $key, 'label' => $label] + ($bold ? ['marks' => [['type' => 'bold']]] : []);
    }

    /**
     * A provider's field (Company, Today).
     *
     * @return array<string, mixed>
     */
    private function companyField(string $path, string $label): array
    {
        return ['type' => 'field', 'path' => $path, 'label' => $label];
    }

    /**
     * A table with a header row; cells are plain text or inline content.
     *
     * @param list<string> $header
     * @param list<list<string|list<array<string, mixed>>>> $rows
     * @return array<string, mixed>
     */
    private function table(array $header, array $rows): array
    {
        $cell = fn (string|array $c): array => is_string($c) ? [$this->text($c)] : $c;

        return [
            'id' => $this->id(),
            'type' => 'table',
            'headerRow' => true,
            'columns' => array_map(static fn (int $i): array => ['align' => $i === 0 ? 'start' : 'end'], array_keys($header)),
            'rows' => [array_map($cell, $header), ...array_map(static fn (array $r): array => array_map($cell, $r), $rows)],
        ];
    }

    private function id(): string
    {
        return 's' . (++$this->ids);
    }
}
