<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupRejectedException;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\UserGroupPresenter;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Ou\PrimaryMembershipOu;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Sdk\Routing\ResolvedRecipient;

/**
 * Named user groups (#999):
 *
 *   GET    /api/group-rules                    (groups:read)
 *   GET    /api/user-groups                    (groups:read)
 *   POST   /api/user-groups                    (groups:write)
 *   POST   /api/user-groups/preview            (groups:write)
 *   GET    /api/user-groups/{id}               (groups:read)
 *   GET    /api/user-groups/{id}/preview       (groups:read)
 *   PATCH  /api/user-groups/{id}               (groups:write)
 *   DELETE /api/user-groups/{id}               (groups:write)
 *
 * A group is a NAMED RULE, not a list of people. Everything on this surface
 * follows from that, and the shape is worth reading as an argument:
 *
 * THERE IS NO MEMBER LIST ENDPOINT, AND THERE WILL NOT BE
 * ------------------------------------------------------
 * `/preview` is the only way to ask who is in a group, and it answers with a
 * COUNT and a bounded sample — "1,043 people right now, including these ten" —
 * with no page parameter and no way to ask for more. That is not an omission to
 * be filled in later. A screen that renders 1,043 rows has rebuilt the exact
 * problem the design exists to avoid: the thousand nodes standing in for the one
 * that says "instructors". A caller who genuinely wants a person-by-person list
 * is asking a different question — "who holds the instructor role" — and
 * `/api/users` already answers it, with its own filtering, its own paging and
 * its own permission.
 *
 * THE LIST CARRIES NO COUNTS
 * --------------------------
 * `GET /api/user-groups` returns definitions only. A `member_count` per row
 * would make every render of the list resolve every rule — forty groups, forty
 * fan-out queries, to decorate a screen on which nobody asked a membership
 * question. Resolution is live and uncached by design
 * ({@see GroupResolver} argues why), so the way to keep that affordable is to
 * resolve only when somebody asks, which is what `/preview` is.
 *
 * TWO CATALOGUES, NOT ONE WITH A FLAG
 * -----------------------------------
 * `/api/group-rules` lists the kinds a group may be DEFINED as, which is a
 * subset of what `/api/routing-rules` lists for a route step: it excludes
 * `group` itself, and any plugin kind that needs the document it is routed with.
 * A client reads whichever list matches the picker it is drawing rather than
 * filtering one list by a rule it would have to know.
 *
 * NAMES IN A SAMPLE ARE GATED SEPARATELY, ON `users:read`
 * ------------------------------------------------------
 * The COUNT is a property of the rule and `groups:read` covers it. The names of
 * the people in the sample are a property of PEOPLE, and who may read those is a
 * question `users:read` already answers everywhere else in the platform.
 * Answering it a second way here would be a quiet widening: "may see group
 * definitions" would start to imply "may see who anybody is". So a caller
 * without `users:read` gets the ids and nulls where the names would be — the
 * same payload shape, which is what stops a client branching on which flavour it
 * received.
 *
 * NOT FOUND, NEVER FORBIDDEN, on another tenant's group id. Group ids are
 * enumerable integers, so a 403 would confirm which of them exist and let the
 * shape of a neighbouring tenant's configuration be read by walking them. Same
 * posture as {@see DocumentsApiHandler} and {@see DocumentCollectionsApiHandler}.
 *
 * DELETION IS NOT GUARDED BY REFERENCES, on purpose. A route step naming a
 * deleted group fails LOUDLY and by name when it is reached
 * ({@see \Whity\Core\Group\GroupRuleResolver}), which is strictly better than
 * either alternative: silently resolving to nobody would drop a whole class of
 * people from a distribution and report success, and refusing the delete would
 * mean scanning every step's opaque JSONB in two SQL dialects and would make a
 * group undeletable because of a route somebody abandoned.
 */
