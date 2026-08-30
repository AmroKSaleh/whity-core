<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\RequestLanguageResolver;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Tell the server which language to answer this request in (#1044).
 *
 * WHAT IT FIXES. `LanguageRegistry` has always had a current language and a
 * `setCurrentLanguage()`, and nothing in production ever called it — only tests.
 * So `getTranslator()` returned English to every caller in every tenant, and any
 * server-side translation built on it would have looked correct in review, passed
 * its unit tests with an explicitly-passed language, and changed nothing a user
 * sees. This is the call that was missing.
 *
 * WHERE IT SITS, AND WHY. After `EnforceTenantIsolation`, because the resolution
 * reads the caller's own profile and there is no caller before authentication
 * has run. Before dispatch, because a handler must not have to remember to ask —
 * the whole failure mode here is a thing everybody assumes somebody else did.
 *
 * IT NEVER FAILS A REQUEST. A language preference is not an authorisation
 * decision: if the profile row is gone, the code was disabled, or the settings
 * read throws, the answer is the source language and the request proceeds.
 * Refusing would turn "your language is unavailable" into "you cannot use the
 * product", which is a far worse trade for a screen's vocabulary.
 */
final class ResolveLanguage
{
    public function __construct(
        private readonly RequestLanguageResolver $resolver,
        private readonly LanguageRegistry $languages,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        try {
            $this->languages->setCurrentLanguage($this->resolver->resolve($this->profileId($request)));
        } catch (\Throwable) {
            // Deliberately swallowed. See the class docblock: the source
            // language is always a usable answer, and a request that 500s
            // because somebody's language could not be looked up is a worse
            // outcome than one rendered in English.
            $this->languages->setCurrentLanguage(LanguageRegistry::SOURCE_LANGUAGE);
        }

        return $next($request);
    }

    /**
     * The authenticated caller, or null.
     *
     * Read the same way every handler reads it (`$request->user->profile_id`),
     * so an unauthenticated or partially-authenticated request resolves to null
     * rather than to somebody else's preference.
     */
    private function profileId(Request $request): ?int
    {
        $actor = $request->user;

        if (!is_object($actor) || !isset($actor->profile_id) || !is_int($actor->profile_id)) {
            return null;
        }

        return $actor->profile_id;
    }
}
