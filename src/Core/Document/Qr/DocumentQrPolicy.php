<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

use Whity\Core\Settings\SettingsRegistry;

/**
 * Does THIS document carry a verification code? (#1036)
 *
 * THE TENANT SWITCH decides: `documents.qr_enabled`, resolved per-tenant ??
 * global ?? registry default by
 * {@see \Whity\Core\Settings\SettingsService::effective()} like every other
 * tunable. Default `false`: switching this on publishes an unauthenticated
 * verification surface for that tenant's documents, and that is a decision
 * somebody should make rather than inherit.
 *
 * The verification code is automatic (register 6.7, E.41): Whity Render prints
 * it at the end of the document. The old Document designer also had a
 * per-template opt-out and let an author place the code; both went with the
 * designer (editor M4c, the owner's call on 31 Oct).
 *
 * WHY THIS IS NOT AN ENTITLEMENT. Nothing here is metered or plan-shaped: a
 * verification code costs the operator one row and one anonymous GET. It is a
 * preference, so it is a setting.
 */
final class DocumentQrPolicy
{
    private function __construct()
    {
    }

    /**
     * Has the tenant (or the operator, or the registry) said yes?
     *
     * Takes the ALREADY-RESOLVED effective settings map rather than a
     * SettingsService, so this class has no collaborators and every caller is
     * forced to have gone through the per-tenant ?? global ?? default chain
     * rather than reading `app_settings` directly.
     *
     * @param array<string, string> $effectiveSettings
     */
    public static function enabledForTenant(array $effectiveSettings): bool
    {
        return ($effectiveSettings[SettingsRegistry::DOCUMENTS_QR_ENABLED] ?? 'false') === 'true';
    }
}
