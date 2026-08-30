<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\RequestLanguageResolver;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

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
 * THE SDK TYPES, NOT THE CORE ONES, AND THAT IS NOT A STYLE CHOICE.
 * `Whity\Core\Response` EXTENDS `Whity\Sdk\Http\Response` — core's is the
 * SUBCLASS. Plugin handlers return the SDK parent, so a middleware that
 * declares `: \Whity\Core\Response` type-errors on every plugin route while
 * every core route keeps working, which reads as "the plugin is broken"
 * rather than "the middleware is too narrow". Every other middleware and
 * `HttpKernel` itself import the SDK pair for exactly this reason; this one
 * did not, and took out all of UiKitShowcase's data-bound blocks until it did.
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

    /**
     * Whether this worker has already reported a resolution failure.
     *
     * Process-level statics are PER FrankenPHP worker, so a permanently broken
     * resolver says so once per worker rather than once per request — loud
     * enough to find, quiet enough not to bury the log.
     */
    private static bool $failureReported = false;

    public function handle(Request $request, callable $next): Response
    {
        try {
            $this->languages->setCurrentLanguage($this->resolver->resolve($this->profileId($request)));
        } catch (\Throwable $e) {
            $this->fallBackToSourceLanguage($e);
        }

        return $next($request);
    }

    /**
     * Answer in the source language, and say so.
     *
     * NOT SILENT, on purpose. A `catch` that swallows without a word is how a
     * feature like this dies invisibly: resolution fails on every request, every
     * test still passes because the request still succeeds, and the only symptom
     * is that nobody's language ever applies.
     *
     * AND THE FALLBACK ITSELF CANNOT THROW. `setCurrentLanguage()` boots the
     * registry on first use, so calling it here can raise the very exception it
     * is recovering from — which, uncaught inside a catch block, would 500 every
     * request on an instance whose languages table cannot be read. The registry
     * already defaults to the source language, so if this fails there is nothing
     * left to do and nothing worth failing a request over.
     */
    private function fallBackToSourceLanguage(\Throwable $cause): void
    {
        if (!self::$failureReported) {
            self::$failureReported = true;
            error_log(
                '[whity] language resolution failed (answering in the source language): '
                . $cause->getMessage()
            );
        }

        try {
            $this->languages->setCurrentLanguage(LanguageRegistry::SOURCE_LANGUAGE);
        } catch (\Throwable) {
            // See above: the registry's own default is already the source
            // language, and a request must not 500 over vocabulary.
        }
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
