<?php

declare(strict_types=1);

namespace Whity\Sdk\DataType;

/**
 * What a lifecycle transition ANSWERED — success or refusal, in the vocabulary
 * the HTTP surface already publishes (SDK 1.23).
 *
 * One vocabulary, two ways in
 * ---------------------------
 * A refusal is a RESULT, not an exception: "you cannot delete this, three
 * recorded entries still point at it" is a normal, expected answer a caller
 * must be able to render. The host's generated endpoints publish that answer as
 * `{reason, message}` plus `blockers`, and this contract is the SAME answer
 * reached in-process — so a plugin calling {@see DataTypeLifecycle} and a client
 * calling `POST /api/data-types/{type}/{id}/trash` branch on ONE vocabulary
 * rather than two that must be kept in step by hand.
 *
 * That is not a resemblance maintained by convention. The host returns the very
 * object its own HTTP handler builds its response from, so the two cannot drift:
 * there is no second implementation to fall behind.
 *
 * `reason` is the contract; `message` is a fallback
 * ------------------------------------------------
 * Branch on {@see self::reason()} and localise your own text. It is a stable,
 * enumerable key. {@see self::message()} is the host's own sentence, offered so
 * you always have something to show — and, for a refusal raised by another
 * plugin's veto, it is the only part only that plugin could have written. The
 * sentences may be reworded without notice; string-matching prose is not an API.
 *
 * Not constructible by a plugin, deliberately: an outcome is something the host
 * ANSWERED, and a plugin able to mint one could hand a fabricated verdict to
 * code that trusts this contract.
 */
interface LifecycleOutcome
{
    /**
     * Whether the transition happened — or was already true, since transitions
     * are idempotent (retiring an already-retired record succeeds).
     */
    public function isOk(): bool;

    /**
     * The record's lifecycle state after the call, or the state it still holds
     * at the time of a refusal. Null when the row is gone, or when the type
     * declares no lifecycle column.
     */
    public function state(): ?string;

    /**
     * The STABLE machine-readable reason key, or null on success.
     *
     * The same keys the HTTP layer publishes and the same ones the pre-flight
     * read predicts — `still_referenced`, `trash_before_deleting`,
     * `retired_records_are_permanent`, `blocked_by_plugin`, `<action>_not_offered`,
     * and the composition keys. This is the field to branch on.
     */
    public function reason(): ?string;

    /**
     * A human-readable sentence explaining the outcome.
     *
     * Built from the declaring plugin's own labels where the host has them
     * ("Still referenced by 3 recorded entries"), and replaced outright by a
     * vetoing plugin's own words where it does not. A fallback for display,
     * never the contract.
     */
    public function message(): string;

    /**
     * The rows standing in the way of a refused delete, each with the declaring
     * plugin's human label and a count.
     *
     * Empty for every outcome that is not a reference refusal. The rows point
     * either at the record itself or at something it owns; {@see self::reason()}
     * says which.
     *
     * @return list<array{table: string, label: string, count: int}>
     */
    public function blockers(): array;

    /**
     * The HTTP status this outcome maps to.
     *
     * Published so a plugin keeping its own route can answer with the SAME
     * status the generated endpoint would — 409 for a refusal the data's current
     * state causes, 405 for an action the type does not offer, 403 for a
     * permission the caller lacks, 404 for a record that is not theirs. A route
     * that translated these by hand would eventually translate one of them
     * differently.
     */
    public function httpStatus(): int;

    /**
     * The outcome as a JSON-serialisable payload — the same shape the generated
     * endpoint's `data` envelope carries.
     *
     * @return array{outcome: string, state: ?string, reason: ?string, message: string, blockers: list<array{table: string, label: string, count: int}>}
     */
    public function toArray(): array;
}
