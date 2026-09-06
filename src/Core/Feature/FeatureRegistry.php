<?php

declare(strict_types=1);

namespace Whity\Core\Feature;

use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Settings\SettingsRegistry;

/**
 * The MAJOR PARTS of the system, and how each one is switched on.
 *
 * WHAT PROBLEM THIS SOLVES. Subsystems were already switchable — `mcp.enabled`,
 * `documents.render_enabled`, `plugins.store_enabled` and six more — but only as
 * individual entries among four hundred settings. Nothing could answer "which
 * subsystems does this build have, and which are on for this tenant?", so the
 * answer lived in whoever remembered that those particular keys were that kind
 * of key. A new subsystem could ship with no switch at all and nobody would
 * notice it was missing.
 *
 * NOT A FOURTH STORE. This resolves over the settings and entitlements that
 * already exist; it does not keep state of its own. `documents.render` reads
 * `documents.render_enabled`, the same key the render path has always read, so
 * there is exactly one truth about whether rendering is on and no way for a
 * flag and a setting to disagree.
 *
 * THE THREE MECHANISMS, AND WHY THEY ARE NOT THE SAME THING
 * --------------------------------------------------------
 *   - A FEATURE says "this instance offers this subsystem". Operator policy,
 *     per-tenant overridable, and the kill switch when a subsystem is new,
 *     optional, or needs infrastructure (the render container) that a given
 *     deployment has not stood up.
 *   - An ENTITLEMENT says "this tenant is PAYING for it". Commercial.
 *   - A SETTING tunes a feature that is already on.
 *
 * They compose rather than compete: a feature is available when the operator
 * has it on AND, where one is declared, the tenant's plan grants the
 * entitlement. Collapsing them would mean either an operator's kill switch that
 * a plan could override, or a paid feature an operator cannot turn off during
 * an incident. Both are worse than two questions.
 */
final class FeatureRegistry
{
    // ── Feature keys ─────────────────────────────────────────────────────────
    // Dotted subsystem names, deliberately WITHOUT the `_enabled` suffix the
    // settings carry: the setting is how a feature is stored, not what it is.

    public const DOCUMENTS_RENDER = 'documents.render';
    public const DOCUMENTS_PERSIST = 'documents.persist';
    public const DOCUMENTS_QR = 'documents.qr';
    public const MCP = 'mcp';
    public const PLUGIN_STORE = 'plugins.store';
    public const SSO = 'auth.sso';
    public const SELF_REGISTRATION = 'auth.self_registration';
    public const ERROR_TRACKING = 'error_tracking';

    /**
     * The catalogue.
     *
     * `setting` is the settings key that stores the operator's switch — an
     * EXISTING one wherever the subsystem already had a switch, which is why
     * this file adds no migration and changes no behaviour on its own.
     *
     * `entitlement` names the commercial gate when the subsystem has one, or is
     * null when availability is purely an operator decision. `sso.tenant_idp`
     * gates the tenant's own bring-your-own provider, so SSO reads it; document
     * rendering is infrastructure rather than a tier, so it does not.
     *
     * @var array<string, array{label: string, description: string, setting: string, entitlement: string|null}>
     */
    private const FEATURES = [
        self::DOCUMENTS_RENDER => [
            'label' => 'Document rendering',
            'description' =>
                'Server-side PDF rendering, including document mode. Needs the render container; '
                . 'off by default because a browser-bearing service is not something a sovereign '
                . 'deployment should acquire by surprise.',
            'setting' => SettingsRegistry::DOCUMENTS_RENDER_ENABLED,
            'entitlement' => null,
        ],
        self::DOCUMENTS_PERSIST => [
            'label' => 'Stored documents',
            'description' => 'Keeping a rendered PDF as a document record rather than streaming it once.',
            'setting' => SettingsRegistry::DOCUMENTS_PERSIST_ENABLED,
            'entitlement' => null,
        ],
        self::DOCUMENTS_QR => [
            'label' => 'Document verification codes',
            'description' => 'The QR stamp that makes an issued document checkable against this instance.',
            'setting' => SettingsRegistry::DOCUMENTS_QR_ENABLED,
            'entitlement' => null,
        ],
        self::MCP => [
            'label' => 'MCP endpoint',
            'description' => 'The Model Context Protocol surface, exposing authored tools to an AI client.',
            'setting' => SettingsRegistry::MCP_ENABLED,
            'entitlement' => null,
        ],
        self::PLUGIN_STORE => [
            'label' => 'Plugin store',
            'description' => 'Installing plugins from a remote store rather than only from disk.',
            'setting' => SettingsRegistry::PLUGINS_STORE_ENABLED,
            'entitlement' => null,
        ],
        self::SSO => [
            'label' => 'Single sign-on',
            'description' => 'Federated login. The tenant\'s own identity provider additionally needs the entitlement.',
            'setting' => SettingsRegistry::SSO_ENABLED,
            'entitlement' => EntitlementRegistry::SSO_TENANT_IDP,
        ],
        self::SELF_REGISTRATION => [
            'label' => 'Self-service signup',
            'description' => 'Whether the public registration endpoint is open at all on this instance.',
            'setting' => SettingsRegistry::SELF_REGISTRATION_ENABLED,
            'entitlement' => null,
        ],
        self::ERROR_TRACKING => [
            'label' => 'Error tracking',
            'description' => 'Reporting server errors to the configured tracker.',
            'setting' => SettingsRegistry::ERROR_TRACKING_ENABLED,
            'entitlement' => null,
        ],
    ];

    /** @return list<string> Every feature key, in catalogue order. */
    public static function keys(): array
    {
        return array_keys(self::FEATURES);
    }

    public static function isKnown(string $feature): bool
    {
        return isset(self::FEATURES[$feature]);
    }

    /**
     * @return array{label: string, description: string, setting: string, entitlement: string|null}
     * @throws \InvalidArgumentException When the feature is not in the catalogue.
     */
    public static function describe(string $feature): array
    {
        if (!isset(self::FEATURES[$feature])) {
            throw new \InvalidArgumentException("Unknown feature: {$feature}");
        }

        return self::FEATURES[$feature];
    }

    /** The settings key that stores this feature's operator switch. */
    public static function settingFor(string $feature): string
    {
        return self::describe($feature)['setting'];
    }

    /** The entitlement gating it commercially, or null when there is none. */
    public static function entitlementFor(string $feature): ?string
    {
        return self::describe($feature)['entitlement'];
    }
}
