<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

/**
 * Scope 3 of #1036: WHERE the verification code sits on the page, and what
 * happens when the answer is "nobody said".
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE
 * -------------------------------------
 * "Enabled" must never silently do nothing. If the tenant switch and the
 * template both say a document carries a verification code, the rendered
 * artifact HAS one — whether or not the author remembered to place the element.
 * A document that claims to be tracked and is not is worse than one that never
 * claimed it: somebody photographs it, scans nothing, and concludes the
 * organisation's verification does not work.
 *
 * So {@see compose()} either finds an authored placement or supplies one, and
 * the caller is told which — never left to assume.
 *
 * The converse is enforced too, and it is the half that is easy to forget. When
 * the code is OFF, an authored placement is REMOVED rather than left to resolve
 * to nothing. The designer's QR element falls back to its literal `value` when
 * its binding resolves to nothing, and a verification element's literal value is
 * empty, so leaving it in place would print a dashed "no value" box in the
 * corner of every document belonging to a tenant that switched the feature OFF.
 *
 * HOW AN ELEMENT SAYS "I AM THE VERIFICATION CODE"
 * ------------------------------------------------
 * By its BINDING, not by a new element type. The designer already has a `qr`
 * element with `value` + `binding` (`packages/ui/src/documents/types.ts`), and
 * the render harness already resolves a binding against the row's data. So the
 * verification code is a `qr` element bound to the reserved key
 * {@see VERIFICATION_BINDING}, and the value is injected into every data row by
 * {@see rowsWith()}.
 *
 * This deliberately does NOT add an element type. A new type would mean the
 * insert menu, the layer palette, the inspector, the exhaustiveness switch in
 * `element-content.tsx`, the desktop twin and the Storybook fixtures — for a
 * thing that is a QR element with a known payload. The reserved binding is the
 * same mechanism a serialized device label already uses, which is the closest
 * existing precedent for variable data in this designer.
 *
 * MILLIMETRES, BECAUSE THE PAGE IS
 * --------------------------------
 * Every number here is millimetres on a print-accurate page (`PageSpec`), not
 * pixels and not a fraction of the page. A QR's scannability is a physical
 * property — module size in millimetres against a phone camera — so a
 * proportional default would produce an unscannable code on a small label and a
 * comically large one on A3.
 */
final class QrTemplateComposer
{
    /**
     * The reserved placeholder key the verification code binds to.
     *
     * Dotted, so it cannot collide with a template's own placeholders: the
     * designer's placeholder keys come from the `placeholders` array an author
     * types, and `document.` reads as a namespace rather than a field somebody
     * would name by accident. The render harness's interpolation regex already
     * admits dots (`/\{\{\s*([\w.-]+)\s*\}\}/`), so the same key also works in a
     * `dynamicText` element for the printed reference beneath the code.
     */
    public const VERIFICATION_BINDING = 'document.verification_url';

    /** The human-readable reference printed under the default placement. */
    public const REFERENCE_BINDING = 'document.verification_reference';

    /**
     * Element ids for the supplied default placement.
     *
     * DETERMINISTIC rather than random, so re-rendering the same document
     * produces the same template JSON and a diff of two renders shows only what
     * actually changed. Namespaced with the platform name because the id space
     * is shared with whatever the author drew.
     */
    public const DEFAULT_QR_ELEMENT_ID = 'whity-verification-qr';
    public const DEFAULT_REFERENCE_ELEMENT_ID = 'whity-verification-ref';

    /**
     * The default code is 20mm square.
     *
     * A 64-character token inside an absolute URL is roughly 105 characters,
     * which is a version-6 QR (41x41 modules) at the 'M' error-correction level.
     * At 20mm that is ~0.49mm per module — comfortably readable by a phone
     * camera at arm's length under office light. 15mm would be ~0.36mm, which
     * works in good light and fails on a photocopy, and a verification code that
     * only scans off the original is not much of a verification code.
     *
     * 'M' rather than 'L': L would shrink the symbol by a version but paper gets
     * folded, stamped and photocopied, and 15% damage tolerance is the standard
     * choice for print for exactly that reason.
     */
    public const DEFAULT_SIZE_MM = 20.0;

    /** Height of the printed reference caption under the code. */
    private const REFERENCE_HEIGHT_MM = 4.0;

    /** Gap between the code and its caption. */
    private const REFERENCE_GAP_MM = 1.0;

    /**
     * The smallest code this class will place unattended.
     *
     * Below this the symbol is decorative rather than scannable, and printing a
     * decorative one would be the same silent failure the class exists to
     * prevent — so a page too small to carry a real code gets NO code and the
     * caller is told, rather than a code nobody can read.
     */
    private const MIN_SIZE_MM = 12.0;

