<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Which language THIS request should be answered in (#1044).
 *
 * WHY THIS DID NOT EXIST, AND WHAT IT UNBLOCKS
 * --------------------------------------------
 * {@see LanguageRegistry} has carried a current language and a
 * `setCurrentLanguage()` since it was written, and nothing in production ever
 * called it — only tests. So the server's language was permanently `en`, and
 * `getTranslator()` returned English to every caller in every tenant.
 *
 * That is invisible until something tries to translate server-side. #1044 is
 * that: rule-kind labels ("Everyone holding a role") are declared in PHP and
 * stay English on an Arabic screen, and a serving-time fix built on
 * `getTranslator()` alone would have returned English, passed its tests with an
 * explicitly-passed language, and changed nothing a user sees.
 *
 * THE CHAIN MIRRORS THE CLIENT'S, deliberately, because a screen answered partly
 * by the server and partly by the browser must not disagree with itself about
 * which language it is in. `LanguageProvider` resolves:
 *
 *   i18n disabled            -> the source language, whatever the profile says
 *   profile.language_code    -> the signed-in user's explicit choice
 *   (remembered locally)     -> browser-only; the server has no equivalent
 *   default                  -> the source language
 *
 * and then VALIDATES the code against the enabled languages, so a language that
 * has since been disabled or deleted cannot resurrect itself. This does the
 * same, minus the browser-local step, which is not a fact the server holds.
 *
 * A NULL `language_code` IS NOT A MISSING VALUE. It means "follow the default",
 * which is what migration 083 says and what the settings screen shows as no
 * explicit choice. Treating it as an error, or as a reason to refuse, would
 * make every user who never opened the switcher unanswerable.
 *
 * AN UNAUTHENTICATED REQUEST GETS THE SOURCE LANGUAGE. There is no profile to
 * read a preference from, and guessing from `Accept-Language` would mean a
 * public screen disagreeing with the signed-in one for the same person — a
 * choice worth making deliberately later rather than inheriting from a header.
 */
final class RequestLanguageResolver
{
    public function __construct(
        private readonly PDO $db,
        private readonly LanguageRegistry $languages,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * The language code this request should be answered in. Never null.
     */
    public function resolve(?int $profileId): string
    {
        if (!$this->i18nEnabled()) {
            // The flag's whole point: a deployment not ready to ship a second
            // language presents as single-language, and every user reads the
            // source language whatever their profile says.
            return LanguageRegistry::SOURCE_LANGUAGE;
        }

        if ($profileId === null) {
            return LanguageRegistry::SOURCE_LANGUAGE;
        }

        $chosen = $this->profileLanguage($profileId);
        if ($chosen === null) {
            return LanguageRegistry::SOURCE_LANGUAGE;
        }

        // A code that has since been disabled or deleted must not resurrect
        // itself from a stored preference — the same validation the client does,
        // and for the same reason: the alternative is a screen half-rendered in
        // a language the instance no longer serves.
        //
        // `getLanguages()` returns the ENABLED languages indexed by code, so
        // this is a key check rather than a scan.
        return array_key_exists($chosen, $this->languages->getLanguages())
            ? $chosen
            : LanguageRegistry::SOURCE_LANGUAGE;
    }

    /**
     * The profile's explicit choice, or null for "follow the default".
     *
     * Tenant-scoped by the profile id alone: `profiles.id` is unique across the
     * install and the caller's id came from their own verified token, so there
     * is no cross-tenant read to guard against here — the id cannot name
     * somebody else's row.
     */
    private function profileLanguage(int $profileId): ?string
    {
        $stmt = $this->db->prepare('SELECT language_code FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $code = $row['language_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function i18nEnabled(): bool
    {
        $global = $this->settings->getGlobal();

        return ($global[SettingsRegistry::I18N_ENABLED]
            ?? SettingsRegistry::defaultFor(SettingsRegistry::I18N_ENABLED)) === 'true';
    }
}
