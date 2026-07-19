<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tests\Support\PluginPackageFixtures;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InstallFromStoreApiHandler;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * PR-3: security coverage for the install-from-store endpoint.
 *
 * The headline risk is SSRF (the SERVER fetches an operator-supplied URL), so
 * the tests concentrate on the allowlist gate, scheme/host checks, input
 * validation, and the fetch-failure envelopes — all reachable WITHOUT a live
 * store by injecting a stub package fetcher. Every negative path also asserts
 * the fetcher was NOT invoked, proving the guard runs before any outbound
 * request. The full valid-package staging is exercised by PluginInstallerTest;
 * one happy path here confirms the handler wires bytes through and returns 201.
 */
final class InstallFromStoreApiHandlerTest extends TestCase
{
    private string $pluginDir;
    private string $workDir;

    protected function setUp(): void
    {
        $this->pluginDir = sys_get_temp_dir() . '/whity_ifs_plugins_' . uniqid();
        $this->workDir = sys_get_temp_dir() . '/whity_ifs_work_' . uniqid();
        mkdir($this->pluginDir, 0775, true);
        mkdir($this->workDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->pluginDir);
        $this->removeRecursive($this->workDir);
    }

    /**
     * Build a handler with the given allowlist and a stub fetcher.
     *
     * @param array{0: array<int, array{url: string, headers: array<string, string>}>} $calls
     *   By-reference sink recording each fetcher invocation (index 0 holds the list).
     */
    private function handler(string $allowlist, ?string $fetchReturns, array &$calls): InstallFromStoreApiHandler
    {
        $pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );
        $settings->setGlobal(SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS, $allowlist);

        $calls = [];
        $fetch = function (string $url, array $headers) use (&$calls, $fetchReturns): ?string {
            $calls[] = ['url' => $url, 'headers' => $headers];
            return $fetchReturns;
        };

