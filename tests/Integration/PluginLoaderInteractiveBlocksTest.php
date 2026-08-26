<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * WC-234: when the loader validates a `screen:'blocks'` feature, the existing
 * data-bound `$walkNode` closure must ALSO validate each `form.submit.endpoint`
 * and `actionButton.action.endpoint` against the plugin's OWN registered
 * POST/PUT routes (ownership) and require the route's `requiredPermission` to
 * EQUAL the block's declared `requiredPermission` (permission match), then
 * version-rewrite the endpoint exactly as data-bound `source` is rewritten.
 *
 * Test 1 (served + versioned):
 *   A plugin that registers `POST /api/x/save` (perm `x:write`) and exposes a
 *   `screen:'blocks'` feature containing a `form` (submit POST /api/x/save,
 *   requiredPermission x:write) is served; `form.submit.endpoint` becomes
 *   `/api/v1/x/save`. Same for an `actionButton` in a separate feature.
 *
 * Test 2 (foreign endpoint → dropped, sibling served):
 *   A `form.submit.endpoint` of `/api/other/thing` (not a registered POST/PUT
 *   route) causes the feature to be ABSENT, no throw, sibling feature intact.
 *
 * Test 3 (permission mismatch → dropped):
 *   The endpoint IS a registered POST/PUT route but its route requiredPermission
 *   differs from the block's declared `requiredPermission` → feature dropped
 *   fail-closed.
 *
 * Test 4 (THE WRITE GATE — a record page that can save):
 *   The three props a record page uses to READ are all compared with route
 *   parameters NORMALIZED — `dataRecord.source` through
 *   `matchesRegisteredGetRoute`, an `accessGate.check` and an `inbox` action
 *   through `normalizeRouteKey`. The two props that WRITE were not, and the
 *   interactive-endpoint map they were compared against recorded POST and PUT
 *   only. So the pattern the SDK documents — a form submitting to
 *   `/api/x/things/{record}` — matched nothing and the whole feature was
 *   dropped at load, fail-closed and silently:
 *
 *     - a `PATCH` submit matched nothing because the verb was never recorded;
 *     - `PUT /api/x/things/{record}` matched nothing because the registered
 *       route spells its parameter `{id}` (or `{id:\d+}`), and the comparison
 *       was on the literal string.
 *
 *   A record page could therefore display a record, gate an editor on the
 *   caller's write permission, and never save. These tests are the shape of
 *   that gap: each fixture below declares the documented pattern and asserts
 *   the feature SURVIVES and its endpoint is version-rewritten, while the
 *   refusals that must keep working — a foreign path, a shape mismatch, a
 *   permission that does not line up — are asserted alongside so the fix is
 *   visibly a normalisation and not a widening.
 */
final class PluginLoaderInteractiveBlocksTest extends TestCase
{
    // ── fixtures ─────────────────────────────────────────────────────────────

    private static string $ownedEndpointDir;
    private static string $foreignEndpointDir;
    private static string $permMismatchDir;
    private static string $templatedWriteDir;

