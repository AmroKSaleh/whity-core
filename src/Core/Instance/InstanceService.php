<?php

declare(strict_types=1);

namespace Whity\Core\Instance;

use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * First-run instance lifecycle state (WC-instance-first-run).
 *
 * Tracks whether an operator has completed the guided first-run setup (the web
 * `/onboarding` wizard). This is deliberately modelled as INSTANCE STATE, not as
 * an operator-tunable website setting:
 *
 *  - It is stored under a reserved `app_settings` key and is NOT a
 *    {@see \Whity\Core\Settings\SettingsRegistry} key, so it never appears on the
 *    global/per-tenant settings catalogue (no stray "instance.configured" toggle
 *    in the Website Settings UI) and cannot be flipped through the settings PATCH
 *    surface — only this service writes it.
 *  - A fresh install (migrated + seeded, but the operator has not run the wizard)
 *    reports `configured = false`. The web app reads this after sign-in and
 *    routes an eligible operator (system tenant + settings:manage) into the
 *    onboarding wizard; completing the wizard flips it to `true` so subsequent
 *    sign-ins go straight to the dashboard.
 *
 * Absent or any non-`'true'` value reads as NOT configured, so the flag is
 * fail-safe: a brand-new instance always offers the guided setup.
 */
final class InstanceService
{
    /**
     * Reserved `app_settings` key holding the first-run completion flag.
     *
     * Intentionally NOT registered in {@see \Whity\Core\Settings\SettingsRegistry}
     * (see the class docblock): it is instance lifecycle state, not a setting.
     */
    public const CONFIGURED_KEY = 'instance.configured';

    public function __construct(private readonly GlobalSettingsRepository $globals)
    {
    }

    /**
     * Whether the guided first-run setup has been completed.
     */
    public function isConfigured(): bool
    {
        return $this->globals->get(self::CONFIGURED_KEY) === 'true';
    }

    /**
     * Mark the guided first-run setup complete. Idempotent (an upsert).
     */
    public function markConfigured(): void
    {
        $this->globals->set(self::CONFIGURED_KEY, 'true');
    }
}
