<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\ManifestValidator;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * WHIT-764: a manifest's own tests, checked with the rest of the manifest.
 */
final class ManifestTestValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $l = static fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];
        $m = AutomationValidatorTest::manifest();
        $m['tests'] = [
            [
                'label' => $l('A draft can be confirmed', 'يمكن تأكيد المسودة'),
                'record' => 'order',
                'as' => ['baker'],
                'given' => ['customer' => 'Example Bakery', 'pickup_on' => '2026-11-03'],
                'state' => 'draft',
                'move' => 'confirm',
                'expect' => ['refused' => false, 'state' => 'confirmed'],
            ],
            [
                'label' => $l('A customer is needed', 'الزبون مطلوب'),
                'record' => 'order',
                'create' => ['pickup_on' => '2026-11-03'],
                'expect' => ['refused' => true, 'issues' => ['customer']],
            ],
        ];

        return $m;
    }

    public function testTheBakeryTestsAreValid(): void
    {
        self::assertSame([], ManifestValidator::validate(self::manifest())['errors']);
        self::assertCount(2, PluginManifest::fromArray(self::manifest())->tests());
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function broken(): iterable
    {
        yield 'unknown record type' => [static fn (array $m): array => self::set($m, 0, 'record', 'invoice'), 'tests[0].record: required, a record type of this manifest'];
        yield 'two actions' => [static fn (array $m): array => self::set($m, 0, 'update', ['customer' => 'x']), 'tests[0]: do exactly one of create, update or move'];
        yield 'unknown move' => [static fn (array $m): array => self::set($m, 0, 'move', 'bake'), 'tests[0].move: must be a move of order'];
        yield 'unknown state' => [static fn (array $m): array => self::set($m, 0, 'state', 'baked'), 'tests[0].state: must be a state of order'];
        yield 'undeclared role' => [static fn (array $m): array => self::set($m, 0, 'as', ['admin']), 'tests[0].as: "admin" isn\'t a role this manifest declares'];
        yield 'given on a create' => [static fn (array $m): array => self::set($m, 1, 'given', []), 'tests[1].given: a create starts from nothing'];
        yield 'an expression as a value' => [static fn (array $m): array => self::set($m, 1, 'create', ['customer' => ['field' => 'x']]), 'tests[1].create.customer: a test\'s values are literals, not expressions'];
        yield 'unknown field' => [static fn (array $m): array => self::set($m, 1, 'create', ['colour' => 'blue']), 'tests[1].create.colour: isn\'t a field of the record type'];
        yield 'fields on a refusal' => [static fn (array $m): array => self::set($m, 1, 'expect', ['refused' => true, 'fields' => []]), 'tests[1].expect.fields: a refused write leaves nothing to check'];
        yield 'no expectation' => [static fn (array $m): array => self::set($m, 1, 'expect', ['fields' => []]), 'tests[1].expect: required, with refused true or false'];
        yield 'no Arabic label' => [static fn (array $m): array => self::set($m, 1, 'label', ['en' => 'x']), 'tests[1].label'];
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
    private static function set(array $m, int $test, string $key, mixed $value): array
    {
        $m['tests'][$test][$key] = $value;

        return $m;
    }
}