    public static function setUpBeforeClass(): void
    {
        self::$ownedEndpointDir  = sys_get_temp_dir() . '/whity_ibb_owned_'    . uniqid();
        self::$foreignEndpointDir = sys_get_temp_dir() . '/whity_ibb_foreign_'  . uniqid();
        self::$permMismatchDir   = sys_get_temp_dir() . '/whity_ibb_permmatch_' . uniqid();
        self::$templatedWriteDir = sys_get_temp_dir() . '/whity_ibb_templated_' . uniqid();

        // ── Plugin 1: registers POST /api/x/save (x:write)
        //    Feature 1a: form block with matching submit endpoint + permission
        //    Feature 1b: actionButton block with matching action endpoint + permission
        //    Feature 1c: valid sibling (custom screen) – always served
        self::writePlugin(self::$ownedEndpointDir, 'IbbOwned', <<<'PHP'
    public function getPermissions(): array { return ['x:write']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'POST',
            'path' => '/api/x/save',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'x:write',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // Feature 1a: form with owned POST endpoint
            [
                'id' => 'x-form',
                'label' => 'X Form',
                'screen' => 'blocks',
                'requiredPermission' => 'x:write',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/x/save'],
                    'requiredPermission' => 'x:write',
                    'children' => [
                        [
                            'type' => 'textInput',
                            'name' => 'title',
                            'label' => 'Title',
                        ],
                        [
                            'type' => 'submitButton',
                            'label' => 'Save',
                        ],
                    ],
                ]],
            ],
            // Feature 1b: standalone actionButton with owned POST endpoint
            [
                'id' => 'x-action',
                'label' => 'X Action',
                'screen' => 'blocks',
                'requiredPermission' => 'x:write',
                'blocks' => [[
                    'type' => 'actionButton',
                    'label' => 'Do It',
                    'action' => ['method' => 'POST', 'endpoint' => '/api/x/save'],
                    'requiredPermission' => 'x:write',
                ]],
            ],
            // Feature 1c: sibling static feature
            [
                'id' => 'x-static',
                'label' => 'X Static',
                'screen' => 'custom',
                'requiredPermission' => 'x:write',
            ],
        ];
    }
PHP);

        // ── Plugin 2: registers POST /api/y/own (y:write)
        //    Feature 2a: form with submit endpoint NOT registered → dropped
        //    Feature 2b: actionButton with action endpoint NOT registered → dropped
        //    Feature 2c: valid sibling → survives
        self::writePlugin(self::$foreignEndpointDir, 'IbbForeign', <<<'PHP'
    public function getPermissions(): array { return ['y:write']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'POST',
            'path' => '/api/y/own',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'y:write',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // INVALID: submit endpoint /api/other/thing is not a registered POST/PUT route
            [
                'id' => 'y-foreign-form',
                'label' => 'Y Foreign Form',
                'screen' => 'blocks',
                'requiredPermission' => 'y:write',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/other/thing'],
                    'requiredPermission' => 'y:write',
                    'children' => [
                        ['type' => 'textInput', 'name' => 'val', 'label' => 'Value'],
                        ['type' => 'submitButton', 'label' => 'Go'],
                    ],
                ]],
            ],
            // INVALID: action endpoint /api/other/action is not registered
            [
                'id' => 'y-foreign-action',
                'label' => 'Y Foreign Action',
                'screen' => 'blocks',
                'requiredPermission' => 'y:write',
                'blocks' => [[
                    'type' => 'actionButton',
                    'label' => 'Do It',
                    'action' => ['method' => 'POST', 'endpoint' => '/api/other/action'],
                    'requiredPermission' => 'y:write',
                ]],
            ],
            // VALID sibling: must survive
            [
                'id' => 'y-static',
                'label' => 'Y Static',
                'screen' => 'custom',
                'requiredPermission' => 'y:write',
            ],
        ];
    }
PHP);

        // ── Plugin 3: registers POST /api/z/save (z:write)
        //    Feature 3a: form declares requiredPermission='z:read' but route needs 'z:write' → dropped
        //    Feature 3b: actionButton declares requiredPermission='z:read' but route needs 'z:write' → dropped
        //    Feature 3c: valid sibling → survives
        self::writePlugin(self::$permMismatchDir, 'IbbPermMismatch', <<<'PHP'
    public function getPermissions(): array { return ['z:write', 'z:read']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'POST',
            'path' => '/api/z/save',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'z:write',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // INVALID: form declares z:read but route requires z:write → mismatch → dropped
            [
                'id' => 'z-form-mismatch',
                'label' => 'Z Form Mismatch',
                'screen' => 'blocks',
                'requiredPermission' => 'z:read',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/z/save'],
                    'requiredPermission' => 'z:read',
                    'children' => [
                        ['type' => 'textInput', 'name' => 'val', 'label' => 'Value'],
                        ['type' => 'submitButton', 'label' => 'Go'],
                    ],
                ]],
            ],
            // INVALID: actionButton declares z:read but route requires z:write → dropped
            [
                'id' => 'z-action-mismatch',
                'label' => 'Z Action Mismatch',
                'screen' => 'blocks',
                'requiredPermission' => 'z:read',
                'blocks' => [[
                    'type' => 'actionButton',
                    'label' => 'Do It',
                    'action' => ['method' => 'POST', 'endpoint' => '/api/z/save'],
                    'requiredPermission' => 'z:read',
                ]],
            ],
            // VALID sibling: must survive
            [
                'id' => 'z-static',
                'label' => 'Z Static',
                'screen' => 'custom',
                'requiredPermission' => 'z:read',
            ],
        ];
    }
