<?php

declare(strict_types=1);

namespace Whity\Core\Document\Legacy;

use Whity\Core\i18n\LanguageRegistry;

/**
 * The note a converted template starts with, in the template's own language
 * rather than the caller's: the conversion runs from the command line, and
 * the person who reads the note is whoever edits the template next.
 *
 * @i18n-keys documents
 *   convert.note = Converted from the old Document designer. Left out: {items}. Check the layout, then delete this note.
 *   convert.dropped.shape = rectangles and lines ({n})
 *   convert.dropped.image = images with no file here ({n}); the logo is in the letterhead
 *   convert.dropped.savedBlock = saved blocks ({n})
 *   convert.dropped.running = the running header and footer; the page view has its own
 *   convert.dropped.qr = the verification QR code; it is added when a document is issued
 *   convert.dropped.symbology = barcodes changed to Code 128 ({n})
 *   convert.dropped.other = other elements ({n})
 */
final class ConversionLabels
{
    public const DOMAIN = 'documents';

    private const ENGLISH = [
        'shape' => 'rectangles and lines ({n})',
        'image' => 'images with no file here ({n}); the logo is in the letterhead',
        'savedBlock' => 'saved blocks ({n})',
        'running' => 'the running header and footer; the page view has its own',
        'qr' => 'the verification QR code; it is added when a document is issued',
        'symbology' => 'barcodes changed to Code 128 ({n})',
        'other' => 'other elements ({n})',
    ];

    public function __construct(private readonly ?LanguageRegistry $languages = null, private readonly ?int $tenantId = null)
    {
    }

    /** @param array<string, int> $dropped */
    public function note(array $dropped, string $lang): string
    {
        $items = [];
        foreach ($dropped as $kind => $n) {
            $english = self::ENGLISH[$kind] ?? self::ENGLISH['other'];
            $items[] = str_replace('{n}', (string) $n, $this->get($lang, 'convert.dropped.' . (isset(self::ENGLISH[$kind]) ? $kind : 'other'), $english));
        }

        return str_replace(
            '{items}',
            implode($lang === 'ar' ? '؛ ' : '; ', $items),
            $this->get($lang, 'convert.note', 'Converted from the old Document designer. Left out: {items}. Check the layout, then delete this note.'),
        );
    }

    private function get(string $lang, string $key, string $english): string
    {
        if ($this->languages === null || $lang === 'en') {
            return $english;
        }
        try {
            $translated = $this->languages->translate($lang, self::DOMAIN, $key, $this->tenantId);
        } catch (\Throwable) {
            return $english;
        }

        return $translated === $key ? $english : $translated;
    }
}
