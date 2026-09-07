<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

use Whity\Core\Settings\SettingsRegistry;

/**
 * Does THIS document carry a verification code? (#1036)
 *
 * Three scopes compose here and nowhere else, so there is one answer rather than
 * one per caller:
 *
 *   1. THE TENANT SWITCH — `documents.qr_enabled`, resolved
 *      per-tenant ?? global ?? registry default by
 *      {@see \Whity\Core\Settings\SettingsService::effective()} like every other
 *      tunable. Default `false`: switching this on publishes an unauthenticated
 *      verification surface for that tenant's documents, and that is a decision
 *      somebody should make rather than inherit.
 *
 *   2. THE TEMPLATE — a flag inside `document_templates.data`, the same JSON the
 *      designer already round-trips (so no migration, and no fifth place that
 *      has to know a template's shape). TRI-STATE, and the absent case is the
 *      common one:
 *
 *        absent → INHERIT (on, when the switch is on)
 *        true   → on
 *        false  → off, whatever the switch says
 *
 *      Inherit-by-default is the polarity the request asks for — "turn on qr
 *      code tracking on ALL documents" — so the per-template control is an
 *      OPT-OUT for the internal memo, not an opt-in every certificate has to
 *      remember. The opposite polarity would make the master switch do nothing
 *      on the day it was turned on, for every template that already exists,
 *      which is precisely the silent no-op #1036 forbids.
 *
 *   3. WHERE IT SITS on the page — authored as an ordinary designer element and
 *      composed by {@see QrTemplateComposer}, which also supplies the default
 *      placement when the first two say yes and nobody placed one.
 *
 * WHY THIS IS NOT AN ENTITLEMENT. Nothing here is metered or plan-shaped: a
 * verification code costs the operator one row and one anonymous GET. It is a
 * preference, so it is a setting.
 */
final class DocumentQrPolicy
{
    /**
     * The key inside `document_templates.data` that carries scope 2.
     *
     * Nested under a `qr` object rather than a flat `qrEnabled` so the next
     * per-template QR decision (size, corner, error-correction level) has an
     * obvious home that does not collide with the designer's own top-level
     * fields (`page`, `placeholders`, `pages`, `sheet`, `sequence`).
     */
    public const TEMPLATE_KEY = 'qr';

    /** The flag inside {@see TEMPLATE_KEY}. */
    public const TEMPLATE_ENABLED_KEY = 'enabled';

    private function __construct()
    {
    }

    /**
     * Scope 1: has the tenant (or the operator, or the registry) said yes?
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

    /**
     * Scope 2: does this template opt out?
     *
     * Anything that is not literally `false` inherits — including a malformed
     * value. That is deliberate: a template whose `qr` object was mangled by a
     * client should keep the tenant's answer rather than silently dropping the
     * code off documents nobody has looked at yet. Failing in the direction of
     * "the document is verifiable" is the safe direction here, because the
     * failure is visible (a code appears where the author did not expect one)
     * rather than invisible (paper that claims nothing and verifies nothing).
     *
     * @param array<string, mixed> $templateData
     */
    public static function enabledForTemplate(array $templateData): bool
    {
        $qr = $templateData[self::TEMPLATE_KEY] ?? null;
        if (!is_array($qr)) {
            return true;
        }

        return ($qr[self::TEMPLATE_ENABLED_KEY] ?? null) !== false;
    }

    /**
     * Scopes 1 and 2 together: should this document carry a code at all?
     *
     * Scope 3 is not consulted here on purpose. "Where does it sit" cannot
     * answer "should there be one" — that is exactly the composition #1036
     * warns about, where the switch and the template both say yes, nobody
     * placed a block, and the document ships claiming to be tracked while
     * carrying nothing. {@see QrTemplateComposer::compose()} resolves the
     * placement AFTER this has said yes, and always produces one.
     *
     * @param array<string, string> $effectiveSettings
     * @param array<string, mixed>  $templateData
     */
    public static function enabled(array $effectiveSettings, array $templateData): bool
    {
        return self::enabledForTenant($effectiveSettings)
            && self::enabledForTemplate($templateData);
    }

    /**
     * Write scope 2 into a template's data, or clear it back to inherit.
     *
     * Used by the designer's save path through the ordinary template update
     * route — there is no separate "set the QR flag" endpoint, because the flag
     * lives in the template document and a second write path for one field
     * inside it is a second way for the two to disagree.
     *
     * @param array<string, mixed> $templateData
     * @return array<string, mixed>
     */
    public static function withTemplateFlag(array $templateData, ?bool $enabled): array
    {
        if ($enabled === null) {
            unset($templateData[self::TEMPLATE_KEY]);

            return $templateData;
        }

        $qr = $templateData[self::TEMPLATE_KEY] ?? [];
        if (!is_array($qr)) {
            $qr = [];
        }
        $qr[self::TEMPLATE_ENABLED_KEY] = $enabled;
        $templateData[self::TEMPLATE_KEY] = $qr;

        return $templateData;
    }
}
