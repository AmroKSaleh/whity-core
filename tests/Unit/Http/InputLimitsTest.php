<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\InputLimits;

/**
 * Unit tests for the shared free-text length-cap helper (WC input hardening).
 */
final class InputLimitsTest extends TestCase
{
    public function testReturnsNullWhenEveryFieldIsWithinBounds(): void
    {
        $result = InputLimits::firstViolation([
            'name'  => ['Acme', InputLimits::NAME_MAX],
            'notes' => [str_repeat('a', 500), InputLimits::TEXT_MAX],
        ]);

        self::assertNull($result);
    }

    public function testSkipsNullValues(): void
    {
        // An absent optional field is not a length violation.
        $result = InputLimits::firstViolation([
            'notes' => [null, InputLimits::TEXT_MAX],
        ]);

        self::assertNull($result);
    }

    public function testValueExactlyAtTheLimitPasses(): void
    {
        $result = InputLimits::firstViolation([
            'name' => [str_repeat('a', InputLimits::NAME_MAX), InputLimits::NAME_MAX],
        ]);

        self::assertNull($result);
    }

    public function testValueOneOverTheLimitIsRejectedWith422AndFieldName(): void
    {
        $result = InputLimits::firstViolation([
            'name' => [str_repeat('a', InputLimits::NAME_MAX + 1), InputLimits::NAME_MAX],
        ]);

        self::assertNotNull($result);
        self::assertSame(422, $result->getStatusCode());
        $body = json_decode($result->getBody(), true);
        self::assertSame('name', $body['details']['field']);
        self::assertStringContainsString('255', $body['error']);
    }

    public function testReportsTheFirstOffendingFieldInIterationOrder(): void
    {
        $result = InputLimits::firstViolation([
            'name'  => ['ok', InputLimits::NAME_MAX],
            'slug'  => [str_repeat('s', InputLimits::NAME_MAX + 1), InputLimits::NAME_MAX],
            'notes' => [str_repeat('n', InputLimits::TEXT_MAX + 1), InputLimits::TEXT_MAX],
        ]);

        self::assertNotNull($result);
        $body = json_decode($result->getBody(), true);
        self::assertSame('slug', $body['details']['field']);
    }
}
