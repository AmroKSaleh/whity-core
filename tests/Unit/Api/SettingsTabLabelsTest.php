<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Api\SettingsApiHandler;
use Whity\Core\i18n\SchemaLabels;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationKeyExtractor;

/**
 * Every settings tab has a translated name, and the wording is stated once (#1044).
 *
 * WHY THE KEY IS DERIVED HERE AND DECLARED ELSEWHERE. A tab id (`branding`,
 * `sso`) is already a stable slug, so the key comes from it and a new tab
 * arrives carrying its own — no annotation on the declaration, unlike the
 * schema-driven screens where a submit button has no identifier at all.
 *
 * What still has to be written by hand is the ENGLISH, in the `@i18n-keys`
 * block, because the extractor reads text and cannot evaluate a constant. This
 * file is what stops the two drifting: a tab added without a line in that block
 * would serve its declared English forever, in every language, with nothing
 * going red.
 */
final class SettingsTabLabelsTest extends TestCase
{
    private const PREFIX = 'settings.tab.';

    /** @return list<array{id: string, label: string}> */
    private function tabs(): array
    {
        /** @var list<array{id: string, label: string}> $tabs */
        $tabs = (new ReflectionClass(SettingsApiHandler::class))->getConstant('TABS');

        return $tabs;
    }

    /** The key a tab's name is looked up under. Mirrors the handler's own rule. */
    private static function keyFor(string $id): string
    {
        return self::PREFIX . str_replace('-', '_', $id);
    }

    /** EVERY tab is declared, with the wording the code actually serves. */
    public function testEveryTabIsDeclaredWithItsOwnEnglish(): void
    {
        $declared = $this->stringsFromBlock();

        foreach ($this->tabs() as $tab) {
            $key = self::keyFor($tab['id']);

            self::assertArrayHasKey(
                $key,
                $declared,
                "tab `{$tab['id']}` has no line in the @i18n-keys block, so its name would "
                . 'stay English in every language'
            );
            self::assertSame(
                $tab['label'],
                $declared[$key],
                "the tab declaration and the @i18n-keys block disagree about `{$tab['id']}`"
            );
        }
    }

    /** And nothing lingers in the block for a tab that no longer exists. */
    public function testTheBlockDeclaresNoTabThatIsGone(): void
    {
        $live = array_map(fn (array $tab): string => self::keyFor($tab['id']), $this->tabs());
        $stale = array_diff(array_keys($this->stringsFromBlock()), $live);

        self::assertSame([], array_values($stale), 'these keys name tabs that no longer exist');
    }

    /**
     * A KEY MAY NOT CONTAIN A HYPHEN, and three tab ids do.
     *
     * `email-domains`, `feature-flags` and `error-tracking` are ids that appear
     * in URLs and in every client that filters on them, so the id stays and the
     * KEY is underscored. Asserting the shape rather than the three names keeps
     * this true for the next hyphenated tab.
     */
    public function testEveryDerivedKeyIsWellFormed(): void
    {
        foreach ($this->tabs() as $tab) {
            self::assertTrue(
                TranslationKeyExtractor::isValidKey(self::keyFor($tab['id'])),
                "the key derived from tab `{$tab['id']}` is not a valid translation key"
            );
        }
    }

    /** The committed catalogue carries them, so `i18n:sync` can seed them. */
    public function testTheCommittedCatalogueCarriesEveryTab(): void
    {
        $catalogue = (new TranslationCatalog($this->repoRoot()))->read()[SchemaLabels::CORE_DOMAIN] ?? [];

        foreach ($this->tabs() as $tab) {
            self::assertArrayHasKey(
                self::keyFor($tab['id']),
                $catalogue,
                'run `php bin/whity-cli i18n:extract` and commit the result'
            );
        }
    }

    /**
     * The `@i18n-keys` block, read back through the REAL extractor.
     *
     * @return array<string, string>
     */
    private function stringsFromBlock(): array
    {
        $report = (new TranslationKeyExtractor($this->repoRoot()))->extractFiles([
            $this->repoRoot() . '/src/Api/SettingsApiHandler.php',
        ]);

        self::assertSame([], $report['problems'], 'the @i18n-keys block must be well formed');

        $all = $report['catalog'][SchemaLabels::CORE_DOMAIN] ?? [];

        return array_filter(
            $all,
            static fn (string $key): bool => str_starts_with($key, self::PREFIX),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
