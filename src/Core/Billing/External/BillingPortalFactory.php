<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * Build the billing portal the one way it is meant to be built.
 *
 * FOUR PLACES NEED ONE: the HTTP path, the queue job, and two cron commands.
 * They were each building it themselves, and every copy carried a comment
 * promising it matched the others — which is what a duplication looks like just
 * before it stops being true. The dangerous version of "stops being true" is
 * quiet: a deployment where the request path talks to the billing service and a
 * sweep decides it is unconfigured would reconcile nobody, silently, forever.
 *
 * CREDENTIALS COME FROM THE ENVIRONMENT, NEVER FROM SETTINGS. Settings live in
 * the database, are readable through an API by anyone holding the right
 * permission, and are dumped into every backup. A signing secret that can be
 * read is a signing secret that can be used.
 *
 * AN UNCONFIGURED DEPLOYMENT GETS {@see NullBillingPortal}, not a broken one.
 * Self-hosted installations bill nobody; that is a supported state rather than a
 * misconfiguration, and it walls nobody.
 */
final class BillingPortalFactory
{
    /**
     * How long to wait on the billing service before giving up.
     *
     * Bounded, because an unbounded wait on a third party is an outage of our
     * own. Tunable per deployment rather than compiled in.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    public static function fromEnvironment(): BillingPortal
    {
        $baseUrl = rtrim(self::env('PAY_BASE_URL'), '/');
        $apiKey = self::env('PAY_API_KEY');

        if ($baseUrl === '' || $apiKey === '') {
            return new NullBillingPortal();
        }

        return new HttpBillingPortal(
            new CurlBillingTransport(
                timeoutSeconds: max(1, (int) (self::env('PAY_TIMEOUT_SECONDS') ?: self::DEFAULT_TIMEOUT_SECONDS)),
            ),
            $baseUrl,
            $apiKey,
        );
    }

    /**
     * `$_ENV` first, then `getenv()`.
     *
     * Both are read because they do not always agree: FrankenPHP's worker mode
     * populates `$_ENV` from the process environment at boot, while a variable
     * injected later — by a `.env` loader, or by a test — reaches only one of
     * them. Reading one would make the portal appear unconfigured in whichever
     * context the other was used.
     */
    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : '';
    }
}
