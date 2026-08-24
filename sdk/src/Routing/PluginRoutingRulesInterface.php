<?php

declare(strict_types=1);

namespace Whity\Sdk\Routing;

/**
 * Declares the DOCUMENT ROUTING RULE KINDS a plugin contributes (#947 item 3).
 *
 * A route step names a rule, never a person. Core owns two kinds — `role` and
 * `role_below_actor` — because both are generic: every deployment has roles and
 * every deployment has a unit tree. Anything narrower than that is a particular
 * organisation's idea of "the next people", which core cannot know and should
 * not guess, so it arrives here.
 *
 * OPTIONAL. Implement it only if the plugin brings rule kinds of its own; the
 * host checks for this interface and skips plugins that do not implement it, so
 * adding it breaks nothing that already exists. Same shape as
 * {@see \Whity\Sdk\Ou\PluginOuTypesInterface},
 * {@see \Whity\Sdk\Rbac\PluginResourceTypesInterface} and
 * {@see \Whity\Sdk\DataType\PluginDataTypesInterface} — this is the platform's
 * ordinary contribution pattern, not a new one.
 *
 * Namespacing
 * -----------
 * Declare BARE slugs. The host stores them under the plugin's own namespace, so
 * a plugin declaring `committee` is registered as `acme:committee`. Two
 * consequences, both intended:
 *
 *  - two plugins may each declare `committee` and get DIFFERENT canonical
 *    kinds, so neither can resolve the other's steps;
 *  - a plugin can never produce a BARE kind, so it cannot SHADOW `role` or
 *    `role_below_actor` — or any future core kind — no matter what it declares.
 *    A step reading `role` always means core's `role`.
 *
 * The prefix is derived from the plugin NAME the loader supplies, never from
 * anything returned here: a plugin may declare any slug it likes, but it cannot
 * declare who said it.
 *
 * Ask the host's registry for the namespaced kind when you need to spell one in
 * code (`RoutingRuleRegistry::canonicalKey($source, $slug)`); do not
 * concatenate the prefix by hand, or a change to the namespacing rule silently
 * breaks every step the plugin has already written.
 *
 * ONE DECLARATION, TWO USES (#999)
 * --------------------------------
 * A kind declared here is also offered as the definition of a named USER GROUP
 * — "the instructors" as one stored rule rather than a thousand membership rows
 * — PROVIDED its resolver also implements
 * {@see \Whity\Sdk\Audience\AudienceRuleResolverInterface}. That interface adds
 * no behaviour; it promises the resolver needs only the tenant, the actor and
 * its own config, which is what makes the kind answerable when no document is
 * being routed. One extra `implements` and a widened parameter, no second class.
 *
 * There is no separate group-rule declaration interface, deliberately. One
 * vocabulary, declared once: a rule that can name a set of people can name the
 * recipients of a step, and two registries for the same list would be two
 * catalogues free to disagree about what a plugin contributed.
 *
 * A DECLARATION IS A CATALOGUE ENTRY, NOT A ROUTE
 * -----------------------------------------------
 * Declaring a kind does not create any route, step or recipient anywhere. It
 * makes the kind AVAILABLE to whoever composes a route — `GET /api/routing-rules`
 * lists what may be named — and supplies the resolver the engine calls when a
 * step naming it is reached. Who is routed what remains tenant data, authored
 * by people.
 *
 * WHAT HAPPENS IF THE PLUGIN IS UNINSTALLED
 * -----------------------------------------
 * Steps naming its kinds stay in the database, deliberately (see migration 112:
 * `rule_kind` has no foreign key, because the catalogue is code rather than
 * rows). Resolving such a step fails loudly, naming the missing kind, instead of
 * being silently skipped — a route that quietly stopped delivering to a whole
 * class of people is the failure mode this whole item is written against.
 *
 *     public function getRoutingRules(): array
 *     {
 *         return [
 *             'committee' => new CommitteeRule($this->committees),
 *             'supervisor' => new SupervisorRule($this->staff),
 *         ];
 *     }
 */
interface PluginRoutingRulesInterface
{
    /**
     * The routing rule kinds this plugin contributes, keyed by BARE slug.
     *
     * Each slug must be lowercase, start with a letter, and contain only
     * letters, digits and underscores — no colon, which is the namespace
     * separator the host applies.
     *
     * Resolvers are constructed by the PLUGIN, so a rule that needs to query
     * brings its own collaborators; the host never injects a database handle
     * (see {@see RoutingRuleContext} for why).
     *
     * A malformed slug or a value that is not a
     * {@see RoutingRuleResolverInterface} is a logged warning against the
     * plugin, not a dead host — matching how declared permissions, OU types and
     * resource types behave.
     *
     * @return array<string, RoutingRuleResolverInterface> Bare slug => resolver.
     */
    public function getRoutingRules(): array;
}
