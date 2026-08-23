<?php

declare(strict_types=1);

namespace Whity\Core\Inbox;

/**
 * One item awaiting somebody, in the shape the `inbox` block already declares
 * (#868/#871, aggregated by #881).
 *
 * WHY THESE FIELDS AND NOT OTHERS
 * -------------------------------
 * They are not invented here. The `inbox` block type names its item shape in
 * its own props — `idField`, `titleField`, `subtitleField`, `timestampField`,
 * `statusField`, `resourceType`, `actions`
 * ({@see \Whity\Sdk\Frontend\Blocks\BlockContract}) — and #881 makes the point
 * that a source emitting items keyed to those fields "conforms to the aggregate
 * BY CONSTRUCTION". So this class is that contract, written down once on the
 * server side, rather than each source guessing at it.
 *
 * The consequence is the cheap hedge #881 describes: a screen can point an
 * `inbox` block at one source's endpoint today with
 * `idField: 'id'`, `titleField: 'title'`, `subtitleField: 'subtitle'`,
 * `timestampField: 'timestamp'`, `statusField: 'status'` — and when the
 * cross-source aggregate lands, the same source is read through the same
 * registry with no change to either side.
 *
 * `status` IS NOT A STORED STATUS
 * ------------------------------
 * For the document-routing source it is the ACTION of the trail event that put
 * the item in the inbox, read through a foreign key rather than stored beside it
 * — see {@see \Whity\Core\Document\Routing\RouteRecipientRepository}. The block
 * prop is called `statusField` because a block needs a word for "the short
 * qualifier line"; nothing about it obliges a source to keep a status column,
 * and this one deliberately does not.
 *
 * WHAT IS DELIBERATELY ABSENT: A `href`
 * ------------------------------------
 * A source says WHAT the item is, not where a particular client should navigate
 * to see it. A URL baked in here would be a web route in a payload the desktop
 * host also consumes. `resourceType` + `id` is what a client resolves a
 * destination from, exactly as the block's own `actions` already do.
 *
 * Immutable value object.
 */
final class InboxItem
{
    /**
     * @param string      $id           Stable identity WITHIN the source. Prefixed by
     *                                  the registry when items from several sources are
     *                                  mixed, so a source never has to think about
     *                                  collisions with another's numbering.
     * @param string      $title        The one line a person reads first.
     * @param string|null $subtitle     Context — where it came from, what it is about.
     * @param string      $timestamp    When it arrived, ISO-8601-ish as the driver
     *                                  returned it. #881 names ordering across
     *                                  heterogeneous sources as undecided, and this is
     *                                  the field it says the default order would use.
     * @param string|null $status       Short qualifier. For routing: how it reached you.
     * @param string|null $resourceType The registered resource type the item is about,
     *                                  so a client can resolve a destination and so a
     *                                  per-record permission can be scoped to it.
     * @param string|null $resourceId   The record's id within that type.
     * @param array<string, mixed> $meta Source-specific extras a client may use and
     *                                  must not depend on. Never load-bearing for the
     *                                  aggregate: anything the aggregate needs is a
     *                                  named field above.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $timestamp,
        public readonly ?string $status,
        public readonly ?string $resourceType,
        public readonly ?string $resourceId,
        public readonly array $meta = [],
    ) {
    }

    /**
     * The wire shape, with the field names the `inbox` block's props default to.
     *
     * Flat, not nested. A block maps a prop to a FIELD NAME, so a nested
     * `resource.type` would not be addressable by `resourceType` at all — the
     * block would need a path syntax it does not have.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'timestamp' => $this->timestamp,
            'status' => $this->status,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'meta' => $this->meta,
        ];
    }
}
