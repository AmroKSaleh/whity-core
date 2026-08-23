<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Core\Inbox\InboxItem;
use Whity\Core\Inbox\InboxSourceInterface;
use Whity\Core\RBAC\ResourceTypeRegistry;

/**
 * Routing's recipients, presented as an #881 INBOX SOURCE (#947 item 3).
 *
 * This class is deliberately thin, and its thinness is the point: it adapts
 * rows {@see RouteRecipientRepository} already produces into
 * {@see InboxItem}s. There is no query here, no second definition of "awaiting
 * me", and no endpoint of its own.
 *
 * WHY THIS EXISTS AT ALL RATHER THAN A `/api/me/document-inbox`
 * ------------------------------------------------------------
 * `document_route_recipients` IS an inbox, so the tempting shape was an
 * endpoint beside the notification one. #947 rejects it in a line — "two inbox
 * surfaces would be the same mistake as two audit trails" — and the reasoning
 * is #881's: a person receiving work from several systems would open the app and
 * find several correct lists whose union exists nowhere. The moment routing owns
 * a surface, the aggregate becomes a migration rather than a read.
 *
 * So routing owns no surface. It owns a source, and
 * {@see \Whity\Api\MeInboxApiHandler} reads the registry.
 *
 * THE ITEM SHAPE IS NOT INVENTED HERE
 * -----------------------------------
 * `title` / `subtitle` / `timestamp` / `status` are the fields the `inbox` block
 * type already declares (#868). #881 notes that a source emitting items keyed to
 * those fields "conforms to the aggregate by construction", which is exactly why
 * they are used verbatim rather than mapped.
 *
 * `status` IS READ THROUGH THE TRAIL, NEVER STORED
 * -----------------------------------------------
 * It is `arrived_by` — the ACTION of the trail event that created the recipient
 * row, joined through `created_by_event_id`. So the qualifier a person reads in
 * their inbox ("forwarded to you", "returned to you") is the trail's own word for
 * what happened, and it cannot drift from it. There is no status column anywhere
 * in routing; migration 108 refused one on `documents` and migration 112 refused
 * one on the recipient row for the same reason.
 *
 * WHY `resourceType` IS THE DOCUMENT AND NOT THE RECIPIENT ROW
 * -----------------------------------------------------------
 * An `inbox` block's `scopedPermission` is resolved at
 * (`resourceType`, the item's id value), against `resource_role_assignments`.
 * The thing a person holds authority over is the DOCUMENT, not the assignment
 * row — grants are written against documents (which is why
 * {@see ResourceTypeRegistry::TYPE_DOCUMENT} exists), and scoping to the row id
 * would resolve every check against a type nobody grants on and quietly answer
 * "no" to all of them.
 *
 * `id` is therefore the recipient row (a person can hold the same document twice
 * over its life, and acting needs to name WHICH item), while `resource_id` is the
 * document. Keeping them separate is what lets both questions be answered.
 */
final class DocumentRoutingInboxSource implements InboxSourceInterface
{
    public function __construct(private readonly RouteRecipientRepository $recipients)
    {
    }

    public function label(): string
    {
        return 'Documents awaiting you';
    }

    /**
     * @return list<InboxItem>
     */
    public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
    {
        $rows = $this->recipients->listForProfile($tenantId, $profileId, $openOnly, $limit, $offset);

        return array_map(self::toItem(...), $rows);
    }

    public function count(int $tenantId, int $profileId, bool $openOnly): int
    {
        return $this->recipients->countForProfile($tenantId, $profileId, $openOnly);
    }

    /**
     * One recipient row as an inbox item.
     *
     * @param array<string, mixed> $row A row from {@see RouteRecipientRepository::listForProfile()}.
     */
    private static function toItem(array $row): InboxItem
    {
        $arrivedBy = (string) ($row['arrived_by'] ?? RouteAction::ISSUED);

        return new InboxItem(
            // A string, because the aggregate will eventually mix sources whose
            // ids are not integers, and a source that emits an int today would
            // force a widening later on the one field every client keys on.
            id: (string) $row['id'],
            title: (string) $row['document_title'],
            // The template it came from, which is the most useful single line of
            // context available without a second query: "Purchase order" tells a
            // person what KIND of thing is waiting, where the title tells them
            // which one.
            subtitle: (string) $row['document_template_name'],
            timestamp: (string) $row['created_at'],
            status: self::statusFor($arrivedBy, $row['closed_by_event_id'] !== null),
            resourceType: ResourceTypeRegistry::TYPE_DOCUMENT,
            resourceId: (string) $row['document_id'],
            meta: [
                // Enough for a client to offer the right actions without a
                // second request: which route to act on, and whether the item is
                // still open. `meta` deliberately, not named fields — these are
                // routing's own detail, and #881's aggregate must not come to
                // depend on them.
                'route_id' => (int) $row['route_id'],
                'step_id' => (int) $row['step_id'],
                'document_id' => (int) $row['document_id'],
                'open' => $row['closed_by_event_id'] === null,
                'arrived_by' => $arrivedBy,
                'arrived_from_profile_id' => $row['arrived_from'] ?? null,
                'ou_id' => $row['ou_id'] ?? null,
            ],
        );
    }

    /**
     * The short qualifier line, derived from the trail.
     *
     * A closed item reads as done rather than as however it arrived: once you
     * have acted, "forwarded to you" is no longer the useful fact. The words are
     * chosen from the reader's side — the trail's verb is what HAPPENED, and this
     * is what happened TO THEM.
     */
    private static function statusFor(string $arrivedBy, bool $closed): string
    {
        if ($closed) {
            return 'Done';
        }

        return match ($arrivedBy) {
            RouteAction::RETURNED => 'Returned to you',
            RouteAction::FORWARDED => 'Forwarded to you',
            default => 'Awaiting you',
        };
    }
}
