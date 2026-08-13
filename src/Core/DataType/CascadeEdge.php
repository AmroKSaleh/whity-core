<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

/**
 * One declared edge of the COMPOSITION graph: "rows in this table are PART of
 * that record, so deleting it must delete them too".
 *
 * The other half of {@see ReferenceGuard}
 * ---------------------------------------
 * The two answer the same question — what happens to related rows when this
 * record is deleted? — and give opposite answers, which is why they are declared
 * side by side and why neither is inferable from the other:
 *
 *  - `blocks_delete` names rows that must OUTLIVE the record. They are somebody
 *    else's data that happens to point here, so the delete is refused while any
 *    of them exists.
 *  - `cascade_delete` names rows that must DIE WITH it. They are part of the
 *    record — a line of an order, a page of a document — and a record's part has
 *    no meaning once the record is gone.
 *
 * Nothing about a table's shape says which it is; only the plugin knows. With no
 * foreign keys between plugin tables (the established convention here) the
 * database will not choose either, so an undeclared composition is silently
 * orphaned: `DELETE FROM <table> WHERE <key>` removed exactly one row and left
 * the parts behind, in a state no screen lists and no guard protects.
 *
 * Deliberately NOT a `ReferenceGuard` with a flag
 * -----------------------------------------------
 * The shapes look alike — a table, a column, a label — but `ignore_when` is the
 * difference that matters, and it makes no sense here. A guard legitimately
 * disregards some referencing rows (a trashed child must not pin its parent
 * alive forever). A cascade that disregarded some rows would leave exactly the
 * orphans this exists to prevent, so a declaration carrying one is REFUSED
 * rather than honoured half-way. Sharing a class would have made that
 * meaningless field expressible, and published it in the round-tripped entry as
 * though core had accepted it.
 *
 * The label is used the same way it is in a guard: core never learns what
 * `acme_lines` means, only that a preview should say "4 line items" and a
 * refusal should name them.
 */
final class CascadeEdge
{
    /**
     * The table holding the owned rows.
     */
    private string $table;

    /**
     * The column in that table holding the owning record's key.
     */
    private string $column;

    /**
     * The human-readable name of what those rows are, for the preview and for
     * any refusal that has to name them.
     */
    private string $label;

    /**
     * The tenant column of the owned table. Every generated statement binds it —
     * a cascade is a DELETE, so an unscoped one would be the single most
     * destructive statement core could be talked into building.
     */
    private string $tenantColumn;

    /**
     * @param string $table        The owned table.
     * @param string $column       Column holding the owning record's key.
     * @param string $label        Human label for previews and refusals.
     * @param string $tenantColumn Tenant column of the owned table.
     */
    public function __construct(
        string $table,
        string $column,
        string $label,
        string $tenantColumn = 'tenant_id'
    ) {
        $this->table = $table;
        $this->column = $column;
        $this->label = $label;
        $this->tenantColumn = $tenantColumn;
    }

    /**
     * The table holding the owned rows.
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * The column holding the owning record's key.
     */
    public function column(): string
    {
        return $this->column;
    }

    /**
     * The human label used in a preview or a refusal.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * The tenant column of the owned table.
     */
    public function tenantColumn(): string
    {
        return $this->tenantColumn;
    }

    /**
     * The declaration as data, for the generated-UI contract.
     *
     * Round-tripped for the same reason every other declared field is: the
     * published entry is the only place an adopter can see what the host
     * accepted, and a composition that is enforced but never echoed is
     * indistinguishable from one that was silently dropped — with a far worse
     * failure mode than a dropped guard, because the reader concludes their
     * children are being cleaned up when they are being orphaned.
     *
     * No `ignore_when` appears here because none can be declared. An empty map
     * would be worse than absence: it reads as "a filter that matched nothing"
     * rather than "a filter this declaration does not have".
     *
     * @return array{table: string, column: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'column' => $this->column,
            'label' => $this->label,
        ];
    }
}
