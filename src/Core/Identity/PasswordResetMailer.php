<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Sends the forgotten-password transactional emails
 * (WC-password-reset-2fa-recovery): the reset-link itself, and the courtesy
 * "your password was reset" notice once an admin-approved reset is applied.
 *
 * Direct-call sender (mirrors {@see TokenEmailVerificationProvider}) rather
 * than a hook-subscriber (like {@see \Whity\Core\Mail\EmailNotifications}):
 * forgot-password is triggered by an UNAUTHENTICATED public request, not an
 * authenticated lifecycle hook, so there is no natural hook payload to listen
 * for — the public handler calls this directly, exactly like registration's
 * email-verification link.
 *
 * Every send is gated on `mail.events.password_reset_enabled`
 * ({@see SettingsRegistry::MAIL_EVENT_PASSWORD_RESET}), rendered via
 * {@see EmailContent}/{@see EmailLayout}/{@see EmailBranding::fromSettings()},
 * and best-effort — a delivery failure is swallowed by the caller (never
 * changes the generic public response shape).
 */
final class PasswordResetMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $resetUrlBase,
        private readonly EmailLayout $layout,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Send the reset link. Caller resolves the raw token from
     * {@see PasswordResetService::issue()} and passes it here to embed in the
     * link; nothing beyond the link is ever transmitted.
     */
    public function sendResetLink(string $email, string $rawToken): void
    {
        if (!$this->eventEnabled()) {
            return;
        }

        $link = $this->buildLink($rawToken);

        $rendered = $this->layout->render(
            new EmailContent(
                heading: 'Reset your password',
                paragraphs: [
                    'We received a request to reset the password for your account.',
                    'This link expires in 1 hour. If you did not request this, you can safely ignore this message — your password will not change.',
                ],
                ctaLabel: 'Reset password',
                ctaUrl: $link,
                footnote: "If you didn't request this, no action is needed.",
            ),
            EmailBranding::fromSettings($this->settings),
        );
        $this->mailer->send($email, 'Reset your password', $rendered->text, $rendered->html);
    }

    /**
     * Send the courtesy "your reset was approved" notice once an
     * admin-approved reset has been applied. Best-effort security awareness —
     * the user should know their credential changed even though they are the
     * one who staged the new password.
     */
    public function sendApprovedNotice(string $email): void
    {
        if (!$this->eventEnabled()) {
            return;
        }

        $rendered = $this->layout->render(
            new EmailContent(
                heading: 'Your password reset was approved',
                paragraphs: [
                    'An administrator approved your pending password-reset request. Your new password is now active — you can sign in with it.',
                    "If you didn't request this, contact an administrator immediately.",
                ],
            ),
            EmailBranding::fromSettings($this->settings),
        );
        $this->mailer->send($email, 'Your password reset was approved', $rendered->text, $rendered->html);
    }

    private function eventEnabled(): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[SettingsRegistry::MAIL_EVENT_PASSWORD_RESET] ?? 'false') === 'true';
    }

    private function buildLink(string $token): string
    {
        $base = rtrim($this->resetUrlBase, '?&');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'token=' . urlencode($token);
    }
}
