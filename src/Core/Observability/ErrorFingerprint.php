<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use Throwable;

/**
 * Groups occurrences of the same bug under one stable key (WC-error-tracking).
 *
 * The fingerprint decides what counts as "the same error", which is the entire
 * value of an inbox: too coarse and unrelated bugs pile into one row, too fine
 * and one bug becomes a thousand rows and the counter means nothing.
 *
 * It hashes the exception CLASS, the throw SITE (file + line) and a NORMALISED
 * message. Normalising matters more than it looks — messages routinely embed
 * ids, timestamps and values ("User 4192 not found"), and hashing those raw
 * would make every occurrence its own group and defeat the grouping entirely.
 */
final class ErrorFingerprint
{
    /**
     * Value shapes replaced with a placeholder before hashing, so occurrences
     * that differ only by data collapse together.
     *
     * @var array<string, string>
     */
    private const NORMALISERS = [
        '#\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b#i' => '<uuid>',
        '#\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\S*#' => '<timestamp>',
        '#\b[A-Fa-f0-9]{16,}\b#' => '<hash>',
        '#\b\d+\b#' => '<n>',
        '#"[^"]*"#' => '"<v>"',
        "#'[^']*'#" => "'<v>'",
    ];

    public static function of(Throwable $e): string
    {
        return self::fromParts($e::class, $e->getMessage(), $e->getFile(), $e->getLine());
    }

    public static function fromParts(string $type, string $message, string $file, int $line): string
    {
        $normalised = $message;
        foreach (self::NORMALISERS as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $normalised);
            if (is_string($result)) {
                $normalised = $result;
            }
        }

        return hash('sha256', $type . '|' . $file . '|' . $line . '|' . $normalised);
    }
}
