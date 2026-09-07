<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFrontendFeatures;
use Whity\Core\Form\PrefillSource;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\SchemaLabels;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationKeyExtractor;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;

/**
 * The forms screens' English is stated twice and may never disagree (#1044).
 *
 * The sibling of `ConveningFeatureLabelsTest`, for the same reason: the wording
 * lives in the declaration where somebody editing the screen will look, and
 * again in the file's `@i18n-keys` block because the extractor reads TEXT, not
 * method bodies. If the two drift, the catalogue seeds one wording and unseeded
 * instances render another, and nothing goes red.
 *
 * THIS FILE CARRIES TWO CHECKS THE CONVENING ONE DOES NOT, because this screen
 * builds two of its pickers from vocabulary classes and falls back to the raw
 * value when a label is missing. That fallback had already fired: `PrefillSource`
 * declared `OU_ID` and the label map did not list it, so the prefill picker
 * offered a literal `profile.ou_id` as a choice. It looked like a translation
 * gap and was not — it was a missing label, showing an internal identifier to
 * whoever was authoring a form.
 */
final class FormFeatureLabelsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function features(): array
    {
        return FormFrontendFeatures::all();
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /** EVERY user-facing string carries a key. */
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
        $fromBlock = $this->stringsFromBlock();

        foreach ($this->stringsFromDeclarations() as $key => $english) {
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

    /** And nothing lingers in the block that no screen uses. */
    public function testTheBlockDeclaresNothingUnused(): void
    {
        $stale = array_diff(
            array_keys($this->stringsFromBlock()),
            array_keys($this->stringsFromDeclarations())
        );

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

    /**
     * NO PICKER OFFERS A RAW IDENTIFIER AS A CHOICE.
     *
     * Both option lists fall back to the value when a label is missing, which
     * fails silently and legibly-wrongly: the screen works, the option is
     * selectable, and it reads `profile.ou_id`. Asserting that no option's label
     * equals its own value catches the whole shape rather than the one instance
     * that had already happened.
     */
    public function testNoOptionLabelIsJustItsValue(): void
    {
        foreach ($this->optionLists() as $name => $options) {
            foreach ($options as $option) {
                self::assertNotSame(
                    $option['value'],
                    $option['label'],
                    "{$name} is showing the raw value `{$option['value']}` as its label — "
                    . 'the vocabulary gained a member that the label map does not list'
                );
            }
        }
    }

    /** Every declared member of both vocabularies is offered, and named. */
    public function testEveryVocabularyMemberIsOffered(): void
    {
        $types = array_column($this->invoke('fieldTypeOptions'), 'value');
        self::assertSame(FieldType::all(), $types, 'every field type must be offered');

        $sources = array_column($this->invoke('prefillSourceOptions'), 'value');
        // The empty option is "do not prefill" and is not a declared source.
        self::assertSame(PrefillSource::all(), array_values(array_filter($sources)));
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    private function optionLists(): array
    {
        return [
            'fieldTypeOptions()' => $this->invoke('fieldTypeOptions'),
            'prefillSourceOptions()' => $this->invoke('prefillSourceOptions'),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function invoke(string $method): array
    {
        $reflected = new \ReflectionMethod(FormFrontendFeatures::class, $method);
        $reflected->setAccessible(true);

        /** @var list<array{value: string, label: string}> $options */
        $options = $reflected->invoke(null);

        return $options;
    }

    /** Localising strips `i18nKey`, so it can never reach a browser. */
    public function testTheKeyPropertyIsNeverServed(): void
    {
        foreach ($this->features() as $feature) {
            $json = (string) json_encode(
                SchemaLabels::localise($feature, SchemaLabels::CORE_DOMAIN, self::labelsWithNothingToOffer())
            );

            self::assertStringNotContainsString(SchemaLabels::KEY_FIELD, $json);
        }
    }

    /**
     * A translator that can find nothing, so every string stays as declared.
     *
     * `ServerLabels` is `final` on purpose; given a registry over an empty
     * database its lookup throws and the helper answers with the declared
     * English, which is the behaviour this assertion wants to observe.
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
     * @return array<string, string>
     */
    private function stringsFromBlock(): array
    {
        $report = (new TranslationKeyExtractor($this->repoRoot()))->extractFiles([
            $this->repoRoot() . '/src/Core/Form/FormFrontendFeatures.php',
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
