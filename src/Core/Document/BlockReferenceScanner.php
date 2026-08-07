<?php

declare(strict_types=1);

namespace Whity\Core\Document;

/**
 * Collects the set of block ids a template's `data` JSON tree references via
 * `blockInstance` elements (ADR 0012 / WC-docdesigner Track 2 — the render
 * endpoint must resolve every referenced block to its live elements before
 * handing the payload to the `whity_render` service, since the render service
 * has no database access of its own).
 *
 * Mirrors {@see DocumentTemplateRepository::treeReferencesBlock()}'s recursive
 * walk (same shape check: `{type: 'blockInstance', blockId: <id>}`, at any
 * depth/any page/element) but COLLECTS ids instead of testing a single one —
 * deliberately does not assume the exact pages/elements shape, so it stays
 * correct across template-schema changes.
 */
final class BlockReferenceScanner
{
    /**
     * Static utility only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * The unique block ids (as strings — the client's `blockId` field is a
     * string; see `web/lib/documents/types.ts`) referenced anywhere in a
     * decoded template/block JSON tree, in first-seen order.
     *
     * @param array<int|string, mixed> $node A decoded template `data` tree (or any subtree).
     * @return list<string>
     */
    public static function collectBlockIds(array $node): array
    {
        $ids = [];
        self::walk($node, $ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int|string, mixed> $node
     * @param list<string>             $ids
     */
    private static function walk(array $node, array &$ids): void
    {
        if (($node['type'] ?? null) === 'blockInstance' && array_key_exists('blockId', $node)) {
            $ids[] = (string) $node['blockId'];
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::walk($value, $ids);
            }
        }
    }
}
