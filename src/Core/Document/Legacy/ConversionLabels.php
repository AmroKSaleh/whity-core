<?php

declare(strict_types=1);

namespace Whity\Core\Document\Legacy;

/**
 * The note a converted template starts with, in the template's own language:
 * the conversion runs in migration 165, with no one signed in, and the person
 * who reads the note is whoever edits the template next. Read from the
 * shipped catalogue (database/i18n), since a migration has no language
 * registry.
 *
 * @i18n-keys documents
 *   convert.note = Converted from the old Document designer. Left out: {items}. Check the layout, then delete this note.
 *   convert.dropped.shape = rectangles and lines ({n})
 *   convert.dropped.image = images with no file here ({n}); the logo is in the letterhead
 *   convert.dropped.savedBlock = saved blocks that no longer exist ({n})
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
        'savedBlock' => 'saved blocks that no longer exist ({n})',
        'running' => 'the running header and footer; the page view has its own',
        'qr' => 'the verification QR code; it is added when a document is issued',
        'symbology' => 'barcodes changed to Code 128 ({n})',
        'other' => 'other elements ({n})',
    ];

    /** @param array<string, array<string, string>> $translations language => key => text */
    public function __construct(private readonly array $translations = [])
    {
    }

    /** The shipped translations of the documents domain, from database/i18n/<lang>/documents.json. */
    public static function fromCatalogue(string $i18nDir): self
    {
        $translations = [];
        foreach (glob(rtrim($i18nDir, '/\\') . '/*/' . self::DOMAIN . '.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && is_string($data['language'] ?? null) && is_array($data['keys'] ?? null)) {
                $translations[$data['language']] = array_filter($data['keys'], 'is_string');
            }
        }

        return new self($translations);
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
        $translated = $this->translations[$lang][$key] ?? null;

        return is_string($translated) && $translated !== '' ? $translated : $english;
    }
}
