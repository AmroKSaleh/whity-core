<?php

declare(strict_types=1);

namespace Tests\OpenAPI;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Api\EntityTagsApiHandler;
use Whity\Api\TagGroupsApiHandler;
use Whity\Api\TagsApiHandler;
use Whity\Core\CoreVersion;
use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\PasswordPolicy;
use Whity\Core\PluginLoader;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Http\InputLimits;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\OpenAPI\SchemaGenerator;

/**
 * The first-party request-body contract, from three angles.
 *
 * 1. THE WIRING. `GET /api/openapi.json` builds its document from the LIVE
 *    router — the one public/index.php fills — and not one of those 250+
 *    `Router::register()` calls passes a `$schema`. The result was a published
 *    spec in which every first-party write endpoint had NO requestBody at all:
 *    a generated client could see `POST /api/v1/users` existed and had no way
 *    to learn it needs `email` and `password`. The offline `generate:openapi`
 *    command was fine, because IT registers {@see CoreApiSchemas} first, so the
 *    committed public/openapi.json hid the problem completely.
 *
 *    {@see testLiveRouterShapedGenerationDocumentsFirstPartyRequestBodies}
 *    reconstructs the live router's shape — every route in index.php, schema
 *    stripped — and asserts the generated document still carries the core
 *    contract.
 *
 * 2. THE BOUNDS. Several declarations disagreed with the code that enforces
 *    them (`password` said `minLength: 6` against a policy of 8). Where the
 *    limit lives in a reachable constant the schema now cites it; where it is
 *    private to a handler the value is pinned here reflectively instead, so the
 *    two cannot drift apart unnoticed.
 *
 * 3. THE VENDOR EXTENSIONS. An `x-whity-reference` whose `resource` is not a
 *    real path renders an empty dropdown in the schema-driven CRUD screen and
 *    reports nothing, so every one of them is resolved against the spec.
 *
 * What this file deliberately does NOT do is assert that a requestBody merely
 * EXISTS. That a body is declared says nothing about whether it is right;
 * {@see RequestSchemaValidationParityTest} drives the real handlers to prove
 * the declared required fields are exactly the ones they reject.
 */
final class RequestSchemaContractTest extends TestCase
{
    /** HTTP methods that can carry a request body. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * The regression that started this: generate the spec from a router shaped
     * like the LIVE one — every route registered with no `schema` argument,
     * exactly as public/index.php registers them — and require the first-party
     * write endpoints to come out documented anyway.
     *
     * Before the core fallback in {@see SchemaGenerator}, `documented` here was
     * ZERO and every assertion below failed.
     */
    public function testLiveRouterShapedGenerationDocumentsFirstPartyRequestBodies(): void
    {
        $spec = $this->generateFromLiveRouterShape();

        $create = self::dig($spec, 'paths', '/api/v1/users', 'post');
        $this->assertNotSame([], $create, 'POST /api/v1/users must be in the document');

        $schema = $this->resolveRequestSchema($spec, $create);
        $this->assertNotNull(
            $schema,
            'POST /api/v1/users must declare a request body. Without it the only way to discover the '
            . 'contract is to post {} and read the validation error.'
        );
        $this->assertSame(
            ['email', 'password'],
            self::requiredOf($schema),
            'email and password are what UsersApiHandler::create() requires; the declaration must say so.'
        );

        $properties = self::dig($schema, 'properties');
        $this->assertArrayHasKey('role', $properties, 'the optional role must be discoverable');
        $this->assertArrayHasKey('role_id', $properties, 'the role_id spelling is accepted too');
        $this->assertNotContains('role', self::requiredOf($schema), 'role is optional — it defaults to `user`');
        $this->assertNotContains('role_id', self::requiredOf($schema), 'role_id is optional — it defaults to `user`');

        // Not a spot check: EVERY core route whose catalogue entry declares a
        // request body must carry one in a live-router-shaped document.
        $missing = [];
        foreach (CoreApiSchemas::routes() as $route) {
            if (!isset($route['schema']['request'])) {
                continue;
            }
            $path = $this->specPath($route);
            $operation = self::dig($spec, 'paths', $path, strtolower($route['method']));
            if ($operation === [] || $this->resolveRequestSchema($spec, $operation) === null) {
                $missing[] = $route['method'] . ' ' . $path;
            }
        }

        $this->assertSame([], $missing, "Declared request bodies lost on the live-router path:\n" . implode("\n", $missing));
    }

