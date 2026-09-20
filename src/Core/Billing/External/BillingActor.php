<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * Who asked for a change, in terms the billing service can write down.
 *
 * ── The gap this closes ────────────────────────────────────────────────────
 *
 * The billing service records the CLIENT that called it — `client:whity` — and
 * nothing finer, because an API key is the whole of what it knows. So "who moved
 * this customer's tier" was unanswerable there, and answerable here only if
 * somebody had thought to write it down.
 *
 * That gap has already cost us once. Moving three tenants between tiers
 * destroyed the record of who had been on what, because `tenant_plan` holds only
 * current state; it had to be reconstructed from a dump taken beforehand. This
 * is the same question one service further out.
 *
 * ── An opaque id, never an address ─────────────────────────────────────────
 *
 * `profile:412`, not an email. What we send is rendered on the billing service's
 * operator dashboard, which is a surface shared across every client they serve —
 * so an address here would be personal data leaving this deployment and landing
 * somewhere we do not control. Their side refuses an `@` outright; this refuses
 * it before the request is built, so the constraint is visible where somebody
 * would otherwise write one rather than as a 422 on a tier change at the worst
 * possible moment.
 *
 * ── A machine must say it is a machine ─────────────────────────────────────
 *
 * The device sweep resizes subscriptions on nobody's instruction. Attributing
 * that to whichever profile happened to be nearby would invent a person, which
 * is worse than attributing nothing: an invented actor is indistinguishable from
 * a real one, and somebody would eventually be asked why they did it.
 *
 * ── The prefixes are OURS ──────────────────────────────────────────────────
 *
 * The billing service stores what it is given verbatim and derives nothing from
 * `profile:` or `job:` — deliberately, so this naming scheme never becomes their
 * problem. It only has to round-trip.
 */
final class BillingActor
{
    /**
     * Their limit, mirrored here.
     *
     * Their audit column holds `client:<slug> actor:<hint>`, so a hint longer
     * than this overflows a row rather than failing a header check — which on
     * PostgreSQL is an error that takes the whole request with it. Refusing at
     * construction means a too-long actor cannot turn into a refused plan
     * change.
     */
    public const MAX_LENGTH = 64;

    private function __construct(public readonly string $value)
    {
    }

    /**
     * A person, by the id their own directory resolves.
     *
     * Their log then reads `client:whity actor:profile:412`, and ours knows who
     * 412 is. The two join at the seam without anything personal crossing it.
     */
    public static function person(int $profileId): self
    {
        return new self('profile:' . $profileId);
    }

    /**
     * A scheduled sweep, naming itself.
     *
     * @param string $name Which job. Dashes and dots only — this ends up in a
     *                     log line on a shared screen.
     */
    public static function job(string $name): self
    {
        $name = trim($name);

        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                'A job actor may use letters, digits, dots, dashes and underscores only.'
            );
        }

        return self::of('job:' . $name);
    }

    /**
     * Anything else, checked against exactly what the other side will accept.
     *
     * @throws \InvalidArgumentException When the value would be refused there.
     *         Raised HERE rather than discovered as a 422, because the calls
     *         this rides on are tier changes and checkouts: the moment to find
     *         out is while writing the code, not while a customer is waiting.
     */
    public static function of(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('An actor cannot be empty; omit it instead of sending nothing.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('An actor must be %d characters or fewer.', self::MAX_LENGTH)
            );
        }

        if (str_contains($value, '@')) {
            throw new \InvalidArgumentException(
                'An actor must be an opaque identifier, not an email address — it is rendered on an '
                . 'operator dashboard shared across every client the billing service serves.'
            );
        }

        // Printable ASCII, no spaces. A newline is the one that matters: their
        // audit entry is one line, so `profile:1\nclient:someone-else` would
        // forge a second one.
        if (preg_match('/^[\x21-\x7E]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('An actor must be printable ASCII with no spaces.');
        }

        return new self($value);
    }

    /** The header this travels in, named once. */
    public const HEADER = 'X-Pay-Actor';

    /** @return array<string, string> Ready to merge into a request's headers. */
    public function header(): array
    {
        return [self::HEADER => $this->value];
    }
}
