<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The Company group every template has: the tenant's name, address and tax
 * number as it invoices (the billing.seller_* settings). The tax number is a
 * fiscal field, so it is required (E.27): a document that would print without
 * it is refused rather than issued.
 */
final class CompanyFieldProvider implements DocumentFieldProvider
{
    private FieldLabels $labels;

    public function __construct(private readonly SettingsService $settings, ?FieldLabels $labels = null)
    {
        $this->labels = $labels ?? new FieldLabels();
    }

    public function recordType(): string
    {
        return '*';
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.group.company', 'Company');
    }

    public function groups(int $tenantId): array
    {
        $values = $this->read($tenantId);
        $items = [];
        foreach ([
            ['company.name', 'fields.company.name', 'Company name', false],
            ['company.address', 'fields.company.address', 'Company address', false],
            ['company.tax_number', 'fields.company.taxNumber', 'Company tax number', true],
        ] as [$path, $key, $english, $required]) {
            $item = ['path' => $path, 'label' => $this->labels->get($key, $english)];
            if ($values[$path] !== null) {
                $item['sample'] = $values[$path];
            }
            if ($required) {
                $item['required'] = true;
            }
            $items[] = $item;
        }

        return [['group' => $this->recordLabel(), 'items' => $items]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        return ['label' => '', 'values' => $this->read($tenantId)];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        return [];
    }

    /** @return array{'company.name': string|null, 'company.address': string|null, 'company.tax_number': string|null} */
    private function read(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $value = static function (string $key) use ($effective): ?string {
            $v = trim((string) ($effective[$key] ?? ''));

            return $v === '' ? null : $v;
        };

        return [
            'company.name' => $value(SettingsRegistry::BILLING_SELLER_NAME),
            'company.address' => $value(SettingsRegistry::BILLING_SELLER_ADDRESS),
            'company.tax_number' => $value(SettingsRegistry::BILLING_SELLER_TAX_ID),
        ];
    }
}