    private function __construct()
    {
    }

    /**
     * Resolve scope 3 for one render.
     *
     * @param array<string, mixed> $templateData The template's `data` JSON.
     * @param bool $enabled Scopes 1 and 2, already composed by {@see DocumentQrPolicy::enabled()}.
     * @return array{data: array<string, mixed>, placed: bool, supplied: bool}
     *         `placed` is whether the rendered artifact will carry a code at all;
     *         `supplied` is whether this class had to put it there. A caller
     *         reporting to a client needs both: "on and authored", "on and
     *         defaulted" and "on but the page is too small" are three different
     *         things and only the last is a problem.
     */
    public static function compose(array $templateData, bool $enabled): array
    {
        if (!$enabled) {
            return [
                'data' => self::withoutVerificationElements($templateData),
                'placed' => false,
                'supplied' => false,
            ];
        }

        if (self::hasVerificationElement($templateData)) {
            return ['data' => $templateData, 'placed' => true, 'supplied' => false];
        }

        $defaulted = self::withDefaultPlacement($templateData);

        return [
            'data' => $defaulted,
            'placed' => $defaulted !== $templateData,
            'supplied' => $defaulted !== $templateData,
        ];
    }

    /**
     * The values the verification elements resolve against, merged into every
     * data row.
     *
     * EVERY row, not the first: a label sheet is one document of N rows and each
     * printed unit is a physical thing somebody may pick up on its own. A code
     * on only the first would make the rest of the sheet unverifiable while
     * looking exactly like the part that is.
     *
     * Reserved keys are written LAST so a template that happens to declare a
     * placeholder called `document.verification_url` cannot overwrite the real
     * one with sample text. The dotted namespace makes that collision very
     * unlikely; letting the author win it anyway would be a way to print a QR
     * pointing wherever they liked.
     *
     * @param list<array<string, string>> $rows
     * @return list<array<string, string>>
     */
    public static function rowsWith(array $rows, string $verificationUrl, string $reference): array
    {
        $reserved = [
            self::VERIFICATION_BINDING => $verificationUrl,
            self::REFERENCE_BINDING => $reference,
        ];

        return array_map(
            static fn (array $row): array => array_merge($row, $reserved),
            $rows,
        );
    }

