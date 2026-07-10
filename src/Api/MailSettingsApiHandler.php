<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Mail\MailException;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\NullMailer;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Email settings API (WC-email) — the operator-only surface backing the admin
 * "Email" settings page. It complements the generic settings endpoints (which
 * carry the plaintext `mail.*` config) with the two things those cannot express:
 *
 *  - GET  /api/v1/settings/mail/status        (settings:manage, system tenant) —
 *        the current transport plus whether an SMTP password is stored. The
 *        password itself is write-only and is NEVER returned.
 *  - PUT  /api/v1/settings/mail/smtp-password (settings:manage, system tenant) —
 *        set (encrypt-at-rest) or clear the SMTP password. Body `{ "password": ... }`;
 *        null/empty clears it.
 *  - POST /api/v1/settings/mail/test          (settings:manage, system tenant) —
 *        send a one-off test message to a given address using the CURRENTLY
 *        configured transport, so the operator can verify SMTP end-to-end.
 *
 * Like {@see SettingsApiHandler}, global mail config is a SYSTEM-TENANT resource:
 * `settings:manage` is necessary but not sufficient — the caller must also be
 * acting in the system tenant (id 0), so a self-service tenant owner can never
 * reach instance-wide mail settings (cross-tenant escalation guard, WC-235).
 *
 * Issues no SQL directly except through {@see GlobalSettingsRepository} for the
 * out-of-registry encrypted-password key. Holds no request state — safe for a
 * FrankenPHP worker.
 */
final class MailSettingsApiHandler
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly GlobalSettingsRepository $globals,
        private readonly EncryptedSecretStore $secrets,
        private readonly RoleChecker $roleChecker,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * GET /api/v1/settings/mail/status — transport + whether a password is stored.
     */
    public function status(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        try {
            $global = $this->settings->getGlobal();

            return Response::json([
                'data' => [
                    'transport' => $global[SettingsRegistry::MAIL_TRANSPORT] ?? 'none',
                    'has_smtp_password' => $this->hasStoredPassword(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('[MailSettingsApiHandler] status failed: ' . $e->getMessage());
            return Response::error('Failed to read mail status', 500);
        }
    }

    /**
     * PUT /api/v1/settings/mail/smtp-password — set or clear the SMTP password.
     *
     * Body: `{ "password": "<secret>" | null }`. A null or empty value clears the
     * stored password; a non-empty value is encrypted at rest and stored. The
     * value is never echoed back.
     */
    public function setPassword(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $body = JsonBody::parsed($request);
        if (!array_key_exists('password', $body)) {
            return Response::error('Request body must include a "password" field (null to clear)', 400);
        }
        $password = $body['password'];

        if ($password !== null && !is_string($password)) {
            return Response::error('Validation failed', 422, ['password' => 'password must be a string or null.']);
        }

        try {
            if ($password === null || $password === '') {
                $this->globals->delete(MailerFactory::SMTP_PASSWORD_SETTING_KEY);
            } else {
                $this->globals->set(
                    MailerFactory::SMTP_PASSWORD_SETTING_KEY,
                    $this->secrets->encrypt($password)
                );
            }

            return Response::json(['data' => ['has_smtp_password' => $this->hasStoredPassword()]], 200);
        } catch (\Throwable $e) {
            // Never log the password or crypto detail.
            $this->logger->error('[MailSettingsApiHandler] setPassword failed: ' . $e->getMessage());
            return Response::error('Failed to store SMTP password', 500);
        }
    }

    /**
     * POST /api/v1/settings/mail/test — send a test message via the current transport.
     *
     * Body: `{ "to": "<email>" }`. Builds the mailer from the CURRENT settings and
     * attempts one send. Returns 422 when email is not configured (no transport /
     * incomplete SMTP), 502 on a transport failure (with a safe message), 200 on
     * success.
     */
    public function test(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $body = JsonBody::parsed($request);
        $to = $body['to'] ?? null;
        if (!is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('Validation failed', 422, ['to' => 'to must be a valid email address.']);
        }

        $mailer = MailerFactory::fromSettings($this->settings, $this->globals, $this->secrets, $this->logger);

        // A NullMailer means email is disabled or the SMTP config is incomplete —
        // "sending" would silently no-op, which is a misleading test result.
        if ($mailer instanceof NullMailer) {
            return Response::error(
                'Email is not configured: set a transport and complete the SMTP settings first',
                422
            );
        }

        $siteName = $this->settings->getGlobal()[SettingsRegistry::SITE_NAME] ?? 'Whity';

        try {
            $mailer->send(
                $to,
                sprintf('%s: test email', $siteName),
                "This is a test message from your Whity instance.\r\n\r\n"
                . "If you received it, your email settings are working."
            );

            return Response::json(['data' => ['sent' => true]], 200);
        } catch (MailException $e) {
            // The transport/protocol detail goes to the SERVER log only — client
            // responses never carry raw exception text (WC-186). The operator
            // reads the worker log to see the exact SMTP failure.
            $this->logger->warning('[MailSettingsApiHandler] test send failed: ' . $e->getMessage());
            return Response::error('Test email failed to send; check the server logs for the SMTP error detail', 502);
        } catch (\Throwable $e) {
            $this->logger->error('[MailSettingsApiHandler] test send error: ' . $e->getMessage());
            return Response::error('Test email failed', 502);
        }
    }

    /**
     * Whether a non-empty encrypted SMTP password is stored.
     */
    private function hasStoredPassword(): bool
    {
        $stored = $this->globals->get(MailerFactory::SMTP_PASSWORD_SETTING_KEY);

        return $stored !== null && $stored !== '';
    }

    /**
     * Resolve the tenant + acting user, require `settings:manage`, and enforce the
     * system-tenant constraint (global mail config is instance-wide).
     *
     * @return array{tenantId: int, userId: int}|Response Context, or a 403 Response.
     */
    private function authorize(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::SETTINGS_MANAGE, $tenantId)
        ) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::SETTINGS_MANAGE]);
        }

        if ($tenantId !== SettingsService::SYSTEM_TENANT_ID) {
            return Response::error('Mail settings are managed by the system tenant only', 403);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
