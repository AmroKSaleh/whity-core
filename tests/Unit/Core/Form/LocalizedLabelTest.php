<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\LocalizedLabel;

/**
 * The `{ar?, en?}` label carried by `forms.name` and `form_fields.label`.
 *
 * Arabic is a first-class requirement on this platform, not an edge case, so the
 * Arabic-only round trip and the unescaped-Unicode encoding are pinned here
 * rather than left to be discovered by an operator running a query against a
 * column full of `ط` sequences.
 */
final class LocalizedLabelTest extends TestCase
{
    public function testABareStringIsReadAsEnglishRatherThanRefused(): void
    {
        // Demanding the object shape would 422 a request that meant something
        // perfectly clear, and push every caller into writing {"en": name}.
        self::assertSame(['en' => 'Equipment request'], LocalizedLabel::fromInput('Equipment request', 'name'));
    }

    public function testAnArabicOnlyLabelIsACompleteLabel(): void
    {
        self::assertSame(['ar' => 'طلب معدات'], LocalizedLabel::fromInput(['ar' => 'طلب معدات'], 'name'));
    }

    public function testAnArabicLabelSurvivesEncodingWithoutBeingEscaped(): void
    {
        $encoded = LocalizedLabel::encode(['ar' => 'طلب معدات', 'en' => 'Equipment request']);

        // Escape sequences decode back correctly and make the column unreadable
        // to whoever queries it — which is the entire reason for
        // JSON_UNESCAPED_UNICODE here and in DocumentRepository::create().
        self::assertStringContainsString('طلب معدات', $encoded);
        self::assertStringNotContainsString('\\u', $encoded);

        self::assertSame(
            ['ar' => 'طلب معدات', 'en' => 'Equipment request'],
            LocalizedLabel::decode($encoded)
        );
    }

    public function testEmptyAndWhitespaceLanguagesAreDroppedRatherThanStored(): void
    {
        self::assertSame(
            ['en' => 'Equipment request'],
            LocalizedLabel::fromInput(['ar' => '   ', 'en' => 'Equipment request'], 'name')
        );
    }

    public function testALabelWithNoLanguageAtAllIsRefused(): void
    {
        // Not a label. Storing it produces a form that renders with no name
        // anywhere and no way to tell why.
        $this->expectException(FormRejectedException::class);
        LocalizedLabel::fromInput(['fr' => 'Demande'], 'name');
    }

    public function testAnEmptyStringIsRefused(): void
    {
        $this->expectException(FormRejectedException::class);
        LocalizedLabel::fromInput('   ', 'name');
    }

    public function testDecodingNeverThrowsOnAColumnThatIsNotJson(): void
    {
        // A row already in the table is a fact, whatever is in the column. A
        // decoder that threw would make one bad row take down the list endpoint —
        // and the form that cannot be read is exactly the one somebody needs to
        // open in order to fix it.
        self::assertSame(['en' => 'legacy plain name'], LocalizedLabel::decode('legacy plain name'));
        self::assertSame([], LocalizedLabel::decode(null));
        self::assertSame([], LocalizedLabel::decode(''));
    }

    public function testPreferredFallsBackThroughEnglishThenArabicThenNothing(): void
    {
        self::assertSame('Equipment request', LocalizedLabel::preferred(['ar' => 'طلب', 'en' => 'Equipment request']));
        self::assertSame('طلب', LocalizedLabel::preferred(['ar' => 'طلب']));
        self::assertSame('', LocalizedLabel::preferred([]));
    }
}