final class UserGroupsApiHandler
{
    /**
     * The ceiling on a group's description.
     *
     * `description` is TEXT, so the database imposes none. A bound exists because
     * the field is rendered beside a name in a picker and a caller who pastes a
     * page into it makes that picker unusable for everybody else in the tenant.
     * Not a tenant setting, deliberately: it is a property of the field's
     * PURPOSE — one or two sentences saying what the group is for — rather than a
     * capacity an operator would tune. Same reasoning as
     * {@see DocumentRoutingApiHandler}'s note ceiling.
     */
    private const MAX_DESCRIPTION_LENGTH = 1000;

    /** Matches the `name VARCHAR(160)` column, so the validator and the column agree. */
    private const MAX_NAME_LENGTH = 160;

    public function __construct(
        private readonly PDO $db,
        private readonly UserGroupRepository $groups,
        private readonly GroupResolver $resolver,
        private readonly RoutingRuleRegistry $rules,
        private readonly SettingsService $settings,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {
    }

    /**
     * GET /api/group-rules — what a group's definition may name on this instance.
     *
     * Instance-wide rather than per-tenant: the catalogue is CODE (core's kinds
     * plus whatever the installed plugins registered), so it is the same for
     * every tenant on the install. Gated on `groups:read` because it is only
     * useful to somebody defining a group, and an unauthenticated reader would
     * learn which plugins are installed.
     */
    public function rules(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return Response::json(['data' => $this->rules->audienceCatalogue()]);
    }

    /**
     * GET /api/user-groups — this tenant's group definitions, by name.
     *
     * Paginated, because a tenant accumulates groups without bound and a picker
     * that fetches all of them is a picker that stops loading at some point
     * nobody predicted.
     */
    public function index(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;

        $p = PaginationParams::fromPath($request->getPath());
        $total = $this->groups->countForTenant($tenantId);
        $rows = $this->groups->listForTenant($tenantId, $p->perPage, $p->offset);

        return Response::json([
            'data' => array_map(UserGroupPresenter::group(...), $rows),
            'pagination' => $p->meta($total),
        ]);
    }

    /**
     * GET /api/user-groups/{id} — one group's definition.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $resolved = $this->resolveGroupRow($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [, , $group] = $resolved;

        return Response::json(['data' => UserGroupPresenter::group($group)]);
    }

    /**
     * POST /api/user-groups — define a group.
     */
    public function create(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $body = JsonBody::parsed($request);

        $name = $this->validName($body);
        if ($name instanceof Response) {
            return $name;
        }
        $description = $this->validDescription($body);
        if ($description instanceof Response) {
            return $description;
        }
        $rule = $this->validRule($body);
        if ($rule instanceof Response) {
            return $rule;
        }
        [$kind, $config] = $rule;

        // The pre-flight check exists so a duplicate is a 409 naming what is
        // already there, rather than a driver integrity error the caller cannot
        // read. The UNIQUE constraint is still the authority and still closes the
        // race a concurrent create opens.
        if ($this->groups->findByName($name, $tenantId) !== null) {
            return Response::error("A user group called '{$name}' already exists in this tenant", 409);
        }

        try {
            // Validated by the RULE's own resolver before anything is written:
            // the only code that knows what an `acme:committee` config means is
            // the resolver the plugin registered, and its message reaches the
            // author verbatim.
            $this->resolver->validateExpression($kind, $config);
        } catch (GroupRejectedException $e) {
            // ->clientMessage, never ->getMessage(): the exception wraps text a
            // PLUGIN wrote, so the two strings have to stay distinguishable. See
            // the exception's docblock.
            return Response::error($e->clientMessage, 422);
        }

        $id = $this->groups->create($tenantId, $name, $description, $kind, $config, $callerId);
        $created = $this->groups->findById($id, $tenantId);
        if ($created === null) {
            return Response::error('The user group was created but could not be read back', 500);
        }

        return Response::json(['data' => UserGroupPresenter::group($created)], 201);
    }

    /**
     * PATCH /api/user-groups/{id} — rename or redefine a group.
     *
     * A PATCH that omits a field leaves it as it was, and a PATCH that changes
     * `rule_kind` without sending `rule_config` is refused rather than silently
     * paired with the old config: the two are one value, and a config that
     * validated against `role` means nothing to `explicit`.
     *
     * The redefinition takes effect immediately for everything naming this group,
     * INCLUDING routes already in flight. That is the intended reading and
     * {@see UserGroupRepository::update()} argues it: the group means what it now
     * says. What each step ACTUALLY reached is recorded immutably in the routing
     * trail, which is where "who got this in March" is answered.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $resolved = $this->resolveGroupRow($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $group] = $resolved;

        $body = JsonBody::parsed($request);

        $name = (string) $group['name'];
        if (array_key_exists('name', $body)) {
            $checked = $this->validName($body);
            if ($checked instanceof Response) {
                return $checked;
            }
            $name = $checked;
        }

        $description = $group['description'] !== null ? (string) $group['description'] : null;
        if (array_key_exists('description', $body)) {
            $checked = $this->validDescription($body);
            if ($checked instanceof Response) {
                return $checked;
            }
            $description = $checked;
        }

        $kind = (string) $group['rule_kind'];
        /** @var array<string, mixed> $config */
        $config = is_array($group['rule_config']) ? $group['rule_config'] : [];

        $sendsKind = array_key_exists('rule_kind', $body);
        $sendsConfig = array_key_exists('rule_config', $body);
        if ($sendsKind !== $sendsConfig && ($sendsKind || $sendsConfig)) {
            return Response::error(
                "'rule_kind' and 'rule_config' must be sent together — a config written for one kind "
                . 'does not mean anything to another',
                422
            );
        }
        if ($sendsKind) {
            $rule = $this->validRule($body);
            if ($rule instanceof Response) {
                return $rule;
            }
            [$kind, $config] = $rule;
        }

        if ($name !== (string) $group['name'] && $this->groups->findByName($name, $tenantId) !== null) {
            return Response::error("A user group called '{$name}' already exists in this tenant", 409);
        }

        try {
            // Re-validated on every update, even when only the name changed. A
            // definition saved under an older version of a plugin may no longer
            // satisfy that plugin's own validator, and refusing the save is
            // better than persisting a rule the resolver will later refuse to
            // resolve.
            $this->resolver->validateExpression($kind, $config);
        } catch (GroupRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        }

        $this->groups->update((int) $group['id'], $tenantId, $name, $description, $kind, $config);
        $updated = $this->groups->findById((int) $group['id'], $tenantId);
        if ($updated === null) {
            return Response::error('The user group was updated but could not be read back', 500);
        }

        return Response::json(['data' => UserGroupPresenter::group($updated)]);
    }

