<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use RuntimeException;

/**
 * A `plugin.json` that can't be read or doesn't pass {@see ManifestValidator}.
 *
 * Carries every error rather than the first, because a manifest is usually
 * fixed in one sitting and a person (or an agent) fixing it one complaint at a
 * time is the slow way round.
 */
final class InvalidManifestException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly string $source,
        private readonly array $errors,
    ) {
        parent::__construct(sprintf(
            'Invalid plugin manifest %s: %s',
            $source,
            implode('; ', $errors),
        ));
    }

    /** Where the manifest came from, usually a file path. */
    public function getSource(): string
    {
        return $this->source;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
