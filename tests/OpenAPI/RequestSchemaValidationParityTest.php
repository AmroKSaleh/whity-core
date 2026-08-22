<?php

declare(strict_types=1);

namespace Tests\OpenAPI;

use Closure;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\EntityTagsApiHandler;
use Whity\Api\OusApiHandler;
use Whity\Api\OuTypesApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\TagGroupsApiHandler;
use Whity\Api\TagsApiHandler;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\Ou\OuTypeRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Taxonomy\EntityTagRepository;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * Proves the declared request schemas are RIGHT, not merely present.
 *
 * A test asserting "POST /api/v1/users has a requestBody" is worth very little:
 * it passes just as happily against a schema that names the wrong fields, and a
 * generated client built on that schema will confidently send the wrong thing —
 * which is worse than sending nothing, because the caller has no reason to
 * doubt it. So instead of reading the declaration twice, this drives the REAL
 * handler against a real engine and checks the declaration against what the
 * code actually does:
 *
 *   - a body carrying every declared property is ACCEPTED, so the schema
 *     describes a request that works;
 *   - omitting any declared-REQUIRED property is REJECTED with a 4xx, so
 *     `required` is not overstated relative to the code;
 *   - omitting any declared-OPTIONAL property is still ACCEPTED, so `required`
 *     is not understated either — this is the half that catches
 *     `MembershipCreateRequest`, which declared nothing required while the
 *     handler answers `400 role_id is required` to an empty body;
 *   - a top-level `anyOf` UNION of two fully-typed alternatives (two spellings
 *     of one mandatory field, as `MembershipCreateRequest` declares them) is
 *     exercised by dropping EVERY spelling: a field required in only some
 *     branches may legally be absent on its own, but the group may not;
 *   - `minProperties: 1` (a PATCH that refuses to be a no-op) is exercised with
 *     an empty body;
 *   - `not: {required: [a, b]}` (address one thing two ways) is exercised by
 *     sending both.
 *
 * Each case supplies its own invoker, because the handlers differ in how they
 * take tenant and caller context. Every invocation gets a distinct sequence
 * number so repeated writes in one case cannot collide on a unique index.
 */
final class RequestSchemaValidationParityTest extends TestCase
{
    private const TENANT = 1;

    /** A profile holding tags:read + tags:manage in {@see self::TENANT}. */
    private const MANAGER = 10;

    /** The only tenant allowed to name another tenant on a write. */
    private const SYSTEM_TENANT = 0;

    /** The global `user` role seeded by the migrations. */
    private const GLOBAL_USER_ROLE = 2;

    private PDO $pdo;

    private Database $db;

    private int $sequence = 0;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = TaxonomyTestSeed::make();
        $this->db = TaxonomyTestSeed::wrap($this->pdo);