    /**
     * Whether any page already carries an authored verification code.
     *
     * Looks at BINDING only. An element whose binding is the reserved key is the
     * verification code wherever it sits and whatever else is set on it, which
     * is what makes "the author placed one" a question with one answer.
     *
     * @param array<string, mixed> $templateData
     */
    public static function hasVerificationElement(array $templateData): bool
    {
        foreach (self::pagesOf($templateData) as $page) {
            foreach (self::elementsOf($page) as $element) {
                if (self::isVerificationElement($element)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The template with every verification element removed.
     *
     * @param array<string, mixed> $templateData
     * @return array<string, mixed>
     */
    public static function withoutVerificationElements(array $templateData): array
    {
        $pages = self::pagesOf($templateData);
        if ($pages === []) {
            return $templateData;
        }

        $changed = false;
        foreach ($pages as $index => $page) {
            $elements = self::elementsOf($page);
            $kept = array_values(array_filter(
                $elements,
                static fn (mixed $element): bool => !self::isVerificationElement($element),
            ));
            if (count($kept) !== count($elements)) {
                $changed = true;
                $page['elements'] = $kept;
                $pages[$index] = $page;
            }
        }

        if (!$changed) {
            return $templateData;
        }

        $templateData['pages'] = array_values($pages);

        return $templateData;
    }

    /**
     * The template with a code (and its printed reference) added to the LAST
     * page, in the bottom-right corner inside the page margin.
     *
     * THE LAST PAGE, because that is where a signature block, a stamp and a
     * footer already live on every official document this feature is for: it is
     * the page somebody photographs to prove they have the whole thing.
     *
     * THE BOTTOM-RIGHT CORNER, and NOT mirrored for right-to-left documents.
     * That is a deliberate stop rather than an oversight. A template records no
     * layout direction — `PageSpec` is width, height, margin and background —
     * so the only direction available is the TENANT'S UI LANGUAGE, and keying
     * placement to it would silently move the code on every existing document
     * the day somebody switched their interface to Arabic. A default that moves
     * under you is worse than one that is occasionally on the wrong side, and
     * the author can move it in one drag. (The RTL requirement in #1036 is about
     * the public verification PAGE, which is a different surface and is
     * direction-aware there.)
     *
     * If the last page is too small to carry a scannable code the template is
     * returned UNCHANGED, so the caller's `placed` comes back false and the
     * absence is reported rather than papered over with an unreadable symbol.
     *
     * @param array<string, mixed> $templateData
     * @return array<string, mixed>
     */
    public static function withDefaultPlacement(array $templateData): array
    {
        $pages = self::pagesOf($templateData);
        if ($pages === []) {
            return $templateData;
        }

        $page = $templateData['page'] ?? [];
        $pageWidth = is_array($page) ? (float) ($page['widthMm'] ?? 0.0) : 0.0;
        $pageHeight = is_array($page) ? (float) ($page['heightMm'] ?? 0.0) : 0.0;
        $margin = is_array($page) ? max(0.0, (float) ($page['marginMm'] ?? 0.0)) : 0.0;

        if ($pageWidth <= 0.0 || $pageHeight <= 0.0) {
            return $templateData;
        }

        // Shrink to fit rather than overflow: a 40x30mm label gets a smaller
        // code, and a page that cannot hold MIN_SIZE_MM gets none at all.
        $available = min($pageWidth - (2 * $margin), $pageHeight - (2 * $margin));
        $size = min(self::DEFAULT_SIZE_MM, $available);
        if ($size < self::MIN_SIZE_MM) {
            return $templateData;
        }

        // The caption is dropped rather than allowed to push the code off the
        // page — it is a convenience for reading the reference aloud, and the
        // code is the thing that has to be there.
        $captionFits = ($size + self::REFERENCE_GAP_MM + self::REFERENCE_HEIGHT_MM)
            <= ($pageHeight - (2 * $margin));
        $blockHeight = $captionFits
            ? $size + self::REFERENCE_GAP_MM + self::REFERENCE_HEIGHT_MM
            : $size;

        $x = $pageWidth - $margin - $size;
        $y = $pageHeight - $margin - $blockHeight;

        $lastIndex = array_key_last($pages);
        $lastPage = $pages[$lastIndex];
        $elements = self::elementsOf($lastPage);
        $z = self::topZ($elements) + 1;

        $elements[] = [
            'id' => self::DEFAULT_QR_ELEMENT_ID,
            'type' => 'qr',
            'x' => $x,
            'y' => $y,
            'w' => $size,
            'h' => $size,
            'rotation' => 0,
            'z' => $z,
            // The literal value stays EMPTY. The binding is the payload, and a
            // literal fallback here would be a QR that silently pointed
            // somewhere else the day the binding failed to resolve.
            'value' => '',
            'binding' => self::VERIFICATION_BINDING,
            'eclevel' => 'M',
        ];

        if ($captionFits) {
            $elements[] = [
                'id' => self::DEFAULT_REFERENCE_ELEMENT_ID,
                'type' => 'dynamicText',
                'x' => $x,
                'y' => $y + $size + self::REFERENCE_GAP_MM,
                'w' => $size,
                'h' => self::REFERENCE_HEIGHT_MM,
                'rotation' => 0,
                'z' => $z + 1,
                'template' => '{{' . self::REFERENCE_BINDING . '}}',
                'style' => [
                    'fontSize' => 6,
                    'fontWeight' => 'normal',
                    'fontStyle' => 'normal',
                    'align' => 'center',
                    'vAlign' => 'middle',
                    'color' => '#000000',
                    // The reference is a Latin-alphanumeric code in every
                    // language, so it is pinned LTR rather than left to the
                    // paragraph-direction heuristic, which would otherwise
                    // reorder it inside an Arabic document.
                    'direction' => 'ltr',
                ],
            ];
        }

        $lastPage['elements'] = array_values($elements);
        $pages[$lastIndex] = $lastPage;
        $templateData['pages'] = array_values($pages);

        return $templateData;
    }

    /**
     * @param array<string, mixed> $templateData
     * @return array<int, array<string, mixed>>
     */
    private static function pagesOf(array $templateData): array
    {
        $pages = $templateData['pages'] ?? null;
        if (!is_array($pages)) {
            return [];
        }

        $out = [];
        foreach (array_values($pages) as $index => $page) {
            if (is_array($page)) {
                $out[$index] = $page;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $page
     * @return list<mixed>
     */
    private static function elementsOf(array $page): array
    {
        $elements = $page['elements'] ?? null;

        return is_array($elements) ? array_values($elements) : [];
    }

    private static function isVerificationElement(mixed $element): bool
    {
        return is_array($element)
            && ($element['binding'] ?? null) === self::VERIFICATION_BINDING;
    }

    /**
     * @param list<mixed> $elements
     */
    private static function topZ(array $elements): int
    {
        $top = 0;
        foreach ($elements as $element) {
            if (is_array($element) && is_numeric($element['z'] ?? null)) {
                $top = max($top, (int) $element['z']);
            }
        }

        return $top;
    }
}
