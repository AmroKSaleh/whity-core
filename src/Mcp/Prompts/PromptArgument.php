<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

/**
 * Describes a single argument slot in a prompt template (WC-7755fc38).
 */
final readonly class PromptArgument
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $required = false,
    ) {}
}
