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
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;

/**
 * Which language a request is answered in, against a real schema (#1044).
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS. `LanguageRegistry` has carried a current
 * language since it was written and NOTHING in production ever set it — only
 * tests. So the server answered every caller in English, and a serving-time
 * translation helper built on `getTranslator()` would have passed its own unit
 * tests, looked right in review, and changed nothing a user sees.
 *
 * WHY REAL REPOSITORIES RATHER THAN MOCKS. The first version of this file mocked
 * `SettingsService`, which is `final` and cannot be doubled — and that refusal
 * was doing the right thing. A mocked settings service and a mocked registry
 * would agree with whatever this test assumed about column names and return
 * shapes, which is the one thing worth checking: the resolver reads
 * `profiles.language_code` and the ENABLED language list, and a test that
 * invented both would pass against code that reads neither.
 *
 * The chain mirrors `LanguageProvider`'s deliberately: a screen answered partly
 * by the server and partly by the browser must not disagree with itself about
 * which language it is in.
 */
final class RequestLanguageResolverRealEngineTest extends TestCase
{
    private PDO $pdo;
    private RequestLanguageResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);

        $languages = new LanguageRegistry(
            new LanguageRepository($this->pdo),
            new TranslationRepository($this->pdo),
            new StaticTenantContextAdapter(),
        );

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );

        $this->resolver = new RequestLanguageResolver($this->pdo, $languages, $settings);
        $this->setFlag('true');
    }

    /**
     * Written through the REPOSITORY, not raw SQL.
     *
     * The first version of this fixture invented a `global_settings` table with
     * a `setting_value` column. The real one is `app_settings(setting_key,
     * value)` — and a mocked settings service would have accepted the invention
     * silently, which is why this suite builds the real thing. Going through
     * `set()` means the fixture cannot drift from the schema at all.
     */
    private function setFlag(string $value): void
    {
        (new GlobalSettingsRepository($this->pdo))->set(SettingsRegistry::I18N_ENABLED, $value);
    }

    /** A profile whose stored preference is `$code` (null = follow the default). */
    private function profileWithLanguage(?string $code): int
    {
        // `password_hash` is NOT NULL with no default — every other column this
        // row needs has one. A placeholder rather than a real hash: nothing here
        // authenticates, and a fixture that looked like a credential would
        // invite somebody to reuse it as one.
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles (display_name, password_hash, language_code, created_at)
             VALUES (:name, :hash, :code, NOW())'
        );
        $stmt->execute([
            ':name' => 'resolver-fixture',
            ':hash' => 'not-a-credential',
            ':code' => $code,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testAProfileWithAnExplicitLanguageGetsIt(): void
    {
        $profileId = $this->profileWithLanguage('ar');

        self::assertSame(
            'ar',
            $this->resolver->resolve($profileId),
            'the whole point: a user who chose Arabic must be answered in Arabic by the SERVER '
            . 'too, not only by the browser'
        );
    }

    public function testAProfileWithNoChoiceGetsTheSourceLanguage(): void
    {
        // NULL is "follow the default" — migration 083's own words, and what the
        // settings screen shows as no explicit choice. Not a missing value, and
        // not a reason to refuse.
        $profileId = $this->profileWithLanguage(null);

        self::assertSame(LanguageRegistry::SOURCE_LANGUAGE, $this->resolver->resolve($profileId));
    }

    public function testAnUnauthenticatedRequestGetsTheSourceLanguage(): void
    {
        // No profile to read a preference from. Guessing from `Accept-Language`
        // would make a public screen disagree with the signed-in one for the
        // same person.
        self::assertSame(LanguageRegistry::SOURCE_LANGUAGE, $this->resolver->resolve(null));
    }

    public function testTheFeatureFlagOverridesTheProfile(): void
    {
        // The flag's whole point: a deployment not ready to ship a second
        // language presents as single-language, whatever any profile says.
        $profileId = $this->profileWithLanguage('ar');
        $this->setFlag('false');

        self::assertSame(LanguageRegistry::SOURCE_LANGUAGE, $this->resolver->resolve($profileId));
    }

    public function testALanguageThatIsNoLongerEnabledDoesNotResurrect(): void
    {
        // The same validation the client does. A code disabled since the user
        // chose it must not come back from the stored preference — otherwise a
        // screen renders half in a language the instance no longer serves.
        $profileId = $this->profileWithLanguage('ar');
        $this->pdo->exec("UPDATE languages SET enabled = " . $this->falseLiteral() . " WHERE code = 'ar'");

        self::assertSame(LanguageRegistry::SOURCE_LANGUAGE, $this->resolver->resolve($profileId));
    }

    public function testAProfileThatNoLongerExistsGetsTheSourceLanguage(): void
    {
        // A stale token naming a deleted profile is a language question with no
        // answer, not a request to refuse: the middleware above never fails a
        // request over vocabulary.
        self::assertSame(LanguageRegistry::SOURCE_LANGUAGE, $this->resolver->resolve(9_999_999));
    }

    /**
     * `false` spelled for whichever engine is under the suite.
     *
     * SQLite has no boolean literal and stores 0/1; PostgreSQL refuses `0` for a
     * boolean column. Writing one of them would pass on the default path and
     * fail every real-engine shard — which is exactly the divergence this suite
     * exists to catch, reintroduced by its own fixture.
     */
    private function falseLiteral(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'false' : '0';
    }
}
