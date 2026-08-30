<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\RequestLanguageResolver;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Http\Middleware\ResolveLanguage;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * The language middleware in the pipeline, not the resolver in isolation (#1044).
 *
 * WHY THIS FILE EXISTS. The resolver had six passing tests and the middleware
 * still took out every data-bound block in UiKitShowcase, because the bug was
 * not in either one's logic — it was in a type declaration. `Whity\Core\Response`
 * EXTENDS `Whity\Sdk\Http\Response`, so core's is the SUBCLASS. Plugin handlers
 * return the SDK parent. A middleware that declares `: \Whity\Core\Response`
 * therefore type-errors on every plugin route while every core route keeps
 * working — and it presents as "the plugin is broken", three shards down, with
 * nothing in the middleware's own suite going red.
 *
 * So the assertion that matters here is not what the middleware computes. It is
 * that a plugin-shaped response survives the trip through it.
 */
final class ResolveLanguageMiddlewareRealEngineTest extends TestCase
{
    private PDO $pdo;
    private LanguageRegistry $languages;
    private ResolveLanguage $middleware;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);

        $this->languages = new LanguageRegistry(
            new LanguageRepository($this->pdo),
            new TranslationRepository($this->pdo),
            new StaticTenantContextAdapter(),
        );

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );

        (new GlobalSettingsRepository($this->pdo))->set(SettingsRegistry::I18N_ENABLED, 'true');

        $this->middleware = new ResolveLanguage(
            new RequestLanguageResolver($this->pdo, $this->languages, $settings),
            $this->languages,
        );
    }

    /**
     * THE REGRESSION. A plugin route's response is an `Sdk\Http\Response`, and it
     * must come back out of the middleware as the very same object.
     *
     * Identity rather than equality on purpose: a middleware that quietly rebuilt
     * the response into a core one would satisfy an equality check while dropping
     * whatever a plugin had set on it.
     */
    public function testAPluginShapedResponsePassesThroughUnchanged(): void
    {
        $pluginResponse = new SdkResponse(200, '{"data":[{"name":"Anika Patel"}]}');

        $returned = $this->middleware->handle(
            new Request('GET', '/api/v1/uikit/demo/rows'),
            static fn (): SdkResponse => $pluginResponse,
        );

        self::assertSame(
            $pluginResponse,
            $returned,
            'a middleware typed to the core Response subclass type-errors here, which is what '
            . 'broke every plugin data route while every core route stayed green'
        );
    }

    /** The core shape must keep working too — the fix widens, it does not swap. */
    public function testACoreShapedResponseAlsoPassesThrough(): void
    {
        $coreResponse = new \Whity\Core\Response(200, '{"data":[]}');

        self::assertSame(
            $coreResponse,
            $this->middleware->handle(
                new Request('GET', '/api/v1/users'),
                static fn (): \Whity\Core\Response => $coreResponse,
            )
        );
    }

    /**
     * And it still does its job: the registry is actually left holding the
     * caller's language, not merely left unbroken.
     */
    public function testTheCallersLanguageIsAppliedToTheRegistry(): void
    {
        $request = new Request('GET', '/api/v1/users');
        $request->user = (object) ['profile_id' => $this->profileWithLanguage('ar')];

        $this->middleware->handle($request, static fn (): SdkResponse => new SdkResponse(200, '{}'));

        self::assertSame('ar', $this->languages->getCurrentLanguageCode());
    }

    /** An unauthenticated request leaves the server answering in the source language. */
    public function testAnUnauthenticatedRequestGetsTheSourceLanguage(): void
    {
        $this->middleware->handle(
            new Request('GET', '/api/v1/health'),
            static fn (): SdkResponse => new SdkResponse(200, '{}'),
        );

        self::assertSame(LanguageRegistry::SOURCE_LANGUAGE, $this->languages->getCurrentLanguageCode());
    }

    /** See the sibling resolver suite: `password_hash` is NOT NULL with no default. */
    private function profileWithLanguage(?string $code): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles (display_name, password_hash, language_code, created_at)
             VALUES (:name, :hash, :code, NOW())'
        );
        $stmt->execute([
            ':name' => 'middleware-fixture',
            ':hash' => 'not-a-credential',
            ':code' => $code,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
