<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

/**
 * One declared edge of the reference graph: "rows in THIS table point at that
 * record, so deleting it would orphan them" (WC-723).
 *
 * Why the graph is data
 * --------------------
 * With no foreign keys between plugin tables (the established convention here),
 * nothing at the database level prevents deleting a row other rows still point
 * at. Each plugin has had to hand-write that check, so the checks are written
 * inconsistently, incompletely, and are trivially bypassed through a secondary
 * path such as an empty-trash endpoint.
 *
 * The reference graph, though, is DATA — a table, a column, and a human label.
 * Nothing about evaluating it requires knowing what a record means. Declaring
 * it rather than coding it is what lets core own the enforcement, at every
 * enforcement point, without knowing any domain.
 *
 * The label is the other half. Core never learns what `acme_entries` is; it
 * learns to say "recorded entries" when refusing, which is the difference
 * between a useful refusal and a 409 the user cannot act on.
 */
final class ReferenceGuard
{
    /**
     * The referencing table.
     */
    private string $table;

    /**
     * The column in that table holding the referenced record's key.
     */
    private string $column;

    /**
     * The human-readable name of what those rows are, for the refusal message.
     */
    private string $label;

    /**
     * The tenant column of the referencing table.
     */
    private string $tenantColumn;

    /**
     * Referencing rows to disregard, as column => list of values.
     *
     * A referencing row that is ITSELF trashed should not keep its parent
     * alive, or a trashed child would pin its parent forever and the guard
     * would be a leak rather than a protection. Declared, not inferred: core
     * has no idea which of a plugin's columns encodes that.
     *
     * @var array<string, list<string>>
     */
    private array $ignoreWhen;

    /**
     * @param string                     $table        Referencing table.
     * @param string                     $column       Column holding the reference.
     * @param string                     $label        Human label for refusals.
     * @param string                     $tenantColumn Tenant column of the referencing table.
     * @param array<string, list<string>> $ignoreWhen  Values that disqualify a row from blocking.
     */
    public function __construct(
        string $table,
        string $column,
        string $label,
        string $tenantColumn = 'tenant_id',
        array $ignoreWhen = []
    ) {
        $this->table = $table;
        $this->column = $column;
        $this->label = $label;
        $this->tenantColumn = $tenantColumn;
        $this->ignoreWhen = $ignoreWhen;
    }

    /**
     * The referencing table.
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * The column holding the reference.
     */
    public function column(): string
    {
        return $this->column;
    }

    /**
     * The human label used in a refusal message.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * The tenant column of the referencing table.
     */
    public function tenantColumn(): string
    {
        return $this->tenantColumn;
    }

    /**
     * Values that disqualify a referencing row from blocking.
     *
     * @return array<string, list<string>>
     */
    public function ignoreWhen(): array
    {
        return $this->ignoreWhen;
    }

    /**
     * The declaration as data, for the generated-UI contract.
     *
     * `ignore_when` is published even though no renderer needs it. The entry is
     * the ONLY place an adopter can see what the host actually accepted, and a
     * guard filter that is enforced but never echoed is indistinguishable from
     * one that was silently dropped — the reader has to go and audit core's
     * source to tell "correct and quiet" from "broken". Echoing it costs a
     * renderer nothing (it ignores the field) and buys the declarer a diff
     * against their own declaration. It is published POST-VALIDATION, so the
     * normalisation core applied — scalars cast to strings — is visible too.
     *
     * @return array{table: string, column: string, label: string, ignore_when: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'column' => $this->column,
            'label' => $this->label,
            'ignore_when' => $this->ignoreWhen,
        ];
    }
}
