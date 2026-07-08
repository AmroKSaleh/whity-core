<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Http;

use PHPUnit\Framework\TestCase;
use Whity\Core\Http\HttpFetcher;

/**
 * Unit tests for the SSRF guard on {@see HttpFetcher} (WC-ae16). Uses IP-literal
 * and loopback hosts so the checks are deterministic and touch no network.
 */
final class HttpFetcherTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            ['http://8.8.8.8/x'],                         // not https
            ['ftp://8.8.8.8/x'],                          // not https
            ['https://127.0.0.1/x'],                      // loopback
            ['https://10.0.0.5/x'],                       // private (10/8)
            ['https://192.168.1.10/x'],                   // private (192.168/16)
            ['https://172.16.5.5/x'],                     // private (172.16/12)
            ['https://169.254.169.254/latest/meta-data'], // cloud-metadata link-local
            ['https://0.0.0.0/x'],                         // reserved
            ['https://[::1]/x'],                           // IPv6 loopback
            ['https://localhost/x'],                       // resolves to loopback
            ['https:///no-host'],                          // no host
            ['not-a-url'],                                 // unparseable
        ];
    }

    /**
     * @dataProvider blockedUrls
     */
    public function testBlocksNonPublicOrNonHttpsUrls(string $url): void
    {
        self::assertFalse(HttpFetcher::isPubliclyRoutableUrl($url), "must block: {$url}");
    }

    /**
     * @return list<array{0: string}>
     */
    public static function allowedUrls(): array
    {
        return [
            ['https://8.8.8.8/x'],           // public IPv4 literal
            ['https://1.1.1.1/'],            // public IPv4 literal
            ['https://[2001:4860:4860::8888]/x'], // public IPv6 literal (Google DNS)
        ];
    }

    /**
     * @dataProvider allowedUrls
     */
    public function testAllowsPubliclyRoutableHttpsUrls(string $url): void
    {
        self::assertTrue(HttpFetcher::isPubliclyRoutableUrl($url), "must allow: {$url}");
    }

    public function testFetchThrowsOnABlockedUrl(): void
    {
        // getJson runs the guard before any I/O, so a blocked URL throws rather
        // than attempting a request.
        $this->expectException(\RuntimeException::class);
        (new HttpFetcher())->getJson('https://169.254.169.254/latest/meta-data');
    }
}
