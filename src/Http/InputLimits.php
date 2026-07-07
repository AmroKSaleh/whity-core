<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\Core\Response;

/**
 * Application-layer length limits for free-text input fields (WC input
 * hardening).
 *
 * The request-body middleware caps the whole payload at 1 MiB, but individual
 * free-text fields were otherwise unbounded at the application layer: a value
 * that overflows a VARCHAR(n) column surfaces as a Postgres 22001 → generic
 * 500, and a TEXT column has no bound at all (a single field could absorb the
 * full 1 MiB). This helper gives every write handler one consistent place to
 * enforce a per-field cap and return a clean 422 that names the field — the
 * same intent as the inline check RegisterApiHandler already applied to its
 * own fields.
 *
 * Byte length (strlen) is used deliberately: it is a conservative upper bound
 * that can never under-reject into a column overflow, and it matches the
 * existing RegisterApiHandler precedent. The bundled maxima mirror the two
 * column shapes in the schema — {@see NAME_MAX} for VARCHAR(255) identifiers
 * and {@see TEXT_MAX} for long-form TEXT (notes/descriptions).
 */
final class InputLimits
{
    /** Cap for VARCHAR(255)-backed identifiers: names, emails, slugs, display names. */
    public const NAME_MAX = 255;

    /** Cap for long-form TEXT-backed fields: notes, descriptions. */
    public const TEXT_MAX = 10000;

    /**
     * Validate a set of `label => [value, max]` byte-length limits. Returns a
     * 422 {@see Response} naming the first field that exceeds its cap, or null
     * when every field is within bounds. Null values are skipped (an absent
     * optional field is not a length violation).
     *
     * @param array<string, array{0: ?string, 1: int}> $fields
     */
    public static function firstViolation(array $fields): ?Response
    {
        foreach ($fields as $label => [$value, $max]) {
            if (is_string($value) && strlen($value) > $max) {
                return Response::error(
                    ucfirst($label) . " must be {$max} characters or fewer",
                    422,
                    ['field' => $label]
                );
            }
        }

        return null;
    }
}