    /**
     * The downstream report's own measure, run against a live-router-shaped
     * document: first-party write operations carrying a NON-EMPTY request-body
     * schema. The reporting team counted 0 of 116 on their instance; the same
     * measure over this repo's route set reads 0 of 151 before the fallback and
     * 71 of 151 after it. A regression that silently drops the fallback puts it
     * back to 0, so a floor is asserted rather than the exact number, which
     * moves whenever a route is added.
     */
    public function testMostFirstPartyWriteOperationsCarryARequestBody(): void
    {
        $spec = $this->generateFromLiveRouterShape();

        $documented = 0;
        $undocumented = [];
        foreach (self::dig($spec, 'paths') as $path => $operations) {
            foreach (self::dig($operations) as $method => $operation) {
                $method = strtoupper((string) $method);
                if (!in_array($method, self::WRITE_METHODS, true) || $this->isPluginPath((string) $path)) {
                    continue;
                }
                if ($this->resolveRequestSchema($spec, self::dig($operation)) !== null) {
                    $documented++;
                } else {
                    $undocumented[] = $method . ' ' . $path;
                }
            }
        }

        $this->assertGreaterThan(
            70,
            $documented,
            "Only {$documented} first-party write operations declare a request body. The core catalogue "
            . 'is no longer reaching the live-router document.'
        );

        // Every REMAINING one has to be undocumented on purpose: either the
        // catalogue does not claim the route at all (a KNOWN_UNDOCUMENTED
        // opt-out, which RouteCatalogueCompletenessTest owns) or it claims it
        // and declares no request — a DELETE by id, an action POST. Anything
        // else is a declaration that was written and then lost in transit,
        // which is the failure this whole file exists to prevent.
        $declaresBody = [];
        foreach (CoreApiSchemas::routes() as $route) {
            if (isset($route['schema']['request'])) {
                $declaresBody[$route['method'] . ' ' . $this->specPath($route)] = true;
            }
        }

        $lost = [];
        foreach ($undocumented as $operation) {
            if (isset($declaresBody[$operation])) {
                $lost[] = $operation;
            }
        }

        $this->assertSame(
            [],
            $lost,
            "These routes declare a request body in the catalogue but lose it on the live-router path:\n"
            . implode("\n", $lost)
        );
    }

    /**
     * A route declaring a request body must not ALSO declare a body-less shape:
     * the resolved schema has to have properties, or a client learns nothing.
     * This is what the downstream report counted as "present-but-empty".
     */
    public function testNoDeclaredRequestBodyIsEmpty(): void
    {
        $spec = $this->generateFromLiveRouterShape();

        $empty = [];
        foreach (self::dig($spec, 'paths') as $path => $operations) {
            foreach (self::dig($operations) as $method => $operation) {
                $operation = self::dig($operation);
                $schema = $this->resolveRequestSchema($spec, $operation);
                if ($schema === null) {
                    continue;
                }
                // A multipart upload body legitimately has no JSON properties.
                if (self::dig($operation, 'requestBody', 'content', 'application/json') === []) {
                    continue;
                }
                // A top-level anyOf/oneOf union carries its fields inside the
                // branches (MembershipCreateRequest), so an absent top-level
                // `properties` is only "empty" when there are no branches either.
                $hasFields = self::dig($schema, 'properties') !== []
                    || self::dig($schema, 'allOf') !== []
                    || self::dig($schema, 'anyOf') !== []
                    || self::dig($schema, 'oneOf') !== [];
                if (!$hasFields) {
                    $empty[] = strtoupper((string) $method) . ' ' . $path;
                }
            }
        }

        $this->assertSame([], $empty, "Request bodies declared with no properties:\n" . implode("\n", $empty));
    }

