<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * WHIT-761: record types in `plugin.json`, checked with the rest of the
 * manifest.
 */
final class RecordTypeValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $l = static fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];

        return [
            'manifest' => 'whity.plugin/1',
            'plugin' => ['name' => 'CakeOrders', 'version' => '1.0.0', 'label' => $l('Cake orders', 'طلبات الكيك')],
            'permissions' => [
                ['slug' => 'cakeorders:read', 'label' => $l('See cake orders', 'عرض طلبات الكيك')],
                ['slug' => 'cakeorders:manage', 'label' => $l('Manage cake orders', 'إدارة طلبات الكيك')],
            ],
            'records' => [
                'order' => [
                    'label' => $l('Cake order', 'طلب كيك'),
                    'titleField' => 'customer',
                    'fields' => [
                        'customer' => ['type' => 'text', 'label' => $l('Customer', 'الزبون'), 'required' => true, 'maxLength' => 120],
                        'cake_type' => ['type' => 'choice', 'label' => $l('Cake type', 'نوع الكيك'), 'options' => [
                            ['value' => 'birthday', 'label' => $l('Birthday', 'عيد ميلاد')],
                            ['value' => 'wedding', 'label' => $l('Wedding', 'زفاف')],
                        ]],
                        'weight_kg' => ['type' => 'number', 'label' => $l('Weight (kg)', 'الوزن (كغ)'), 'min' => 0.5],
                        'price' => ['type' => 'money', 'label' => $l('Price', 'السعر'), 'currency' => 'JOD', 'min' => 0],
                        'pickup_on' => ['type' => 'date', 'label' => $l('Pickup date', 'تاريخ الاستلام'), 'required' => true],
                        'paid' => ['type' => 'boolean', 'label' => $l('Paid', 'مدفوع')],
                        'parent' => ['type' => 'record', 'label' => $l('Re-order of', 'إعادة طلب لـ'), 'record' => 'order'],
                    ],
                    'lifecycle' => [
                        'states' => [
                            ['key' => 'draft', 'label' => $l('Draft', 'مسودة')],
                            ['key' => 'confirmed', 'label' => $l('Confirmed', 'مؤكد')],
                        ],
                        'initial' => 'draft',
                        'moves' => [
                            'confirm' => ['from' => 'draft', 'to' => 'confirmed', 'permission' => 'cakeorders:manage', 'label' => $l('Confirm', 'تأكيد')],
                        ],
                    ],
                    'permissions' => [
                        'read' => 'cakeorders:read',
                        'create' => 'cakeorders:manage',
                        'edit' => 'cakeorders:manage',
                        'delete' => 'cakeorders:manage',
                    ],
                ],
            ],
        ];
    }

    public function testTheBakeryRecordTypeIsValid(): void
    {
        $result = ManifestValidator::validate(self::manifest());

        self::assertSame([], $result['errors']);
        self::assertSame('customer', PluginManifest::fromArray(self::manifest())->records()['order']['titleField']);
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function refusals(): iterable
    {
        yield 'unknown field type' => [static function (array $m): array {
            $m['records']['order']['fields']['price']['type'] = 'currency';
            return $m;
        }, 'records.order.fields.price.type: one of'];
        yield 'reserved field name' => [static function (array $m): array {
            $m['records']['order']['fields']['state'] = ['type' => 'text', 'label' => ['en' => 'S', 'ar' => 'ح']];
            return $m;
        }, '"state" is reserved for the record store'];
        yield 'money without a currency' => [static function (array $m): array {
            unset($m['records']['order']['fields']['price']['currency']);
            return $m;
        }, 'records.order.fields.price.currency: required on money'];
        yield 'choice without options' => [static function (array $m): array {
            $m['records']['order']['fields']['cake_type']['options'] = [];
            return $m;
        }, 'records.order.fields.cake_type.options: required'];
        yield 'link to an undeclared record type' => [static function (array $m): array {
            $m['records']['order']['fields']['parent']['record'] = 'customer';
            return $m;
        }, 'records.order.fields.parent.record: required on a record link'];
        yield 'missing Arabic field label' => [static function (array $m): array {
            unset($m['records']['order']['fields']['customer']['label']['ar']);
            return $m;
        }, 'records.order.fields.customer.label.ar: required'];
        yield 'an ungated action' => [static function (array $m): array {
            unset($m['records']['order']['permissions']['delete']);
            return $m;
        }, 'records.order.permissions.delete: required'];
        yield 'gated by an undeclared permission' => [static function (array $m): array {
            $m['records']['order']['permissions']['read'] = 'users:read';
            return $m;
        }, 'records.order.permissions.read: required, a permission this manifest declares'];
        yield 'title field that does not exist' => [static function (array $m): array {
            $m['records']['order']['titleField'] = 'name';
            return $m;
        }, 'records.order.titleField'];
        yield 'move to an undeclared state' => [static function (array $m): array {
            $m['records']['order']['lifecycle']['moves']['confirm']['to'] = 'shipped';
            return $m;
        }, 'records.order.lifecycle.moves.confirm.to'];
        yield 'initial state not declared' => [static function (array $m): array {
            $m['records']['order']['lifecycle']['initial'] = 'new';
            return $m;
        }, 'records.order.lifecycle.initial'];
        yield 'unknown key on a field' => [static function (array $m): array {
            $m['records']['order']['fields']['customer']['default'] = 'x';
            return $m;
        }, 'records.order.fields.customer.default: unknown key'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('refusals')]
    public function testRefuses(callable $mutate, string $expected): void
    {
        $result = ManifestValidator::validate($mutate(self::manifest()));

        self::assertFalse($result['ok']);
        self::assertNotEmpty(
            array_filter($result['errors'], static fn (string $e): bool => str_contains($e, $expected)),
            "Expected '{$expected}', got: " . implode(' | ', $result['errors']),
        );
    }
}
