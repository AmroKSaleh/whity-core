<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Http;

use PHPUnit\Framework\TestCase;
use Whity\Core\Http\DeviceLabel;

/**
 * Unit tests for {@see DeviceLabel} (WC-b3330495) — friendly session/device
 * labels parsed from raw User-Agent strings.
 */
final class DeviceLabelTest extends TestCase
{
    /**
     * @dataProvider userAgents
     */
    public function testFromUserAgent(string $ua, string $expected): void
    {
        self::assertSame($expected, DeviceLabel::fromUserAgent($ua));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function userAgents(): array
    {
        return [
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Chrome on Windows',
            ],
            'Edge on Windows (Edg token beats Chrome)' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
                'Edge on Windows',
            ],
            'Firefox on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Firefox on Windows',
            ],
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
                'Safari on macOS',
            ],
            'Chrome on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Chrome on macOS',
            ],
            'Safari on iOS (iPhone, not macOS)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
                'Safari on iOS',
            ],
            'Chrome on Android' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'Chrome on Android',
            ],
            'Chrome on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Chrome on Linux',
            ],
            'curl (no OS)' => ['curl/8.4.0', 'curl'],
            'unknown' => ['SomeRandomBot/1.0', 'Unknown device'],
        ];
    }

    public function testEmptyAndNullYieldUnknown(): void
    {
        self::assertSame('Unknown device', DeviceLabel::fromUserAgent(null));
        self::assertSame('Unknown device', DeviceLabel::fromUserAgent(''));
        self::assertSame('Unknown device', DeviceLabel::fromUserAgent('   '));
    }

    public function testOsOnlyWhenBrowserUnknown(): void
    {
        // A UA with a recognizable OS but no known browser token → OS alone.
        self::assertSame('Windows', DeviceLabel::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'));
    }
}