        return new InstallFromStoreApiHandler($this->pluginDir, $settings, null, null, $fetch);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        return new Request('POST', '/api/plugins/install-from-store', [], json_encode($body) ?: '');
    }

    public function testRejectsNonJsonBody(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls);
        $res = $handler->install(new Request('POST', '/api/plugins/install-from-store', [], 'not json'));
        self::assertSame(400, $res->getStatusCode());
        self::assertSame([], $calls);
    }

    public function testRejectsMissingFields(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls);
        $res = $handler->install($this->request(['store_url' => 'https://store.example.com']));
        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $calls);
    }

    public function testRejectsUnsafeSlug(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls);
        $res = $handler->install($this->request([
            'store_url' => 'https://store.example.com',
            'slug' => '../../etc/passwd',
            'version' => '1.0.0',
        ]));
        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $calls);
    }

    public function testRejectsUnsafeVersion(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls);
        $res = $handler->install($this->request([
            'store_url' => 'https://store.example.com',
            'slug' => 'acme',
            'version' => '1.0/../../x',
        ]));
        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $calls);
    }

    public function testDisabledWhenAllowlistEmpty(): void
    {
        $calls = [];
        $handler = $this->handler('', null, $calls);
        $res = $handler->install($this->request([
            'store_url' => 'https://store.example.com',
            'slug' => 'acme',
            'version' => '1.0.0',
        ]));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame([], $calls, 'no outbound request when the feature is disabled');
    }

    public function testRejectsHostNotOnAllowlist(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls);
        $res = $handler->install($this->request([
            // A classic SSRF target — must be refused because the host is not allowlisted.
            'store_url' => 'https://169.254.169.254',
            'slug' => 'acme',
            'version' => '1.0.0',
        ]));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame([], $calls, 'the allowlist must block before any fetch');
    }

    public function testRejectsNonHttpsScheme(): void
    {
        $calls = [];
        // Even if the host string matches, a non-https scheme is refused.
        $handler = $this->handler('store.example.com', null, $calls);
        $res = $handler->install($this->request([
            'store_url' => 'http://store.example.com',
            'slug' => 'acme',
            'version' => '1.0.0',
        ]));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame([], $calls);
    }

    public function testRejectsNonDefaultPortOnAllowlistedHost(): void
    {
        $calls = [];
        // Even on an allowlisted host, a non-443 port could reach a co-located
        // service (e.g. Redis) — must be refused before any fetch.
        $handler = $this->handler('store.example.com', null, $calls);
        $res = $handler->install($this->request([
            'store_url' => 'https://store.example.com:6379',
            'slug' => 'acme',
            'version' => '1.0.0',
        ]));
        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $calls);
    }

    public function testRejectsStoreUrlWithPathOrQuery(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls);
        foreach (['https://store.example.com/some/path', 'https://store.example.com/?x=1', 'https://user:pw@store.example.com'] as $url) {
            $res = $handler->install($this->request([
                'store_url' => $url,
                'slug' => 'acme',
                'version' => '1.0.0',
            ]));
            self::assertSame(422, $res->getStatusCode(), "must reject non-bare origin: {$url}");
        }
        self::assertSame([], $calls);
    }

    public function testFetchFailureReturns502(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls); // fetcher returns null
        $res = $handler->install($this->request([
            'store_url' => 'https://store.example.com',
            'slug' => 'acme',
            'version' => '1.0.0',
        ]));
        self::assertSame(502, $res->getStatusCode());
        self::assertCount(1, $calls, 'an allowlisted host is fetched exactly once');
    }

    public function testNonPackageBytesFromStoreReturn400(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', 'garbage-not-a-package', $calls);
        $res = $handler->install($this->request([
            'store_url' => 'https://store.example.com',
            'slug' => 'acme',
            'version' => '1.0.0',
        ]));
        self::assertSame(400, $res->getStatusCode());
    }

    public function testHappyPathStagesPluginAndSendsBearerToCorrectUrl(): void
    {
        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'StoreFetched');
        $bytes = (string) file_get_contents($zip);

        $calls = [];
        $handler = $this->handler('store.example.com', $bytes, $calls);
        $res = $handler->install($this->request([
            'store_url' => 'https://store.example.com/',
            'slug' => 'acme-widget',
            'version' => '2.1.0',
            'token' => 'wps_secret',
        ]));

        self::assertSame(201, $res->getStatusCode());
        self::assertStringContainsString('StoreFetched', $res->getBody());

        // Download URL correctly assembled (single slash, encoded segments).
        self::assertCount(1, $calls);
        self::assertSame(
            'https://store.example.com/api/v1/plugin-store/plugins/acme-widget/versions/2.1.0/download',
            $calls[0]['url']
        );
        self::assertSame('Bearer wps_secret', $calls[0]['headers']['Authorization'] ?? null);
    }

    public function testNoAuthorizationHeaderWhenTokenOmitted(): void
    {
        $calls = [];
        $handler = $this->handler('store.example.com', null, $calls);
        $handler->install($this->request([
            'store_url' => 'https://store.example.com',
            'slug' => 'acme',
            'version' => '1.0.0',
        ]));
        self::assertCount(1, $calls);
        self::assertArrayNotHasKey('Authorization', $calls[0]['headers']);
    }

    // ── Browse / allowed-stores (admin UI surface) ───────────────────────────

    /**
     * Build a handler with a stub JSON catalogue fetcher (for browse tests).
     *
     * @param array<string, mixed>|null $jsonReturns catalogue payload the store "returns"
     * @param array{0: list<string>}     $urls        by-ref sink of fetched URLs (index 0)
     */
    private function browseHandler(string $allowlist, ?array $jsonReturns, array &$urls): InstallFromStoreApiHandler
    {
        $pdo = SchemaFromMigrations::make(true);
        $settings = new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );
        $settings->setGlobal(SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS, $allowlist);

        $urls = [];
        $fetchJson = function (string $url) use (&$urls, $jsonReturns): ?array {
            $urls[] = $url;
            return $jsonReturns;
        };

        // fetchPackage unused here; pass a null-returning stub in slot 5.
        $fetchPackage = static fn (string $u, array $h): ?string => null;

        return new InstallFromStoreApiHandler($this->pluginDir, $settings, null, null, $fetchPackage, $fetchJson);
    }

    private function catalogRequest(string $qs): Request
    {
        return new Request('GET', '/api/plugins/store/catalog?' . $qs, [], '');
    }

    /** @return array<string, mixed> */
    private function sampleCatalog(): array
    {
        return ['data' => [
            ['slug' => 'quote-of-day', 'name' => 'Quote of the Day', 'description' => 'A rotating quote.', 'tags' => ['demo', 'fun']],
            ['slug' => 'system-ping', 'name' => 'System Ping', 'description' => 'Health pong.', 'tags' => ['demo', 'ops']],
        ]];
    }

    public function testAllowedStoresReportsDisabledWhenEmpty(): void
    {
        $urls = [];
        $handler = $this->browseHandler('', null, $urls);
        $res = $handler->allowedStores(new Request('GET', '/api/plugins/store/allowed'));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);
        self::assertFalse($body['data']['enabled']);
        self::assertSame([], $body['data']['hosts']);
    }

    public function testAllowedStoresListsConfiguredHosts(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com, Other.Example.COM', null, $urls);
        $res = $handler->allowedStores(new Request('GET', '/api/plugins/store/allowed'));
        $body = json_decode($res->getBody(), true);
        self::assertTrue($body['data']['enabled']);
        self::assertSame(['store.example.com', 'other.example.com'], $body['data']['hosts']);
    }

    public function testBrowseRequiresStoreUrl(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com', $this->sampleCatalog(), $urls);
        $res = $handler->browseCatalog($this->catalogRequest('q=quote'));
        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $urls);
    }

    public function testBrowseDisabledWhenAllowlistEmpty(): void
    {
        $urls = [];
        $handler = $this->browseHandler('', $this->sampleCatalog(), $urls);
        $res = $handler->browseCatalog($this->catalogRequest('store_url=https://store.example.com'));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame([], $urls, 'no outbound request when disabled');
    }

    public function testBrowseRejectsHostNotOnAllowlist(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com', $this->sampleCatalog(), $urls);
        $res = $handler->browseCatalog($this->catalogRequest('store_url=https://169.254.169.254'));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame([], $urls, 'the allowlist must block before any fetch');
    }

    public function testBrowseReturnsCatalogueAndFetchesCorrectUrl(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com', $this->sampleCatalog(), $urls);
        $res = $handler->browseCatalog($this->catalogRequest('store_url=https://store.example.com'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['https://store.example.com/api/v1/plugin-store/plugins'], $urls);
        $body = json_decode($res->getBody(), true);
        self::assertCount(2, $body['data']);
        self::assertSame('https://store.example.com', $body['store_url']);
        self::assertSame(2, $body['count']);
    }

    public function testBrowseSearchFiltersCatalogue(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com', $this->sampleCatalog(), $urls);
        // 'ops' matches only system-ping (via its tag).
        $res = $handler->browseCatalog($this->catalogRequest('store_url=https://store.example.com&q=ops'));
        $body = json_decode($res->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('system-ping', $body['data'][0]['slug']);
    }

    public function testBrowseFetchFailureReturns502(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com', null, $urls); // fetchJson returns null
        $res = $handler->browseCatalog($this->catalogRequest('store_url=https://store.example.com'));
        self::assertSame(502, $res->getStatusCode());
        self::assertCount(1, $urls);
    }

    public function testBrowseMalformedCatalogueReturns502(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com', ['unexpected' => true], $urls);
        $res = $handler->browseCatalog($this->catalogRequest('store_url=https://store.example.com'));
        self::assertSame(502, $res->getStatusCode());
    }

    /**
     * At runtime Request::fromGlobals() strips the query from getPath() (path
     * only), so the handler must read the query from PHP's native $_GET. Simulate
     * that: a path WITHOUT a query string + a populated $_GET.
     */
    public function testBrowseReadsQueryFromSuperglobalWhenPathHasNone(): void
    {
        $urls = [];
        $handler = $this->browseHandler('store.example.com', $this->sampleCatalog(), $urls);

        $previous = $_GET;
        $_GET = ['store_url' => 'https://store.example.com', 'q' => 'ops'];
        try {
            $res = $handler->browseCatalog(new Request('GET', '/api/plugins/store/catalog'));
        } finally {
            $_GET = $previous;
        }

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['https://store.example.com/api/v1/plugin-store/plugins'], $urls);
        $body = json_decode($res->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('system-ping', $body['data'][0]['slug']);
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
