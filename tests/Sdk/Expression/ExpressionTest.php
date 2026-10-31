<?php

declare(strict_types=1);

namespace Tests\Sdk\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Expression\Expression;
use Whity\Sdk\Expression\ExpressionContext;
use Whity\Sdk\Expression\ExpressionError;

/**
 * WHIT-759: the PHP evaluator against the shared fixtures. The TypeScript
 * evaluator runs the same file (packages/features/src/expressions), which is
 * what keeps a rule checked in a form and the same rule checked on save from
 * disagreeing.
 */
final class ExpressionTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function fixtures(): array
    {
        $path = dirname(__DIR__, 3) . '/sdk/resources/expression-fixtures.json';
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * The fixture file names a few inputs too big to write out; both harnesses
     * expand them the same way.
     */
    public static function expand(mixed $value): mixed
    {
        if ($value === 'BIG_LIST_4000') {
            return range(1, 4000);
        }
        if ($value === 'DEEP_25') {
            $node = true;
            for ($i = 0; $i < 25; $i++) {
                $node = ['not' => [$node]];
            }
            return $node;
        }
        if ($value === 'WIDE_300') {
            return ['+' => array_fill(0, 300, 1)];
        }
        if ($value === 'LONG_TEXT_1001') {
            return str_repeat('a', 1001);
        }
        if (is_array($value)) {
            return array_map([self::class, 'expand'], $value);
        }

        return $value;
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function evaluateCases(): iterable
    {
        foreach (self::fixtures()['evaluate'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidCases(): iterable
    {
        foreach (self::fixtures()['invalid'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('evaluateCases')]
    public function testEvaluatesLikeTheFixtureSays(array $case): void
    {
        $expression = self::expand($case['expr']);
        $validation = Expression::validate($expression);
        self::assertTrue($validation['ok'], implode('; ', $validation['errors']));

        /** @var array<string, mixed> $contextData */
        $contextData = self::expand($case['context'] ?? self::fixtures()['context']);
        $context = ExpressionContext::fromArray($contextData);

        if (array_key_exists('error', $case)) {
            try {
                Expression::evaluate($expression, $context);
                self::fail("Expected an ExpressionError of kind {$case['error']}");
            } catch (ExpressionError $e) {
                self::assertSame($case['error'], $e->kind, $e->getMessage());
            }
            return;
        }

        self::assertSameValue($case['expect'], Expression::evaluate($expression, $context));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('invalidCases')]
    public function testRefusesWhatTheFixtureSays(array $case): void
    {
        $result = Expression::validate(self::expand($case['expr']));

        self::assertFalse($result['ok']);
        self::assertNotEmpty(
            array_filter($result['errors'], static fn (string $e): bool => str_contains($e, (string) $case['error'])),
            'Expected an error containing "' . $case['error'] . '", got: ' . implode(' | ', $result['errors']),
        );
    }

    /**
     * Numbers compare by value (JSON has no int/float distinction, and the
     * TypeScript side has one number type); everything else must be identical.
     */
    private static function assertSameValue(mixed $expected, mixed $actual): void
    {
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            self::assertEquals((float) $expected, (float) $actual);
            return;
        }
        self::assertSame($expected, $actual);
    }
}
