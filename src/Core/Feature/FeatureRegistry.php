<?php

declare(strict_types=1);

namespace Whity\Core\Feature;

use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Settings\SettingsRegistry;

/**
 * WHICH FEATURE FLAGS ALSO NEED A PLAN, and nothing else.
 *
 * The flags themselves are NOT redeclared here. {@see SettingsRegistry} already
 * curates them — `FEATURE_FLAG_KEYS`, an `isFeatureFlag()` helper, and the admin
 * Feature Flags tab that renders them — and this file exists beside that rather
 * than beside a copy of it.
 *
 * The first version of this class did keep its own catalogue: eight entries,
 * hand-listed, against the registry's eleven. It was wrong within an hour of
 * being written, which is the whole argument against a second list of the same
 * thing. So the list comes from {@see SettingsRegistry::featureFlagKeys()} and
 * only the part that genuinely does not exist anywhere lives here.
 *
 * WHAT DOES NOT EXIST ANYWHERE ELSE is the commercial half. The settings tab
 * answers "has the operator switched this on for the instance", which is one of
 * the two questions that decide whether a tenant can use a subsystem. The other
 * is "does this tenant's plan include it", and nothing joined them — so a
 * subsystem could be switched on instance-wide and still be unavailable to a
 * tenant, with no single place saying why.
 *
 * The two are kept apart rather than merged into one flag because they fail
 * differently and are owned by different people: merged, you get either a plan
 * that overrides an operator's kill switch, or a paid subsystem an operator
 * cannot turn off during an incident.
 */
final class FeatureRegistry
{
    /**
     * Feature flag => the entitlement a tenant additionally needs, for the few
     * flags that have a commercial gate at all.
     *
     * Deliberately sparse. Most flags are pure operator policy — whether this
     * instance runs a render container, exposes an MCP endpoint, or opens public
     * signup — and giving those an entitlement would invent a commercial rule
     * nobody sells. SSO is here because the tenant's own bring-your-own provider
     * is already an entitlement (`sso.tenant_idp`); the flag governs whether the
     * instance federates at all.
     *
     * @var array<string, string>
     */
    private const ENTITLEMENT_FOR = [
        SettingsRegistry::SSO_ENABLED => EntitlementRegistry::SSO_TENANT_IDP,
    ];

    /**
     * Every feature flag, in the settings registry's own order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return SettingsRegistry::featureFlagKeys();
    }

    public static function isKnown(string $flag): bool
    {
        return SettingsRegistry::isFeatureFlag($flag);
    }

    /**
     * The entitlement gating this flag commercially, or null when availability
     * is purely an operator decision.
     *
     * @throws \InvalidArgumentException When the key is not a curated flag.
     *         Loud on purpose: a typo answering "no entitlement" would silently
     *         drop a commercial gate.
     */
    public static function entitlementFor(string $flag): ?string
    {
        if (!self::isKnown($flag)) {
            throw new \InvalidArgumentException("Not a feature flag: {$flag}");
        }

        return self::ENTITLEMENT_FOR[$flag] ?? null;
    }
}
