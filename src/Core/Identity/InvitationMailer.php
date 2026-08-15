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
 * Sends the tenant-invitation email (WHIT-417).
 *
 * Direct-call sender, like {@see PasswordResetMailer} rather than the
 * hook-subscriber {@see \Whity\Core\Mail\EmailNotifications}: the raw token
 * exists for exactly one statement inside the handler and is never persisted,
 * so putting it on a hook payload would broadcast a live credential to every
 * listener a plugin cares to register. The existing `user.created` hook still
 * sends its "you've been added" note for the admin-creates-a-user path; this
 * is the tokened cousin for the invite path.
 *
 * Gated on the SAME `mail.events.invitation_enabled`
 * ({@see SettingsRegistry::MAIL_EVENT_INVITATION}) that already governs that
 * note, so an operator who has turned invitation mail off stays off — a second
 * switch for the same category is a switch somebody will miss.
 *
 * ONE THING THE COPY MUST NOT SAY: whether the address already has an account.
 * The mail is identical either way. The invitee learns which flow they are in
 * from the accept page, after presenting the token — the recipient of the mail
 * has proven mailbox control, but the mail itself may be forwarded, quoted in a
 * ticket, or sent to an address chosen by an administrator who was guessing.
 *
 * Best-effort throughout: the caller swallows delivery failures so a broken
 * SMTP relay can never change an API response shape.
 */
final class InvitationMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $acceptUrlBase,
        private readonly EmailLayout $layout,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Send the invitation link.
     *
     * @param string $tenantName The inviting workspace's display name; blank
     *        falls back to the instance name, so a tenant with no name set
     *        still produces a sentence rather than a gap.
     * @param string $rawToken   Resolved from {@see InvitationService::invite()}
     *        and embedded in the link; nothing else about it is transmitted.
     * @param int    $ttlDays    How long the link stays usable — stated in the
     *        mail so "it stopped working" is an expectation, not a support ticket.
     */
    public function sendInvitation(string $email, string $tenantName, string $rawToken, int $ttlDays): void
    {
        if (!$this->eventEnabled()) {
            return;
        }

        $branding = EmailBranding::fromSettings($this->settings);
        $workspace = trim($tenantName) !== '' ? trim($tenantName) : $branding->siteName;
        $link = $this->buildLink($rawToken);
        $expiry = $ttlDays === 1 ? '1 day' : $ttlDays . ' days';

        $rendered = $this->layout->render(
            new EmailContent(
                heading: 'You have been invited to ' . $workspace,
                paragraphs: [
                    'An administrator has invited you to join ' . $workspace . ' on ' . $branding->siteName . '.',
                    'Use the link below to accept. It expires in ' . $expiry . ' and can only be used once.',
                ],
                ctaLabel: 'Accept invitation',
                ctaUrl: $link,
                callout: 'Workspace: ' . $workspace,
                footnote: 'If you were not expecting this invitation you can ignore this message — nothing is created until you accept.',
            ),
            $branding,
        );

        $this->mailer->send($email, 'You have been invited to ' . $workspace, $rendered->text, $rendered->html);
    }

    private function eventEnabled(): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[SettingsRegistry::MAIL_EVENT_INVITATION] ?? 'false') === 'true';
    }

    private function buildLink(string $token): string
    {
        $base = rtrim($this->acceptUrlBase, '?&');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'token=' . urlencode($token);
    }
}
