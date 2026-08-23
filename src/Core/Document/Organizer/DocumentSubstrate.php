<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * A FACT SOURCE the document organizer can build a view out of (#978).
 *
 * A substrate is not a feature flag and not a permission. It answers one
 * question — "does this installation actually record the fact this folder would
 * be computed from?" — and nothing else. `documents.origin_ou_id` exists, so
 * "raised by my unit" CAN be computed; on an installation that has not run
 * migration 108 it cannot, and no amount of permission or configuration changes
 * that.
 *
 * Note the limit of what a YES buys. #947 item 3's recipient rows exist, so
 * `routing.recipients` resolves — and "awaiting me" is still not a folder,
 * because nothing has written its predicate. A substrate says the fact is
 * recorded, never that somebody built the view.
 *
 * WHY THIS TYPE EXISTS AT ALL
 * ---------------------------
 * Without it the organizer is a hardcoded list of view keys, three of which
 * return an empty page. An empty "Awaiting me" states *"nothing awaits you"* —
 * which is false, and unfalsifiable from the outside — rather than *"this is
 * not built"*. A document somebody was supposed to act on failing to appear is
 * indistinguishable from having nothing to do, and the person it fails is the
 * one least able to find out. #951 and #756 establish the same rule one layer
 * up: a surface must not assert something it cannot know.
 *
 * So a view names its substrates and is ABSENT until they resolve. Nothing
 * renders half-computed.
 *
 * WHAT A SUBSTRATE IS MADE OF
 * ---------------------------
 * `requires` is a list of schema requirements, each either a bare table name
 * (`document_route_recipients`) or a `table.column` pair
 * (`documents.origin_ou_id`). Column granularity matters more than it looks: a
 * substrate about `documents.created_by` declared as just `documents` would
 * resolve on any installation that has the table, which every one of them does,
 * so it would report YES while the column it needs is absent.
 *
 * The requirement list is what a {@see SchemaPresence} probe MEASURES. Nobody
 * gets to assert a substrate is present; they get to say what would make it
 * present, and the database answers.
 *
 * Immutable value object — worker-safe.
 */
final class DocumentSubstrate
{
    /**
     * @param string       $key         Stable identifier a view references, e.g. `documents.origin_ou`.
     * @param string       $description What fact this holds, in one sentence, for the operator
     *                                  reading an "unavailable" report and wondering what is missing.
     * @param list<string> $requires    Schema requirements: `table` or `table.column`.
     * @param string|null  $provenance  What ships it — an issue or migration reference — so an
     *                                  absent substrate points at the work that would supply it
     *                                  rather than reading as a defect.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $description,
        public readonly array $requires,
        public readonly ?string $provenance = null,
    ) {
    }

    /**
     * Whether every schema requirement is satisfied by the live database.
     *
     * A substrate with no requirements is unconditionally satisfied — that is
     * not a loophole, it is how a substrate backed by something other than a
     * table (an entitlement, a configured external service) would declare
     * itself, and such a substrate is expected to be a distinct implementation
     * rather than an empty list here.
     */
    public function isSatisfiedBy(SchemaPresence $schema): bool
    {
        foreach ($this->requires as $requirement) {
            $parts = explode('.', $requirement, 2);
            $satisfied = count($parts) === 2
                ? $schema->hasColumn($parts[0], $parts[1])
                : $schema->hasTable($parts[0]);

            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }
}
