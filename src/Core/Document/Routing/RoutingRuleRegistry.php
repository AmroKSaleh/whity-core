<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;
use Whity\Sdk\Audience\AudienceRuleResolverInterface;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * The catalogue of declarable DOCUMENT ROUTING RULE KINDS (#947 item 3).
 *
 * A route step names a RULE, never a person — that is the first of the three
 * semantics the engine exists to preserve, and this is where the vocabulary of
 * available rules lives.
 *
 * Unremarkable beside {@see ResourceTypeRegistry}, {@see \Whity\Core\Ou\OuTypeRegistry},
 * {@see \Whity\Core\DataType\DataTypeRegistry} and {@see \Whity\Core\RBAC\PermissionRegistry}.
 * That is the point: nothing about routing rules is different in kind from any
 * other plugin contribution point, so it uses the shape the platform already
 * has thirteen instances of rather than inventing a fourteenth idea of what
 * "registered" means.
 *
 * ONE CATALOGUE, TWO CONSUMERS (#999)
 * -----------------------------------
 * The class name records where this was born, not who may read it. #999 added
 * named USER GROUPS, and a group is one of these same rule expressions given a
 * name and stored once — so it reads this registry too, through
 * {@see audienceResolver()} and {@see audienceCatalogue()}, which expose the
 * SUBSET of kinds that can answer without a document.
 *
 * A second registry for "the same vocabulary, for the other caller" was the
 * alternative and is exactly what #714 warns against: two catalogues free to
 * disagree about what a plugin contributed, and two places a plugin author has
 * to declare the same kind. Renaming this class to match its widened audience
 * was considered and rejected as churn — it is resolved from the container by
 * class name and consulted by a merged, shipped endpoint, and the rename would
 * touch #989's wiring to change nothing about behaviour.
 *
 * WHAT CORE OWNS, AND WHY EXACTLY FOUR
 * -----------------------------------
 * {@see RoleRuleResolver} (`role`) and {@see RoleBelowActorRuleResolver}
 * (`role_below_actor`). Both are generic in the strong sense: every deployment
 * has roles, and every deployment has a unit tree, so both resolve correctly
 * against a schema core already owns without knowing anything about the
 * organisation using it.
 *
 * {@see ExplicitRuleResolver} (`explicit`) — exactly these named people. It is a
 * KIND rather than an exception to kinds so that a hand-picked set and a computed
 * set are the same object with different innards: one code path downstream, and
 * an admin who need not decide up front which sort they are making.
 *
 * {@see GroupRuleResolver} (`group`) — everyone in a named user group. The
 * reference kind, and the only one of the four that is routing-only: it does not
 * implement the audience interface, which is what makes a group-of-groups
 * impossible rather than merely discouraged.
 *
 * Nothing narrower belongs here. A `supervisor` rule sounds generic and is not —
 * core has no notion of a supervisor, and the three deployments that want one
 * mean three different things by it (line manager, unit head, whoever signed
 * the last one). Shipping a guess would be the OU-depth mistake #822 records:
 * a rule phrased in core that resolves to a different set of people per install
 * while looking identical.
 *
 * THE NAMESPACE RULE, AND WHAT IT GUARANTEES
 * ------------------------------------------
 * Plugin kinds are PREFIXED from the source the loader supplies
 * (`$plugin->getName()`), never from anything the plugin returns:
 * `acme:committee`. Two consequences, both intended and both identical to
 * {@see ResourceTypeRegistry::register()}:
 *
 *  - two plugins may each declare `committee` and get DIFFERENT canonical
 *    kinds, so neither can resolve the other's steps;
 *  - a plugin can never produce a BARE kind. `role` and `role_below_actor`
 *    always mean core's, and a step already stored naming one cannot be
 *    re-pointed by installing a plugin.
 *
 * `core` is RESERVED as a source for exactly that reason.
 *
 * WHY A RESOLVER, NOT A DECLARATION
 * ---------------------------------
 * Unlike the OU-type and resource-type registries, which store DATA a plugin
 * declared, this stores BEHAVIOUR a plugin supplied — closer to
 * {@see \Whity\Core\Queue\JobRegistry}, which maps a job name to the handler
 * that runs it. The reason is the same one that makes rules better than lists:
 * "who are the next people" is a question that has to be re-asked every time it
 * is reached, against the organisation as it stands then, and only the code
 * that knows what the kind means can answer it.
 *
 * The resolver is constructed by the PLUGIN, so a rule needing to query brings
 * its own collaborators. The host never hands one a database handle — see
 * {@see \Whity\Sdk\Routing\RoutingRuleContext} — and never lets a resolver
 * write: it returns suggestions that {@see DocumentRouter} filters against the
 * tenant's own memberships before any row exists.
 *
 * DELIBERATELY AN INSTANCE SERVICE
 * --------------------------------
 * Not a static catalogue. Process-level statics are per FrankenPHP worker, so a
 * registration performed while serving one request is invisible to the other
 * workers — the hazard that produced the stale-permission bug in PR #701. An
 * instance resolved from the container is rebuilt per request from the same
 * plugin bootstrap every worker runs, so every worker agrees.
 *
 * {@see HostWiredService}: an improvised, empty instance would report every
 * kind as unknown — including core's own two — and every route as
 * unauthorable. "This kind is not registered" is an ordinary answer for an
 * uninstalled plugin, so the caller could not tell an unwired container from a
 * genuinely unknown kind. An unregistered lookup throws instead.
 */
final class RoutingRuleRegistry implements HostWiredService
{
    /** Source name for rules shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its slug: `acme:committee`.
     *
     * The same separator {@see ResourceTypeRegistry} uses, referenced rather
     * than repeated so the catalogues cannot drift into spelling the same
     * plugin's keys two different ways.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /**
     * Everyone holding a named role, anywhere in the tenant.
     *
     * The unscoped fan-out: a circular that has to reach every registrar,
     * wherever they sit.
     */
    public const KIND_ROLE = 'role';

    /**
     * Everyone holding a named role, within the acting person's own unit and
     * everything beneath it.
     *
     * The scoped fan-out, and the reason {@see \Whity\Sdk\Routing\RoutingRuleContext}
     * carries the actor: this kind resolves to a different set for each person
     * who reaches it, which is what "distribution fans out, it does not block"
     * looks like when a rule is written down.
     */
    public const KIND_ROLE_BELOW_ACTOR = 'role_below_actor';

    /**
     * Exactly these named people, and nobody else (#999).
     *
     * The enumerated case, expressed as a RULE rather than as an exception to
     * rules. It is here so that a hand-picked set and a computed set are the same
     * kind of object — one code path for preview, routing, permissions and
     * deletion, and an admin who does not have to decide up front which sort of
     * thing they are making.
     *
     * {@see \Whity\Core\Audience\ExplicitRuleResolver} argues why a list in a
     * single opaque JSONB value is not the membership table migration 116
     * rejects.
     */
    public const KIND_EXPLICIT = 'explicit';

    /**
     * Everyone in a named USER GROUP (#999).
     *
     * The reference kind: `{"group_id": 7}`. This is what makes a group REUSABLE
     * rather than merely named — one stored definition, dereferenced from every
     * step that names it, so "the instructors" keeps meaning what the institution
     * currently means by it.
     *
     * DELIBERATELY NOT AN AUDIENCE KIND. {@see \Whity\Core\Group\GroupRuleResolver}
     * implements only {@see RoutingRuleResolverInterface}, so `group` never
     * appears in {@see audienceCatalogue()} and a group can therefore not be
     * defined as another group. Nesting is impossible rather than guarded, which
     * is why there is no cycle detection anywhere in the group code.
     */
    public const KIND_GROUP = 'group';

    /**
     * Widest kind `document_route_steps.rule_kind` holds (migration 112).
     *
     * Validated here as well as by the column so an over-long kind is refused at
     * declaration with a message, rather than truncated or rejected by the
     * driver in the middle of authoring a route.
     */
    public const KEY_MAX_LENGTH = 128;

    /**
     * Registered resolvers, keyed by canonical kind.
     *
     * @var array<string, RoutingRuleResolverInterface>
     */
    private array $resolvers = [];

    /**
     * Which SOURCE registered each canonical kind, kept for attribution — the
     * catalogue endpoint says where a kind came from, so an operator can tell
     * "this is core's" from "this arrived with the clinic plugin" when deciding
     * what is safe to uninstall.
     *
     * @var array<string, string>
     */
    private array $sourceByKind = [];

    /** Whether core's own two kinds have been applied. */
    private bool $coreRegistered = false;

    public function __construct(
        private readonly ?HookManager $hookManager = null,
    ) {
    }

    /**
     * Register a source's routing rules.
     *
     * Each rule is validated and stored INDEPENDENTLY, matching
     * {@see \Whity\Core\DataType\DataTypeRegistry::register()} and
     * {@see \Whity\Core\Ou\OuTypeRegistry::register()}: one malformed
     * declaration is rejected on its own and does not discard the source's
     * other rules, so a plugin author's typo costs them one rule rather than
     * all of them. A rule is never partially stored.
     *
     * @param string                                     $source    Plugin name supplied by the loader.
     * @param array<string, RoutingRuleResolverInterface> $declared Bare slug => resolver.
     * @return list<string> The canonical kinds actually registered.
     *
     * @throws InvalidRoutingRuleException On the FIRST invalid declaration, so
     *         the loader can log it against the plugin. Rules validated before
     *         it are already stored.
     */
    public function register(string $source, array $declared): array
    {
        if ($source === self::CORE_SOURCE) {
            throw InvalidRoutingRuleException::forReservedSource($source);
        }

        $prefix = SourceSlug::from($source);
        if ($prefix === null) {
            throw InvalidRoutingRuleException::forSource($source);
        }

        return $this->store($source, $prefix, $declared);
    }

    /**
     * The routing rules core owns.
     *
     * Kept as a method rather than inlined so adding a core kind later is a
     * change here and nowhere else — and so the list is readable as the answer
     * to "what did core decide is generic enough to ship".
     *
     * @return array<string, RoutingRuleResolverInterface>
     */
    public static function coreRoutingRules(
        RoleRuleResolver $role,
        RoleBelowActorRuleResolver $below,
        ExplicitRuleResolver $explicit,
        GroupRuleResolver $group,
    ): array {
        return [
            self::KIND_ROLE => $role,
            self::KIND_ROLE_BELOW_ACTOR => $below,
            self::KIND_EXPLICIT => $explicit,
            self::KIND_GROUP => $group,
        ];
    }

    /**
     * Apply core's declaration. Idempotent and bootstrap-safe.
     *
     * Core's resolvers query, so unlike the OU-type and resource-type
     * registries this cannot be applied lazily on first read from data the
     * registry already holds — the host has to hand them in. That is why there
     * is no `ensureCoreRegistered()` behind {@see get()}: a registry asked for
     * `role` before the host wired it must not silently answer "unknown", so
     * the wiring is explicit and the {@see HostWiredService} marker makes an
     * unwired container throw rather than improvise.
     */
    public function registerCoreRoutingRules(
        RoleRuleResolver $role,
        RoleBelowActorRuleResolver $below,
        ExplicitRuleResolver $explicit,
        GroupRuleResolver $group,
    ): void {
        if ($this->coreRegistered) {
            return;
        }

        // Set first so a dispatch hook cannot recurse back into core
        // registration (the same guard PermissionRegistry, ResourceTypeRegistry
        // and OuTypeRegistry use).
        $this->coreRegistered = true;
        $this->store(self::CORE_SOURCE, null, self::coreRoutingRules($role, $below, $explicit, $group));
    }

    /**
     * The resolver for a kind IF it can answer without a document, or null.
     *
     * The accessor a named USER GROUP reads (#999). A group is one of these same
     * rule expressions given a name and stored once, and it is resolved with no
     * document, route or step in the question — so only a kind that promised it
     * needs none of those may define one. The promise is
     * {@see AudienceRuleResolverInterface}, and this method is where it is
     * checked, once, rather than at every call site.
     *
     * Null has three causes and every caller distinguishes them, because they
     * need three different fixes: nothing registered the kind, something did but
     * it is routing-only, or the kind is `group` itself (which is routing-only ON
     * PURPOSE — see {@see KIND_GROUP} — and is why nesting is impossible rather
     * than guarded). {@see \Whity\Core\Group\GroupResolver} does that
     * discrimination and writes the message.
     */
    public function audienceResolver(string $kind): ?AudienceRuleResolverInterface
    {
        $resolver = $this->resolvers[$kind] ?? null;

        return $resolver instanceof AudienceRuleResolverInterface ? $resolver : null;
    }

    /**
     * The resolver for a kind, or null when nothing registered it.
     *
     * Null is a REAL answer, not merely a miss: a step naming a kind whose
     * plugin has since been uninstalled is a state migration 112 deliberately
     * allows (`rule_kind` carries no foreign key), and callers turn the null
     * into a failure that NAMES the missing kind. Silently skipping such a step
     * would drop a whole class of people from a distribution and report success
     * — the exact failure this item exists to prevent.
     */
    public function get(string $kind): ?RoutingRuleResolverInterface
    {
        return $this->resolvers[$kind] ?? null;
    }

    /**
     * Whether a kind was declared in code by core or a plugin.
     */
    public function has(string $kind): bool
    {
        return array_key_exists($kind, $this->resolvers);
    }

    /**
     * Every registered kind with its label and source, ordered by kind.
     *
     * What `GET /api/routing-rules` renders — the list of things a route step
     * may name. Ordering is presentational and stable so a picker does not
     * reshuffle between requests served by different workers.
     *
     * @return list<array{kind: string, label: string, source: string}>
     */
    public function catalogue(): array
    {
        $kinds = array_keys($this->resolvers);
        sort($kinds);

        return array_map(
            fn (string $kind): array => [
                'kind' => $kind,
                'label' => $this->resolvers[$kind]->label(),
                'source' => $this->sourceByKind[$kind] ?? self::CORE_SOURCE,
            ],
            $kinds
        );
    }

    /**
     * Every registered kind that can DEFINE A USER GROUP, ordered by kind.
     *
     * What `GET /api/group-rules` renders (#999) — the list of things a group's
     * definition may name, which is a SUBSET of what a route step may name. Two
     * endpoints over one registry rather than one endpoint with a flag: a client
     * asking "what can I put in this picker" gets an answer it can render
     * without filtering, and the difference between the two lists is a fact about
     * the kinds rather than something the client has to know.
     *
     * The subset is not arbitrary. It excludes `group` itself (deliberately — see
     * {@see KIND_GROUP}) and any kind whose resolver needs the document it is
     * being routed with, because such a kind genuinely cannot answer the question
     * a group asks.
     *
     * Ordering is presentational and stable so a picker does not reshuffle
     * between requests served by different workers.
     *
     * @return list<array{kind: string, label: string, source: string}>
     */
    public function audienceCatalogue(): array
    {
        $kinds = [];
        foreach (array_keys($this->resolvers) as $kind) {
            if ($this->resolvers[$kind] instanceof AudienceRuleResolverInterface) {
                $kinds[] = $kind;
            }
        }
        sort($kinds);

        return array_map(
            fn (string $kind): array => [
                'kind' => $kind,
                'label' => $this->resolvers[$kind]->label(),
                'source' => $this->sourceByKind[$kind] ?? self::CORE_SOURCE,
            ],
            $kinds
        );
    }

    /**
     * The source that registered a kind, or null when the kind is unknown.
     */
    public function sourceOf(string $kind): ?string
    {
        return $this->sourceByKind[$kind] ?? null;
    }

    /**
     * The canonical kind a given source's bare slug resolves to.
     *
     * Callers holding a bare slug and a source use this rather than
     * concatenating by hand, so the namespacing rule lives in exactly one place
     * and a change to it cannot silently orphan every step a plugin's users
     * have already authored. Delegates to {@see ResourceTypeRegistry::canonicalKey()}
     * for the same reason the separator is borrowed: one plugin, one namespace,
     * spelled the same way by every catalogue in the platform.
     */
    public static function canonicalKey(string $source, string $slug): string
    {
        if ($source === self::CORE_SOURCE) {
            return $slug;
        }

        return ResourceTypeRegistry::canonicalKey($source, $slug);
    }

    /**
     * A valid BARE slug: lowercase, starts with a letter, then letters, digits
     * and underscores.
     *
     * Intentionally has no colon — the colon is the namespace separator the host
     * applies, so accepting one here would let a declaration choose its own
     * namespace, which is the whole thing the loader-stamped prefix prevents.
     */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $slug) === 1
            && strlen($slug) <= self::KEY_MAX_LENGTH;
    }

    /**
     * A valid CANONICAL kind: a bare slug, or `prefix:slug` for a plugin rule.
     *
     * The shape every stored `document_route_steps.rule_kind` must match. A
     * value that fails it is malformed input (422 when a route is authored),
     * never a step that is quietly stored and fails when somebody reaches it.
     */
    public static function isValidKind(string $kind): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(?::[a-z][a-z0-9_]*)?$/', $kind) === 1
            && strlen($kind) <= self::KEY_MAX_LENGTH;
    }

    /**
     * Validate and store a batch under an already-resolved prefix.
     *
     * @param string                                      $source   Raw source name, kept for attribution.
     * @param string|null                                 $prefix   Namespace prefix, or null for core (bare kinds).
     * @param array<string, RoutingRuleResolverInterface> $declared Bare slug => resolver.
     * @return list<string>
     *
     * @throws InvalidRoutingRuleException
     */
    private function store(string $source, ?string $prefix, array $declared): array
    {
        $registered = [];

        foreach ($declared as $slug => $resolver) {
            $slug = (string) $slug;
            if (!self::isValidSlug($slug)) {
                throw InvalidRoutingRuleException::forSlug($slug);
            }

            $kind = $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
            if (strlen($kind) > self::KEY_MAX_LENGTH) {
                // Reachable with a valid slug: the prefix is added by the host,
                // so a legal slug under a long plugin name can still overflow
                // the column. Caught here rather than by the driver, mid-write.
                throw InvalidRoutingRuleException::forOverlongKey($kind, self::KEY_MAX_LENGTH);
            }
            if (array_key_exists($kind, $this->resolvers)) {
                throw InvalidRoutingRuleException::forDuplicateKey($kind);
            }
            if (!$resolver instanceof RoutingRuleResolverInterface) {
                throw InvalidRoutingRuleException::forMalformedResolver($kind);
            }

            $this->resolvers[$kind] = $resolver;
            $this->sourceByKind[$kind] = $source;
            $registered[] = $kind;

            $this->hookManager?->dispatch('routing_rule.registered', [
                'source' => $source,
                'rule_kind' => $kind,
            ]);
        }

        return $registered;
    }
}
