<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

use Whity\Core\Container\HostWiredService;

/**
 * The document organizer's folders (#978, implementing #947 item 5) — and the
 * seam that decides which of them exist.
 *
 * THE ONE RULE
 * ------------
 * A view is listed, and is requestable, ONLY when every
 * {@see DocumentSubstrate} it declares resolves against the live schema. There
 * is no hardcoded list of folder keys anywhere and no view that returns an
 * empty page because the thing it reads has not been built.
 *
 * That rule is the whole point, and it is worth being precise about the failure
 * it prevents. #947 item 5 specifies six folders; three of them — "awaiting
 * me", "acted on by me", "passed through my unit" — are computed from routing
 * facts. Item 3 shipped those facts, so their substrates now resolve, and the
 * three folders are STILL absent: each needs a predicate on
 * {@see DocumentCriteria} and a registration of its own. Rendering them on the
 * strength of a resolvable substrate alone would produce an empty "Awaiting
 * me", which states *"nothing awaits you"*. That claim is false, it is
 * unfalsifiable from the outside, and the person it misleads is the one who
 * would have acted. An empty inbox and an unbuilt inbox look identical, which
 * is why the answer is not to render one carefully but not to render one at
 * all.
 *
 * WHAT MAKES THIS A SEAM RATHER THAN A CONDITIONAL
 * ------------------------------------------------
 * When item 3 lands it registers its tables' substrates and its three views,
 * and nothing here changes: not this class, not the API handler, not the
 * response shape, not the client. The views appear because their substrate
 * resolves, in the same request cycle the migration completes in. The
 * alternative — `if (tableExists('recipients')) { … }` scattered through a
 * handler — works exactly once and has to be found and edited by every feature
 * after it.
 *
 * Concretely, adding a view is: register a substrate saying which tables back
 * it, register a view naming that substrate and returning a
 * {@see DocumentCriteria}. The one thing that is NOT free is a predicate the
 * criteria vocabulary cannot express — that needs a slot on
 * {@see DocumentCriteria} and a literal fragment in
 * {@see \Whity\Core\Document\DocumentRepository}. That cost is bought
 * deliberately, and the reason is in DocumentCriteria's docblock: the CI
 * tenant-predicate guard verifies isolation by reading literal SQL, so
 * view-supplied fragments would be the one class of query nothing can police.
 *
 * CORE REGISTERS NO VIEW IT CANNOT COMPUTE
 * ----------------------------------------
 * Not even one guarded by an absent substrate. A registered-but-filtered view
 * is a stub, and a stub is what somebody flips on. {@see CoreDocumentSubstrates}
 * does declare the routing fact source as ABSENT, because naming a missing
 * dependency in a diagnostic is honest; turning it into a folder is not.
 *
 * HOST-WIRED, FOR THE REASON THIS CLASS ALREADY ARGUES
 * ----------------------------------------------------
 * {@see HostWiredService}, because "no folders" is an ordinary-looking answer.
 * An unregistered instance improvised by {@see \Whity\app()} would have no
 * views in it, the rail would render nothing, and that is indistinguishable
 * from a correctly-wired installation whose substrates all happen to be absent.
 * It is the same conflation this whole file refuses — absent and empty are
 * different answers — arriving through the container instead of through a view.
 *
 * The marker governs IMPROVISATION, not lifetime: both entry points still build
 * this per request, alongside the substrate registry it reads.
 *
 * Availability is resolved per instance, and instances are per request.
 */
final class DocumentViewRegistry implements HostWiredService
{
    /** @var array<string, DocumentView> */
    private array $views = [];

    public function __construct(private readonly DocumentSubstrateRegistry $substrates)
    {
    }

    /**
     * Register a folder. Re-registering a key REPLACES it, matching
     * {@see DocumentSubstrateRegistry::register()}: an installation that wants
     * core's "created by me" to mean something slightly different should be
     * able to say so without core's registration order deciding whether it
     * boots.
     */
    public function register(DocumentView $view): void
    {
        $this->views[$view->key] = $view;
    }

    /**
     * The view behind this key, or null when there is none the caller could
     * have meant.
     *
     * Null covers BOTH "no such key" and "registered, but its substrate is
     * absent here", and that is deliberate: from outside, a folder computed
     * from facts this installation does not record does not exist, and saying
     * so any other way invites a client to render it as unavailable-but-real.
     */
    public function get(string $key): ?DocumentView
    {
        $view = $this->views[$key] ?? null;

        return $view !== null && $this->substrates->allAvailable($view->requires) ? $view : null;
    }

    /**
     * Every folder this installation can actually compute, in rail order.
     *
     * @return list<DocumentView>
     */
    public function available(): array
    {
        $available = array_values(array_filter(
            $this->views,
            fn (DocumentView $view): bool => $this->substrates->allAvailable($view->requires)
        ));

        // Stable: `order` first, then key, so a rail does not reshuffle between
        // requests because two views share an order and the map iterated
        // differently.
        usort(
            $available,
            static fn (DocumentView $a, DocumentView $b): int
                => [$a->order, $a->key] <=> [$b->order, $b->key]
        );

        return $available;
    }
}
