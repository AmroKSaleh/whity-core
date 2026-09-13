<?php

declare(strict_types=1);

namespace Whity\Core;

use Psr\Log\LoggerInterface;
use Whity\Core\Entitlement\EntitlementDefinition;
use Whity\Core\Entitlement\EntitlementValidationException;
use Whity\Core\Entitlement\PluginEntitlements;
use Whity\Sdk\PluginEntitlementsInterface;

/**
 * Turns a plugin's declared limits into catalogue entries.
 *
 * The counterpart to {@see PluginRoleSeeder}: a plugin says what it sells, this
 * validates the shape and hands it to {@see PluginEntitlements}, and the tier
 * editor then lists it beside core's own limits.
 *
 * ── One bad row must not cost an operator the plugin ───────────────────────
 *
 * Every entry is taken on its own. A malformed one is refused with the reason
 * logged and the rest are still registered, because the alternative — refusing
 * the plugin — turns a typo in a description into an outage of a feature the
 * customer is using. The refusal is LOUD rather than silent for the reason
 * #527 was expensive: a declaration that quietly does nothing looks identical
 * to one that works until somebody tries to price it.
 */
final class PluginEntitlementRegistrar
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /**
     * Register everything a plugin declares.
     *
     * @return int How many limits were added, for the caller's own logging.
     */
    public function register(PluginEntitlementsInterface $plugin, string $pluginName): int
    {
        $added = 0;

        foreach ($plugin->getEntitlements() as $index => $entry) {
            $definition = $this->toDefinition($entry, $pluginName, $index);
            if ($definition === null) {
                continue;
            }

            try {
                PluginEntitlements::register($definition);
                $added++;
            } catch (EntitlementValidationException $e) {
                // The registry's own rules — namespacing, core-name ownership,
                // a collision with another plugin, a default its type rejects.
                // Its message already says which and why.
                $this->refuse($pluginName, $e->getMessage());
            }
        }

        return $added;
    }

    /**
     * @param mixed $entry Whatever the plugin returned for this position.
     */
    private function toDefinition(mixed $entry, string $pluginName, int|string $index): ?EntitlementDefinition
    {
        if (!is_array($entry)) {
            $this->refuse($pluginName, "entry {$index} is not an array");

            return null;
        }

        foreach (['key', 'type', 'default', 'description'] as $required) {
            if (!isset($entry[$required]) || !is_string($entry[$required])) {
                $this->refuse($pluginName, "entry {$index} is missing a string '{$required}'");

                return null;
            }
        }

        $period = $entry['period'] ?? null;
        if ($period !== null && !is_string($period)) {
            $this->refuse($pluginName, "entry {$index} has a non-string 'period'");

            return null;
        }

        /** @var array{key: string, type: string, default: string, description: string} $entry */
        return new EntitlementDefinition(
            $entry['key'],
            $entry['type'],
            $entry['default'],
            $entry['description'],
            $period,
            // THE OWNER IS THE HOST'S WORD, never the plugin's. Taking it from
            // the declaration would let a plugin claim another's namespace by
            // simply naming it, which is the one thing the namespacing rule
            // exists to stop.
            $pluginName,
        );
    }

    private function refuse(string $pluginName, string $reason): void
    {
        $this->logger?->warning(
            "Plugin '{$pluginName}' declared an entitlement that was refused: {$reason}. "
                . 'It will not appear in the tier editor and nothing will enforce it.',
            ['event' => 'plugin.entitlement.refused', 'plugin' => $pluginName, 'reason' => $reason]
        );
    }
}