    /**
     * DELETE /api/user-groups/{id}.
     *
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $resolved = $this->resolveGroupRow($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $group] = $resolved;

        $this->groups->delete((int) $group['id'], $tenantId);

        // AUDITED, and it is the ONLY act here that is. The others announce
        // themselves: a created or renamed group appears in the list, and a
        // redefined one changes what a preview says. A DELETION is the one whose
        // consequence surfaces LATER AND SOMEWHERE ELSE — as "step 3 names user
        // group 7, which does not exist" on a route somebody is trying to
        // forward, possibly weeks afterwards. Without a row here, nothing
        // connects that symptom to the act that caused it, and the rule and its
        // name are gone along with the group.
        //
        // Same reasoning {@see TagsApiHandler} records for a forced tag
        // destruction, and the reason the metadata carries the RULE rather than
        // only the id: the id is the only thing the later failure mentions, and
        // the rule is what somebody would need to recreate it.
        $this->auditLogger?->record('user_group.deleted', [
            'tenant_id' => $tenantId,
            'actor_user_id' => $callerId,
            'target_type' => 'user_group',
            'target_id' => (int) $group['id'],
            'metadata' => [
                'name' => $group['name'],
                'rule_kind' => $group['rule_kind'],
                'rule_config' => $group['rule_config'],
            ],
        ]);

        return Response::json(['data' => ['id' => (int) $group['id'], 'deleted' => true]]);
    }

    /**
     * GET /api/user-groups/{id}/preview — how many people, and a few of them.
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        $resolved = $this->resolveGroupRow($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $group] = $resolved;

        /** @var array<string, mixed> $config */
        $config = is_array($group['rule_config']) ? $group['rule_config'] : [];

