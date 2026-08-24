<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

use Whity\Core\Container\HostWiredService;

/**
 * Registry of all available MCP prompts (WC-7755fc38).
 *
 * Core prompts are registered at boot by CorePrompts::register().
 * Plugin-contributed prompts will be registered via PluginMcpInterface
 * (WC-7abb732f). The registry is read at prompts/list and prompts/get
 * call time, so late-registered entries are naturally included.
 *
 * {@see HostWiredService}: an improvised, empty instance would list no prompts
 * and find none by name — the same answers a deployment with no prompts gives,
 * so the caller could never tell the two apart. An unregistered lookup throws
 * instead.
 */
final class PromptRegistry implements HostWiredService
{
    /** @var list<Prompt> */
    private array $prompts = [];

    public function register(Prompt $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    /**
     * Drop every registered prompt.
     *
     * The registry is a long-lived worker singleton that the prompts handlers
     * hold by reference, so it cannot be swapped for a fresh one when the plugin
     * registry reloads — and re-collecting into it without clearing would leave
     * an uninstalled plugin's prompts listed forever while duplicating the ones
     * that survived. Callers re-seed core prompts immediately afterwards; a
     * registry left empty lists nothing (#952).
     */
    public function reset(): void
    {
        $this->prompts = [];
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
