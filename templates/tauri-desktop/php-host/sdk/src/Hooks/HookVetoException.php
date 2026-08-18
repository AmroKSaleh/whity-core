<?php

declare(strict_types=1);

namespace Whity\Sdk\Hooks;

use RuntimeException;

/**
 * The one exception a hook listener may throw to ABORT the operation that
 * dispatched it (SDK 1.15, WC-713).
 *
 * Every other Throwable raised inside a plugin hook is swallowed by the host's
 * per-plugin error boundary: a broken plugin must never take the platform down,
 * so its exception is logged, its lifecycle error counter ticks, and dispatch
 * continues with the payload unchanged. That isolation is deliberate — but it
 * also means a plugin had NO way to say "do not do this" or "my cleanup failed,
 * undo it". This exception is the sanctioned escape hatch: the host lets it (and
 * only it) cross the boundary.
 *
 * Where it is honoured
 * --------------------
 * ENTITY DELETION (`tenant.*`, `ou.*`, `role.*`). The host runs the `*.deleting`
 * hook, the DELETE, and the `*.deleted` hook inside ONE database transaction.
 * Throwing this from either hook rolls the whole transaction back — the row
 * survives — and the caller receives `409 Conflict` with `reason` echoed in the
 * error details. Concretely:
 *
 *  - from `*.deleting` — a VETO: "this record still has dependants of mine".
 *    Nothing has been written yet; the delete simply does not happen.
 *  - from `*.deleted` — a CLEANUP FAILURE: the row is gone *within* the
 *    transaction but not yet committed, so aborting here restores it rather than
 *    leaving the plugin's own tables orphaned.
 *
 * DATA-TYPE LIFECYCLE (`datatype.lifecycle.changing`). The host dispatches this
 * hook before the write, for every mutating action — `trash`, `restore`,
 * `retire` and `delete` alike — and inside the same transaction as the write.
 * Throwing this aborts the transition with nothing written, and the caller
 * receives `409 Conflict` with `reason: "blocked_by_plugin"` and this
 * exception's {@see reason()} as the message. Use it for a rule the host cannot
 * derive: a declared `blocks_delete` guard counts rows and governs DELETE only,
 * so "this record is depended on and must not be TRASHED" has no other
 * expression. The post-transition `datatype.lifecycle.changed` hook is
 * observation only — it fires after the write committed, so there is nothing
 * left for a veto to stop.
 *
 * Anywhere else this behaves like any other exception (i.e. it is isolated by
 * the error boundary), so throwing it from an unrelated hook is a no-op the host
 * logs. Never throw it from an `*.async` listener: those run in the relay worker,
 * long after the originating request committed, and there is nothing left to
 * veto.
 *
 * A veto is never counted as a plugin FAILURE. It is a healthy plugin doing
 * exactly what this contract invites it to do, so it does not tick the host's
 * consecutive-error breaker — a plugin disabled for refusing correctly would
 * stop refusing, silently.
 *
 * The {@see reason()} text is shown to the API caller, so write it for a human
 * administrator ("3 devices are still assigned to this unit"), never as a raw
 * internal error or with sensitive detail in it. It is trimmed and capped at
 * {@see REASON_MAX_LENGTH} characters.
 *
 * Example:
 * ```php
 * public function getHooks(): array
 * {
 *     return [Events::OU_DELETING => [$this, 'onOuDeleting']];
 * }
 *
 * public function onOuDeleting(array $data, array $context): array
 * {
 *     if ($this->countDevicesIn((int) $data['id']) > 0) {
 *         throw HookVetoException::forEvent(
 *             Events::OU_DELETING,
 *             'Devices are still assigned to this organizational unit.'
 *         );
 *     }
 *
 *     return $data;
 * }
 * ```
 */
final class HookVetoException extends RuntimeException
{
    /**
     * Maximum length of the client-visible {@see reason()} text. A listener that
     * writes more is truncated rather than rejected — a veto must never fail to
     * take effect because its explanation was too long.
     */
    public const REASON_MAX_LENGTH = 300;

    /**
     * The generic reason used when a listener supplies an empty one. The veto
     * still takes effect; only the explanation is missing.
     */
    public const DEFAULT_REASON = 'A plugin blocked this operation.';

    private string $eventName;

    private string $reason;

    /**
     * @param string $eventName The hook event being vetoed (e.g. `ou.deleting`).
     * @param string $reason    Human-readable, client-safe explanation.
     */
    public function __construct(string $eventName, string $reason = self::DEFAULT_REASON)
    {
        $this->eventName = trim($eventName);
        $this->reason = self::sanitizeReason($reason);

        parent::__construct(sprintf(
            'Hook listener vetoed "%s": %s',
            $this->eventName === '' ? '(unnamed event)' : $this->eventName,
            $this->reason
        ));
    }

    /**
     * Named constructor — reads better at the throw site than `new`.
     */
    public static function forEvent(string $eventName, string $reason = self::DEFAULT_REASON): self
    {
        return new self($eventName, $reason);
    }

    /**
     * The hook event that was vetoed.
     */
    public function eventName(): string
    {
        return $this->eventName;
    }

    /**
     * The client-safe explanation, normalised: trimmed, control characters
     * collapsed to spaces, and capped at {@see REASON_MAX_LENGTH}.
     *
     * Deliberately NOT `getMessage()` — the host's leak guard forbids raw
     * exception text in a client response, and this is the vetted subset that is
     * safe to surface.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Normalise listener-supplied text into something safe to echo back: strip
     * control characters (a newline in a JSON error detail is just noise),
     * collapse runs of whitespace, trim, and cap the length.
     *
     * The cap is applied with a UTF-8-aware PCRE pattern rather than
     * mb_substr(): the SDK depends on nothing but PHP itself (pinned by the
     * host's package-contract test), and ext-mbstring is not part of "nothing
     * but PHP" — PCRE's /u mode is always available. Truncating with plain
     * substr() would be worse than a missing cap, since it can slice an Arabic
     * or emoji code point in half and hand the client mojibake.
     */
    private static function sanitizeReason(string $reason): string
    {
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason) ?? $reason;
        $collapsed = preg_replace('/\s+/u', ' ', $collapsed) ?? $collapsed;
        $collapsed = trim($collapsed);

        if ($collapsed === '') {
            return self::DEFAULT_REASON;
        }

        // Whole code points only; `s` so a stray newline can never end the match
        // early. A non-UTF-8 byte sequence makes /u fail to match — fall back to
        // the generic reason rather than emitting invalid JSON.
        if (preg_match('/^.{0,' . self::REASON_MAX_LENGTH . '}/us', $collapsed, $matches) !== 1) {
            return self::DEFAULT_REASON;
        }

        return $matches[0];
    }
}
