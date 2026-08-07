<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Psr\Log\LoggerInterface;

/**
 * Seeds the starter document/label templates + blocks for a newly created
 * tenant (WC-515 REMAINING #3), so a fresh tenant is never staring at an empty
 * designer. Ported from the client-side seed source
 * `web/lib/documents/starters.ts` (STARTER_TEMPLATES / STARTER_BLOCKS) — the
 * page/placeholder/element layout mirrors that file exactly, minus the
 * 'blank' pseudo-starter (no content — not worth a persisted row; "New" in
 * the designer already covers it).
 *
 * COMPANY-INFO PRE-FILL: a brand-new tenant has only a name at creation time
 * (see TenantsApiHandler::create() — no address/logo/contact yet, those are
 * filled in later via branding/tenant settings). So the ONLY real pre-fill is
 * the `company_name` PLACEHOLDER'S SAMPLE VALUE (the token itself, e.g.
 * `{{company_name}}` in a dynamicText element, stays a token — same as the
 * client starters — so it keeps resolving from whatever the tenant's actual
 * placeholder data is later, not a frozen string). Every other placeholder
 * keeps the generic sample from starters.ts. A block's elements carry no
 * placeholder/sample list of their own (only the CONTAINING template does),
 * so blocks need no tenant-specific substitution at all — company_header/
 * footer are ported verbatim.
 *
 * IDEMPOTENT + UPGRADE-SAFE: each starter carries a stable `starter_key`
 * (migration 075) — see {@see DocumentTemplateRepository::starterKeysForTenant()}
 * for why that (not `name`) is the identity check. seedForTenant() only
 * INSERTs starters whose key isn't already present for the tenant; it never
 * UPDATEs, so a user's edits to a system starter are never touched. Calling
 * it again after a NEW starter is added to the sets below inserts just the
 * new one for every tenant that doesn't have it yet.
 *
 * Rows are seeded scope=system (visible to everyone in the tenant, per
 * {@see DocumentAccessPolicy}), is_system=true, created_by=null.
 */
final class DocumentStarterSeeder
{
    private DocumentTemplateRepository $templates;
    private DocumentBlockRepository $blocks;
    private ?LoggerInterface $logger;

    public function __construct(
        DocumentTemplateRepository $templates,
        DocumentBlockRepository $blocks,
        ?LoggerInterface $logger = null,
    ) {
        $this->templates = $templates;
        $this->blocks = $blocks;
        $this->logger = $logger;
    }

