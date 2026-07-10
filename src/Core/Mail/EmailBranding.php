<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The brand inputs an {@see EmailLayout} needs, resolved from GLOBAL instance
 * settings (WC-email). This is what makes the template "customisable without
 * editing it": an operator sets the site name / brand colour / support address /
 * footer once (Branding + mail settings) and every message inherits them.
 *
 * A text wordmark (the site name on a brand-coloured tile) is used rather than a
 * remote logo image — email clients frequently block images, and a wordmark can
 * never break — so the header is always on-brand and always renders.
 */
final class EmailBranding
{
    public function __construct(
        public readonly string $siteName,
        public readonly string $brandColor,
        public readonly string $supportEmail,
        public readonly string $footerText,
    ) {
    }

    /**
     * Resolve branding from the global settings layer, falling back to registry
     * defaults. Never throws on a read issue — the caller (mail) is best-effort.
     */
    public static function fromSettings(SettingsService $settings): self
    {
        try {
            $g = $settings->getGlobal();
        } catch (\Throwable) {
            $g = [];
        }

        $siteName = trim((string) ($g[SettingsRegistry::SITE_NAME] ?? '')) ?: 'Whity';
        $brandColor = trim((string) ($g[SettingsRegistry::MAIL_BRAND_COLOR] ?? ''));
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) !== 1) {
            $brandColor = '#2B6CD2';
        }

        return new self(
            siteName: $siteName,
            brandColor: $brandColor,
            supportEmail: trim((string) ($g[SettingsRegistry::SUPPORT_EMAIL] ?? '')),
            footerText: trim((string) ($g[SettingsRegistry::MAIL_FOOTER_TEXT] ?? '')),
        );
    }

    /**
     * The single uppercase letter shown on the brand tile (first letter of the
     * site name, or 'W').
     */
    public function initial(): string
    {
        $first = mb_substr($this->siteName, 0, 1);
        return $first !== '' ? mb_strtoupper($first) : 'W';
    }
}
