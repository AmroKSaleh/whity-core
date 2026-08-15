<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\PlatformVersionApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\CoreVersion;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Update\LatestReleaseCheck;
use Whity\Sdk\Sdk;

/**
 * WHIT-587: platform version state over HTTP, for operators who have no shell.
 *
 * Two things are pinned here. First the payload: core AND SDK version, plus a
 * latest-release verdict that reuses the same comparison the CLI runs — a
 * second implementation would drift and lie. Second the gate: this is
 * deployment-wide state on a shared install, so a tenant admin must not reach
 * it even holding settings:manage in their own tenant.
 */
final class PlatformVersionApiHandlerTest extends TestCase
{
    private const SYSTEM_TENANT = 0;
    private const REGULAR_TENANT = 7;
    private const ACTOR = 42;

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testReportsCoreAndSdkVersion(): void
    {
        $response = $this->handler()->version($this->request(self::SYSTEM_TENANT));

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = $this->decode($response->getBody());

        self::assertSame(CoreVersion::VERSION, $body['core_version']);
        self::assertSame(Sdk::VERSION, $body['sdk_version'], 'the plugin SDK contract version was in no endpoint at all');
        self::assertSame(PHP_VERSION, $body['php_version']);
    }

    public function testVersionIsRefusedToATenantAdmin(): void
    {
        $response = $this->handler()->version($this->request(self::REGULAR_TENANT));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString(Sdk::VERSION, $response->getBody());
    }

    public function testVersionIsRefusedWithoutTheSettingsPermission(): void
    {
        $response = $this->handler(granted: false)->version($this->request(self::SYSTEM_TENANT));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testVersionIsRefusedWithoutATenantContext(): void
    {
        TenantContext::reset();
        $request = new Request('GET', '/api/v1/platform/version');
        $request->user = (object) ['profile_id' => self::ACTOR];

        self::assertSame(403, $this->handler()->version($request)->getStatusCode());
    }

    public function testVersionIsRefusedToAnUnauthenticatedCaller(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::SYSTEM_TENANT);

        $response = $this->handler()->version(new Request('GET', '/api/v1/platform/version'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testLatestReportsTheSameVerdictTheCliWouldPrint(): void
    {
        $handler = $this->handler(release: [
            'tag_name' => 'v99.0.0',
            'html_url' => 'https://example.invalid/release',
            'published_at' => '2026-06-12T00:00:00Z',
        ]);

        $response = $handler->latest($this->request(self::SYSTEM_TENANT));

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = $this->decode($response->getBody());

        self::assertSame('update_available', $body['status']);
        self::assertTrue($body['update_available']);
        self::assertSame(CoreVersion::VERSION, $body['current_version']);
        self::assertSame('99.0.0', $body['latest_version']);
        self::assertSame('https://example.invalid/release', $body['release_url']);
    }

    /**
     * A check that could not be performed is still a 200: the endpoint
     * answered truthfully ("I could not tell"), and the operator UI has to be
     * able to render that distinctly from "up to date". An HTTP error would
     * collapse the two.
     */
    public function testAFailedCheckIsAReportedStatusNotAnHttpError(): void
    {
        $response = $this->handler(release: null)->latest($this->request(self::SYSTEM_TENANT));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response->getBody());

        self::assertSame('check_failed', $body['status']);
        self::assertFalse($body['update_available']);
        self::assertSame('unreachable', $body['failure_reason']);
    }

    public function testLatestIsRefusedToATenantAdmin(): void
    {
        $response = $this->handler()->latest($this->request(self::REGULAR_TENANT));

        self::assertSame(403, $response->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $release Canned GitHub payload; null simulates an unreachable API.
     */
    private function handler(bool $granted = true, ?array $release = null): PlatformVersionApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')->willReturn($granted);

        $fetcher = static function (string $url) use ($release): ?string {
            return $release === null ? null : (string) json_encode($release);
        };

        return new PlatformVersionApiHandler($roleChecker, new LatestReleaseCheck($fetcher));
    }

    private function request(int $tenantId): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $request = new Request('GET', '/api/v1/platform/version');
        $request->user = (object) ['profile_id' => self::ACTOR];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
