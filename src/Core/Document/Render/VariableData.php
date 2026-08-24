<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * The ONE reading of a document's variable data (#947 item 1).
 *
 * A template carries placeholders — `{{reference}}`, `{{date}}` — and both
 * halves of the subsystem have to agree on what a caller's values for them look
 * like:
 *
 *   - {@see DocumentRenderer} needs them to build the render payload's
 *     `dataRows`, and has needed them since ADR 0012.
 *   - `POST /api/documents` needs them to PERSIST, before and independently of
 *     any render — `documents.render_enabled` defaults to false, so a document
 *     is routinely created on an instance that will never render it (migration
 *     118 says why that is the supported case rather than a degraded one).
 *
 * These methods were private to {@see DocumentRenderer}. They are here because
 * the create path has to validate and store the same values the renderer would
 * later interpolate, and the alternative is a second normaliser: one that
 * accepts a row the renderer rejects (so the document persists values that can
 * never be rendered), or rejects one the renderer accepts (so a legal document
 * cannot be created). Either way the disagreement surfaces as "this document
 * will not render" weeks later, with nothing pointing at the two spellings.
 *
 * The renderer still calls these on every render rather than trusting a caller
 * to have normalised first. Normalisation is IDEMPOTENT — a normalised list
 * re-normalises to itself — so the second pass costs nothing and the renderer's
 * contract ("hand me anything, I validate it") is unchanged for the ephemeral
 * preview path, which has no document and no create route in front of it.
 *
 * Stateless, static, no dependencies — worker-safe.
 */
final class VariableData
{
    private function __construct()
    {
    }

    /**
     * Validate + normalise a caller's `dataRows`: a list of flat string=>string
     * maps, one per row/label.
     *
     * Absent (`null`) or empty defaults to a SINGLE row built from the
     * template's placeholder samples, mirroring the designer's own
     * `sampleDataOf()` preview default — a render with no explicit batch still
     * produces one sensible page rather than an empty one.
     *
     * Returns null on a validation failure, and the two callers turn that into
     * their own refusal: the renderer throws {@see DocumentRenderRejectedException}
     * (a 422 with the message it has always used, preserved verbatim because it
     * is a documented body), and the create route answers 422 without writing a
     * row.
     *
     * @param array<string, mixed> $templateData The verbatim client DocTemplate JSON.
     * @return list<array<string, string>>|null
     */
    public static function normalizeRows(mixed $raw, array $templateData): ?array
    {
        if ($raw === null) {
            return [self::samplesOf($templateData)];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        if ($raw === []) {
            return [self::samplesOf($templateData)];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                return null;
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    return null;
                }
                $normalized[$key] = (string) $value;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * The sample-data map built from a template's placeholders (key -> sample),
     * mirroring `sampleDataOf()` in `web/lib/documents/storage.ts`.
     *
     * @param array<string, mixed> $templateData
     * @return array<string, string>
     */
    public static function samplesOf(array $templateData): array
    {
        $out = [];
        $placeholders = $templateData['placeholders'] ?? [];
        if (is_array($placeholders)) {
            foreach ($placeholders as $p) {
                if (is_array($p) && is_string($p['key'] ?? null)) {
                    $out[$p['key']] = (string) ($p['sample'] ?? '');
                }
            }
        }

        return $out;
    }

    /**
     * The placeholder KEYS a template declares, in declaration order.
     *
     * Used by the create route to refuse a value for a placeholder the template
     * does not have. That refusal is not pedantry: `{{refrence}}` typed into a
     * client that sent whatever it was given would be accepted, stored, and
     * render as the literal text `{{reference}}` in the finished document —
     * a document that looks issued and is wrong, discovered by its recipient.
     *
     * @param array<string, mixed> $templateData
     * @return list<string>
     */
    public static function keysOf(array $templateData): array
    {
        return array_keys(self::samplesOf($templateData));
    }
}
