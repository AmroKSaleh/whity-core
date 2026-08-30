<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * Translate a string the SERVER declares, at serving time (#1044).
 *
 * WHAT THIS IS FOR. Most user-facing text is written in a screen and translated
 * by the screen's own `t()`. Some is not: rule-kind labels, schema-driven field
 * labels and setting names are declared in PHP and shipped inside an API
 * response as finished English. No amount of client-side i18n reaches them —
 * they arrive already worded. So an Arabic flow editor renders "Everyone holding
 * a role" in English, which #1044 is about, and which reads as broken rather
 * than as untranslated because everything around it IS Arabic.
 *
 * WHY IT IS NOT JUST `getTranslator()`. {@see LanguageRegistry::translate()}
 * returns THE KEY when nothing is seeded — deliberately, because a blank would
 * be indistinguishable from a string meant to be empty. That is the right answer
 * for a screen whose keys are all seeded together, and the wrong one here: these
 * keys are added alongside code that already has English text, so a miss must
 * render the English the declaration already carries, never
 * `routing.rule.kind.role` in front of a user.
 *
 * That single rule is why this class exists rather than each caller writing the
 * lookup inline. A caller that forgets the fallback does not fail loudly; it
 * ships raw keys to screens, in the one language that was previously fine.
 *
 * REQUIRES {@see \Whity\Http\Middleware\ResolveLanguage} TO HAVE RUN, which is
 * what makes the current language the CALLER's rather than permanently `en`.
 * Before #1120 nothing in production ever set it, so a helper like this one
 * would have returned English to everybody, passed every test that passed a
 * language explicitly, and changed nothing a user sees.
 */
final class ServerLabels
{
    public function __construct(private readonly LanguageRegistry $languages)
    {
    }

    /**
     * The caller's wording for `$key`, or `$english` if there is none.
     *
     * @param string $domain  Catalogue domain — bare for core (`documents`),
     *                        `<plugin>:<slug>` for a plugin's own.
     * @param string $key     Dot-delimited key, named for the thing rather than
     *                        for the English words.
     * @param string $english The declaration's own text, used verbatim on a miss.
     */
    public function label(string $domain, string $key, string $english): string
    {
        try {
            $translated = ($this->languages->getTranslator($domain))($key);
        } catch (\Throwable) {
            // A label is not worth failing a list endpoint over, and a registry
            // that cannot boot has already been reported once per worker by
            // ResolveLanguage — so this stays quiet rather than logging the same
            // outage once per row.
            return $english;
        }

        // `translate()` hands back the key when it finds nothing anywhere.
        return $translated === $key ? $english : $translated;
    }
}
