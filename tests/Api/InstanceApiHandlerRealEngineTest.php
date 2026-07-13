<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\InstanceApiHandler;
use Whity\Core\CoreVersion;
use Whity\Core\Instance\InstanceService;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see InstanceApiHandler} + {@see InstanceService}
 * (WC-instance-first-run).
 *
 * Proves the first-run contract: a fresh install reports configured=false;
 * completing setup from the SYSTEM tenant flips it (idempotently) and is reflected
 * by both the status endpoint and the service; and completion is rejected (422)
 * from any non-system tenant — the handler's operator/global guard, independent of
 * the route-level settings:manage permission (enforced by middleware, not here).
 */
final class InstanceApiHandlerRealEngineTest extends TestCase
{
    private const SYSTEM_TENANT = 0;
    private const REGULAR_TENANT = 1;

    private PDO $pdo;
    private InstanceService $service;
    private InstanceApiHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->service = new InstanceService(new GlobalSettingsRepository($this->pdo));
        $this->handler = new InstanceApiHandler($this->service);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testFreshInstanceReportsNotConfigured(): void
    {
        $body = $this->fetchStatus(self::SYSTEM_TENANT);

        self::assertFalse($body['configured'], 'a fresh install must offer first-run setup');
        self::assertFalse($this->service->isConfigured());
    }

    public function testStatusIncludesCoreVersion(): void
    {
        $body = $this->fetchStatus(self::SYSTEM_TENANT);

        self::assertSame(CoreVersion::VERSION, $body['version']);
    }

    public function testCompleteSetupFromSystemTenantMarksConfigured(): void
    {
        $res = $this->handler->completeSetup($this->request('POST', self::SYSTEM_TENANT));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['configured']);

        // Reflected by the service and by a subsequent status probe.
        self::assertTrue($this->service->isConfigured());
        self::assertTrue($this->fetchStatus(self::SYSTEM_TENANT)['configured']);
    }

    public function testCompleteSetupRejectedForNonSystemTenant(): void
    {
        $res = $this->handler->completeSetup($this->request('POST', self::REGULAR_TENANT));

        self::assertSame(422, $res->getStatusCode());
        // The flag is untouched — a regular tenant can never flip instance-wide state.
        self::assertFalse($this->service->isConfigured());
    }

    public function testCompleteSetupIsIdempotent(): void
    {
        self::assertSame(200, $this->handler->completeSetup($this->request('POST', self::SYSTEM_TENANT))->getStatusCode());
        self::assertSame(200, $this->handler->completeSetup($this->request('POST', self::SYSTEM_TENANT))->getStatusCode());
        self::assertTrue($this->service->isConfigured());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function fetchStatus(int $tenantId): array
    {
        $res = $this->handler->status($this->request('GET', $tenantId));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function request(string $method, int $tenantId): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $path = $method === 'GET' ? '/api/instance/status' : '/api/instance/complete-setup';

        return new Request($method, $path, [], '');
    }
}
