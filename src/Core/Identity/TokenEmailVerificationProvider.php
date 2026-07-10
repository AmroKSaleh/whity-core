<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Settings\SettingsService;

/**
 * Concrete {@see EmailVerificationProvider} (WC-235): issues a token via
 * {@see EmailVerificationService} and delivers the verification link via a
 * {@see Mailer}. Bound in the composition root in place of
 * {@see NullEmailVerificationProvider} so that, when EMAIL_VERIFICATION_ENFORCED
 * is on, registration (and the resend endpoint) actually send a link.
 *
 * Resolution is by email (globally unique in profile_emails). Already-verified
 * or unknown addresses are a silent no-op — there is nothing to verify, and the
 * public resend path must not reveal which addresses exist.
 *
 * When an {@see EmailLayout} + {@see SettingsService} are supplied (production
 * wiring), the link is delivered as a branded HTML email (with a plain-text
 * alternative). Without them it falls back to a plain-text message.
 */
final class TokenEmailVerificationProvider implements EmailVerificationProvider
{
    public function __construct(
        private readonly EmailVerificationService $service,
        private readonly ProfileEmailRepository $emails,
        private readonly Mailer $mailer,
        private readonly string $verifyUrlBase,
        private readonly ?EmailLayout $layout = null,
        private readonly ?SettingsService $settings = null,
    ) {}

    public function sendVerification(int $profileId, string $email): void
    {
        $row = $this->emails->findByEmail($email);

        // Nothing to do: unknown address, belongs to another profile, or already
        // verified. Silent (no enumeration, no redundant email).
        if ($row === null
            || (int) $row['profile_id'] !== $profileId
            || $row['verified'] === true
        ) {
            return;
        }

        $token = $this->service->issue((int) $row['id']);
        $link  = $this->buildLink($token);
        $subject = 'Verify your email address';

        if ($this->layout !== null && $this->settings !== null) {
            $rendered = $this->layout->render(
                new EmailContent(
                    heading: 'Confirm your email address',
                    paragraphs: [
                        'Thanks for signing up. Confirm this address to activate your account and secure it to you.',
                    ],
                    ctaLabel: 'Verify email address',
                    ctaUrl: $link,
                    footnote: "If you didn't request this, you can safely ignore this message.",
                ),
                EmailBranding::fromSettings($this->settings),
            );
            $this->mailer->send($email, $subject, $rendered->text, $rendered->html);

            return;
        }

        // Fallback: plain-text only (no layout/branding wired).
        $this->mailer->send(
            $email,
            $subject,
            "Confirm your email address by opening this link:\n\n{$link}\n\n"
            . "If you did not request this, you can ignore this message."
        );
    }

    /**
     * Compose the verification link. The base may already carry a query string;
     * append the token with the correct separator.
     */
    private function buildLink(string $token): string
    {
        $base = rtrim($this->verifyUrlBase, '?&');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'token=' . urlencode($token);
    }
}
