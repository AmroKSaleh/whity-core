<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UiPreferencesApiHandler;
use Whity\Auth\JwtParser;
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
    private const JWT_SECRET = 'ui-preferences-test-secret-0123456789';

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
     * NOT cacheable, and the assertion is deliberate rather than incidental.
     *
     * This answer varies by WHO IS ASKING — the tenant comes from the caller's
     * own token when they carry one. It shipped as `public, max-age=60`, copied
     * from branding, whose answer varies only by host; a browser walk found the
     * consequence, which is that a reader who loaded the login screen kept its
     * (global-layer) answer for the first minute of their session and saw every
     * date their tenant had asked to hide. A SHARED cache would be worse still:
     * one tenant's preference served to another tenant's reader.
     */
    public function testItIsNotCacheableBecauseTheAnswerDependsOnWhoIsAsking(): void
    {
        $response = $this->handler->get(new Request('GET', '/api/ui/preferences', [], ''));

        self::assertSame('no-store', $response->getHeaders()['cache-control'] ?? null);
        self::assertSame('Cookie, Authorization', $response->getHeaders()['vary'] ?? null);
    }

    /**
     * A signed-in caller's own token names the tenant, and it must be read HERE.
     *
     * This route is on EnforceTenantIsolation's public list, which returns the
     * request to the pipeline BEFORE tenant resolution runs — so `TenantContext`
     * is empty even for a caller holding a perfectly good session. Reading the
     * context and stopping there is exactly what shipped, and what a browser
     * walk caught: with the setting on and an administrator signed in, every
     * screen still showed its dates.
     */
    public function testASignedInCallersTokenNamesTheTenantEvenWithNoResolvedContext(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');

        $request = new Request('GET', '/api/ui/preferences', [
            'Authorization' => 'Bearer ' . $this->tokenFor(self::TENANT_A),
        ], '');

        self::assertSame(['hideDates' => true], $this->dataOf($this->tokenAware()->get($request)));
    }

    /** The cookie the browser actually sends, not only a Bearer header. */
    public function testTheSessionCookieNamesTheTenantToo(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');

        $request = new Request('GET', '/api/ui/preferences', [
            'Cookie' => 'theme=dark; access_token=' . $this->tokenFor(self::TENANT_A),
        ], '');

        self::assertSame(['hideDates' => true], $this->dataOf($this->tokenAware()->get($request)));
    }

    /**
     * One caller's token does not answer for another tenant. The same assertion
     * as the context-based one above, made through the path a real browser
     * request actually takes.
     */
    public function testAnotherTenantsTokenGetsThatTenantsAnswer(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');

        $request = new Request('GET', '/api/ui/preferences', [
            'Authorization' => 'Bearer ' . $this->tokenFor(self::TENANT_B),
        ], '');

        self::assertSame(['hideDates' => false], $this->dataOf($this->tokenAware()->get($request)));
    }

    /**
     * A token it cannot read is not an authentication decision. This endpoint
     * has none to make, so it falls through to the host and then the global
     * layer rather than refusing — the behaviour that existed before the
     * setting did.
     */
    public function testAnUnreadableTokenFallsThroughRatherThanRefusing(): void
    {
        $this->settings->setTenant(self::TENANT_A, 'ui.hide_dates', 'true');

        $request = new Request('GET', '/api/ui/preferences', [
            'Authorization' => 'Bearer not.a.token',
        ], '');

        $response = $this->tokenAware()->get($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['hideDates' => false], $this->dataOf($response));
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

    /**
     * A handler that can read a token, i.e. the one production wires. The
     * default `$this->handler` deliberately has no parser, so the tests above
     * exercise the context and host paths in isolation.
     */
    private function tokenAware(): UiPreferencesApiHandler
    {
        return new UiPreferencesApiHandler(
            $this->settings,
            new HostResolver(new TenantHostRepository($this->pdo), 'example.test'),
            new JwtParser(self::JWT_SECRET)
        );
    }

    private function tokenFor(int $tenantId): string
    {
        return (new JwtParser(self::JWT_SECRET))->create([
            'profile_id' => 1,
            'active_tenant_id' => $tenantId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(\Whity\Sdk\Http\Response $response): array
    {
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
