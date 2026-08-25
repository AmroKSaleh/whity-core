<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UiPreferencesApiHandler;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for GET /api/v1/ui/preferences (#1068).
 *
 * The endpoint is one boolean, which is exactly why it is worth testing: every
 * screen in the product reads the answer, and the two things that can go wrong
 * with it are silent. If the per-tenant layer is skipped, a tenant that turned
 * the setting on keeps seeing dates and believes it did not work. If the
 * UNAUTHENTICATED path resolves the wrong tenant — or refuses — the sign-in
 * screen and the public status page render one tenant's preference for another's
 * reader.
 *
 * Drives the REAL handler + SettingsService + repositories against the full
 * migration-built schema, so the resolution chain under test is the one that
 * runs in production rather than a stub of it.
 */
final class UiPreferencesApiRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private SettingsService $settings;
    private UiPreferencesApiHandler $handler;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->handler = new UiPreferencesApiHandler(
            $this->settings,
            new HostResolver(new TenantHostRepository($this->pdo), 'example.test')
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * An instance that has never touched the setting behaves exactly as it does
     * today. This is the assertion that makes the feature safe to ship.
     */
    public function testTheDefaultIsDatesVisible(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        self::assertSame(['hideDates' => false], $this->get());
    }

    public function testATenantOverrideTurnsItOn(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');
        TenantContext::setTenantId(self::TENANT_A);

        self::assertSame(['hideDates' => true], $this->get());
    }

    /**
     * The per-tenant layer WINS over the global one, which is the whole point of
     * the key being tenant-overridable rather than governance.
     */
    public function testATenantOverrideBeatsTheGlobalDefault(): void
    {
        $this->settings->setGlobal('ui.hide_dates', 'true');
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'false');
        TenantContext::setTenantId(self::TENANT_A);

        self::assertSame(['hideDates' => false], $this->get());
    }

    /**
     * An operator's global choice reaches a tenant that has expressed none.
     */
    public function testTheGlobalDefaultReachesATenantWithNoOverride(): void
    {
        $this->settings->setGlobal('ui.hide_dates', 'true');
        TenantContext::setTenantId(self::TENANT_B);

        self::assertSame(['hideDates' => true], $this->get());
    }

    /**
     * One tenant's preference is not another's. Trivial to state and the exact
     * failure that would make this feature look broken on a multi-tenant
     * instance: a shared shell reading a cached answer for the wrong tenant.
     */
    public function testOneTenantsPreferenceDoesNotReachAnother(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');

        TenantContext::setTenantId(self::TENANT_A);
        self::assertSame(['hideDates' => true], $this->get());

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        self::assertSame(['hideDates' => false], $this->get());
    }

    // ── the unauthenticated path ────────────────────────────────────────────

    /**
     * With no session, the HOST decides — branding's exact ladder. The sign-in
     * screen and the public status page both render dates before any session
     * exists, so an endpoint that only answered authenticated callers would
     * arrive after the screens it governs.
     */
    public function testWithNoSessionTheRequestHostResolvesTheTenant(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');

        self::assertSame(['hideDates' => true], $this->get(['Host' => 'tenant-a.example.test']));
    }

    public function testWithNoSessionAndAnUnknownHostTheGlobalLayerAnswers(): void
    {
        $this->settings->setGlobal('ui.hide_dates', 'true');
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'false');

        self::assertSame(['hideDates' => true], $this->get(['Host' => 'nobody.example.test']));
    }

    /**
     * A forwarded host wins, because the app sits behind a proxy in every
     * deployment that has more than one tenant.
     */
    public function testTheForwardedHostIsPreferredOverTheDirectOne(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');

        self::assertSame(
            ['hideDates' => true],
            $this->get(['Host' => 'internal:8000', 'X-Forwarded-Host' => 'tenant-a.example.test'])
        );
    }

    // ── the contract itself ─────────────────────────────────────────────────

    /**
     * It answers 200 to a caller with nothing at all. This route is deliberately
     * ungated: `settings:read` is an administrative right, and a preference
     * governing every screen has to reach the readers who will never hold it.
     */
    public function testItIsPublicAndAlwaysAnswers(): void
    {
        $response = $this->handler->get(new Request('GET', '/api/ui/preferences', [], ''));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * It carries ONLY the display preference. A stray extra key here would be a
     * tenant setting published to anonymous callers, which is precisely what the
     * gated settings surface exists to prevent.
     */
    public function testItPublishesNothingButTheDisplayPreference(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'site_name', 'Ministry of Records');
        $this->settings->setGlobal('mail.smtp.host', 'smtp.internal');
        TenantContext::setTenantId(self::TENANT_A);

        self::assertSame(['hideDates'], array_keys($this->get()));
    }

    /**
     * A short public cache, matching branding: long enough that the shell is not
     * re-asking on every navigation, short enough that an administrator who has
     * just flipped the setting sees it take effect while still looking at the
     * screen they changed it from.
     */
    public function testItIsBrieflyCacheable(): void
    {
        $response = $this->handler->get(new Request('GET', '/api/ui/preferences', [], ''));

        self::assertSame('public, max-age=60', $response->getHeaders()['cache-control'] ?? null);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function get(array $headers = []): array
    {
        $response = $this->handler->get(new Request('GET', '/api/ui/preferences', $headers, ''));
        self::assertSame(200, $response->getStatusCode());

        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data']);

        return $decoded['data'];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'tenant-a', 'tenant-a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'tenant-b', 'tenant-b')");

        return $pdo;
    }
}
