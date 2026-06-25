<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

/**
 * Registry of all available MCP prompts (WC-7755fc38).
 *
 * Core prompts are registered at boot by CorePrompts::register().
 * Plugin-contributed prompts will be registered via PluginMcpInterface
 * (WC-7abb732f). The registry is read at prompts/list and prompts/get
 * call time, so late-registered entries are naturally included.
 */
final class PromptRegistry
{
    /** @var list<Prompt> */
    private array $prompts = [];

    public function register(Prompt $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    /** @return list<Prompt> */
    public function all(): array
    {
        return $this->prompts;
    }

    public function find(string $name): ?Prompt
    {
        foreach ($this->prompts as $prompt) {
            if ($prompt->name === $name) {
                return $prompt;
            }
        }
        return null;
    }
}
