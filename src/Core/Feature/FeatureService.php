<?php

declare(strict_types=1);

namespace Whity\Core\Feature;

use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Settings\SettingsService;

/**
 * Is a major subsystem available to this tenant?
 *
 * Two questions, both of which must say yes:
 *
 *   1. has the OPERATOR switched the subsystem on (per-tenant override, else
 *      the global value, else the registry default — the resolution
 *      {@see SettingsService::effective()} already performs), and
 *   2. where the feature declares one, does the tenant's plan grant the
 *      ENTITLEMENT?
 *
 * Kept apart because they fail differently and are owned by different people.
 * Folding them into one value would mean either a plan that can override an
 * operator's kill switch, or a paid feature an operator cannot turn off during
 * an incident.
 *
 * NO STATE OF ITS OWN. Every answer is computed from the settings and
 * entitlements that already exist, so a feature and the subsystem's own switch
 * cannot drift apart — they are the same key.
 */
final class FeatureService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /**
     * Is `$feature` available to `$tenantId`?
     *
     * @throws \InvalidArgumentException When the feature is not in the catalogue.
     *         Deliberately loud: a typo'd feature key that answered "false"
     *         would silently disable a subsystem, and a typo that answered
     *         "true" would silently expose one.
     */
    public function isEnabled(string $feature, int $tenantId): bool
    {
        $setting = FeatureRegistry::settingFor($feature);
        $effective = $this->settings->effective($tenantId);

        if (($effective[$setting] ?? 'false') !== 'true') {
            return false;
        }

        $entitlement = FeatureRegistry::entitlementFor($feature);
        if ($entitlement === null) {
            return true;
        }

        return $this->entitlements->isGranted($tenantId, $entitlement);
    }

    /**
     * Every feature and its state for this tenant, for an operator screen.
     *
     * Reports the two halves SEPARATELY as well as the answer. "Off" is not one
     * condition: a subsystem the operator disabled and a subsystem the tenant's
     * plan does not include need different actions from different people, and a
     * single boolean sends whoever is looking to the wrong place.
     *
     * @return list<array{
     *     key: string, label: string, description: string,
     *     enabled: bool, operator_enabled: bool, entitlement: string|null, entitled: bool
     * }>
     */
    public function all(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $out = [];

        foreach (FeatureRegistry::keys() as $key) {
            $meta = FeatureRegistry::describe($key);
            $operatorOn = ($effective[$meta['setting']] ?? 'false') === 'true';
            $entitled = $meta['entitlement'] === null
                || $this->entitlements->isGranted($tenantId, $meta['entitlement']);

            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => $operatorOn && $entitled,
                'operator_enabled' => $operatorOn,
                'entitlement' => $meta['entitlement'],
                'entitled' => $entitled,
            ];
        }

        return $out;
    }
}
