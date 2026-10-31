<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * WHIT-742: template slots in `plugin.json` (`documents.slots`), checked with
 * the rest of the manifest.
 */
final class DocumentSlotValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $manifest = RecordTypeValidatorTest::manifest();
        $manifest['documents'] = [
            'slots' => [
                'order_confirmation' => [
                    'label' => ['en' => 'Order confirmation', 'ar' => 'تأكيد الطلب'],
                    'record' => 'order',
                    'kinds' => ['template'],
                ],
                'order_label' => [
                    'label' => ['en' => 'Box label', 'ar' => 'ملصق العلبة'],
                    'record' => 'order',
                    'kinds' => ['label'],
                ],
            ],
        ];

        return $manifest;
    }

    public function testTheBakerySlotsAreValid(): void
    {
        self::assertSame([], ManifestValidator::validate(self::manifest())['errors']);
        $slots = PluginManifest::fromArray(self::manifest())->documentSlots();
        self::assertSame(['order_confirmation', 'order_label'], array_keys($slots));
        self::assertSame('order', $slots['order_confirmation']['record']);
    }

    public function testAManifestWithoutDocumentsHasNoSlots(): void
    {
        self::assertSame([], PluginManifest::fromArray(RecordTypeValidatorTest::manifest())->documentSlots());
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'documents not an object' => [
            static function (array $m): array { $m['documents'] = ['x']; return $m; },
            'documents: must be an object',
        ];
        yield 'unknown documents key' => [
            static function (array $m): array { $m['documents']['themes'] = []; return $m; },
            "documents: unknown key 'themes'",
        ];
        yield 'bad slot name' => [
            static function (array $m): array {
                $m['documents']['slots']['Order-Slip'] = $m['documents']['slots']['order_label'];
                return $m;
            },
            'documents.slots.Order-Slip: slot names are lowercase letters, digits and underscores, up to 40',
        ];
        yield 'unknown slot key' => [
            static function (array $m): array { $m['documents']['slots']['order_label']['numbering'] = 'per_year'; return $m; },
            "documents.slots.order_label: unknown key 'numbering'",
        ];
        yield 'label missing' => [
            static function (array $m): array { unset($m['documents']['slots']['order_label']['label']); return $m; },
            'documents.slots.order_label.label',
        ];
        yield 'record missing' => [
            static function (array $m): array { unset($m['documents']['slots']['order_label']['record']); return $m; },
            "documents.slots.order_label.record: required, the record type the slot's documents are for",
        ];
        yield 'record of another manifest' => [
            static function (array $m): array { $m['documents']['slots']['order_label']['record'] = 'invoice'; return $m; },
            "documents.slots.order_label.record: 'invoice' is not a record type this manifest declares",
        ];
        yield 'empty kinds' => [
            static function (array $m): array { $m['documents']['slots']['order_label']['kinds'] = []; return $m; },
            'documents.slots.order_label.kinds: a non-empty list of template, label',
        ];
        yield 'unknown kind' => [
            static function (array $m): array { $m['documents']['slots']['order_label']['kinds'] = ['letter']; return $m; },
            'documents.slots.order_label.kinds: each kind is one of template, label',
        ];
        yield 'repeated kind' => [
            static function (array $m): array { $m['documents']['slots']['order_label']['kinds'] = ['label', 'label']; return $m; },
            'documents.slots.order_label.kinds: no repeats',
        ];
        yield 'a starter, which waits for WHIT-687' => [
            static function (array $m): array { $m['documents']['slots']['order_label']['starter'] = 'starters/x.json'; return $m; },
            "documents.slots.order_label: unknown key 'starter'",
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalid')]
    public function testItRefuses(callable $mutate, string $expected): void
    {
        $errors = ManifestValidator::validate($mutate(self::manifest()))['errors'];
        $matching = array_filter($errors, static fn (string $e): bool => str_starts_with($e, $expected));
        self::assertNotSame([], $matching, "Expected an error starting with: {$expected}\nGot: " . implode("\n", $errors));
    }
}