    /**
     * `MembershipCreateRequest` has to admit BOTH spellings of the grant, and
     * neither on its own.
     *
     * This exists because the first attempt declared `role_id` required and
     * nothing else, which reads as a reasonable narrowing until you notice that
     * core's OWN memberships modal posts `{role: name}` — so the schema
     * invalidated a call the platform makes itself, and the generated client
     * made that a compile error. The frontend type check caught it; nothing in
     * PHP did. This is the PHP-side guard, asserting against the declared
     * schema rather than against the handler (which
     * RequestSchemaValidationParityTest already drives).
     */
    public function testTheMembershipGrantAdmitsBothSpellingsAndNeitherAlone(): void
    {
        $schema = self::dig($this->generateFromLiveRouterShape(), 'components', 'schemas', 'MembershipCreateRequest');
        $this->assertNotSame([], $schema, 'MembershipCreateRequest must be in the document');

        $this->assertTrue(
            $this->satisfiesRequired($schema, ['role_id' => 7]),
            'a grant by role id must satisfy the declared schema'
        );
        $this->assertTrue(
            $this->satisfiesRequired($schema, ['role' => 'admin']),
            "a grant by role NAME must satisfy the declared schema — it is what core's own "
            . 'memberships modal sends, and a schema that refused it would make the typed client '
            . 'reject a working call'
        );
        $this->assertTrue(
            $this->satisfiesRequired($schema, ['role_id' => 7, 'role' => 'admin']),
            'sending both is legal (role_id wins), so the union must be anyOf rather than oneOf'
        );
        $this->assertFalse(
            $this->satisfiesRequired($schema, ['ou_id' => 3]),
            'a body with no role at all must satisfy NEITHER alternative — it is the 400 the '
            . 'reporting team discovered by posting {}'
        );
    }

    /**
     * Whether a body satisfies a schema's REQUIRED fields, seeing through a
     * top-level anyOf/oneOf union (at least one alternative must be satisfied).
     *
     * Deliberately not a full JSON-Schema validator: `required` is the property
     * this file is about, and a real validator would pull in a dependency to
     * assert something narrower than it checks.
     *
     * @param array<array-key, mixed> $schema
     * @param array<string, mixed> $body
     */
    private function satisfiesRequired(array $schema, array $body): bool
    {
        $alternatives = self::dig($schema, 'anyOf') ?: self::dig($schema, 'oneOf');
        if ($alternatives === []) {
            $alternatives = [$schema];
        }

        foreach ($alternatives as $alternative) {
            $satisfied = true;
            foreach (self::requiredOf(self::dig($alternative)) as $field) {
                if (!array_key_exists($field, $body)) {
                    $satisfied = false;
                    break;
                }
            }
            if ($satisfied) {
                return true;
            }
        }

        return false;
    }

    // ==================== bounds pinned to the enforcing code ====================

    /**
     * `password` declared `minLength: 6` while {@see PasswordPolicy} has
     * rejected anything under 8 since it was centralised — the schema described
     * a request the API answers 400 to. Both user-facing password fields now
     * read the policy, and this fails if either stops doing so.
     */
    public function testPasswordBoundsMatchThePolicy(): void
    {
        foreach (['UserCreateRequest', 'UserUpdateRequest'] as $component) {
            $password = self::dig(self::components(), $component, 'properties', 'password');

            $this->assertSame(
                PasswordPolicy::MIN_LENGTH,
                $password['minLength'] ?? null,
                "{$component}.password must declare the policy's minimum, not a literal of its own"
            );
            $this->assertSame(
                PasswordPolicy::MAX_LENGTH,
                $password['maxLength'] ?? null,
                "{$component}.password must declare the policy's maximum (bcrypt truncates past it)"
            );
        }
    }

