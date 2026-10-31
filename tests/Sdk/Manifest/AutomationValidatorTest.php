<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * WHIT-763: automations in `plugin.json`, checked with the rest of the
 * manifest.
 */
final class AutomationValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $l = static fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];
        $manifest = RecordTypeValidatorTest::manifest();
        $manifest['roles'] = [
            'baker' => ['label' => $l('Baker', 'خباز'), 'permissions' => ['cakeorders:read']],
        ];
        $manifest['records']['order']['fields']['taken_by'] = ['type' => 'person', 'label' => $l('Taken by', 'استلمه')];
        $manifest['records']['order']['fields']['confirmed_on'] = ['type' => 'date', 'label' => $l('Confirmed on', 'تاريخ التأكيد')];
        $manifest['records']['task'] = [
            'label' => $l('Baking task', 'مهمة خبز'),
            'fields' => [
                'title' => ['type' => 'text', 'label' => $l('Title', 'العنوان')],
                'order' => ['type' => 'record', 'label' => $l('Order', 'الطلب'), 'record' => 'order'],
            ],
            'permissions' => [
                'read' => 'cakeorders:read', 'create' => 'cakeorders:manage',
                'edit' => 'cakeorders:manage', 'delete' => 'cakeorders:manage',
            ],
        ];
        $manifest['documents'] = ['slots' => [
            'order_confirmation' => ['label' => $l('Order confirmation', 'تأكيد الطلب'), 'record' => 'order'],
            'baking_sheet' => ['label' => $l('Baking sheet', 'ورقة الخبز'), 'record' => 'task'],
        ]];
        $manifest['automations'] = [
            'confirmation' => [
                'label' => $l('Tell the bakers', 'إبلاغ الخبازين'),
                'on' => 'order.moved.confirmed',
                'when' => ['>' => [['field' => 'weight_kg'], 0]],
                'steps' => [
                    ['set_fields' => ['values' => ['confirmed_on' => ['today' => []]]]],
                    ['create_record' => ['type' => 'task', 'values' => ['title' => ['field' => 'customer']], 'link' => 'order']],
                    ['notify' => ['role' => 'baker', 'message' => $l('New order for {{pickup_on}}', 'طلب جديد ليوم {{pickup_on}}')]],
                    ['notify' => ['person' => 'taken_by', 'message' => $l('Confirmed', 'تم التأكيد')]],
                ],
            ],
            'on_change' => [
                'label' => $l('Re-check', 'إعادة الفحص'),
                'on' => 'order.changed.price',
                'steps' => [['move' => ['move' => 'confirm']]],
            ],
        ];

        return $manifest;
    }

    public function testTheBakeryAutomationsAreValid(): void
    {
        self::assertSame([], ManifestValidator::validate(self::manifest())['errors']);
        self::assertSame(
            'order.moved.confirmed',
            PluginManifest::fromArray(self::manifest())->automations()['confirmation']['on'],
        );
    }

    public function testIssuingADocumentFromTheRecordTypesOwnSlotIsValid(): void
    {
        $m = self::step(self::manifest(), ['issue_document' => [
            'slot' => 'order_confirmation',
            'values' => ['template.note' => ['concat' => ['Pickup ', ['field' => 'pickup_on']]]],
        ]]);

        self::assertSame([], ManifestValidator::validate($m)['errors']);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function broken(): iterable
    {
        yield 'unknown record type' => [
            static fn (array $m): array => self::set($m, 'on', 'invoice.created'),
            'automations.confirmation.on: "invoice" isn\'t a record type of this manifest',
        ];
        yield 'unknown state' => [
            static fn (array $m): array => self::set($m, 'on', 'order.moved.baked'),
            'automations.confirmation.on: "baked" isn\'t a state of order',
        ];
        yield 'unknown field in trigger' => [
            static fn (array $m): array => self::set($m, 'on', 'order.changed.colour'),
            'automations.confirmation.on: "colour" isn\'t a field of order',
        ];
        yield 'a type without a lifecycle never moves' => [
            static fn (array $m): array => self::set($m, 'on', 'task.moved'),
            'automations.confirmation.on: task has no lifecycle, so it never moves',
        ];
        yield 'garbled trigger' => [
            static fn (array $m): array => self::set($m, 'on', 'order deleted'),
            'automations.confirmation.on: required, "<record type>.created", ".changed", ".changed.<field>", ".moved" or ".moved.<state>"',
        ];
        yield 'when reads another type\'s field' => [
            static fn (array $m): array => self::set($m, 'when', ['field' => 'title']),
            'automations.confirmation.when: refers to "title", which isn\'t a field of this record type',
        ];
        yield 'a step that arrives later is refused by name' => [
            static fn (array $m): array => self::step($m, ['send_webhook' => ['url' => 'https://example.com']]),
            'automations.confirmation.steps[0].send_webhook: this step isn\'t available in this version yet',
        ];
        yield 'a slot the manifest doesn\'t declare' => [
            static fn (array $m): array => self::step($m, ['issue_document' => ['slot' => 'invoice']]),
            'automations.confirmation.steps[0].issue_document.slot: required, a slot under documents.slots',
        ];
        yield 'a slot for another record type' => [
            static fn (array $m): array => self::step($m, ['issue_document' => ['slot' => 'baking_sheet']]),
            'automations.confirmation.steps[0].issue_document.slot: "baking_sheet" is for task, not order',
        ];
        yield 'a value that isn\'t a field path' => [
            static fn (array $m): array => self::step($m, ['issue_document' => ['slot' => 'order_confirmation', 'values' => ['Note!' => 'x']]]),
            'automations.confirmation.steps[0].issue_document.values.Note!: a template field path, such as template.note',
        ];
        yield 'unknown step' => [
            static fn (array $m): array => self::step($m, ['run_sql' => ['sql' => 'DROP TABLE x']]),
            'automations.confirmation.steps[0].run_sql: unknown step; the steps are set_fields, create_record, move, notify',
        ];
        yield 'setting a computed field' => [
            static function (array $m): array {
                $m['records']['order']['fields']['paid']['computed'] = ['>=' => [['field' => 'price'], 0]];

                return self::step($m, ['set_fields' => ['values' => ['paid' => true]]]);
            },
            'automations.confirmation.steps[0].set_fields.values.paid: is calculated and can\'t be set',
        ];
        yield 'link that does not point back' => [
            static fn (array $m): array => self::step($m, ['create_record' => ['type' => 'task', 'values' => [], 'link' => 'title']]),
            'automations.confirmation.steps[0].create_record.link: must name a field of task that links to order',
        ];
        yield 'undeclared role' => [
            static fn (array $m): array => self::step($m, ['notify' => ['role' => 'admin', 'message' => ['en' => 'x', 'ar' => 'س']]]),
            'automations.confirmation.steps[0].notify.role: must be a role this manifest declares',
        ];
        yield 'notify without Arabic' => [
            static fn (array $m): array => self::step($m, ['notify' => ['role' => 'baker', 'message' => ['en' => 'x']]]),
            'automations.confirmation.steps[0].notify.message',
        ];
        yield 'unknown move' => [
            static fn (array $m): array => self::step($m, ['move' => ['move' => 'bake']]),
            'automations.confirmation.steps[0].move.move: required, a move of order',
        ];
        yield 'too many steps' => [
            static fn (array $m): array => self::set($m, 'steps', array_fill(0, 21, ['move' => ['move' => 'confirm']])),
            'automations.confirmation.steps: at most 20',
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('broken')]
    public function testAMistakeIsNamedByPath(callable $break, string $expected): void
    {
        $errors = ManifestValidator::validate($break(self::manifest()))['errors'];

        $found = array_filter($errors, static fn (string $e): bool => str_starts_with($e, $expected));
        self::assertNotSame([], $found, "Expected an error starting with:\n{$expected}\nGot:\n" . implode("\n", $errors));
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private static function set(array $m, string $key, mixed $value): array
    {
        $m['automations']['confirmation'][$key] = $value;

        return $m;
    }

    /**
     * @param array<string, mixed> $m
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function step(array $m, array $step): array
    {
        return self::set($m, 'steps', [$step]);
    }
}
