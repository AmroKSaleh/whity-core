<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Routing\RoutingRuleLabels;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationKeyExtractor;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;

/**
 * Rule-kind labels answer in the caller's language (#1044).
 *
 * WHAT WAS ACTUALLY BROKEN. These labels are declared in PHP and shipped inside
 * `GET /api/v1/routing-rules` as finished English, so no amount of client-side
 * i18n reached them: an Arabic flow editor rendered "Everyone holding a role"
 * in English, next to Arabic everywhere else. Mixed like that it reads as
 * broken rather than as untranslated, which is why #1044 is its own issue and
 * not a footnote on the Arabic sweep.
 *
 * THE ASSERTION THAT MATTERS reads Arabic back out of the serving path without
 * seeding anything itself — migration 121 seeds the committed catalogues, so the
 * whole chain the product uses is under test. Everything else here (that a key
 * exists, that the catalogue carries it) can be true while the screen is still
 * English, which is exactly the state this issue describes.
 */
final class RoutingRuleLabelsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private LanguageRegistry $languages;
    private ServerLabels $labels;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);

        $this->languages = new LanguageRegistry(
            new LanguageRepository($this->pdo),
            new TranslationRepository($this->pdo),
            new StaticTenantContextAdapter(),
        );

        $this->labels = new ServerLabels($this->languages);
    }

    /** The committed Arabic for a key, straight from `database/i18n/ar/`. */
    private function committedArabic(string $key): string
    {
        $locale = (new TranslationCatalog($this->repoRoot()))->readLocale('ar');
        $value = $locale[RoutingRuleLabels::DOMAIN][$key] ?? null;

        self::assertIsString($value, "database/i18n/ar/documents.json is missing `{$key}`");

        return $value;
    }

    /**
     * THE POINT OF THE WHOLE ISSUE.
     */
    public function testAnArabicCallerGetsArabicRuleLabels(): void
    {
        // Nothing is hand-seeded here on purpose. Migration 121 seeds the
        // committed catalogues, so this exercises the whole chain the product
        // uses — `database/i18n/ar/documents.json` -> migration -> translations
        // table -> serving — rather than a row this test invented and then
        // proved it could read back.
        $this->languages->setCurrentLanguage('ar');

        $localised = RoutingRuleLabels::localise(
            [['kind' => 'role', 'label' => 'Everyone holding a role', 'source' => 'core']],
            $this->labels
        );

        self::assertSame($this->committedArabic('routing.rule.kind.role'), $localised[0]['label']);
        self::assertNotSame('Everyone holding a role', $localised[0]['label']);
    }

    /**
     * A MISS RENDERS THE ENGLISH, NEVER THE KEY.
     *
     * `LanguageRegistry::translate()` returns the key when it finds nothing,
     * which is right for a screen and wrong here: these keys ship alongside code
     * that already carries the English, so an unseeded instance must look
     * exactly like today rather than showing `routing.rule.kind.role` to a user.
     */
    public function testAnUnseededKeyFallsBackToTheDeclaredEnglish(): void
    {
        $this->languages->setCurrentLanguage('ar');

        // A key nothing has ever seeded. Asserted through the helper directly
        // because every real rule key IS seeded — which is the point, and also
        // means the catalogue cannot demonstrate a miss.
        self::assertSame(
            'Everyone holding a role',
            $this->labels->label(
                RoutingRuleLabels::DOMAIN,
                'routing.rule.kind.never_seeded_probe',
                'Everyone holding a role'
            )
        );
    }

    /**
     * A PLUGIN'S OWN KIND IS LEFT ALONE.
     *
     * Its wording belongs to the plugin's catalogue domain, and its namespaced
     * kind (`acme:committee`) is not even a legal key. Passing it through is the
     * boundary, not an oversight.
     */
    public function testAPluginKindPassesThroughUntouched(): void
    {
        $this->languages->setCurrentLanguage('ar');

        $localised = RoutingRuleLabels::localise(
            [['kind' => 'acme:committee', 'label' => 'The steering committee', 'source' => 'acme']],
            $this->labels
        );

        self::assertSame('The steering committee', $localised[0]['label']);
        self::assertNull(RoutingRuleLabels::keyFor('acme:committee'));
    }

    /** Shape-preserving: same rows, same order, same fields. */
    public function testLocalisingPreservesTheCatalogueShape(): void
    {
        $catalogue = [
            ['kind' => 'explicit', 'label' => 'Specific people, chosen by name', 'source' => 'core'],
            ['kind' => 'group', 'label' => 'Everyone in a user group', 'source' => 'core'],
        ];

        $localised = RoutingRuleLabels::localise($catalogue, $this->labels);

        self::assertSame(array_column($catalogue, 'kind'), array_column($localised, 'kind'));
        self::assertSame(array_column($catalogue, 'source'), array_column($localised, 'source'));
        self::assertCount(2, $localised);
    }

    /**
     * THE DUPLICATION GUARD.
     *
     * The English exists twice — in the resolver that owns the kind, and in the
     * `@i18n-keys` block that feeds the catalogue — because the extractor reads
     * text, not method bodies. If the two drift, the catalogue seeds one wording
     * and unseeded instances render the other, with nothing going red. So the
     * declaration is read back through the real extractor and compared.
     */
    public function testTheDeclaredEnglishMatchesEveryResolver(): void
    {
        $registry = new RoutingRuleRegistry();
        $declared = $this->declaredEnglish();

        foreach (RoutingRuleLabels::CORE_KINDS as $kind) {
            $key = RoutingRuleLabels::keyFor($kind);
            self::assertNotNull($key, "core kind `{$kind}` must have a key");
            self::assertArrayHasKey(
                $key,
                $declared,
                "core kind `{$kind}` is missing from the @i18n-keys block in RoutingRuleLabels"
            );
        }

        // The registry's own wording, for the kinds it has resolvers for.
        foreach ($registry->catalogue() as $entry) {
            $key = RoutingRuleLabels::keyFor($entry['kind']);
            if ($key === null) {
                continue;
            }

            self::assertSame(
                $entry['label'],
                $declared[$key],
                "the @i18n-keys block and the resolver disagree about `{$entry['kind']}`"
            );
        }
    }

    /** The committed English catalogue carries the keys, so `i18n:sync` can seed them. */
    public function testTheCommittedCatalogueCarriesEveryRuleKey(): void
    {
        $catalogue = (new TranslationCatalog($this->repoRoot()))->read()[RoutingRuleLabels::DOMAIN] ?? [];

        foreach (RoutingRuleLabels::CORE_KINDS as $kind) {
            self::assertArrayHasKey(
                (string) RoutingRuleLabels::keyFor($kind),
                $catalogue,
                'run `php bin/whity-cli i18n:extract` and commit the result'
            );
        }
    }

    /**
     * The `@i18n-keys` block, read back through the real extractor.
     *
     * @return array<string, string>
     */
    private function declaredEnglish(): array
    {
        $report = (new TranslationKeyExtractor($this->repoRoot()))->extractFiles([
            $this->repoRoot() . '/src/Core/Document/Routing/RoutingRuleLabels.php',
        ]);

        self::assertSame([], $report['problems'], 'the declaration block itself must be well formed');

        return $report['catalog'][RoutingRuleLabels::DOMAIN] ?? [];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
