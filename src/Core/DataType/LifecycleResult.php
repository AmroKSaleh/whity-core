<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

/**
 * The outcome of a lifecycle transition (WC-723).
 *
 * A refusal is a RESULT, not an exception: "you cannot delete this, three
 * recorded entries still point at it" is a normal, expected answer that the
 * caller must be able to render, not an error condition. Modelling it as a
 * value keeps the blockers attached to the refusal, which is what turns a bare
 * 409 into a message the user can act on.
 */
final class LifecycleResult
{
    /** The transition happened (or was already true — transitions are idempotent). */
    public const OK = 'ok';

    /** No such record in this tenant. Indistinguishable from another tenant's row, deliberately. */
    public const NOT_FOUND = 'not_found';

    /** A declared referential guard has blocking rows. */
    public const BLOCKED = 'blocked';

    /** The transition is not legal from the record's current state. */
    public const REFUSED = 'refused';

    /** The type does not offer this action at all (undeclared lifecycle or permission). */
    public const UNSUPPORTED = 'unsupported';

    private string $outcome;

    /**
     * The record's state after the call (or at the time of refusal).
     */
    private ?string $state;

    /**
     * @var list<array{table: string, label: string, count: int}>
     */
    private array $blockers;

    /**
     * A stable machine-readable reason key, for refusals and unsupported calls.
     */
    private ?string $reason;

    /**
     * @param string                                                $outcome  One of the class constants.
     * @param string|null                                           $state    Resulting/current state.
     * @param list<array{table: string, label: string, count: int}> $blockers Blocking references.
     * @param string|null                                           $reason   Stable reason key.
     */
    private function __construct(string $outcome, ?string $state, array $blockers, ?string $reason)
    {
        $this->outcome = $outcome;
        $this->state = $state;
        $this->blockers = $blockers;
        $this->reason = $reason;
    }

    /**
     * The transition succeeded, leaving the record in `$state`.
     *
     * @param string|null $state The resulting state (null when the row is gone).
     */
    public static function ok(?string $state): self
    {
        return new self(self::OK, $state, [], null);
    }

    /**
     * No such record in this tenant.
     */
    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null, [], 'not_found');
    }

    /**
     * A guard blocks the transition; `$blockers` says which and how many.
     *
     * @param list<array{table: string, label: string, count: int}> $blockers Blocking references.
     * @param string|null                                           $state    The record's current state.
     */
    public static function blocked(array $blockers, ?string $state = null): self
    {
        return new self(self::BLOCKED, $state, $blockers, 'still_referenced');
    }

    /**
     * The transition is not legal from the record's current state.
     *
     * @param string      $reason A stable reason key.
     * @param string|null $state  The record's current state.
     */
    public static function refused(string $reason, ?string $state = null): self
    {
        return new self(self::REFUSED, $state, [], $reason);
    }

    /**
     * The type does not offer this action.
     *
     * @param string $reason A stable reason key.
     */
    public static function unsupported(string $reason): self
    {
        return new self(self::UNSUPPORTED, null, [], $reason);
    }

    /**
     * Whether the transition succeeded.
     */
    public function isOk(): bool
    {
        return $this->outcome === self::OK;
    }

    /**
     * The outcome constant.
     */
    public function outcome(): string
    {
        return $this->outcome;
    }

    /**
     * The record's state after the call, or at the time of refusal.
     */
    public function state(): ?string
    {
        return $this->state;
    }

    /**
     * The blocking references, empty unless the outcome is {@see self::BLOCKED}.
     *
     * @return list<array{table: string, label: string, count: int}>
     */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /**
     * The stable machine-readable reason key, or null on success.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * The HTTP status this outcome maps to.
     *
     * BLOCKED and REFUSED are both 409: the request was well-formed and
     * authorized, and the CURRENT STATE of the data is what forbids it — which
     * is precisely what 409 means. UNSUPPORTED is 405: the action does not
     * exist for this type, at any state.
     */
    public function httpStatus(): int
    {
        return match ($this->outcome) {
            self::OK => 200,
            self::NOT_FOUND => 404,
            self::BLOCKED, self::REFUSED => 409,
            default => 405,
        };
    }

    /**
     * A human-readable sentence naming what blocks the action.
     *
     * Built from the plugin's own labels: core never learns what
     * `acme_entries` is, only that the refusal should say "3 recorded entries".
     */
    public function message(): string
    {
        return match ($this->outcome) {
            self::OK => 'Done',
            self::NOT_FOUND => 'Not found',
            self::BLOCKED => 'Still referenced by ' . implode(', ', array_map(
                static fn (array $b): string => $b['count'] . ' ' . $b['label'],
                $this->blockers
            )),
            self::REFUSED => match ($this->reason) {
                'retirement_is_permanent' => 'A retired record cannot be restored — retirement is permanent',
                'retired_records_are_permanent' => 'A retired record cannot be deleted — existing references still resolve to it',
                'retired_records_cannot_be_trashed' => 'A retired record cannot be trashed — retirement is not a mistake to undo',
                'restore_before_retiring' => 'Restore this record before retiring it — a trashed record is a mistake, not an achievement',
                'trash_before_deleting' => 'Move this record to the trash before deleting it',
                default => 'The record\'s current state does not allow this action',
            },
            default => 'This data type does not offer that action',
        };
    }

    /**
     * The result as a JSON-serialisable payload.
     *
     * @return array{outcome: string, state: ?string, reason: ?string, message: string, blockers: list<array{table: string, label: string, count: int}>}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'state' => $this->state,
            'reason' => $this->reason,
            'message' => $this->message(),
            'blockers' => $this->blockers,
        ];
    }
}
