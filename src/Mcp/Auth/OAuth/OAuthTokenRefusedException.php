<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth\OAuth;

/**
 * A genuine OAuth access token that still cannot be used here (WHIT-709).
 *
 * Distinct from an invalid token, which is a plain null and a 401: a 401 sends
 * the client back to sign in, and signing in again would only produce another
 * token with the same problem (no linked account, no way to tell which
 * workspace). This is answered with a 403 and a message the user can act on.
 *
 * The message is shown to the token's holder, so it names the problem and the
 * fix and nothing about other accounts.
 */
final class OAuthTokenRefusedException extends \RuntimeException
{
}
