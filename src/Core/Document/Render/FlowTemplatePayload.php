<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * Turns a DESIGNER TEMPLATE in document mode into the payload
 * `POST /render/flow` accepts (#1186).
 *
 * WHY THIS HAD TO EXIST AT ALL
 * ----------------------------
 * The designer grew a second mode — blocks one below another, the way a word
 * processor works — and nothing connected it to a renderer. `PrintDocument`
 * iterates `template.pages`, and {@see DocumentRenderer} had no notion of a
 * mode, so a document authored in flow mode printed its CANVAS pages: for a
 * document built entirely in flow mode, the blank starting page. The content
 * was authored, saved, and unprintable, and nothing along the way said so.
 *
 * The flowing render path itself was already built and tested. It was reachable
 * only by plugins, through the SDK's `FlowDocument`. This is the same journey
 * for the designer's own templates.
 *
 * THE STORED SHAPE IS NOT THE WIRE SHAPE, and this is the seam between them.
 * `FlowContent` (packages/ui/src/documents/flow.ts) stores what an author set —
 * a `contents` object when they want a table of contents — while the service
 * takes a `frontMatter` LIST of `{kind}` entries. `FlowDocument::toPayload()`
 * does exactly this translation on the SDK side, so doing it here keeps one
 * pattern rather than inventing a second.
 *
 * That is serialisation, not the mapping layer #1186 rejected. The rejected
 * thing was a richer editor MODEL translated down to the printer's vocabulary,
 * which lets an author build something that cannot print. The blocks here are
 * passed through untouched: they are already the renderer's own vocabulary,
 * which is the whole point of the mode.
 */
final class FlowTemplatePayload
{
    /**
     * Millimetres of margin to fall back on when a template carries none.
     *
     * Matches the canvas template default rather than the render service's, so
     * a document switched between modes keeps the margin its author set on the
     * page. The service has its own default for callers that send nothing; this
     * one exists so the designer never relies on it.
     */
    private const DEFAULT_MARGIN_MM = 10.0;

    /**
     * Does this template want the flowing renderer rather than the canvas one?
     *
     * @param array<string, mixed> $templateData
     */
    public static function isFlowMode(array $templateData): bool
    {
        return ($templateData['mode'] ?? null) === 'flow';
    }

    /**
     * Build the payload.
     *
     * @param array<string, mixed> $templateData The stored DocTemplate tree.
     * @return array<string, mixed> The shape `POST /render/flow` validates.
     *
     * @throws DocumentRenderRejectedException When the document has no content.
     *         Refused HERE rather than by the service so the message names the
     *         document instead of arriving as a relayed 422 about an array —
     *         and because an empty flow template is the exact state a mode
     *         switch leaves behind, so it is worth saying plainly.
     */
    public static function build(array $templateData): array
    {
        $flow = is_array($templateData['flow'] ?? null) ? $templateData['flow'] : [];
        $blocks = is_array($flow['blocks'] ?? null) ? array_values($flow['blocks']) : [];

        if ($blocks === []) {
            throw DocumentRenderRejectedException::because(
                'This document is in document mode but has no content to print'
            );
        }

        $payload = [
            'page' => self::page($templateData['page'] ?? null),
            'content' => $blocks,
        ];

        // Passed through only when stored. The designer has no direction
        // control yet, and writing 'ltr' here would put a value in every
        // payload that the service already defaults to — and would quietly
        // pin an Arabic document to the wrong direction the day one is added.
        $direction = $flow['direction'] ?? null;
        if ($direction === 'rtl' || $direction === 'ltr') {
            $payload['direction'] = $direction;
        }

        $frontMatter = self::frontMatter($flow);
        if ($frontMatter !== []) {
            $payload['frontMatter'] = $frontMatter;
        }

        foreach (['header', 'footer'] as $band) {
            if (is_array($flow[$band] ?? null) && $flow[$band] !== []) {
                $payload[$band] = $flow[$band];
            }
        }

        return $payload;
    }

    /**
     * The page box.
     *
     * A template carries ONE margin for all four sides (the canvas is laid out
     * against a single inset); the service takes four. Spreading the one value
     * is the honest reading — a template that says 10 mm means 10 mm all round —
     * and it is done here rather than left to the service's own default, which
     * is 25/20/25/20 and would silently reflow a document the author had
     * already set margins on.
     *
     * @param mixed $page
     * @return array<string, mixed>
     */
    private static function page(mixed $page): array
    {
        $source = is_array($page) ? $page : [];
        $margin = self::number($source['marginMm'] ?? null) ?? self::DEFAULT_MARGIN_MM;

        return [
            'widthMm' => self::number($source['widthMm'] ?? null) ?? 210.0,
            'heightMm' => self::number($source['heightMm'] ?? null) ?? 297.0,
            'margin' => [
                'topMm' => $margin,
                'rightMm' => $margin,
                'bottomMm' => $margin,
                'leftMm' => $margin,
            ],
        ];
    }

    /**
     * The generated lists, in the order they are printed.
     *
     * Contents, then tables, then figures — the conventional order, and fixed
     * rather than authored because the stored shape has no way to express an
     * order and inventing one here would be a preference nobody set.
     *
     * A `title` is forwarded only when the author gave one; absent, the service
     * fills in its own localised label, which is right for both languages and
     * better than this layer guessing in one of them.
     *
     * @param array<string, mixed> $flow
     * @return list<array<string, mixed>>
     */
    private static function frontMatter(array $flow): array
    {
        $out = [];
        foreach ([
            'contents' => 'contents',
            'listOfTables' => 'tables',
            'listOfFigures' => 'figures',
        ] as $storedKey => $kind) {
            $spec = $flow[$storedKey] ?? null;
            if (!is_array($spec)) {
                continue;
            }

            $entry = ['kind' => $kind];
            if (is_string($spec['title'] ?? null) && $spec['title'] !== '') {
                $entry['title'] = $spec['title'];
            }
            // Only the contents list is depth-limited; a list of tables has no
            // levels to stop at, so forwarding maxLevel there would be a key
            // the service reads and cannot use.
            if ($kind === 'contents') {
                $maxLevel = self::number($spec['maxLevel'] ?? null);
                if ($maxLevel !== null) {
                    $entry['maxLevel'] = (int) $maxLevel;
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