    /**
     * Every write handler bounds its free-text fields through
     * {@see InputLimits}, answering 422 past the cap. The declarations cite the
     * same constants.
     */
    public function testFreeTextBoundsMatchInputLimits(): void
    {
        $components = self::components();

        $nameBounded = [
            ['UserCreateRequest', 'email'],
            ['UserUpdateRequest', 'email'],
            ['RoleCreateRequest', 'name'],
            ['RoleUpdateRequest', 'name'],
            ['OuCreateRequest', 'name'],
            ['OuUpdateRequest', 'name'],
        ];
        foreach ($nameBounded as [$component, $field]) {
            $this->assertSame(
                InputLimits::NAME_MAX,
                self::dig($components, $component, 'properties', $field)['maxLength'] ?? null,
                "{$component}.{$field} must be bounded by InputLimits::NAME_MAX"
            );
        }

        $textBounded = [
            ['RoleCreateRequest', 'description'],
            ['RoleUpdateRequest', 'description'],
            ['OuCreateRequest', 'description'],
            ['OuUpdateRequest', 'description'],
        ];
        foreach ($textBounded as [$component, $field]) {
            $this->assertSame(
                InputLimits::TEXT_MAX,
                self::dig($components, $component, 'properties', $field)['maxLength'] ?? null,
                "{$component}.{$field} must be bounded by InputLimits::TEXT_MAX"
            );
        }
    }

    /**
     * The OU type key's shape lives in {@see OuTypeRegistry}, whose maximum is
     * public — so the declaration cites it directly and only the pattern needs
     * pinning here.
     */
    public function testOuTypeKeyBoundsMatchTheRegistry(): void
    {
        foreach ([['OuCreateRequest', 'type'], ['OuUpdateRequest', 'type'], ['OuTypeCreateRequest', 'key']] as [$component, $field]) {
            $declared = self::dig(self::components(), $component, 'properties', $field);

            $this->assertSame(
                OuTypeRegistry::KEY_MAX_LENGTH,
                $declared['maxLength'] ?? null,
                "{$component}.{$field} must be bounded by OuTypeRegistry::KEY_MAX_LENGTH"
            );
            $this->assertTrue(
                OuTypeRegistry::isValidKey('acme:clinic'),
                'guard: the registry still accepts a namespaced key'
            );
            $pattern = $declared['pattern'] ?? null;
            $this->assertIsString($pattern, "{$component}.{$field} must declare a pattern");
            $this->assertSame(
                1,
                preg_match('/' . $pattern . '/', 'acme:clinic'),
                "{$component}.{$field}'s pattern must accept what the registry accepts"
            );
            $this->assertSame(
                0,
                preg_match('/' . $pattern . '/', 'Acme Clinic'),
                "{$component}.{$field}'s pattern must reject what the registry rejects"
            );
        }
    }

    /**
     * The taxonomy handlers keep their limits in PRIVATE constants, so the
     * declarations carry literals. Read the constants reflectively and pin them,
     * which gives the same drift protection without the OpenAPI layer reaching
     * into the Api layer.
     *
     * @dataProvider taxonomyBoundCases
     */
    public function testTaxonomyBoundsMatchTheHandlerConstants(
        string $component,
        string $field,
        string $handler,
        string $constant
    ): void {
        /** @var class-string $handler */
        $value = (new ReflectionClass($handler))->getConstant($constant);
        $declared = self::dig(self::components(), $component, 'properties', $field);

        if (is_int($value)) {
            $this->assertSame(
                $value,
                $declared['maxLength'] ?? null,
                "{$component}.{$field} must match {$handler}::{$constant}"
            );

            return;
        }

        // A PHP preg pattern (delimiters included) vs the bare OpenAPI one.
        $this->assertIsString($value);
        $this->assertSame(
            trim($value, '/'),
            $declared['pattern'] ?? null,
            "{$component}.{$field} must match {$handler}::{$constant} with the preg delimiters removed"
        );
    }

    /**
     * @return array<string, array{string, string, class-string, string}>
     */
    public static function taxonomyBoundCases(): array
    {
        return [
            'tag name' => ['TagCreateRequest', 'name', TagsApiHandler::class, 'MAX_NAME_LENGTH'],
            'tag rename' => ['TagUpdateRequest', 'name', TagsApiHandler::class, 'MAX_NAME_LENGTH'],
            'group key' => ['TagGroupCreateRequest', 'key', TagGroupsApiHandler::class, 'KEY_PATTERN'],
            'group key (update)' => ['TagGroupUpdateRequest', 'key', TagGroupsApiHandler::class, 'KEY_PATTERN'],
            'entity type' => ['EntityTagAssociationRequest', 'entity_type', EntityTagsApiHandler::class, 'MAX_ENTITY_TYPE_LENGTH'],
        ];
    }

