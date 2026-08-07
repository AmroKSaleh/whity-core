<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

/**
 * Resolves a recipient's notification preferences into the set of channels a
 * given notification may be delivered on (WC-notifications). The dispatcher
 * calls {@see self::filterChannels()} before recording deliveries.
 *
 * Model (opt-OUT): a channel is delivered UNLESS the profile has an explicit
 * preference disabling it. An exact (type, channel) row wins over a
 * ('*', channel) channel-wide row; with no matching row the default is enabled.
 *
 * TRANSACTIONAL types — security / account / auth / password / billing by
 * default (a smart preset, overridable via the constructor) — ALWAYS deliver on
 * every requested channel regardless of stored preferences; they cannot be
 * disabled. A null recipient (no profile, e.g. an email to a non-member) also
 * bypasses filtering.
 */
final class NotificationPreferenceResolver
{
    /** The '*' type row is a channel-wide toggle (applies to every type). */
    public const WILDCARD = '*';

    /** Notification-type prefixes that always deliver, regardless of user prefs. */
    public const DEFAULT_TRANSACTIONAL_PREFIXES = ['security.', 'account.', 'auth.', 'password.', 'billing.'];

    private NotificationPreferenceRepository $repo;

    /** @var list<string> */
    private array $transactionalPrefixes;

    /**
     * @param list<string>|null $transactionalPrefixes
     */
    public function __construct(NotificationPreferenceRepository $repo, ?array $transactionalPrefixes = null)
    {
        $this->repo = $repo;
        $this->transactionalPrefixes = $transactionalPrefixes ?? self::DEFAULT_TRANSACTIONAL_PREFIXES;
    }

    /**
     * Whether a type is transactional (always delivered, cannot be disabled).
     */
    public function isTransactional(string $type): bool
    {
        foreach ($this->transactionalPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured transactional prefixes (so the API/UI can show which types
     * are forced-on / locked).
     *
     * @return list<string>
     */
    public function transactionalPrefixes(): array
    {
        return $this->transactionalPrefixes;
    }

    /**
     * Filter requested channels to those the recipient has not opted out of for
     * this type. Null profile or a transactional type keeps all channels.
     *
     * @param list<string> $channels
     * @return list<string>
     */
    public function filterChannels(int $tenantId, ?int $profileId, string $type, array $channels): array
    {
        if ($profileId === null || $this->isTransactional($type)) {
            return $channels;
        }

        $prefs = $this->repo->listForProfile($tenantId, $profileId);
        if ($prefs === []) {
            return $channels; // opt-out default: no prefs => everything enabled
        }

        $exact = [];
        $wildcard = [];
        foreach ($prefs as $p) {
            if ($p['type'] === $type) {
                $exact[$p['channel']] = $p['enabled'];
            } elseif ($p['type'] === self::WILDCARD) {
                $wildcard[$p['channel']] = $p['enabled'];
            }
        }

        $kept = [];
        foreach ($channels as $channel) {
            // Precedence: exact (type, channel) → ('*', channel) → default true.
            if ($exact[$channel] ?? $wildcard[$channel] ?? true) {
                $kept[] = $channel;
            }
        }

        return $kept;
    }
}
