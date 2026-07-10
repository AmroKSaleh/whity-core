<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Sends the platform's customer-facing lifecycle emails (WC-email) by listening
 * on {@see HookManager} events, rendering them through the branded
 * {@see EmailLayout}, and delivering via the {@see Mailer}.
 *
 * Decoupled by design: the flows that create/approve/invite accounts just
 * dispatch a hook; this subscriber (and any plugin) reacts. Every send is:
 *  - gated on the matching `mail.events.*` toggle (operator opt-out per event),
 *  - best-effort — a failure is logged and swallowed so it can never break the
 *    originating request (email is a side channel), and
 *  - a no-op when the transport is off (the injected mailer is a NullMailer).
 *
 * Registered on the hook manager once at boot via {@see subscribe()}.
 */
final class EmailNotifications
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly EmailLayout $layout,
        private readonly SettingsService $settings,
        private readonly string $appUrl = '',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Wire this subscriber's listeners onto the hook manager.
     */
    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen('registration.completed', [$this, 'onRegistrationCompleted']);
    }

    /**
     * Welcome a newly-registered owner — but only once their account is usable
     * (approval NOT pending). When approval is required the welcome is deferred to
     * the approval email, so a pending owner isn't told to "sign in" prematurely.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onRegistrationCompleted(array $data, array $context = []): array
    {
        if (($data['approval_required'] ?? false) === true) {
            return $data;
        }
        if (!$this->eventEnabled(SettingsRegistry::MAIL_EVENT_WELCOME)) {
            return $data;
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return $data;
        }
        $name = trim((string) ($data['name'] ?? ''));
        $branding = EmailBranding::fromSettings($this->settings);

        $greeting = 'Welcome to ' . $branding->siteName . ($name !== '' ? ', ' . $name : '');
        $this->trySend(
            $email,
            'Welcome to ' . $branding->siteName,
            new EmailContent(
                heading: $greeting,
                paragraphs: [
                    'Your workspace is ready — ' . $branding->siteName
                        . ' is your self-hosted home for teams and tools, fully under your control.',
                    'Sign in to invite your team, set up single sign-on, and make it yours.',
                ],
                ctaLabel: $this->appUrl !== '' ? 'Open your workspace' : null,
                ctaUrl: $this->appUrl !== '' ? $this->appUrl : null,
            ),
            $branding,
        );

        return $data;
    }

    /**
     * Whether an event's `mail.events.*` toggle is on. A settings-read failure
     * disables the send (fail-closed for a side channel).
     */
    private function eventEnabled(string $key): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[$key] ?? 'false') === 'true';
    }

    /**
     * Render + send, swallowing any failure (best-effort side channel).
     */
    private function trySend(string $toEmail, string $subject, EmailContent $content, EmailBranding $branding): void
    {
        try {
            $rendered = $this->layout->render($content, $branding);
            $this->mailer->send($toEmail, $subject, $rendered->text, $rendered->html);
        } catch (\Throwable $e) {
            $this->logger->warning('[mail] lifecycle notification failed: ' . $e->getMessage());
        }
    }
}
