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
 * Sends the "I lost my 2FA device" recovery-request transactional emails
 * (WC-password-reset-2fa-recovery): the confirmation link (proves mailbox
 * ownership before the request becomes admin-visible) and the "your request
 * was submitted" notice once it lands in the admin queue.
 *
 * Direct-call sender, same rationale as {@see PasswordResetMailer} (a public,
 * unauthenticated request, not an authenticated lifecycle hook). Reuses the
 * SAME `mail.events.password_reset_enabled` toggle as password-reset — both
 * are account-recovery emails from the same feature area, and this avoids
 * introducing a second, narrower settings key for what an operator would
 * think of as one "account recovery email" concern.
 */
final class TwoFactorRecoveryMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $confirmUrlBase,
        private readonly EmailLayout $layout,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Send the confirmation link. Caller resolves the raw token from
     * {@see TwoFactorRecoveryService::issue()} and passes it here.
     */
    public function sendConfirmationLink(string $email, string $rawToken): void
    {
        if (!$this->eventEnabled()) {
            return;
        }

        $link = $this->buildLink($rawToken);

        $rendered = $this->layout->render(
            new EmailContent(
                heading: 'Confirm your account-recovery request',
                paragraphs: [
                    'We received a request to recover your account because you may have lost access to your password and your two-factor device.',
                    'Confirm this request to submit it for administrator review. This link expires in 1 hour.',
                ],
                ctaLabel: 'Confirm recovery request',
                ctaUrl: $link,
                footnote: "If you didn't request this, you can safely ignore this message.",
            ),
            EmailBranding::fromSettings($this->settings),
        );
        $this->mailer->send($email, 'Confirm your account-recovery request', $rendered->text, $rendered->html);
    }

    /**
     * Send the "your request was submitted" notice once the confirmed request
     * lands in the admin queue.
     */
    public function sendSubmittedNotice(string $email): void
    {
        if (!$this->eventEnabled()) {
            return;
        }

        $rendered = $this->layout->render(
            new EmailContent(
                heading: 'Your account-recovery request was submitted',
                paragraphs: [
                    'Your account-recovery request has been submitted for administrator review. You will receive another email once it has been reviewed.',
                    "If you didn't request this, contact an administrator.",
                ],
            ),
            EmailBranding::fromSettings($this->settings),
        );
        $this->mailer->send($email, 'Your account-recovery request was submitted', $rendered->text, $rendered->html);
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
        $base = rtrim($this->confirmUrlBase, '?&');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'token=' . urlencode($token);
    }
}
