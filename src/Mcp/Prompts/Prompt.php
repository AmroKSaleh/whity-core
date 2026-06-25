<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

/**
 * An MCP prompt template (WC-7755fc38).
 *
 * Prompts are curated message templates that guide AI agents through specific
 * workflows. A prompt can carry required or optional arguments whose values get
 * substituted into the message content before being returned to the client.
 *
 * Access control: if either requiredRole or requiredPermission is set the
 * Dispatcher enforces it in PromptsGetHandler; PromptsListHandler filters the
 * prompt out of the listing for callers who do not hold the required grant.
 */
final readonly class Prompt
{
    /**
     * @param list<PromptArgument> $arguments
     * @param list<PromptMessage>  $messages
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $arguments = [],
        public array $messages = [],
        public ?string $requiredRole = null,
        public ?string $requiredPermission = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->requiredRole === null && $this->requiredPermission === null;
    }
}