        // A unit and a type to reference, plus a profile to grant memberships to.
        $this->pdo->exec(
            "INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at)
             VALUES (500, 1, NULL, 'Engineering', 'engineering', '', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (600, 'member', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at)
             VALUES (1600, 600, 1, 101, 'active', datetime('now'))"
        );

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    /**
     * The declared body, in full, must be a request the handler accepts. If this
     * fails the schema is describing an API that does not exist.
     *
     * @dataProvider cases
     */
    public function testTheFullyPopulatedDeclaredBodyIsAccepted(string $component, string $case): void
    {
        [$invoke, $body] = $this->caseFor($case);

        $response = $invoke($body($this->next()));

        $this->assertLessThan(
            400,
            $response->getStatusCode(),
            "A body carrying every property {$component} declares must be accepted, but the handler answered "
            . $response->getStatusCode() . ': ' . $response->getBody()
        );
    }

    /**
     * Omitting a declared-REQUIRED property must be refused. An overstated
     * `required` would show up here as a 2xx.
     *
     * @dataProvider cases
     */
    public function testOmittingEachRequiredPropertyIsRejected(string $component, string $case): void
    {
        [$invoke, $body] = $this->caseFor($case);
        $schema = CoreApiSchemas::components()[$component];

        $omissions = $this->requiredOmissions($schema);
        $this->assertNotSame([], $omissions, "{$component} declares nothing mandatory — remove it from this provider");

        foreach ($omissions as $label => $drop) {
            $payload = $body($this->next());
            foreach ($drop as $field) {
                unset($payload[$field]);
            }

            $response = $invoke($payload);

            $this->assertGreaterThanOrEqual(
                400,
                $response->getStatusCode(),
                "{$component} declares {$label} mandatory, but the handler ACCEPTED a body without it ("
                . $response->getStatusCode() . '). Either the handler stopped requiring it or the schema '
                . 'overstates the contract — a generated client would omit it and be surprised either way.'
            );
            $this->assertLessThan(
                500,
                $response->getStatusCode(),
                "{$component} without {$label} must be a client error, not a {$response->getStatusCode()}"
            );
        }
    }

    /**
     * Omitting a declared-OPTIONAL property must still be accepted. This is the
     * half that catches an UNDERSTATED `required` — a schema that lets a
     * generated client believe an empty body is legal when the handler refuses
     * it, which is precisely what sent the downstream team probing with `{}`.
     *
     * @dataProvider cases
     */
    public function testOmittingEachOptionalPropertyIsStillAccepted(string $component, string $case): void
    {
        [$invoke, $body] = $this->caseFor($case);
        $schema = CoreApiSchemas::components()[$component];

        $mandatory = $this->mandatoryNames($schema);
        $optional = array_diff(self::propertyNames($schema), $mandatory);
        if ($optional === []) {
            // A legitimately all-mandatory body (an association's three keys).
            // Nothing to drop here; the required half above carries the case.
            $this->markTestSkipped("{$component} declares every property mandatory");
        }

        foreach ($optional as $field) {
            $payload = $body($this->next());
            if (!array_key_exists($field, $payload)) {
                continue; // a mutually-exclusive partner the valid body already leaves out
            }
            unset($payload[$field]);

            $response = $invoke($payload);

            $this->assertLessThan(
                400,
                $response->getStatusCode(),
                "{$component} declares '{$field}' OPTIONAL, but the handler refused a body without it ("
                . $response->getStatusCode() . ': ' . $response->getBody() . '). A generated client that '
                . 'omits it would fail against a schema that said it could.'
            );
        }
    }

    /**
     * `not: {required: [a, b]}` says the two spellings cannot be combined. The
     * handlers answer 422 to exactly that, so the constraint is not decoration.
     *
     * @dataProvider mutuallyExclusiveCases
     */
    public function testSendingBothMutuallyExclusiveFieldsIsRejected(string $component, string $case): void
    {
        $schema = CoreApiSchemas::components()[$component];
        $forbidden = self::stringList(self::asArray($schema['not'] ?? null), 'required');
        $this->assertNotSame([], $forbidden, "{$component} must declare its mutual exclusion");

        [$invoke, $body] = $this->caseFor($case);
        $payload = $body($this->next());

        // The valid body deliberately carries only one of the pair; add the other.
        $payload['ou_type_id'] = $this->seedOuType();
        $payload['type'] = 'department';

        $response = $invoke($payload);

        $this->assertGreaterThanOrEqual(
            400,
            $response->getStatusCode(),
            implode(' and ', $forbidden) . " must not be accepted together, but the handler answered "
            . $response->getStatusCode()
        );
    }

    /**
     * `MembershipCreateRequest` is a union: one alternative requires `role_id`,
     * the other `role`. Both spellings therefore have to work, and neither may
     * be the only one that does — a schema that named just one would invalidate
     * a request the API accepts, and core's own memberships modal sends the
     * `role` spelling, so getting this backwards breaks the platform's own UI
     * (which is how the first attempt here was caught).
     */
    public function testTheRoleAliasIsAcceptedInPlaceOfRoleId(): void
    {
        $byId = $this->users()->addMembership(
            $this->json('POST', '/api/users/600/memberships', ['role_id' => 102]),
            ['id' => 600]
        );
        $this->assertSame(201, $byId->getStatusCode(), 'the declared `role_id` spelling must work: ' . $byId->getBody());

        // 200 rather than 201: the fixture already grants profile 600 this role,
        // and re-granting is idempotent by design. Both are acceptances, which
        // is the only thing the alias has to prove.
        $byName = $this->users()->addMembership(
            $this->json('POST', '/api/users/600/memberships', ['role' => 'tag-manager-a']),
            ['id' => 600]
        );
        $this->assertLessThan(
            400,
            $byName->getStatusCode(),
            'the documented `role` alias must work too, or the description is lying: ' . $byName->getBody()
        );

        $neither = $this->users()->addMembership(
            $this->json('POST', '/api/users/600/memberships', ['ou_id' => 500]),
            ['id' => 600]
        );
        $this->assertSame(400, $neither->getStatusCode(), 'supplying neither spelling is a 400');
    }

    /**
     * Why `RoleCreateRequest` does NOT carry a `not: {required: [tenant_id,
     * global]}` clause, pinned so nobody "tidies" one in.
     *
     * The refusal is VALUE-dependent, not presence-dependent: the handler
     * refuses `global: true` alongside `tenant_id`, and accepts `global: false`
     * alongside it (falsy `global` falls through to the named-tenant branch). A
     * presence-based `not` would forbid `{tenant_id: 1, global: false}` — a body
     * the API accepts — and a generated client validating against it would
     * refuse to send a legal request. An over-broad constraint misdescribes the
     * body just as surely as a missing one, so the rule stays in the property
     * description, where it can be stated accurately.
     */
    public function testRoleOwnershipExclusionIsValueDependentNotPresenceDependent(): void
    {
        $schema = CoreApiSchemas::components()['RoleCreateRequest'];
        $this->assertArrayNotHasKey(
            'not',
            $schema,
            'RoleCreateRequest must not claim tenant_id and global are mutually exclusive by PRESENCE'
        );

        $both = $this->roles()->create($this->json('POST', '/api/roles', [
            'name' => 'ownership-conflict',
            'tenant_id' => self::TENANT,
            'global' => true,
        ], self::SYSTEM_TENANT));
        $this->assertSame(400, $both->getStatusCode(), 'global: true with tenant_id is refused');

        $falseGlobal = $this->roles()->create($this->json('POST', '/api/roles', [
            'name' => 'ownership-explicit-false',
            'tenant_id' => self::TENANT,
            'global' => false,
        ], self::SYSTEM_TENANT));
        $this->assertSame(
            201,
            $falseGlobal->getStatusCode(),
            'global: false alongside tenant_id is ACCEPTED — which is why the exclusion cannot be '
            . 'expressed as a presence-based `not`: ' . $falseGlobal->getBody()
        );
    }

    /**
     * The documented DEFAULT, exercised rather than asserted from the comment:
     * `POST /api/users` with no role at all lands the caller on the global
     * `user` role. There is a live discussion about changing that default, so
     * this pins TODAY'S behaviour — and will fail loudly the day it changes,
     * which is when the schema's description has to change with it.
     */
    public function testOmittingTheRoleOnUserCreateDefaultsToTheGlobalUserRole(): void
    {
        $response = $this->users()->create(
            $this->json('POST', '/api/users', ['email' => 'defaulted@example.test', 'password' => 'a-long-enough-password'])
        );

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());

        $roleId = $this->scalar(
            "SELECT role_id FROM memberships WHERE profile_id = (
                 SELECT profile_id FROM profile_emails WHERE email = 'defaulted@example.test'
             )"
        );