        return $this->renderPreview($tenantId, $callerId, (string) $group['rule_kind'], $config);
    }

    /**
     * POST /api/user-groups/preview — preview a definition that has not been saved.
     *
     * The point of the whole preview contract. An author writing "everyone
     * holding the instructor role, in my unit and below" needs to know they wrote
     * what they meant BEFORE committing to it, and a preview that only worked on
     * saved groups would make them save a wrong one to find out.
     *
     * Gated on `groups:write` rather than `groups:read`, and that is the tighter
     * of the two on purpose: this endpoint resolves an ARBITRARY rule the caller
     * composed, so it answers questions about the organisation that no stored
     * group asks. A reader who may only see existing definitions should not be
     * able to probe "how many people hold role 4" by inventing rules.
     */
    public function previewDraft(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $body = JsonBody::parsed($request);
        $rule = $this->validRule($body);
        if ($rule instanceof Response) {
            return $rule;
        }
        [$kind, $config] = $rule;

        return $this->renderPreview($tenantId, $callerId, $kind, $config);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Resolve, render and name-decorate a preview.
     *
     * @param array<string, mixed> $config
     */
    private function renderPreview(int $tenantId, int $callerId, string $kind, array $config): Response
    {
        // The actor a relative rule resolves against is the CALLER, from their
        // primary membership unit. A group defined as `role_below_actor` means
        // something different for every person who uses it, and a preview that
        // silently resolved against nobody would report zero for a perfectly
        // good rule. The unit is reported back in `resolved_for` so two
        // colleagues reading two different counts have the explanation on screen.
        $actorOuId = PrimaryMembershipOu::forProfile($this->db, $tenantId, $callerId);

        try {
            $preview = $this->resolver->preview(
                $tenantId,
                $kind,
                $config,
                $callerId,
                $actorOuId,
                $this->previewSampleSize($tenantId),
            );
        } catch (GroupRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        }

        return Response::json([
            'data' => UserGroupPresenter::preview($preview, $this->displayNames($callerId, $tenantId, $preview->sample)),
        ]);
    }

    /**
     * Display names for the sample, or an empty map when the caller may not read
     * people.
     *
     * See the class docblock: the count is a fact about the rule and is covered
     * by `groups:read`; a person's name is a fact about a person, and
     * `users:read` is the platform's existing answer to who may read those.
     * Widening `groups:read` to imply it would be a quiet privilege escalation
     * dressed as a convenience field.
     *
     * One query for the whole sample, never one per row — the sample is bounded
     * by the preview sample size, so this is a single small `IN`.
     *
     * @param list<ResolvedRecipient> $sample
     * @return array<int, string>
     */
    private function displayNames(int $callerId, int $tenantId, array $sample): array
    {
        if ($sample === []) {
            return [];
        }
        $permissions = $this->roleChecker->getEffectivePermissionsForProfile($callerId, $tenantId);
        if (!in_array(CorePermissions::USERS_READ, $permissions, true)) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($sample as $i => $recipient) {
            $key = ':p' . $i;
            $placeholders[] = $key;
            $params[$key] = $recipient->profileId;
        }

        // `profiles` is a SANCTIONED GLOBAL table (ADR 0005) — identity belongs
        // to a person, not to an org, so there is no `tenant_id` to bind and the
        // predicate guard exempts it. Tenant scoping happened upstream: every id
        // here came out of {@see \Whity\Core\Audience\ActiveMemberFilter}, which
        // is where the membership check lives, so this statement cannot be asked
        // about a profile outside the tenant.
        $stmt = $this->db->prepare(
            'SELECT id, display_name FROM profiles WHERE id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);

        $names = [];
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) $row['display_name'];
        }

        return $names;
    }

    /**
     * Per-tenant, then global, then the registry default. Never hardcoded.
     *
     * The same resolution {@see \Whity\Core\Document\Routing\DocumentRouter}
     * uses for its ceilings, and the same treatment of a bad stored value: a
     * non-numeric or non-positive value falls back to the default rather than
     * being honoured, because a "0" typed into a settings field must not silently
     * mean "show nobody" — a preview whose sample is empty reports a count with
     * nothing to check it against.
     */
    private function previewSampleSize(int $tenantId): int
    {
        $effective = $this->settings->effective($tenantId);
        $raw = $effective[SettingsRegistry::GROUPS_PREVIEW_SAMPLE_SIZE] ?? null;
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        // `defaultFor()`, not `defaults()[...] ?? '10'`. The key is a constant on
        // the registry, so a literal beside it is not a safety net — it is a
        // SECOND default, in a second place, that nothing would ever notice
        // disagreeing with the first. DocumentRouter needs the `??` form because
        // its key is a variable; here the registry either knows the key or the
        // constant is wrong, and `defaultFor()` says so instead of quietly
        // substituting a number of its own.
        return max(1, (int) SettingsRegistry::defaultFor(SettingsRegistry::GROUPS_PREVIEW_SAMPLE_SIZE));
    }

    /**
     * Validate `name`, or an error Response.
     *
     * @param array<string, mixed> $body
     */
    private function validName(array $body): string|Response
    {
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return Response::error("'name' is required", 422);
        }
        $name = trim($name);
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return Response::error(
                "'name' must be " . self::MAX_NAME_LENGTH . ' characters or fewer',
                422
            );
        }

        return $name;
    }

    /**
     * Validate `description`, or an error Response. Absent and empty both mean
     * null — a description somebody cleared is a description that is not there,
     * and storing an empty string would make two spellings of the same absence.
     *
     * @param array<string, mixed> $body
     */
    private function validDescription(array $body): string|null|Response
    {
        $description = $body['description'] ?? null;
        if ($description === null) {
            return null;
        }
        if (!is_string($description)) {
            return Response::error("'description' must be a string when present", 422);
        }
        $description = trim($description);
        if ($description === '') {
            return null;
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            return Response::error(
                "'description' must be " . self::MAX_DESCRIPTION_LENGTH . ' characters or fewer',
                422
            );
        }

        return $description;
    }

    /**
     * Validate `rule_kind` + `rule_config` as a pair, or an error Response.
     *
     * The SHAPE only. Whether the config is usable is the resolver's question and
     * is asked separately, because only the resolver knows — and its answer is a
     * message written for the author.
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: array<string, mixed>}|Response
     */
    private function validRule(array $body): array|Response
    {
        $kind = $body['rule_kind'] ?? null;
        if (!is_string($kind) || $kind === '') {
            return Response::error("'rule_kind' is required", 422);
        }
        if (!RoutingRuleRegistry::isValidKind($kind)) {
            return Response::error("'{$kind}' is not a well-formed rule kind", 422);
        }

        $config = $body['rule_config'] ?? [];
        if (!is_array($config)) {
            return Response::error("'rule_config' must be an object", 422);
        }
        // A JSON object decodes to an associative array and a JSON array to a
        // list; both arrive here as `array`. An empty list is the one ambiguous
        // case (`[]` is how a client spells "no options") and is accepted as an
        // empty map, which is what the column stores.
        if ($config !== [] && array_values($config) === $config) {
            return Response::error("'rule_config' must be an object, not an array", 422);
        }

        /** @var array<string, mixed> $config */
        return [$kind, $config];
    }

    /**
     * Resolve the tenant, the caller, and a group in that tenant.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolveGroupRow(Request $request, array $params): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $group = $this->groups->findById((int) ($params['id'] ?? 0), $tenantId);
        if ($group === null) {
            return Response::error('User group not found', 404);
        }

        return [$tenantId, $callerId, $group];
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response. Mirrors
     * {@see DocumentRoutingApiHandler::context()}.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }
}
