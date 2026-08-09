<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

/**
 * The lifecycle a data type declares: where its state lives, which states
 * exist, and which of them mean TRASHED and RETIRED (WC-723).
 *
 * Trashed and retired are independent axes, not two points on one scale
 * ---------------------------------------------------------------------
 * This is the distinction plugins consistently get wrong, and the reason this
 * class exists rather than a single `deleted_at` column:
 *
 *  - **trashed** — "this should not exist". A mistake. REVERSIBLE, and once
 *    nothing references the row, removable for real. It is pending removal.
 *  - **retired** — "this served its purpose". Not a mistake and NOT reversible.
 *    The row must persist because references already written still need it to
 *    resolve. Retirement removes the FUTURE — no new references — and leaves
 *    the past untouched. It is NOT pending removal, and no amount of
 *    dereferencing ever makes it deletable.
 *
 * Collapsing them into one "soft delete" loses precisely the property that
 * matters: whether the record is on its way out or is a permanent part of the
 * record of what happened.
 *
 * A type may declare either, both, or neither. Neither is a legitimate answer —
 * {@see self::none()} — and it means the type simply has no lifecycle to
 * generate affordances from, not that the declaration is broken.
 */
final class Lifecycle
{
    /**
     * The column holding the state, or null when the type declares no
     * lifecycle at all.
     */
    private ?string $column;

    /**
     * Every state the type says a record may occupy.
     *
     * @var list<string>
     */
    private array $states;

    /**
     * The state a restored record returns to.
     */
    private string $defaultState;

    /**
     * The state meaning TRASHED, or null when the type is not trashable.
     */
    private ?string $trashedState;

    /**
     * The state meaning RETIRED, or null when the type is not retirable.
     */
    private ?string $retiredState;

    /**
     * @param string|null  $column       Column holding the state (null = no lifecycle).
     * @param list<string> $states       Every declared state.
     * @param string       $defaultState The state a restore returns to.
     * @param string|null  $trashedState The trashed state, or null if not trashable.
     * @param string|null  $retiredState The retired state, or null if not retirable.
     */
    public function __construct(
        ?string $column,
        array $states,
        string $defaultState,
        ?string $trashedState,
        ?string $retiredState
    ) {
        $this->column = $column;
        $this->states = $states;
        $this->defaultState = $defaultState;
        $this->trashedState = $trashedState;
        $this->retiredState = $retiredState;
    }

    /**
     * A type that declares no lifecycle.
     *
     * Its records are never trashed and never retired; a delete is a delete,
     * still subject to the referential guards. Honest degradation: the trash
     * and retire affordances are simply absent rather than present and inert.
     */
    public static function none(): self
    {
        return new self(null, [], '', null, null);
    }

    /**
     * Whether the type declares a state column at all.
     */
    public function isDeclared(): bool
    {
        return $this->column !== null;
    }

    /**
     * The column holding the state, or null when none is declared.
     */
    public function column(): ?string
    {
        return $this->column;
    }

    /**
     * Every declared state.
     *
     * @return list<string>
     */
    public function states(): array
    {
        return $this->states;
    }

    /**
     * The state a restored record returns to.
     */
    public function defaultState(): string
    {
        return $this->defaultState;
    }

    /**
     * The state meaning trashed, or null when the type is not trashable.
     */
    public function trashedState(): ?string
    {
        return $this->trashedState;
    }

    /**
     * The state meaning retired, or null when the type is not retirable.
     */
    public function retiredState(): ?string
    {
        return $this->retiredState;
    }

    /**
     * Whether records of this type can be trashed (and therefore restored).
     */
    public function isTrashable(): bool
    {
        return $this->column !== null && $this->trashedState !== null;
    }

    /**
     * Whether records of this type can be retired.
     */
    public function isRetirable(): bool
    {
        return $this->column !== null && $this->retiredState !== null;
    }

    /**
     * Whether a state is the trashed one.
     *
     * @param string|null $state The observed state.
     */
    public function isTrashed(?string $state): bool
    {
        return $this->trashedState !== null && $state === $this->trashedState;
    }

    /**
     * Whether a state is the retired one.
     *
     * @param string|null $state The observed state.
     */
    public function isRetired(?string $state): bool
    {
        return $this->retiredState !== null && $state === $this->retiredState;
    }

    /**
     * Whether a NEW reference to a record in this state may be created.
     *
     * False for both trashed and retired — the two states agree here and
     * disagree everywhere else, which is exactly why "closed to new references"
     * cannot stand in for "pending removal".
     *
     * @param string|null $state The observed state.
     */
    public function acceptsNewReferences(?string $state): bool
    {
        return !$this->isTrashed($state) && !$this->isRetired($state);
    }

    /**
     * Whether a record in this state is on its way out.
     *
     * True only for trashed. A retired record is permanent, so it is never
     * pending removal however long it sits unreferenced.
     *
     * @param string|null $state The observed state.
     */
    public function isPendingRemoval(?string $state): bool
    {
        return $this->isTrashed($state);
    }

    /**
     * Whether a record in this state can still change state.
     *
     * Retirement is terminal: a retired record is neither restorable nor
     * trashable nor deletable. This single predicate is what stops "retire"
     * decaying into "another word for archived".
     *
     * @param string|null $state The observed state.
     */
    public function isTerminal(?string $state): bool
    {
        return $this->isRetired($state);
    }

    /**
     * The declaration as data, for the generated-UI contract.
     *
     * @return array{declared: bool, column: ?string, states: list<string>, default_state: ?string, trashable: bool, retirable: bool, trashed_state: ?string, retired_state: ?string}
     */
    public function toArray(): array
    {
        return [
            'declared' => $this->isDeclared(),
            'column' => $this->column,
            'states' => $this->states,
            'default_state' => $this->column === null ? null : $this->defaultState,
            'trashable' => $this->isTrashable(),
            'retirable' => $this->isRetirable(),
            'trashed_state' => $this->trashedState,
            'retired_state' => $this->retiredState,
        ];
    }
}
