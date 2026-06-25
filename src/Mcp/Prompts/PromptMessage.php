<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

/**
 * One message turn in a prompt template (WC-7755fc38).
 *
 * $content is a template string; `{{arg_name}}` placeholders are substituted
 * with caller-supplied argument values by PromptsGetHandler before the message
 * is returned to the client.
 */
final readonly class PromptMessage
{
    /**
     * @param 'user'|'assistant' $role
     */
    public function __construct(
        public string $role,
        public string $content,
    ) {}
}
