<?php

declare(strict_types=1);

namespace Whity\Core\Http;

/**
 * The one home for every link the backend builds INTO a frontend page.
 *
 * Whity serves no pages itself, but it hands people links to them: emails,
 * SSO redirects, public form links, QR verification codes, error alerts. Those
 * used to be assembled at each call site as APP_URL plus a path the bundled
 * Next.js admin happens to serve, which a headless deployment — a team running
 * its own frontend, on its own origin, with its own routes — could not change.
 *
 * Two layers, each optional, so an existing deployment resolves exactly what it
 * did before:
 *
 *   - FRONTEND_URL is the origin pages are served from. It falls back to
 *     APP_URL, because until headless they were the same address. APP_URL keeps
 *     its other meaning — the backend's own public origin, which the SSO
 *     callback is built on — and this class never touches that.
 *   - each link has its own env var holding a FULL URL prefix, for a frontend
 *     whose routes differ from the bundled admin's. Unset, the link is the
 *     frontend origin plus the bundled admin's path.
 *
 * Every value is returned trimmed of a trailing slash. EMPTY is a real state —
 * an instance nobody has told its address — and each consumer already answers
 * it (refusing to mint a QR, returning a null form link, omitting a CTA).
 */
final class FrontendUrls
{
    public const EMAIL_VERIFICATION = 'EMAIL_VERIFICATION_URL';
    public const PASSWORD_RESET = 'PASSWORD_RESET_URL';
    public const TWO_FACTOR_RECOVERY = 'TWO_FACTOR_RECOVERY_URL';
    public const INVITATION_ACCEPT = 'INVITATION_ACCEPT_URL';
    public const SSO_SUCCESS = 'SSO_SUCCESS_URL';
    public const SSO_LOGIN = 'SSO_LOGIN_URL';
    public const PUBLIC_FORM = 'PUBLIC_FORM_URL';
    public const DOCUMENT_VERIFY = 'DOCUMENT_VERIFY_URL';
    public const ERROR_DETAILS = 'ERROR_DETAILS_URL';

    /**
     * The bundled admin's path for each link — what an override replaces.
     */
    private const DEFAULT_PATHS = [
        self::EMAIL_VERIFICATION => '/verify-email',
        self::PASSWORD_RESET => '/reset-password',
        self::TWO_FACTOR_RECOVERY => '/account-recovery',
        self::INVITATION_ACCEPT => '/accept-invitation',
        self::SSO_SUCCESS => '/dashboard',
        self::SSO_LOGIN => '/login',
        self::PUBLIC_FORM => '/f',
        self::DOCUMENT_VERIFY => '/verify',
        self::ERROR_DETAILS => '/admin/errors',
    ];

    /**
     * @param array<string, string> $env Variable name => value. Only the keys
     *        this class reads matter; anything else is ignored.
     */
    public function __construct(private readonly array $env)
    {
    }

    /**
     * Read from the process environment, the way the rest of bootstrap does
     * ($_ENV first, then getenv()).
     */
    public static function fromEnv(): self
    {
        $names = array_merge(['FRONTEND_URL', 'APP_URL', 'WHITY_PUBLIC_URL'], array_keys(self::DEFAULT_PATHS));
        $env = [];
        foreach ($names as $name) {
            $value = $_ENV[$name] ?? getenv($name);
            if (is_string($value)) {
                $env[$name] = $value;
            }
        }

        return new self($env);
    }

    /**
     * The origin frontend pages are served from: FRONTEND_URL, else APP_URL.
     */
    public function base(): string
    {
        $frontend = $this->value('FRONTEND_URL');

        return $frontend !== '' ? $frontend : $this->value('APP_URL');
    }

    /**
     * A link's URL prefix: its own override, else base() plus the bundled
     * admin's path. Empty when neither is known — never a bare relative path.
     *
     * @param string $link One of this class's link constants (an *_URL name).
     */
    public function link(string $link): string
    {
        if (!isset(self::DEFAULT_PATHS[$link])) {
            throw new \InvalidArgumentException('Unknown frontend link: ' . $link);
        }

        $override = $this->value($link);
        if ($override !== '') {
            return $override;
        }

        // The error-alert link predates this class and was built on
        // WHITY_PUBLIC_URL alone; a deployment that set it keeps getting it.
        if ($link === self::ERROR_DETAILS) {
            $legacy = $this->value('WHITY_PUBLIC_URL');
            if ($legacy !== '') {
                return $legacy . self::DEFAULT_PATHS[$link];
            }
        }

        $base = $this->base();

        return $base === '' ? '' : $base . self::DEFAULT_PATHS[$link];
    }

    /**
     * {@see link()}, but a same-origin relative path instead of '' when the
     * instance has no address. Only for a browser redirect, which resolves a
     * relative Location against the request; never for anything that leaves
     * the request (an email, a QR, a copied link).
     *
     * @param string $link One of this class's link constants (an *_URL name).
     */
    public function linkOrPath(string $link): string
    {
        $url = $this->link($link);

        return $url !== '' ? $url : self::DEFAULT_PATHS[$link];
    }

    private function value(string $name): string
    {
        return rtrim(trim($this->env[$name] ?? ''), '/');
    }
}