    // ==================== vendor extensions ====================

    /**
     * `x-whity-reference` names a collection the CRUD screen FETCHES. A resource
     * that is not a real path produces an empty dropdown and reports nothing, so
     * every one is resolved against the document — including through the version
     * prefix, which is where a hand-written literal would rot first.
     */
    public function testEveryReferenceResolvesToAPathTheSpecServes(): void
    {
        $spec = $this->generateFromLiveRouterShape();
        $dangling = [];

        $this->walk($spec['components']['schemas'], '', function (array $node, string $trail) use ($spec, &$dangling): void {
            $reference = $node['x-whity-reference'] ?? null;
            if (!is_array($reference)) {
                return;
            }

            foreach (['resource', 'valueField', 'labelField'] as $key) {
                if (!isset($reference[$key]) || !is_string($reference[$key]) || $reference[$key] === '') {
                    $dangling[] = "{$trail}: incomplete marker (missing {$key}) — the renderer degrades it to a number box";

                    return;
                }
            }

            if (!isset($spec['paths'][$reference['resource']]['get'])) {
                $dangling[] = "{$trail}: resource '{$reference['resource']}' is not a GET the API serves";
            }
        });

        $this->assertSame([], $dangling, implode("\n", $dangling));
        $this->assertGreaterThan(0, $this->countReferences($spec), 'guard: the references were not silently dropped');
    }

