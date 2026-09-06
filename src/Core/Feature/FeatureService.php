<?php

declare(strict_types=1);

namespace Whity\Core\Feature;

use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Settings\SettingsService;

/**
 * Is a feature-flagged subsystem available TO THIS TENANT?
 *
 * Two questions, both of which must say yes:
 *
 *   1. has the OPERATOR switched the flag on — the curated setting the admin
 *      Feature Flags tab already edits, resolved per-tenant then global then
 *      default by {@see SettingsService::effective()}; and
 *   2. where the flag declares one, does the tenant's plan grant the
 *      ENTITLEMENT?
 *
 * WHY THIS EXISTS BESIDE THE SETTINGS TAB rather than replacing it. That tab is
 * global-only and system-tenant-only: it answers "what has the operator turned
 * on for the instance". It cannot answer "what can THIS tenant use", because it
 * knows nothing about plans — so a subsystem could be on instance-wide and
 * unavailable to a tenant with nothing saying why. This joins the two halves and
 * keeps no state of its own; the flags are the same keys the subsystems
 * themselves read.
 */
final class FeatureService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /**
     * @throws \InvalidArgumentException When the key is not a curated flag.
     *         Deliberately loud: a typo answering "false" would silently
     *         disable a subsystem, and one answering "true" would silently
     *         expose one.
     */
    public function isEnabled(string $flag, int $tenantId): bool
    {
        $entitlement = FeatureRegistry::entitlementFor($flag); // also validates the key
        $effective = $this->settings->effective($tenantId);

        if (($effective[$flag] ?? 'false') !== 'true') {
            return false;
        }

        return $entitlement === null || $this->entitlements->isGranted($tenantId, $entitlement);
    }

    /**
     * Every feature flag and its state for this tenant.
     *
     * Reports the two halves SEPARATELY as well as the answer, because "off" is
     * not one condition: a subsystem the operator disabled and one the tenant's
     * plan does not include need different actions from different people, and a
     * single boolean sends whoever is looking to the wrong place.
     *
     * @return list<array{
     *     key: string, enabled: bool, operator_enabled: bool,
     *     entitlement: string|null, entitled: bool
     * }>
     */
    public function all(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $out = [];

        foreach (FeatureRegistry::keys() as $flag) {
            $entitlement = FeatureRegistry::entitlementFor($flag);
            $operatorOn = ($effective[$flag] ?? 'false') === 'true';
            $entitled = $entitlement === null
                || $this->entitlements->isGranted($tenantId, $entitlement);

            $out[] = [
                'key' => $flag,
                'enabled' => $operatorOn && $entitled,
                'operator_enabled' => $operatorOn,
                'entitlement' => $entitlement,
                'entitled' => $entitled,
            ];
        }

        return $out;
    }
}