PHP);

        // ── Plugin 4: the WRITE GATE. Registers the write routes a record page
        //    actually needs — each with a PATH PARAMETER, which is the whole
        //    point: a record page is about ONE record, so the route that saves
        //    it is templated by construction.
        //
        //      PUT    /api/r/items/{id}         (r:manage)  param name differs
        //      PATCH  /api/r/items/{id}/state   (r:manage)  verb was unrecorded
        //      PATCH  /api/r/codes/{id:\d+}     (r:manage)  inline constraint
        //      PUT    /api/r/locked/{id}        (r:manage)  for the perm pin
        //
        //    Every declaration below spells its parameter `{record}` — the
        //    host-seeded binding a record ROUTE supplies — exactly as the SDK
        //    documents. Domain-neutral throughout: `items`, `codes`, `state`.
        self::writePlugin(self::$templatedWriteDir, 'IbbTemplated', <<<'PHP'
    public function getPermissions(): array { return ['r:manage', 'r:read']; }
    public function getRoutes(): array
    {
        $ok = static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]);

        return [
            ['method' => 'PUT',   'path' => '/api/r/items/{id}',       'handler' => $ok,
             'requiredRole' => null, 'requiredPermission' => 'r:manage'],
            ['method' => 'PATCH', 'path' => '/api/r/items/{id}/state', 'handler' => $ok,
             'requiredRole' => null, 'requiredPermission' => 'r:manage'],
            ['method' => 'PATCH', 'path' => '/api/r/codes/{id:\d+}',   'handler' => $ok,
             'requiredRole' => null, 'requiredPermission' => 'r:manage'],
            ['method' => 'PUT',   'path' => '/api/r/locked/{id}',      'handler' => $ok,
             'requiredRole' => null, 'requiredPermission' => 'r:manage'],
            ['method' => 'DELETE','path' => '/api/r/items/{id}',       'handler' => $ok,
             'requiredRole' => null, 'requiredPermission' => 'r:manage'],
        ];
    }
    public function getFrontendFeatures(): array
    {
        $saveButton = ['type' => 'submitButton', 'label' => 'Save'];
        $field = ['type' => 'textInput', 'name' => 'title', 'label' => 'Title'];

        return [
            // The documented record-page pattern: PUT a templated record path,
            // parameter named differently from the registered route's.
            [
                'id' => 'r-put-templated',
                'label' => 'R Put Templated',
                'screen' => 'blocks',
                'requiredPermission' => 'r:manage',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PUT', 'endpoint' => '/api/r/items/{record}'],
                    'requiredPermission' => 'r:manage',
                    'children' => [$field, $saveButton],
                ]],
            ],
            // PATCH — the sync update verb the contract accepts and the map
            // never recorded.
            [
                'id' => 'r-patch-templated',
                'label' => 'R Patch Templated',
                'screen' => 'blocks',
                'requiredPermission' => 'r:manage',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PATCH', 'endpoint' => '/api/r/items/{record}/state'],
                    'requiredPermission' => 'r:manage',
                    'children' => [$field, $saveButton],
                ]],
            ],
            // An INLINE CONSTRAINT on the registered route. The declaration
            // cannot restate it (and should not have to): the constraint is the
            // route's business, and the block names the segment.
            [
                'id' => 'r-patch-constrained',
                'label' => 'R Patch Constrained',
                'screen' => 'blocks',
                'requiredPermission' => 'r:manage',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PATCH', 'endpoint' => '/api/r/codes/{record}'],
                    'requiredPermission' => 'r:manage',
                    'children' => [$field, $saveButton],
                ]],
            ],
            // The same, through an actionButton rather than a form.
            [
                'id' => 'r-action-templated',
                'label' => 'R Action Templated',
                'screen' => 'blocks',
                'requiredPermission' => 'r:manage',
                'blocks' => [[
                    'type' => 'actionButton',
                    'label' => 'Advance',
                    'action' => ['method' => 'PATCH', 'endpoint' => '/api/r/items/{record}/state'],
                    'requiredPermission' => 'r:manage',
                ]],
            ],
            // STILL REFUSED (1): the permission pin. The path normalises onto a
            // route this plugin owns, and the block's declared permission does
            // not equal that route's — so the feature must still drop. This is
            // the assertion that proves normalising the KEY did not lose the
            // VALUE the pin is read from.
            [
                'id' => 'r-perm-mismatch-templated',
                'label' => 'R Perm Mismatch Templated',
                'screen' => 'blocks',
                'requiredPermission' => 'r:read',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PUT', 'endpoint' => '/api/r/locked/{record}'],
                    'requiredPermission' => 'r:read',
                    'children' => [$field, $saveButton],
                ]],
            ],
            // STILL REFUSED (2): a templated endpoint under a path this plugin
            // never registered. Normalising parameter NAMES must not turn the
            // ownership gate into a shape wildcard.
            [
                'id' => 'r-foreign-templated',
                'label' => 'R Foreign Templated',
                'screen' => 'blocks',
                'requiredPermission' => 'r:manage',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PUT', 'endpoint' => '/api/elsewhere/{record}'],
                    'requiredPermission' => 'r:manage',
                    'children' => [$field, $saveButton],
                ]],
            ],
            // STILL REFUSED (3): the right prefix, the wrong SHAPE — one more
            // segment than any registered route has.
            [
                'id' => 'r-wrong-shape',
                'label' => 'R Wrong Shape',
                'screen' => 'blocks',
                'requiredPermission' => 'r:manage',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PUT', 'endpoint' => '/api/r/items/{record}/extra'],
                    'requiredPermission' => 'r:manage',
                    'children' => [$field, $saveButton],
                ]],
            ],
            // STILL REFUSED (4): the right path and shape, the wrong VERB. The
            // plugin registers no POST under /api/r/items/{id}.
            [
                'id' => 'r-wrong-verb',
                'label' => 'R Wrong Verb',
                'screen' => 'blocks',
                'requiredPermission' => 'r:manage',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/r/items/{record}'],
                    'requiredPermission' => 'r:manage',
                    'children' => [$field, $saveButton],
                ]],
            ],
            // Sibling: must survive every drop above.
            [
                'id' => 'r-static',
                'label' => 'R Static',
                'screen' => 'custom',
                'requiredPermission' => 'r:read',
            ],
        ];
    }
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([
            self::$ownedEndpointDir,
            self::$foreignEndpointDir,
            self::$permMismatchDir,
            self::$templatedWriteDir,
        ] as $dir) {
            self::removeDirectory($dir);
        }
    }

    // ── TEST 1: owned endpoint → served + versioned ───────────────────────

    /**
     * A plugin registering POST /api/x/save (perm x:write) and a form block
     * with submit:{method:POST, endpoint:/api/x/save, requiredPermission:x:write}
     * must be SERVED, with form.submit.endpoint rewritten to /api/v1/x/save.
     */
    public function testOwnedFormEndpointIsServedAndVersioned(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'x-form',
            $byId,
            'A form feature whose submit endpoint is a plugin-owned POST route must be served'
        );

        $feature = $byId['x-form'];
        $this->assertSame('blocks', $feature['screen']);
        $this->assertIsArray($feature['blocks']);
        $this->assertCount(1, $feature['blocks']);

        $formNode = $feature['blocks'][0];
        $this->assertSame('form', $formNode['type']);
        $this->assertIsArray($formNode['submit']);
        $this->assertSame(
            '/api/v1/x/save',
            $formNode['submit']['endpoint'],
            'The form.submit.endpoint must be rewritten to the versioned URL (/api/v1/x/save)'
        );
        $this->assertSame('POST', $formNode['submit']['method']);
    }

    /**
     * A plugin with an actionButton whose action.endpoint is an owned POST route
     * must be SERVED, with action.endpoint rewritten to /api/v1/x/save.
     */
    public function testOwnedActionButtonEndpointIsServedAndVersioned(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'x-action',
            $byId,
            'An actionButton feature whose action endpoint is a plugin-owned POST route must be served'
        );

        $feature = $byId['x-action'];
        $this->assertSame('blocks', $feature['screen']);
        $this->assertIsArray($feature['blocks']);
        $this->assertCount(1, $feature['blocks']);

        $btnNode = $feature['blocks'][0];
        $this->assertSame('actionButton', $btnNode['type']);
        $this->assertIsArray($btnNode['action']);
        $this->assertSame(
            '/api/v1/x/save',
            $btnNode['action']['endpoint'],
            'The actionButton.action.endpoint must be rewritten to the versioned URL (/api/v1/x/save)'
        );
        $this->assertSame('POST', $btnNode['action']['method']);
    }

    /**
     * The sibling static feature is unaffected by the interactive endpoint check.
     */
    public function testSiblingStaticFeatureIsNotAffectedByInteractiveBlockLogic(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('x-static', $byId, 'The sibling static feature must still be served');
        $this->assertSame('custom', $byId['x-static']['screen']);
    }

    /**
     * With an empty version prefix, endpoints must NOT be altered (no rewrite).
     */
    public function testEmptyVersionPrefixLeavesEndpointsUnchanged(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router(''));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey('x-form', $byId);
        $this->assertSame(
            '/api/x/save',
            $byId['x-form']['blocks'][0]['submit']['endpoint'],
            'With empty version prefix, submit.endpoint must remain as declared'
        );

        $this->assertArrayHasKey('x-action', $byId);
        $this->assertSame(
            '/api/x/save',
            $byId['x-action']['blocks'][0]['action']['endpoint'],
            'With empty version prefix, action.endpoint must remain as declared'
        );
    }

    // ── TEST 2: foreign endpoint → dropped fail-closed ───────────────────

    /**
     * A form whose submit.endpoint is NOT a registered POST/PUT route is
     * DROPPED fail-closed. No exception thrown.
     */
    public function testForeignFormEndpointDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'y-foreign-form',
            $ids,
            'A form feature with a foreign submit endpoint must be DROPPED (fail-closed)'
        );
    }

    /**
     * An actionButton whose action.endpoint is NOT a registered POST/PUT route
     * is DROPPED fail-closed. No exception thrown.
     */
    public function testForeignActionButtonEndpointDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'y-foreign-action',
            $ids,
            'An actionButton feature with a foreign action endpoint must be DROPPED (fail-closed)'
        );
    }

    /**
     * When foreign-endpoint features are dropped, the sibling static feature
     * (y-static) is still served — the drop is per-feature, not per-plugin.
     */
    public function testForeignEndpointDropDoesNotKillSiblingFeatures(): void
    {
        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey(
            'y-static',
            $byId,
            'A valid sibling feature must survive even when the interactive endpoint feature is dropped'
        );
    }

    /**
     * No exception is thrown when a foreign-endpoint feature is encountered.
     */
    public function testForeignEndpointNeverThrows(): void
    {
        $this->expectNotToPerformAssertions();

        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));
        $loader->getFrontendFeatures();
    }

    // ── TEST 3: permission mismatch → dropped ────────────────────────────

    /**
     * A form whose block-level requiredPermission does NOT match the registered
     * route's requiredPermission is DROPPED fail-closed.
     */
    public function testPermissionMismatchOnFormDropsTheFeature(): void
    {
        [$loader] = $this->loadDir(self::$permMismatchDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'z-form-mismatch',
            $ids,
            'A form feature where block requiredPermission != route requiredPermission must be DROPPED'
        );
    }

    /**
     * An actionButton whose block-level requiredPermission does NOT match the
     * registered route's requiredPermission is DROPPED fail-closed.
     */
    public function testPermissionMismatchOnActionButtonDropsTheFeature(): void
    {
        [$loader] = $this->loadDir(self::$permMismatchDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'z-action-mismatch',
            $ids,
            'An actionButton feature where block requiredPermission != route requiredPermission must be DROPPED'
        );
    }

    /**
     * When permission-mismatch features are dropped, the sibling static feature
     * (z-static) is still served.
     */
    public function testPermissionMismatchDropDoesNotKillSiblingFeatures(): void
    {
        [$loader] = $this->loadDir(self::$permMismatchDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey(
            'z-static',
            $byId,
            'A valid sibling feature must survive even when the permission-mismatch feature is dropped'
        );
    }

    // ── TEST 4: the write gate ───────────────────────────────────────────

    /**
     * The documented record-page pattern: a form PUTting a templated record
     * path whose parameter the plugin's own route spells differently.
     *
     * `PUT /api/r/items/{record}` against a registered `PUT /api/r/items/{id}`.
     * The renderer substitutes a concrete id there, the dispatcher matches the
     * route's own pattern, and the parameter's NAME is a word neither of them
     * reads. This is the same comparison `dataRecord.source` has always made
     * for the READ half.
     */
    public function testATemplatedPutSubmitIsOwnedWhenTheRouteSpellsItsParameterDifferently(): void
    {
        [$loader] = $this->loadDir(self::$templatedWriteDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'r-put-templated',
            $byId,
            "A form submitting PUT /api/r/items/{record} must be served: the plugin registers "
            . 'PUT /api/r/items/{id}, and the parameter name is not part of the route'
        );
        $this->assertSame(
            '/api/v1/r/items/{record}',
            $byId['r-put-templated']['blocks'][0]['submit']['endpoint'],
            'and its endpoint must be version-rewritten like every other owned endpoint'
        );
    }

    /**
     * PATCH — the sync update verb `BlockValidator::validateSubmitSpec()`
     * accepts and the interactive-endpoint map never recorded, so a PATCH
     * submit matched nothing at all regardless of its path.
     */
    public function testATemplatedPatchSubmitIsOwned(): void
    {
        [$loader] = $this->loadDir(self::$templatedWriteDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'r-patch-templated',
            $byId,
            'A form submitting PATCH to a registered PATCH route must be served — the verb has to be '
            . 'recorded in the ownership map, or every PATCH submit is refused for not existing'
        );
        $this->assertSame(
            '/api/v1/r/items/{record}/state',
            $byId['r-patch-templated']['blocks'][0]['submit']['endpoint']
        );
    }

    /**
     * A registered route carrying an INLINE CONSTRAINT (`{id:\d+}`, WC-160).
     *
     * The block cannot restate the constraint — it is the route's own business
     * — so a literal comparison could never match one, which is the second half
     * of why the literal comparison had to go.
     */
    public function testATemplatedSubmitIsOwnedWhenTheRouteCarriesAnInlineConstraint(): void
    {
        [$loader] = $this->loadDir(self::$templatedWriteDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'r-patch-constrained',
            $byId,
            'A form submitting to a route registered as /api/r/codes/{id:\\d+} must be served — the '
            . 'constraint belongs to the route, and no declaration can or should repeat it'
        );
        $this->assertSame(
            '/api/v1/r/codes/{record}',
            $byId['r-patch-constrained']['blocks'][0]['submit']['endpoint']
        );
    }

    /** The same rule through `actionButton.action` rather than `form.submit`. */
    public function testATemplatedActionButtonEndpointIsOwned(): void
    {
        [$loader] = $this->loadDir(self::$templatedWriteDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey('r-action-templated', $byId);
        $this->assertSame(
            '/api/v1/r/items/{record}/state',
            $byId['r-action-templated']['blocks'][0]['action']['endpoint']
        );
    }

    /**
     * The permission pin still fires on a normalised key.
     *
     * The path normalises onto a route this plugin owns and the block's declared
     * permission does not equal that route's, so the feature must still drop.
     * This is the assertion that proves normalising the KEY did not lose the
     * VALUE the pin reads.
     */
    public function testATemplatedSubmitIsStillRefusedWhenThePermissionDoesNotMatchTheRoute(): void
    {
        [$loader] = $this->loadDir(self::$templatedWriteDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'r-perm-mismatch-templated',
            $ids,
            'A templated submit whose block permission differs from the route it normalises onto must '
            . 'still be DROPPED — the pin is the point of the check, not a side effect of the key'
        );
    }

    /**
     * Normalising parameter NAMES must not turn the ownership gate into a shape
     * wildcard. Three refusals that have to keep working: a foreign path, one
     * segment too many, and the wrong verb on the right path.
     *
     * @param string $featureId The feature that must be absent.
     * @param string $why       What it would mean if it were served.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stillRefusedFeatures')]
    public function testNormalisingParameterNamesDoesNotWidenTheOwnershipGate(
        string $featureId,
        string $why
    ): void {
        [$loader] = $this->loadDir(self::$templatedWriteDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains($featureId, $ids, $why);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stillRefusedFeatures(): array
    {
        return [
            'foreign path' => [
                'r-foreign-templated',
                'A templated endpoint under a path this plugin never registered must be DROPPED',
            ],
            'wrong shape' => [
                'r-wrong-shape',
                'One segment more than any registered route has is a different route, not a different '
                . 'spelling, and must be DROPPED',
            ],
            'wrong verb' => [
                'r-wrong-verb',
                'The right path with a verb this plugin registered no handler for must be DROPPED',
            ],
        ];
    }

    /** Every drop above is per-feature: the sibling is still served. */
    public function testTemplatedWriteDropsDoNotKillSiblingFeatures(): void
    {
        [$loader] = $this->loadDir(self::$templatedWriteDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('r-static', $byId);
    }

    /**
     * DELETE is recorded in the write-route map and is NOT reachable through
     * these two props — both facts stated together, because each is misleading
     * alone.
     *
     * `BlockValidator::validateSubmitSpec()` accepts POST, PUT and PATCH only,
     * so no `form.submit` or `actionButton.action` can ever name a DELETE. The
     * map records DELETE anyway because it is the ownership basis for `inbox`
     * actions and `accessGate` checks too, and a map that is the write routes
     * for two consumers and a subset of them for a third is exactly the split
     * that produced this bug. Asserted so nobody "fixes" the map by narrowing
     * it back, and nobody assumes a DELETE submit is now expressible.
     */
    public function testDeleteIsNotExpressibleAsASubmitVerb(): void
    {
        $result = \Whity\Sdk\Frontend\Blocks\BlockValidator::validate([[
            'type' => 'form',
            'submit' => ['method' => 'DELETE', 'endpoint' => '/api/r/items/{record}'],
            'children' => [['type' => 'submitButton', 'label' => 'Delete']],
        ]]);

        $this->assertFalse(
            $result['ok'],
            "A DELETE 'form.submit.method' is refused by the contract, so widening the loader's write-route "
            . 'map to DELETE cannot make one expressible'
        );
        $this->assertStringContainsString("must be 'POST', 'PUT', or 'PATCH'", implode('; ', $result['errors']));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: PluginLoader, 1: Router}
     */
    private function loadDir(string $dir, ?Router $router = null): array
    {
        $router ??= new Router('');
        $loader = new PluginLoader($dir, $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        return [$loader, $router];
    }

    private static function writePlugin(string $baseDir, string $name, string $body): void
    {
        mkdir($baseDir . '/' . $name, 0755, true);

        file_put_contents($baseDir . '/' . $name . '/Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\Http\\Response;
use Whity\\Sdk\\PluginFrontendInterface;
use Whity\\Sdk\\PluginInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.8'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
{$body}
}
PHP);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
