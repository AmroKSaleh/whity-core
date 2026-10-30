<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

/**
 * What blocks publishing a template (E.37; contract `PublishBlocker`):
 * a field the record type doesn't have, and a block without a print view
 * (E.18), which covers plugin blocks until M7 adds their print views and the
 * plugin-off state (E.19). Open suggestions join with M5, when they are stored.
 *
 * The same check runs when the editor opens the dialog and again inside
 * Publish, so a template can't be published past it.
 */
final class DocumentPublishCheck
{
    /**
     * @param list<mixed> $blocks
     * @param array<string, true> $knownPaths The record type's fields.
     * @return list<array{kind: 'unknown-field', path: string}|array{kind: 'block-without-print-view', blockId: string}>
     */
    public static function blockers(array $blocks, array $knownPaths): array
    {
        $out = [];
        $seen = [];
        foreach (WhityDocumentPrinter::fieldRefs($blocks) as $ref) {
            $path = (string) $ref['path'];
            if (!isset($knownPaths[$path]) && !isset($seen[$path])) {
                $seen[$path] = true;
                $out[] = ['kind' => 'unknown-field', 'path' => $path];
            }
        }
        foreach (self::unprintable($blocks) as $blockId) {
            $out[] = ['kind' => 'block-without-print-view', 'blockId' => $blockId];
        }

        return $out;
    }

    /**
     * Ids of blocks, nested ones included, that have no print view.
     *
     * @param list<mixed> $blocks
     * @return list<string>
     */
    private static function unprintable(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if (!in_array($type, WhityDocumentPrinter::PRINTABLE, true)) {
                $ids[] = (string) ($block['id'] ?? '');
                continue;
            }
            if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                array_push($ids, ...self::unprintable($block['children']));
            }
            if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                foreach ($block['columns'] as $column) {
                    if (is_array($column)) {
                        array_push($ids, ...self::unprintable($column));
                    }
                }
            }
        }

        return $ids;
    }
}