    /**
     * Seed the starter set for one tenant. Safe to call more than once (a
     * retried tenant.created dispatch, or a future upgrade backfill for
     * existing tenants) — NEVER throws; a seeding failure is logged and
     * swallowed so it can never break the tenant-creation request that
     * triggered it (mirrors AuditLogger's write-then-swallow policy — "the
     * side effect must never break the action it accompanies").
     */
    public function seedForTenant(int $tenantId, string $tenantName): void
    {
        try {
            $this->seedTemplates($tenantId, $tenantName);
            $this->seedBlocks($tenantId);
        } catch (\Throwable $e) {
            $this->logger?->error('Starter document/block seeding failed', [
                'event'     => 'documents.starter_seed_failed',
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function seedTemplates(int $tenantId, string $tenantName): void
    {
        $existing = array_fill_keys($this->templates->starterKeysForTenant($tenantId), true);
        foreach (self::templateStarters($tenantName) as $starterKey => $starter) {
            if (isset($existing[$starterKey])) {
                continue;
            }
            $this->templates->create($tenantId, [
                'name'        => $starter['name'],
                'data'        => $starter['data'],
                'scope'       => DocumentAccessPolicy::SCOPE_SYSTEM,
                'is_system'   => true,
                'starter_key' => $starterKey,
                'created_by'  => null,
            ]);
        }
    }

    private function seedBlocks(int $tenantId): void
    {
        $existing = array_fill_keys($this->blocks->starterKeysForTenant($tenantId), true);
        foreach (self::blockStarters() as $starterKey => $starter) {
            if (isset($existing[$starterKey])) {
                continue;
            }
            $this->blocks->create($tenantId, [
                'name'        => $starter['name'],
                'data'        => $starter['elements'],
                'scope'       => DocumentAccessPolicy::SCOPE_SYSTEM,
                'is_system'   => true,
                'starter_key' => $starterKey,
                'created_by'  => null,
            ]);
        }
    }

    // ── ported starter set (web/lib/documents/starters.ts) ───────────────────

    /**
     * @return array<string, array{name: string, data: array<string, mixed>}>
     */
    private static function templateStarters(string $tenantName): array
    {
        return [
            'invoice'    => ['name' => 'Invoice',         'data' => self::invoice($tenantName)],
            'exam'       => ['name' => 'Exam sheet',      'data' => self::examSheet()],
            'production' => ['name' => 'Production note', 'data' => self::productionNote($tenantName)],
            'shipping'   => ['name' => 'Shipping label',  'data' => self::shippingLabel($tenantName)],
        ];
    }

    /**
     * @return array<string, array{name: string, elements: list<array<string, mixed>>}>
     */
    private static function blockStarters(): array
    {
        return [
            'sys-header' => ['name' => 'Company header', 'elements' => self::headerBlockElements()],
            'sys-footer' => ['name' => 'Company footer', 'elements' => self::footerBlockElements()],
        ];
    }

    /** The tenant's real name, or the starters' original generic sample if blank. */
    private static function companyNameSample(string $tenantName): string
    {
        $trimmed = trim($tenantName);

        return $trimmed !== '' ? $trimmed : 'Acme Corp';
    }

    /**
     * @param array<string, mixed>  $page
     * @param list<array<string, mixed>>  $placeholders
     * @param list<array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    private static function tpl(string $name, array $page, array $placeholders, array $elements): array
    {
        $z = 0;
        $stacked = array_map(static function (array $e) use (&$z): array {
            $z++;
            $e['z'] = $z;

            return $e;
        }, $elements);

        return [
            'version'      => 2,
            'name'         => $name,
            'page'         => $page,
            'placeholders' => $placeholders,
            'pages'        => [['id' => 'p1', 'elements' => $stacked]],
        ];
    }

    /** @return array<string, mixed> */
    private static function page(float $widthMm, float $heightMm, float $marginMm = 10): array
    {
        return ['widthMm' => $widthMm, 'heightMm' => $heightMm, 'marginMm' => $marginMm, 'background' => '#ffffff'];
    }

    /** @return array<string, mixed> */
    private static function a4(): array
    {
        return self::page(210, 297);
    }

    /** @return array<string, mixed> */
    private static function placeholder(string $key, string $label, string $sample): array
    {
        return ['key' => $key, 'label' => $label, 'sample' => $sample];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function textStyle(array $overrides = []): array
    {
        return array_merge([
            'fontSize'      => 11,
            'fontWeight'    => 'normal',
            'fontStyle'     => 'normal',
            'align'         => 'left',
            'vAlign'        => 'top',
            'color'         => '#111111',
            'direction'     => 'auto',
            'lineHeight'    => 1.2,
            'letterSpacing' => 0,
        ], $overrides);
    }

    private static int $seq = 0;

    private static function eid(string $type): string
    {
        self::$seq++;

        return sprintf('%s-s%d', $type, self::$seq);
    }

    /** @return array<string, mixed> */
    private static function common(string $type, float $x, float $y, float $w, float $h): array
    {
        return ['id' => self::eid($type), 'type' => $type, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rotation' => 0, 'z' => 0];
    }

    /**
     * @param array<string, mixed> $styleOverrides
     * @return array<string, mixed>
     */
    private static function text(float $x, float $y, float $w, float $h, string $text, array $styleOverrides = []): array
    {
        return [...self::common('text', $x, $y, $w, $h), 'text' => $text, 'style' => self::textStyle($styleOverrides)];
    }

    /**
     * @param array<string, mixed> $styleOverrides
     * @return array<string, mixed>
     */
    private static function dynamicText(float $x, float $y, float $w, float $h, string $template, array $styleOverrides = []): array
    {
        return [...self::common('dynamicText', $x, $y, $w, $h), 'template' => $template, 'style' => self::textStyle($styleOverrides)];
    }

    /** @return array<string, mixed> */
    private static function line(float $x, float $y, float $w): array
    {
        return [...self::common('line', $x, $y, $w, 0.4), 'stroke' => '#333333', 'strokeWidth' => 0.4];
    }

    /** @return array<string, mixed> */
    private static function rect(float $x, float $y, float $w, float $h): array
    {
        return [...self::common('rect', $x, $y, $w, $h), 'fill' => '#ffffff', 'stroke' => '#999999', 'strokeWidth' => 0.3, 'radius' => 1];
    }

    /** @return array<string, mixed> */
    private static function barcode(float $x, float $y, float $w, float $h, string $value): array
    {
        return [...self::common('barcode', $x, $y, $w, $h), 'symbology' => 'code128', 'value' => $value, 'binding' => null, 'showText' => true];
    }

    /** @return array<string, mixed> */
    private static function invoice(string $tenantName): array
    {
        $company = self::companyNameSample($tenantName);

        return self::tpl(
            'Invoice',
            self::a4(),
            [
                self::placeholder('company_name', 'Company name', $company),
                self::placeholder('company_contact', 'Company contact', $company . ' · info@acme.com · +1 555 0100'),
                self::placeholder('invoice_no', 'Invoice #', 'INV-1001'),
                self::placeholder('date', 'Date', '2026-01-15'),
                self::placeholder('bill_to', 'Bill to', "Customer Name\n123 Example St\nCity"),
                self::placeholder('total', 'Total', '$0.00'),
            ],
            [
                self::dynamicText(15, 15, 120, 12, '{{company_name}}', ['fontSize' => 20, 'fontWeight' => 'bold']),
                self::text(150, 15, 45, 12, 'INVOICE', ['fontSize' => 22, 'fontWeight' => 'bold', 'align' => 'right']),
                self::line(15, 30, 180),
                self::text(15, 40, 30, 6, 'Bill To', ['fontWeight' => 'bold']),
                self::dynamicText(15, 47, 95, 24, '{{bill_to}}'),
                self::text(135, 40, 30, 6, 'Invoice #', ['fontWeight' => 'bold', 'align' => 'right']),
                self::dynamicText(167, 40, 28, 6, '{{invoice_no}}', ['align' => 'right']),
                self::text(135, 47, 30, 6, 'Date', ['fontWeight' => 'bold', 'align' => 'right']),
                self::dynamicText(167, 47, 28, 6, '{{date}}', ['align' => 'right']),
                self::text(15, 78, 95, 6, 'Description', ['fontWeight' => 'bold']),
                self::text(115, 78, 30, 6, 'Qty', ['fontWeight' => 'bold', 'align' => 'right']),
                self::text(160, 78, 35, 6, 'Amount', ['fontWeight' => 'bold', 'align' => 'right']),
                self::line(15, 85, 180),
                self::text(150, 250, 20, 8, 'Total', ['fontWeight' => 'bold', 'align' => 'right']),
                self::dynamicText(172, 250, 23, 8, '{{total}}', ['fontWeight' => 'bold', 'align' => 'right']),
                self::line(15, 280, 180),
                self::dynamicText(15, 283, 180, 6, '{{company_contact}}', ['fontSize' => 8, 'align' => 'center', 'color' => '#666666']),
            ]
        );
    }

    /** @return array<string, mixed> */
    private static function examSheet(): array
    {
        return self::tpl(
            'Exam sheet',
            self::a4(),
            [
                self::placeholder('exam_title', 'Exam title', 'Midterm Examination'),
                self::placeholder('subject', 'Subject', 'Mathematics'),
            ],
            [
                self::dynamicText(15, 15, 180, 12, '{{exam_title}}', ['fontSize' => 20, 'fontWeight' => 'bold', 'align' => 'center']),
                self::dynamicText(15, 28, 180, 7, '{{subject}}', ['fontSize' => 12, 'align' => 'center', 'color' => '#555555']),
                self::text(15, 42, 18, 6, 'Name:', ['fontWeight' => 'bold']),
                self::line(34, 48, 90),
                self::text(135, 42, 14, 6, 'Date:', ['fontWeight' => 'bold']),
                self::line(151, 48, 44),
                self::text(15, 52, 18, 6, 'Score:', ['fontWeight' => 'bold']),
                self::line(34, 58, 50),
                self::line(15, 64, 180),
                self::text(15, 68, 180, 6, 'Answer all questions. Show your work clearly.', ['fontStyle' => 'italic', 'fontSize' => 10]),
                self::text(15, 82, 10, 6, '1.', ['fontWeight' => 'bold']),
                self::line(27, 92, 168),
                self::text(15, 102, 10, 6, '2.', ['fontWeight' => 'bold']),
                self::line(27, 112, 168),
                self::text(15, 122, 10, 6, '3.', ['fontWeight' => 'bold']),
                self::line(27, 132, 168),
            ]
        );
    }

    /** @return array<string, mixed> */
    private static function productionNote(string $tenantName): array
    {
        $company = self::companyNameSample($tenantName);

        return self::tpl(
            'Production note',
            self::a4(),
            [
                self::placeholder('company_name', 'Company name', $company),
                self::placeholder('order_no', 'Order #', 'WO-5567'),
                self::placeholder('product', 'Product', 'Widget A'),
                self::placeholder('qty', 'Quantity', '250'),
                self::placeholder('date', 'Date', '2026-01-15'),
            ],
            [
                self::dynamicText(15, 15, 110, 10, '{{company_name}}', ['fontSize' => 16, 'fontWeight' => 'bold']),
                self::text(120, 15, 75, 10, 'PRODUCTION NOTE', ['fontSize' => 16, 'fontWeight' => 'bold', 'align' => 'right']),
                self::line(15, 28, 180),
                self::text(15, 36, 28, 6, 'Order #', ['fontWeight' => 'bold']),
                self::dynamicText(45, 36, 70, 6, '{{order_no}}'),
                self::text(15, 44, 28, 6, 'Product', ['fontWeight' => 'bold']),
                self::dynamicText(45, 44, 90, 6, '{{product}}'),
                self::text(15, 52, 28, 6, 'Quantity', ['fontWeight' => 'bold']),
                self::dynamicText(45, 52, 40, 6, '{{qty}}'),
                self::text(15, 60, 28, 6, 'Date', ['fontWeight' => 'bold']),
                self::dynamicText(45, 60, 40, 6, '{{date}}'),
                self::barcode(140, 34, 55, 22, '{{order_no}}'),
                self::text(15, 75, 40, 6, 'Notes', ['fontWeight' => 'bold']),
                self::rect(15, 82, 180, 90),
            ]
        );
    }

    /** @return array<string, mixed> */
    private static function shippingLabel(string $tenantName): array
    {
        $company = self::companyNameSample($tenantName);

        return self::tpl(
            'Shipping label',
            self::page(101.6, 152.4, 6),
            [
                self::placeholder('company_name', 'Company name', $company),
                self::placeholder('ship_to', 'Ship to', "Recipient Name\n456 Delivery Rd\nCity, ZIP"),
                self::placeholder('tracking', 'Tracking', 'TRK-000123456'),
                self::placeholder('sku', 'SKU', 'WID-001'),
            ],
            [
                self::dynamicText(6, 6, 90, 8, '{{company_name}}', ['fontWeight' => 'bold', 'fontSize' => 12]),
                self::line(6, 16, 90),
                self::text(6, 20, 40, 5, 'SHIP TO:', ['fontWeight' => 'bold', 'fontSize' => 8, 'color' => '#555555']),
                self::dynamicText(6, 26, 90, 28, '{{ship_to}}', ['fontSize' => 12]),
                self::barcode(6, 60, 90, 26, '{{tracking}}'),
                self::dynamicText(6, 132, 90, 6, '{{sku}}', ['fontSize' => 8, 'align' => 'center', 'color' => '#666666']),
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function headerBlockElements(): array
    {
        $elements = [
            self::dynamicText(0, 0, 120, 10, '{{company_name}}', ['fontSize' => 18, 'fontWeight' => 'bold']),
            self::text(0, 11, 180, 5, 'Address line · City · Country', ['fontSize' => 8, 'color' => '#666666']),
            self::line(0, 20, 180),
        ];

        return self::stackZ($elements);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function footerBlockElements(): array
    {
        $elements = [
            self::line(0, 0, 180),
            self::dynamicText(0, 2, 180, 6, '{{company_name}} · contact@example.com · +1 555 0100', [
                'fontSize' => 8, 'align' => 'center', 'color' => '#666666',
            ]),
        ];

        return self::stackZ($elements);
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @return list<array<string, mixed>>
     */
    private static function stackZ(array $elements): array
    {
        $z = 0;

        return array_map(static function (array $e) use (&$z): array {
            $z++;
            $e['z'] = $z;

            return $e;
        }, $elements);
    }
}
