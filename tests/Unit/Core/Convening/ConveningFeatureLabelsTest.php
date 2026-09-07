<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Convening;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Convening\ConveningFeatures;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\SchemaLabels;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationKeyExtractor;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Router;
use Whity\Core\Tenant\StaticTenantContextAdapter;

/**
 * The convening screens' English is stated twice and may never disagree (#1044).
 *
 * WHY IT IS STATED TWICE AT ALL. The wording lives in the declaration, beside
 * the field it belongs to, because that is where somebody editing the screen
 * will look. It lives again in the file's `@i18n-keys` block because the
 * extractor reads TEXT, not method bodies — it cannot call
 * `ConveningFeatures::all()` and would not be a static analyser if it did.
 *
 * WHAT GOES WRONG WITHOUT THIS FILE, and it is quiet rather than loud: the
 * catalogue seeds one wording, the declaration renders another on any instance
 * where the key is unseeded, and both look right in isolation. Nothing fails.
 * A translator translates a sentence no user will ever see.
 *
 * So all three are compared against each other — the declarations, the block,
 * and the committed catalogue — and the completeness check below is the one
 * that matters most, because it is the only one that catches the failure that
 * created this whole issue: text added to a screen with no key at all.
 */
final class ConveningFeatureLabelsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function features(): array
    {
        return ConveningFeatures::all(new Router('/v1'));
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * EVERY user-facing string carries a key.
     *
     * The guard against the original failure. Adding a field is routine;
     * remembering it needs a key is not, and forgetting leaves one string
     * English in every language forever with nothing going red.
     */
    public function testNoUserFacingStringIsWithoutAKey(): void
    {
        $orphans = [];

        foreach ($this->features() as $feature) {
            foreach (SchemaLabels::unkeyed($feature) as $problem) {
                $orphans[] = sprintf(
                    '%s / %s.%s = %s',
                    (string) ($feature['id'] ?? '?'),
                    $problem['path'],
                    $problem['field'],
                    $problem['text']
                );
            }
        }

        self::assertSame(
            [],
            $orphans,
            "these strings would stay English in every language — give each node an "
            . "'i18nKey' and add the wording to the @i18n-keys block:\n  "
            . implode("\n  ", $orphans)
        );
    }

    /** The declarations and the `@i18n-keys` block agree, word for word. */
    public function testTheDeclaredEnglishMatchesTheBlock(): void
    {
        $fromCode = $this->stringsFromDeclarations();
        $fromBlock = $this->stringsFromBlock();

        foreach ($fromCode as $key => $english) {
            self::assertArrayHasKey(
                $key,
                $fromBlock,
                "`{$key}` is declared by a screen but missing from the @i18n-keys block"
            );
            self::assertSame(
                self::flatten($english),
                $fromBlock[$key],
                "the screen and the @i18n-keys block disagree about `{$key}`"
            );
        }
    }

    /**
     * And nothing lingers in the block that no screen uses.
     *
     * The other direction, which matters because a stale key is not harmless:
     * it is a string a translator spends time on and no user ever sees.
     */
    public function testTheBlockDeclaresNothingUnused(): void
    {
        $fromCode = $this->stringsFromDeclarations();
        $stale = array_diff(array_keys($this->stringsFromBlock()), array_keys($fromCode));

        self::assertSame([], array_values($stale), 'these keys are declared but no screen uses them');
    }

    /** The committed catalogue carries them, so `i18n:sync` can seed them. */
    public function testTheCommittedCatalogueCarriesEveryKey(): void
    {
        $catalogue = (new TranslationCatalog($this->repoRoot()))->read()[SchemaLabels::CORE_DOMAIN] ?? [];
        $missing = array_diff(array_keys($this->stringsFromDeclarations()), array_keys($catalogue));

        self::assertSame(
            [],
            array_values($missing),
            'run `php bin/whity-cli i18n:extract` and commit the result'
        );
    }

    /** Localising strips `i18nKey`, so it can never reach a browser. */
    public function testTheKeyPropertyIsNeverServed(): void
    {
        foreach ($this->features() as $feature) {
            $json = (string) json_encode(
                SchemaLabels::localise($feature, SchemaLabels::CORE_DOMAIN, self::labelsWithNothingToOffer())
            );

            self::assertStringNotContainsString(
                SchemaLabels::KEY_FIELD,
                $json,
                'the wire shape must be exactly what it was before this existed'
            );
        }
    }

    /**
     * A translator that can find nothing, so every string stays as declared.
     *
     * `ServerLabels` is `final` and cannot be doubled — deliberately. Given a
     * registry over an empty database its lookup throws, which the helper
     * catches and answers with the declared English. That is precisely the
     * behaviour this test wants to observe, so a real object over an empty
     * schema is a better stand-in than a mock would have been.
     */
    private static function labelsWithNothingToOffer(): ServerLabels
    {
        $pdo = new PDO('sqlite::memory:');

        return new ServerLabels(new LanguageRegistry(
            new LanguageRepository($pdo),
            new TranslationRepository($pdo),
            new StaticTenantContextAdapter(),
        ));
    }

    /** @return array<string, string> */
    private function stringsFromDeclarations(): array
    {
        $all = [];

        foreach ($this->features() as $feature) {
            foreach (SchemaLabels::declaredStrings($feature) as $key => $text) {
                $all[$key] = $text;
            }
        }

        return $all;
    }

    /**
     * The `@i18n-keys` block, read back through the REAL extractor.
     *
     * Not a hand-rolled parse: if this test read the block differently from the
     * tool that seeds the catalogue, it would be checking something nothing uses.
     *
     * @return array<string, string>
     */
    private function stringsFromBlock(): array
    {
        $report = (new TranslationKeyExtractor($this->repoRoot()))->extractFiles([
            $this->repoRoot() . '/src/Core/Convening/ConveningFeatures.php',
        ]);

        self::assertSame([], $report['problems'], 'the @i18n-keys block must be well formed');

        return $report['catalog'][SchemaLabels::CORE_DOMAIN] ?? [];
    }

    /** The block is one line per key; a declaration may wrap across several. */
    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