        $this->assertSame(
            self::GLOBAL_USER_ROLE,
            (int) $roleId,
            "UserCreateRequest documents `role` as optional-and-defaulting; the default must be the global `user` role."
        );
    }

    // ==================== cases ====================

    /**
     * @return array<string, array{string, string}> component, case key
     */
    public static function cases(): array
    {
        return [
            'POST /api/users' => ['UserCreateRequest', 'users.create'],
            'POST /api/users/{id}/memberships' => ['MembershipCreateRequest', 'users.addMembership'],
            'POST /api/roles' => ['RoleCreateRequest', 'roles.create'],
            'POST /api/ous' => ['OuCreateRequest', 'ous.create'],
            'POST /api/ous/{id}/roles' => ['OuRoleAssignRequest', 'ous.assignRole'],
            'POST /api/ou-types' => ['OuTypeCreateRequest', 'ouTypes.create'],
            'POST /api/tag-groups' => ['TagGroupCreateRequest', 'tagGroups.create'],
            'POST /api/tags' => ['TagCreateRequest', 'tags.create'],
            'POST /api/entity-tags' => ['EntityTagAssociationRequest', 'entityTags.attach'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mutuallyExclusiveCases(): array
    {
        return [
            'POST /api/ous' => ['OuCreateRequest', 'ous.create'],
        ];
    }

    /**
     * Resolve a case key to its invoker and its fully-populated valid body.
     *
     * @return array{Closure(array<string, mixed>): Response, Closure(int): array<string, mixed>}
     */
    private function caseFor(string $case): array
    {
        return match ($case) {
            'users.create' => [
                fn (array $body): Response => $this->users()->create($this->json('POST', '/api/users', $body)),
                fn (int $n): array => [
                    'email' => "created{$n}@example.test",
                    'password' => 'a-long-enough-password',
                    'role' => 'tag-manager-a',
                    'role_id' => 101,
                    'ou_id' => 500,
                ],
            ],
            'users.addMembership' => [
                fn (array $body): Response => $this->users()->addMembership(
                    $this->json('POST', '/api/users/600/memberships', $body),
                    ['id' => 600]
                ),
                // `role` is absent as well as `tenant_id`: `role` is an ALIAS
                // for `role_id`, so including both would let the required-field
                // loop drop `role_id` and still be accepted, and the point of
                // that loop is that a client honouring `required` sends enough.
                // The alias itself is covered by
                // testTheRoleAliasIsAcceptedInPlaceOfRoleId.
                //
                // `tenant_id` is honoured only for a tenant-0 caller and this one
                // is tenant 1, so it is legitimately absent too; the
                // optional-omission loop skips what is not there.
                fn (int $n): array => ['role_id' => 102, 'ou_id' => 500],
            ],
            'roles.create' => [
                // Runs as the SYSTEM tenant: `tenant_id` and `global` are
                // honoured only for tenant 0 (403 otherwise), so a tenant-1
                // caller could never exercise them and the declaration's two
                // ownership fields would go unchecked.
                fn (array $body): Response => $this->roles()->create(
                    $this->json('POST', '/api/roles', $body, self::SYSTEM_TENANT)
                ),
                // `global` is false, not true: `global: true` together with
                // `tenant_id` is the one combination the handler refuses, and
                // testSendingGlobalTrueWithATenantIdIsRejected covers that.
                fn (int $n): array => [
                    'name' => "declared-role-{$n}",
                    'description' => 'created by the schema parity test',
                    'permissions' => [],
                    'tenant_id' => self::TENANT,
                    'global' => false,
                ],
            ],
            'ous.create' => [
                fn (array $body): Response => $this->ous()->create($this->json('POST', '/api/ous', $body)),
                // `type` is omitted: it and `ou_type_id` are mutually exclusive,
                // and sending both is the 422 the `not` clause describes.
                fn (int $n): array => [
                    'name' => "Declared Unit {$n}",
                    'description' => 'created by the schema parity test',
                    'parent_id' => 500,
                    'ou_type_id' => $this->seedOuType(),
                ],
            ],
            'ous.assignRole' => [
                fn (array $body): Response => $this->ous()->assignRole(
                    $this->json('POST', '/api/ous/500/roles', $body),
                    ['id' => 500]
                ),
                fn (int $n): array => ['role_id' => $this->seedRole($n)],
            ],
            'ouTypes.create' => [
                fn (array $body): Response => $this->ouTypes()->create($this->json('POST', '/api/ou-types', $body)),
                fn (int $n): array => ['key' => "declared_type_{$n}", 'label' => 'Declared', 'sort_order' => 50],
            ],
            'tagGroups.create' => [
                fn (array $body): Response => $this->tagGroups()->create(
                    $this->asManager('POST', '/api/tag-groups', $body)
                ),
                fn (int $n): array => ['key' => "declared-group-{$n}", 'display_name' => ['en' => 'Declared', 'ar' => 'معلن']],
            ],
            'tags.create' => [
                fn (array $body): Response => $this->tags()->create($this->asManager('POST', '/api/tags', $body)),
                fn (int $n): array => ['group_id' => $this->seedTagGroup(), 'name' => "declared-tag-{$n}"],
            ],
            'entityTags.attach' => [
                fn (array $body): Response => $this->entityTags()->attach(
                    $this->asManager('POST', '/api/entity-tags', $body)
                ),
                fn (int $n): array => [
                    'entity_type' => 'invoice',
                    'entity_id' => 1000 + $n,
                    'tag_id' => $this->seedTag($n),
                ],
            ],
            default => throw new \LogicException("unknown case {$case}"),
        };
    }

    // ==================== schema reading ====================

    /**
     * The sets of properties whose ABSENCE the schema says is a refusal.
     *
     * A plain `required` entry contributes one omission of itself; an `anyOf`
     * over required-only branches contributes one omission of every name it
     * mentions (dropping just one spelling is legal — dropping all of them is
     * not); `minProperties: 1` contributes the empty body.
     *
     * @param array<string, mixed> $schema
     * @return array<string, list<string>> label => properties to drop
     */
    private function requiredOmissions(array $schema): array
    {
        $omissions = [];

        foreach (self::alwaysRequired($schema) as $field) {
            $omissions["'{$field}'"] = [$field];
        }

        // A field required in SOME union branches but not all is one spelling of
        // an at-least-one rule: dropping it alone is legal, dropping the whole
        // group is not. That group is the thing to omit.
        $group = self::atLeastOneOf($schema);
        if ($group !== []) {
            $omissions['any of ' . implode('/', $group)] = $group;
        }

        if (self::minProperties($schema) >= 1) {
            $omissions['every property (minProperties)'] = self::propertyNames($schema);
        }

        return $omissions;
    }

    /**
     * Every property the schema treats as non-optional, in any spelling.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function mandatoryNames(array $schema): array
    {
        $names = array_merge(self::alwaysRequired($schema), self::atLeastOneOf($schema));

        // With minProperties every property is load-bearing as a set, so none of
        // them can be dropped one-at-a-time and still be a meaningful test.
        if (self::minProperties($schema) >= 1) {
            $names = array_merge($names, self::propertyNames($schema));
        }

        return array_values(array_unique($names));
    }

    /**
     * The union branches of a component, or the component itself when it is a
     * plain object. `MembershipCreateRequest` is an `anyOf` of two fully-typed
     * alternatives (the only spelling openapi-typescript renders usefully), so
     * every reader here has to see through that shape.
     *
     * @param array<array-key, mixed> $schema
     * @return list<array<array-key, mixed>>
     */
    private static function branches(array $schema): array
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = self::asArray($schema[$keyword] ?? null);
            if ($branches !== []) {
                return array_values(array_map(
                    static fn (mixed $branch): array => self::asArray($branch),
                    $branches
                ));
            }
        }

        return [$schema];
    }

    /**
     * Fields required by EVERY branch — unconditionally mandatory, so omitting
     * one on its own must be refused.
     *
     * @param array<array-key, mixed> $schema
     * @return list<string>
     */
    private static function alwaysRequired(array $schema): array
    {
        $branches = self::branches($schema);
        $shared = self::stringList($branches[0], 'required');

        foreach (array_slice($branches, 1) as $branch) {
            $shared = array_intersect($shared, self::stringList($branch, 'required'));
        }

        return array_values($shared);
    }

    /**
     * Fields required by SOME branch but not all — interchangeable spellings of
     * one mandatory thing. At least one of them must be present.
     *
     * @param array<array-key, mixed> $schema
     * @return list<string>
     */
    private static function atLeastOneOf(array $schema): array
    {
        $branches = self::branches($schema);
        if (count($branches) < 2) {
            return [];
        }

        $group = [];
        foreach ($branches as $branch) {
            foreach (self::stringList($branch, 'required') as $field) {
                $group[$field] = true;
            }
        }

        foreach (self::alwaysRequired($schema) as $field) {
            unset($group[$field]);
        }

        return array_keys($group);
    }

    /**
     * Whatever the spec handed us, as an array — [] when it is anything else.
     *
     * @return array<array-key, mixed>
     */
    private static function asArray(mixed $node): array
    {
        return is_array($node) ? $node : [];
    }

    /**
     * The single column of a one-row query, or null when the query failed or
     * matched nothing. PDO::query() returns false on failure, which is the
     * distinction the seeds below all need to make.
     */
    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return null;
        }

        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * The string entries of `$schema[$key]`, which the spec types as mixed.
     *
     * @param array<array-key, mixed> $schema
     * @return list<string>
     */
    private static function stringList(array $schema, string $key): array
    {
        $values = [];
        foreach (self::asArray($schema[$key] ?? null) as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return list<string>
     */
    private static function propertyNames(array $schema): array
    {
        $names = [];
        foreach (self::branches($schema) as $branch) {
            foreach (array_keys(self::asArray($branch['properties'] ?? null)) as $name) {
                $names[(string) $name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private static function minProperties(array $schema): int
    {
        $declared = $schema['minProperties'] ?? 0;

        return is_int($declared) ? $declared : 0;
    }

    // ==================== fixtures ====================

    private function next(): int
    {
        return ++$this->sequence;
    }

    private function seedOuType(): int
    {
        $existing = $this->scalar("SELECT id FROM ou_types WHERE tenant_id = 1 AND type_key = 'department'");
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) (new OuTypeRepository($this->pdo))->create(self::TENANT, 'department', 'Department', 10, 'tenant');
    }

    private function seedRole(int $n): int
    {
        $this->pdo->prepare("INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, '', 1, datetime('now'))")
            ->execute(["assignable-role-{$n}"]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedTagGroup(): int
    {
        $existing = $this->scalar("SELECT id FROM tag_groups WHERE tenant_id = 1 AND group_key = 'parity'");
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) (new TagGroupRepository($this->pdo))->create(self::TENANT, 'parity', []);
    }

    private function seedTag(int $n): int
    {
        return (int) (new TagRepository($this->pdo))->create(self::TENANT, $this->seedTagGroup(), "parity-tag-{$n}");
    }

    // ==================== handlers ====================

    private function users(): UsersApiHandler
    {
        return new UsersApiHandler($this->pdo, new HookManager());
    }

    private function roles(): RolesApiHandler
    {
        return new RolesApiHandler($this->pdo, new HookManager());
    }

    private function ous(): OusApiHandler
    {
        return new OusApiHandler($this->pdo, new HookManager());
    }

    private function ouTypes(): OuTypesApiHandler
    {
        return new OuTypesApiHandler(new OuTypeRepository($this->pdo), new OuTypeRegistry());
    }

    private function tagGroups(): TagGroupsApiHandler
    {
        return new TagGroupsApiHandler(new TagGroupRepository($this->pdo), $this->roleChecker());
    }

    private function tags(): TagsApiHandler
    {
        return new TagsApiHandler(
            new TagRepository($this->pdo),
            new TagGroupRepository($this->pdo),
            $this->roleChecker()
        );
    }

    private function entityTags(): EntityTagsApiHandler
    {
        return new EntityTagsApiHandler(
            new EntityTagRepository($this->pdo),
            new TagRepository($this->pdo),
            $this->roleChecker()
        );
    }

    private function roleChecker(): RoleChecker
    {
        return new RoleChecker($this->db, new PermissionRegistry());
    }

    // ==================== requests ====================

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body, int $tenantId = self::TENANT): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        return new Request($method, $path, [], (string) json_encode($body));
    }

    /**
     * A request from the tags:manage-holding manager, for the RBAC-checking
     * taxonomy handlers.
     *
     * @param array<string, mixed> $body
     */
    private function asManager(string $method, string $path, array $body): Request
    {
        $request = $this->json($method, $path, $body);
        $request->user = (object) ['profile_id' => self::MANAGER, 'active_tenant_id' => self::TENANT];

        return $request;
    }
}
