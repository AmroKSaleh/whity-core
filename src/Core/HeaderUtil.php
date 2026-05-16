<?php

namespace Whity\Core;

/**
 * HTTP header normalization utility
 *
 * Provides static methods for normalizing header names to a consistent format.
 * All headers are normalized to lowercase with hyphens as separators.
 */
class HeaderUtil
{
    /**
     * Normalize a header name to a consistent format (lowercase with hyphens)
     *
     * Converts underscores to hyphens and converts to lowercase for consistent
     * header key storage and retrieval.
     *
     * @param string $name Header name (e.g., 'Content-Type', 'content_type', 'CONTENT_TYPE')
     * @return string Normalized header name (e.g., 'content-type')
     */
    public static function normalize(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }
}
