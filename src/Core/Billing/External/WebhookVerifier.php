<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * The only thing standing between a public URL and this tenant's access.
 *
 * The notification endpoint cannot be authenticated the ordinary way — the
 * sender is a server, holds no session, and never will. The signature IS the
 * credential, which means every property below is load-bearing rather than
 * ceremonial.
 *
 * OVER THE RAW BODY, NEVER A RE-ENCODED PARSE. Decoding JSON and re-encoding it
 * reorders keys and changes escaping, so the bytes signed stop being the bytes
 * verified and every legitimate delivery fails. The failure looks like a wrong
 * secret, which is the wrong thing to go and check.
 *
 * `hash_equals`, NEVER `===`. A comparison that returns at the first differing
 * byte leaks how much of a forged signature was right, and a few thousand
 * attempts turn that leak into the whole digest.
 *
 * VERIFIED BEFORE ANYTHING IS PARSED. Not "before state changes" — before the
 * payload is even decoded, so a forged body never reaches a parser.
 *
 * NO SECRET, NO ACCEPTANCE. A deployment that has not configured one refuses
 * every delivery. The alternative — treating an unset secret as "skip
 * verification" — turns a missing environment variable into an open endpoint
 * that grants paid access to anyone who finds it.
 */
final class WebhookVerifier
{
    /**
     * How far the sender's clock may be from ours before a delivery is stale.
     *
     * Bounds replay of a captured request. Retries are re-signed with a fresh
     * timestamp, so a window this size does not interfere with them.
     */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /** @var callable(): int */
    private $clock;

    /**
     * @param string        $secret    Empty means no billing service is configured.
     * @param callable(): int|null $clock
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param string $rawBody   The bytes as received. Not a re-encoding of them.
     * @param string $signature The `X-Pay-Signature` header, `sha256=` + hex.
     * @param string $timestamp The `X-Pay-Timestamp` header, unix seconds.
     */
    public function verify(string $rawBody, string $signature, string $timestamp): bool
    {
        if ($this->secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        // The timestamp is part of the signed payload, so this check cannot be
        // skipped by an attacker who only edits the header — editing it
        // invalidates the signature. It is checked first because it is cheap and
        // because a stale-but-authentic replay should not even be hashed.
        if (!$this->timestampIsFresh($timestamp)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);

        return hash_equals($expected, $signature);
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (preg_match('/^\d{1,20}$/', $timestamp) !== 1) {
            return false;
        }

        $drift = abs(($this->clock)() - (int) $timestamp);

        // Symmetric on purpose: a timestamp far in the FUTURE is as suspicious
        // as one far in the past, and clamping only the past half would let a
        // captured request be replayed indefinitely by moving it forward.
        return $drift <= $this->toleranceSeconds;
    }
}
