<?php

declare(strict_types=1);

namespace Whity\Sdk\Notification;

/**
 * The pluggable delivery-channel contract (SDK v1.x).
 *
 * A transport delivers a {@see NotificationMessage} over ONE channel — email,
 * SMS, in-app inbox, web/mobile push. Core ships a null/log transport; plugins
 * contribute real ones (SMTP, an SMS provider, web push, …). The host registers
 * transports by {@see self::channel()} and resolves the active one per
 * (tenant, channel).
 *
 * Contract the transport must uphold:
 *  - **channel()** returns the stable channel id this transport serves.
 *  - **send() is FAIL-SOFT.** It returns a {@see SendResult} (sent/failed) and
 *    MUST NOT throw for an ordinary delivery failure (a bad address, a provider
 *    5xx, a timeout) — the host records the failure and applies retry/backoff.
 *    It may throw only for genuine misuse (a programming error).
 *  - **validateConfig()** checks a tenant's per-channel sender config
 *    (from/reply-to, credentials, endpoints) BEFORE it is persisted, returning
 *    human-readable error strings (empty ⇒ valid). It MUST NOT echo secrets
 *    back in an error message.
 */
interface NotificationTransport
{
    /**
     * The stable channel id this transport delivers (e.g. 'email').
     */
    public function channel(): string;

    /**
     * Attempt delivery of one message. Fail-soft: report failure via SendResult,
     * do not throw for ordinary delivery errors.
     */
    public function send(NotificationMessage $message): SendResult;

    /**
     * Validate a tenant's per-channel sender configuration.
     *
     * @param array<string, mixed> $config
     * @return list<string> Validation errors; empty when the config is valid.
     */
    public function validateConfig(array $config): array;
}
