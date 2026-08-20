<?php

declare(strict_types=1);

namespace Whity\Sdk\Ou;

/**
 * Declares the ORGANIZATIONAL-UNIT TYPES a plugin contributes (#822).
 *
 * An OU type names what KIND of thing a unit in the tree is — a campus, a
 * faculty, a clinic, a ward — so a consumer can ask for "every unit of kind X"
 * without knowing the shape of a particular tenant's tree. Depth cannot answer
 * that question: an institution with one top-level unit has its faculties at
 * depth 0 and one with two campuses has them at depth 1, and inserting a parent
 * above an existing unit silently changes every depth below it.
 *
 * OPTIONAL. Implement it only if the plugin brings a vocabulary of its own; the
 * host checks for this interface and skips plugins that do not implement it, so
 * adding it breaks nothing that already exists.
 *
 * Namespacing
 * -----------
 * Declare BARE slugs. The host stores them under the plugin's own namespace, so
 * a plugin declaring `clinic` is registered as `acme:clinic`. Two plugins can
 * therefore both declare `clinic` without colliding, and no plugin can mint a
 * BARE key — the unprefixed namespace belongs to core and to the tenant's own
 * vocabulary, so a plugin can never squat on a name a tenant might want. The
 * prefix is derived from the plugin NAME the loader supplies, never from
 * anything returned here.
 *
 * Ask the host's OU-type registry for the namespaced key when you need to spell
 * one in code (`OuTypeRegistry::canonicalKey($source, $slug)`); do not
 * concatenate the prefix by hand, or a change to the namespacing rule silently
 * breaks every reference the plugin has written.
 *
 * A declaration is a CATALOGUE ENTRY, not a write
 * -----------------------------------------------
 * Declaring a type does NOT insert it into any tenant's vocabulary. The
 * vocabulary is tenant data: a multi-tenant deployment holds a university
 * (campus → faculty → department) and a company (region → branch → team) side
 * by side, and force-seeding a clinic type into the university's picker would be
 * a cross-tenant write driven by an install-wide plugin. Instead an
 * administrator ADOPTS a declared key through `POST /api/ou-types`, at which
 * point the label and sort order declared here become the DEFAULTS for that
 * tenant's row and remain overridable per tenant.
 *
 *     public function getOuTypes(): array
 *     {
 *         return [
 *             'clinic' => ['label' => 'Clinic', 'sort_order' => 30],
 *             'ward'   => ['label' => 'Ward',   'sort_order' => 40],
 *         ];
 *     }
 */
interface PluginOuTypesInterface
{
    /**
     * The OU types this plugin contributes, keyed by BARE slug.
     *
     * Each slug must be lowercase, start with a letter, and contain only
     * letters, digits and underscores — no colon, which is the namespace
     * separator the host applies.
     *
     * Each declaration is an array of optional defaults:
     *  - `label`      (string) the human name an adopting tenant starts with.
     *                 Falls back to the bare slug when absent.
     *  - `sort_order` (int)    where the type ranks against its siblings — a
     *                 campus outranks a faculty outranks a department. Falls
     *                 back to the end of the adopting tenant's list.
     *
     * A malformed slug or declaration is a logged warning against the plugin,
     * not a dead host, matching how declared permissions and resource types
     * behave.
     *
     * @return array<string, array<string, mixed>> Bare slug => declaration.
     */
    public function getOuTypes(): array;
}
