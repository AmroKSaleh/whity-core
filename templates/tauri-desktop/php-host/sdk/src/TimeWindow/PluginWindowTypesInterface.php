<?php

declare(strict_types=1);

namespace Whity\Sdk\TimeWindow;

/**
 * Declares the TIME-WINDOW TYPES a plugin contributes (#1070).
 *
 * A time window is a NAMED, NON-OVERLAPPING PERIOD that records can be scoped
 * to and rolled up by, and which can be CLOSED — sealed, the way a set of books
 * is closed. A window TYPE is the kind of period: the vocabulary a deployment
 * uses for its own periods. A window INSTANCE is one period of that kind, with
 * real boundaries.
 *
 * WHY THE TYPE IS DECLARED AND NOT HARDCODED
 * ------------------------------------------
 * Two deployments of the same platform slice time differently and neither
 * should carry the other's words. An agricultural operation reasons in a
 * `crop_year` and the `growing_season`s inside it; a ceramics works reasons in a
 * `kiln_campaign` and the `firing_run`s inside it. Nothing in either vocabulary
 * belongs in the other's picker, and a core enumeration would have to contain
 * both.
 *
 * BOUNDARIES ARE NEVER DERIVED
 * ----------------------------
 * A declaration says nothing about WHEN a window starts or how long it lasts,
 * and that omission is the design. A period of a given kind does not have to
 * begin on the first of a month, does not have to be a fixed fraction of its
 * parent, and does not have to be the same length as its siblings — a crop year
 * begins when the ground is ready and a firing run ends when the kiln is cool.
 * Every instance therefore carries its own explicit start and end dates,
 * authored by whoever knows, and nothing in this platform computes them from a
 * calendar.
 *
 * OPTIONAL. Implement it only if the plugin brings a vocabulary of its own; the
 * host checks for this interface and skips plugins that do not implement it, so
 * adding it breaks nothing that already exists.
 *
 * Namespacing
 * -----------
 * Declare BARE slugs. The host stores them under the plugin's own namespace, so
 * a plugin declaring `growing_season` is registered as `acme:growing_season`.
 * Two plugins can therefore both declare `growing_season` without colliding, and
 * no plugin can mint a BARE key — the unprefixed namespace belongs to core and
 * to the tenant's own vocabulary, so a plugin can never squat on a name a tenant
 * might want. The prefix is derived from the plugin NAME the loader supplies,
 * never from anything returned here.
 *
 * Ask the host's window-type registry for the namespaced key when you need to
 * spell one in code (`WindowTypeRegistry::canonicalKey($source, $slug)`); do not
 * concatenate the prefix by hand, or a change to the namespacing rule silently
 * breaks every reference the plugin has written.
 *
 * A declaration is a CATALOGUE ENTRY, not a write
 * -----------------------------------------------
 * Declaring a type does NOT insert it into any tenant's vocabulary, and it
 * certainly does not create any period. The vocabulary is tenant data: a
 * multi-tenant deployment holds both of the tenants sketched above side by side,
 * and force-seeding a `firing_run` into the agricultural tenant's picker would
 * be a cross-tenant write driven by an install-wide plugin. Instead an
 * administrator ADOPTS a declared key through `POST /api/v1/time-window-types`,
 * at which point the label and nesting declared here become that tenant's
 * DEFAULTS and remain overridable per tenant.
 *
 *     public function getWindowTypes(): array
 *     {
 *         return [
 *             'crop_year'      => ['label' => 'Crop year'],
 *             'growing_season' => ['label' => 'Growing season', 'parent' => 'crop_year'],
 *         ];
 *     }
 */
interface PluginWindowTypesInterface
{
    /**
     * The window types this plugin contributes, keyed by BARE slug.
     *
     * Each slug must be lowercase, start with a letter, and contain only
     * letters, digits and underscores — no colon, which is the namespace
     * separator the host applies.
     *
     * Each declaration is an array of optional defaults:
     *  - `label`  (string) the human name an adopting tenant starts with.
     *             Falls back to the bare slug when absent.
     *  - `parent` (string) the BARE slug of the type this one nests inside, so a
     *             sub-period can be declared alongside the period that contains
     *             it. It must be another slug from THIS SAME declaration — a
     *             plugin may not nest its vocabulary inside core's or another
     *             plugin's, because it does not own the outer type and cannot
     *             know a given tenant adopted it. Absent means "nests inside
     *             nothing", which is what a top-level period is.
     *
     * A malformed slug or declaration is a logged warning against the plugin,
     * not a dead host, matching how declared permissions, OU types and routing
     * rules behave.
     *
     * @return array<string, array<string, mixed>> Bare slug => declaration.
     */
    public function getWindowTypes(): array;
}
