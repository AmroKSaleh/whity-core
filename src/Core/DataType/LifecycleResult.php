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

    /**
     * The reason key a plugin's veto carries.
     *
     * A veto is published as an ordinary REFUSED outcome under one STABLE key
     * rather than as a new outcome or as the plugin's own words in `reason`.
     * Both alternatives were rejected on the same ground: `reason` is the
     * contract clients branch on (see {@see self::message()} and
     * docs/wiki/Plugin-Data-Types.md), so it must stay a fixed, enumerable
     * vocabulary. A plugin-authored string there would turn the branchable field
     * into free prose, and a per-plugin key would make the set unenumerable.
     * The plugin's own sentence goes where every other human-readable
     * explanation goes: `message`.
     */
    public const BLOCKED_BY_PLUGIN = 'blocked_by_plugin';

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
     * A sentence supplied by whoever refused, used INSTEAD of core's own.
     *
     * Set only for a plugin veto, where the explanation is not core's to write:
     * the host does not know what "a downstream record would become unusable"
     * means, and inventing a generic sentence in its place would throw away the
     * only part of the refusal a human can act on.
     */
    private ?string $explanation;

    /**
     * @param string                                                $outcome     One of the class constants.
     * @param string|null                                           $state       Resulting/current state.
     * @param list<array{table: string, label: string, count: int}> $blockers    Blocking references.
     * @param string|null                                           $reason      Stable reason key.
     * @param string|null                                           $explanation Refuser-supplied sentence, if any.
     */
    private function __construct(
        string $outcome,
        ?string $state,
        array $blockers,
        ?string $reason,
        ?string $explanation = null
    ) {
        $this->outcome = $outcome;
        $this->state = $state;
        $this->blockers = $blockers;
        $this->reason = $reason;
        $this->explanation = $explanation;
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
     * The record itself is free, but a row it OWNS is still referenced.
     *
     * A distinct reason key rather than a second flavour of `still_referenced`,
     * because the two send the caller to different places: `still_referenced`
     * means "detach what points at this record", while this one means "something
     * points at one of its parts". Reporting the first for the second would send
     * a user hunting for references to a record that has none.
     *
     * The blockers ride along under the same shape and the same rule — a table,
     * the declaring plugin's label for it, and a count — so a renderer needs no
     * second code path to say what is in the way. What widened is only WHERE
     * those rows point: at this record, or at something it owns. `reason` is what
     * says which, and it is the field clients branch on.
     *
     * @param list<array{table: string, label: string, count: int}> $blockers Blocking references.
     * @param string|null                                           $state    The record's current state.
     */
    public static function compositionBlocked(array $blockers, ?string $state = null): self
    {
        return new self(self::BLOCKED, $state, $blockers, 'composition_still_referenced');
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
     * A plugin listening on `datatype.lifecycle.changing` refused the transition.
     *
     * Deliberately an ordinary REFUSED outcome — the same 409, the same
     * `{reason, message}` pair, the same envelope as every state refusal — so a
     * client branches on ONE contract rather than learning a second shape for
     * the same event: "this transition did not happen, and here is why".
     *
     * `$explanation` is the veto's {@see \Whity\Sdk\Hooks\HookVetoException::reason()},
     * never its `getMessage()`. That boundary is the WC-186 leak guard and it is
     * not negotiable: `getMessage()` is raw exception text that may carry
     * internal detail, while `reason()` is the trimmed, control-character-
     * stripped, length-capped subset a plugin author wrote FOR the client.
     *
     * @param string      $explanation The veto's client-safe sentence.
     * @param string|null $state       The record's state, unchanged by the refusal.
     */
    public static function vetoed(string $explanation, ?string $state = null): self
    {
        return new self(self::REFUSED, $state, [], self::BLOCKED_BY_PLUGIN, $explanation);
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
     *
     * A refuser-supplied sentence wins outright. That is the whole point of a
     * veto: only the plugin knows why, so core replacing its words with a
     * generic "the record's current state does not allow this action" would
     * discard the only actionable part of the refusal. `reason` remains the
     * stable key to branch on either way.
     */
    public function message(): string
    {
        if ($this->explanation !== null) {
            return $this->explanation;
        }

        return match ($this->outcome) {
            self::OK => 'Done',
            self::NOT_FOUND => 'Not found',
            self::BLOCKED => ($this->reason === 'composition_still_referenced'
                ? 'Rows belonging to this record are still referenced by '
                : 'Still referenced by ') . implode(', ', array_map(
                    static fn (array $b): string => $b['count'] . ' ' . $b['label'],
                    $this->blockers
                )),
            self::REFUSED => match ($this->reason) {
                'retirement_is_permanent' => 'A retired record cannot be restored — retirement is permanent',
                'retired_records_are_permanent' => 'A retired record cannot be deleted — existing references still resolve to it',
                'retired_records_cannot_be_trashed' => 'A retired record cannot be trashed — retirement is not a mistake to undo',
                'restore_before_retiring' => 'Restore this record before retiring it — a trashed record is a mistake, not an achievement',
                'trash_before_deleting' => 'Move this record to the trash before deleting it',
                'nothing_to_restore' => 'This record is not in the trash, so there is nothing to restore',
                'composition_is_permanent' => 'This record owns a retired record, and a retired record is never deleted',
                'cascade_would_nest' => 'This record owns rows that own rows of their own. Core deletes one level of '
                    . 'composition, so removing this record would orphan the level below it',
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