    /**
     * The reference label must be a STRING property of the referenced row. The
     * renderer stringifies it, so pointing at a `x-whity-localized-text` object
     * (a tag group's `display_name`, say) would render "[object Object]" in
     * every dropdown option.
     */
    public function testEveryReferenceLabelIsAStringOnTheReferencedRow(): void
    {
        $spec = $this->generateFromLiveRouterShape();
        $bad = [];

        $this->walk($spec['components']['schemas'], '', function (array $node, string $trail) use ($spec, &$bad): void {
            $reference = $node['x-whity-reference'] ?? null;
            if (!is_array($reference) || !isset($reference['resource'], $reference['labelField'])) {
                return;
            }

            $row = $this->rowSchemaOf($spec, (string) $reference['resource']);
            if ($row === null) {
                return; // resolution itself is covered by the test above
            }

            $label = $row['properties'][$reference['labelField']] ?? null;
            if (!is_array($label) || ($label['type'] ?? null) !== 'string') {
                $bad[] = "{$trail}: labelField '{$reference['labelField']}' is not a string on {$reference['resource']}";
            }
        });

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    // ==================== helpers ====================

    /**
     * Generate a document from a router shaped like the LIVE one: every route
     * public/index.php registers, with NO schema argument — which is exactly how
     * index.php registers them.
     *
     * The route list is extracted from the source the same way
     * {@see RouteCatalogueCompletenessTest} extracts it, so the two gates cannot
     * disagree about what "live" means.
     *
     * @return array<string, mixed>
     */
    private function generateFromLiveRouterShape(): array
    {
        $router = new Router('/v1');
        $noop = static fn (): Response => new Response(501, '');

        foreach ($this->liveRoutes() as [$method, $path, $unversioned]) {
            if ($unversioned) {
                $router->registerUnversioned($method, $path, $noop);
            } else {
                $router->register($method, $path, $noop);
            }
        }

        $loader = new PluginLoader(dirname(__DIR__, 2) . '/plugins', $router);
        $generator = new SchemaGenerator('Whity Core API', CoreVersion::VERSION, $loader, $router);

        ['spec' => $spec, 'errors' => $errors] = $generator->generateAndValidate();
        $this->assertSame([], $errors, "the live-router document must be structurally valid:\n" . implode("\n", $errors));

        return $spec;
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}> method, path, unversioned
     */
    private function liveRoutes(): array
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $this->assertIsString($source, 'could not read public/index.php');

        preg_match_all(
            '/\$router->(register|registerUnversioned)\s*\(\s*\'(GET|POST|PATCH|DELETE|PUT)\'\s*,\s*\'([^\']+)\'/',
            $source,
            $matches,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($matches, 'no route registrations found in public/index.php');

        $routes = [];
        foreach ($matches as $match) {
            $routes[] = [$match[2], $match[3], $match[1] === 'registerUnversioned'];
        }

        return $routes;
    }

    /**
     * The spec path a catalogue route ends up at: version-prefixed unless the
     * declaration opts out, with routing constraints stripped.
     *
     * @param array<string, mixed> $route
     */
    private function specPath(array $route): string
    {
        $path = (string) $route['path'];
        if (($route['unversioned'] ?? false) !== true) {
            $pos = strpos($path, '/', 1);
            $path = $pos === false ? $path . '/v1' : substr($path, 0, $pos) . '/v1' . substr($path, $pos);
        }

        return (string) preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)[^{}]*\}#', '{$1}', $path);
    }

    /**
     * Follow a chain of keys, yielding [] the moment the path stops being an
     * array. The spec is `mixed` all the way down as far as PHPStan is
     * concerned, and threading is_array() guards through every reader would
     * bury what each assertion is actually about.
     *
     * @return array<array-key, mixed>
     */
    private static function dig(mixed $node, int|string ...$keys): array
    {
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return [];
            }
            $node = $node[$key];
        }

        return is_array($node) ? $node : [];
    }

    /**
     * The declared `required` list of a schema, as strings.
     *
     * @return list<string>
     */
    private static function requiredOf(mixed $schema): array
    {
        $required = [];
        foreach (self::dig($schema, 'required') as $field) {
            if (is_string($field)) {
                $required[] = $field;
            }
        }

        return $required;
    }

    /**
     * The JSON request-body schema of an operation, `$ref` followed, or null
     * when the operation declares no body at all.
     *
     * @param array<array-key, mixed> $spec
     * @param array<array-key, mixed> $operation
     * @return array<array-key, mixed>|null
     */
    private function resolveRequestSchema(array $spec, array $operation): ?array
    {
        $content = self::dig($operation, 'requestBody', 'content');
        if ($content === []) {
            return null;
        }

        $schema = self::dig($content, 'application/json', 'schema');
        if ($schema === []) {
            // A non-JSON body (the multipart plugin upload) is still documented.
            return ['properties' => [], 'required' => []];
        }

        return $this->deref($spec, $schema);
    }

    /**
     * @param array<array-key, mixed> $spec
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function deref(array $spec, array $schema): array
    {
        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref)) {
            return $schema;
        }

        return self::dig($spec, 'components', 'schemas', substr($ref, strlen('#/components/schemas/')));
    }

    /**
     * The item schema of a collection endpoint's `{data: [...]}` envelope.
     *
     * @param array<array-key, mixed> $spec
     * @return array<array-key, mixed>|null
     */
    private function rowSchemaOf(array $spec, string $path): ?array
    {
        $schema = self::dig(
            $spec,
            'paths',
            $path,
            'get',
            'responses',
            '200',
            'content',
            'application/json',
            'schema'
        );
        if ($schema === []) {
            return null;
        }

        $items = self::dig($this->deref($spec, $schema), 'properties', 'data', 'items');

        return $items === [] ? null : $this->deref($spec, $items);
    }

    private function isPluginPath(string $path): bool
    {
        foreach (['/api/v1/hello', '/api/v1/demo-catalog', '/api/v1/uikit'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param \Closure(array<string, mixed>, string): void $visit
     */
    private function walk(array $node, string $trail, \Closure $visit): void
    {
        $visit($node, $trail === '' ? '(root)' : $trail);

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->walk($value, $trail . '/' . $key, $visit);
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function countReferences(array $spec): int
    {
        $count = 0;
        $this->walk($spec['components']['schemas'], '', static function (array $node) use (&$count): void {
            if (isset($node['x-whity-reference'])) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function components(): array
    {
        return CoreApiSchemas::components();
    }
}
