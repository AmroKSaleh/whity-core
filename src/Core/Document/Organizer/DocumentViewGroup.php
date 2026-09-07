<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * A section of the document rail: the heading a set of folders sits under.
 *
 * WHY THIS EXISTS. `DocumentView::$group` was a free string, and the rail
 * filtered it against the two names the client happened to know — `derived` and
 * `personal`. A view registered under any third group was computed correctly by
 * the registry, reported correctly by `GET /api/documents/views`, and then
 * discarded on the way to the screen: no rail entry, no error, no log.
 *
 * That contradicts the point of a capability-driven registry, which is that the
 * server decides what exists and a later folder appears with no client change.
 * The rail was quietly holding a veto over it.
 *
 * It also meant the client owned the section HEADINGS — names for groups it did
 * not define. Making the group a declared thing rather than a bare string moves
 * the label to whoever registers the group, which is the only place that knows
 * what the section is called.
 *
 * WHO IT BITES FIRST. A plugin. If a plugin registers folders under a group of
 * its own, they vanish with no diagnostic, and the author cannot tell that from
 * "my registration never ran".
 */
final class DocumentViewGroup
{
    /**
     * @param string $key   Matches {@see DocumentView::$group}.
     * @param string $label English default; a client translates by key where it
     *                      knows one, exactly as it does for a view's label.
     * @param int    $order Ascending. Ties break on key so a rail does not
     *                      reshuffle between requests.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $order = 100,
    ) {
    }

    /**
     * The group to use for a `group` string nobody registered.
     *
     * FAILS OPEN, and that is the whole point of the class. The alternative —
     * dropping views whose group is unknown — is the defect this replaces, and
     * it hides itself: the rail still looks complete, so nothing suggests
     * anything is missing.
     *
     * The key stands in as the label. It is worse than a written one and better
     * than an absent section, and it is visibly a fallback rather than a name
     * somebody chose, which is the signal a plugin author needs to go and
     * register the group properly.
     *
     * Ordered last so an unregistered group never displaces a declared one.
     */
    public static function fallbackFor(string $key): self
    {
        return new self($key, $key, PHP_INT_MAX);
    }
}
