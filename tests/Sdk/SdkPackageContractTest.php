<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * WC-162: the standalone, semver'd plugin SDK contract package.
 *
 * Whity\Sdk is the keystone for cross-app feature sharing: plugins implement
 * the SDK contract (not Whity\Core types), the SDK carries its own
 * composer.json + autoload root with an independent 1.0.0 version, and it must
 * never depend on whity-core — that is what makes a plugin distributable to
 * any downstream Whity-based application without dragging the host framework
 * along.
 */
final class SdkPackageContractTest extends TestCase
{
    private const SDK_DIR = __DIR__ . '/../../sdk';

    // ==================== package + version ====================

    public function testSdkHasItsOwnComposerJsonWithIndependentVersion(): void
    {
        $composerPath = self::SDK_DIR . '/composer.json';
        $this->assertFileExists($composerPath, 'The SDK must carry its own composer.json');

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $this->assertIsArray($composer);

        $this->assertSame('whity/plugin-sdk', $composer['name'] ?? null);
        $this->assertMatchesRegularExpression(
            '/^1\.\d+\.\d+$/',
            (string) ($composer['version'] ?? ''),
            'The SDK carries an independent 1.x semver (additive policy: minors add capabilities)'
        );
        $this->assertSame(
            \Whity\Sdk\Sdk::VERSION,
            $composer['version'] ?? null,
            'composer.json and Sdk::VERSION must agree'
        );
        $this->assertArrayHasKey('autoload', $composer);
        $this->assertSame(
            'src/',
            $composer['autoload']['psr-4']['Whity\\Sdk\\'] ?? null,
            'The SDK owns the Whity\Sdk autoload root'
        );
    }

    public function testSdkRequiresOnlyPhp(): void
    {
        $composer = json_decode((string) file_get_contents(self::SDK_DIR . '/composer.json'), true);
        $this->assertIsArray($composer);

        $this->assertSame(
            ['php'],
            array_keys($composer['require'] ?? []),
            'The SDK must require ONLY php — not whity-core, not any library'
        );
    }

    /**
     * Standalone proof at the source level: no SDK file may reference a
     * Whity\Core / Whity\Database / Whity\Http / Whity\Auth symbol.
     */
    public function testSdkSourcesReferenceNoCoreNamespaces(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SDK_DIR . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $checked++;
            $source = (string) file_get_contents($file->getPathname());
            foreach (['Whity\\Core', 'Whity\\Database', 'Whity\\Http\\', 'Whity\\Auth', 'Whity\\Api'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$file->getPathname()} must not reference {$forbidden} — the SDK is standalone"
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'The SDK source tree must contain PHP files');
    }

    public function testCoreComposerRequiresTheSdkViaPathRepository(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertIsArray($composer);

        $this->assertArrayHasKey(
            'whity/plugin-sdk',
            $composer['require'] ?? [],
            'whity-core must depend on the SDK package'
        );

        $paths = array_column($composer['repositories'] ?? [], 'url');
        $this->assertContains('sdk', $paths, 'The SDK is consumed through a composer path repository');
    }

    // ==================== contract types ====================

    public function testPluginInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginInterface::class))->getMethods()
        );
        sort($methods);
        $this->assertSame(
            ['getHooks', 'getMigrations', 'getName', 'getPermissions', 'getRoutes', 'getVersion'],
            $methods
        );
    }

    public function testDeprecatedCorePluginInterfaceAliasIsRemoved(): void
    {
        $this->assertFalse(
            interface_exists(\Whity\Core\PluginInterface::class),
            'The deprecated Whity\Core\PluginInterface alias was removed in WC-215; '
            . 'implement \Whity\Sdk\PluginInterface directly.'
        );
    }

    public function testMigrationInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\MigrationInterface::class));

        $up = new \ReflectionMethod(\Whity\Sdk\MigrationInterface::class, 'up');
        $down = new \ReflectionMethod(\Whity\Sdk\MigrationInterface::class, 'down');
        $this->assertSame('PDO', (string) $up->getParameters()[0]->getType());
        $this->assertSame('PDO', (string) $down->getParameters()[0]->getType());
    }

    /**
     * SDK 1.2 (WC-169): the OPTIONAL frontend feature descriptor capability.
     * Mirrors PluginRequirementsInterface — a sibling interface a plugin MAY
     * implement, keeping PluginInterface itself backend-only.
     */
    public function testPluginFrontendInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginFrontendInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginFrontendInterface::class))->getMethods()
        );
        $this->assertSame(['getFrontendFeatures'], $methods);

        $return = (new \ReflectionMethod(\Whity\Sdk\PluginFrontendInterface::class, 'getFrontendFeatures'))
            ->getReturnType();
        $this->assertSame('array', (string) $return);
    }

    /**
     * SDK 1.28: the OPTIONAL async-job contribution point. Same sibling shape as
     * PluginRolesInterface — a declaration map plus a second method keyed by the
     * same names carrying the extra policy (here: which of them the public
     * submission API may enqueue).
     */
    public function testPluginJobsInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginJobsInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginJobsInterface::class))->getMethods()
        );
        sort($methods);
        $this->assertSame(['getJobs', 'getSubmittableJobs'], $methods);

        foreach (['getJobs', 'getSubmittableJobs'] as $method) {
            $return = (new \ReflectionMethod(\Whity\Sdk\PluginJobsInterface::class, $method))->getReturnType();
            $this->assertSame('array', (string) $return);
        }
    }

    /**
     * SDK 1.29: the OPTIONAL audited-event contribution point. One declaration
     * method, like PluginFrontendInterface — the policy that would have gone in
     * a second method (which audit action to record) is the declaration KEY, so
     * an event and the action it is filed under can never disagree.
     */
    public function testPluginEventsInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginEventsInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginEventsInterface::class))->getMethods()
        );
        $this->assertSame(['getAuditedEvents'], $methods);

        $return = (new \ReflectionMethod(\Whity\Sdk\PluginEventsInterface::class, 'getAuditedEvents'))
            ->getReturnType();
        $this->assertSame('array', (string) $return);
    }

    /**
     * SDK 1.29 publishes the namespacing rule the host has always applied, so a
     * plugin can spell a namespaced name for itself. Nothing else is allowed to
     * derive it independently: two implementations of "who is this?" is how the
     * name a plugin dispatches and the name the host listens for drift apart,
     * and a dispatch nobody listens to reports nothing at all.
     */
    public function testTheHostsSlugRuleIsTheSdksSlugRule(): void
    {
        foreach (['Acme', 'Acme\\Widgets\\Plugin', 'Hello-World', '_odd_', '', '!!!', '9lives'] as $name) {
            $this->assertSame(
                \Whity\Sdk\PluginNamespace::slug($name),
                \Whity\Core\Support\SourceSlug::from($name),
                "core and the SDK must agree on the slug for '{$name}'"
            );
        }
    }

    /**
     * A plugin name yielding no slug must not degrade into an UNPREFIXED name:
     * unprefixed is exactly the shape of a core event, so the fallback would
     * hand a plugin the ability to dispatch `user.deleted` by naming itself
     * badly.
     */
    public function testQualifyingUnderAnUnusableNameThrowsRatherThanReturningABareName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \Whity\Sdk\Hooks\Events::forPlugin('!!!', 'user.deleted');
    }

    public function testSdkVersionIsOneEightForInteractiveBlocks(): void
    {
        $this->assertSame(
            '1.40.0',
            \Whity\Sdk\Sdk::VERSION,
            'SDK 1.40 BRINGS THE FORM PRELOAD INTO THE CONTRACT: form.dataSource, the '
            . '{method: GET, path} spec the renderer has honoured since #949 and which the '
            . 'contract never declared. An undeclared prop is neither validated nor stripped — '
            . 'BlockValidator::validateProps() walks the DECLARED rules rather than the node keys, '
            . 'and PluginLoader returns the node it was handed — so this path reached the client '
            . 'exactly as written and was never checked against the routes the plugin registered. '
            . 'It was the only endpoint a block can name that was not: submit, every source, '
            . 'inbox.actions and every rowActionList were all ownership-checked. A tree that '
            . 'validated under 1.39 can therefore be REFUSED under 1.40, but only if its form '
            . 'preloaded a route the plugin does not own or wrote a dataSource of the wrong shape; '
            . 'a declaration naming its own GET is unaffected and now gets the version rewrite '
            . 'every other endpoint already got. '
            . 'SDK 1.39 ADDS TIME-WINDOW TYPES: PluginWindowTypesInterface, the contribution point '
            . 'for the KINDS of named period a deployment slices time into. #1070 puts a named, '
            . 'non-overlapping period that records can be scoped to and rolled up by — and that can '
            . 'be CLOSED, the way a set of books is closed — into core, because it is a primitive '
            . 'the platform did not have: everybody who needed one either built their own or did '
            . 'without, and two implementations of a period disagree the moment both exist with '
            . 'nothing reporting that they differ. THE VOCABULARY IS THE PART THAT CANNOT BE '
            . 'CORE\'S — two deployments slice time into words with nothing in common, so a core '
            . 'enumeration would have to carry both and ship each deployment the other\'s. A plugin '
            . 'declares KEYS and the defaults a tenant starts from; an administrator ADOPTS one, '
            . 'and declaring is a catalogue entry rather than a write into anybody\'s tenant. BARE '
            . 'SLUGS ONLY, as with OU types, so no plugin can mint a bare key and squat on a name a '
            . 'tenant might want. NESTING IS DECLARABLE, BOUNDARIES ARE NOT: a sub-period sitting '
            . 'inside a period is structural and knowable, whereas when a period starts and how '
            . 'long it runs is not, and assuming it is calendar-aligned is the specific error this '
            . 'concept exists to avoid. A malformed declaration costs that plugin its whole window '
            . 'vocabulary rather than one type, which is the one place this differs from the '
            . 'OU-type catalogue: the declarations are interdependent through nesting, so a partial '
            . 'store would leave parents pointing at nothing. Additive; every tree that validated '
            . 'under 1.38 still validates. '
            . 'SDK 1.38 GENERALISES THE RULE VOCABULARY OUT OF ROUTING: AudienceRuleContext and '
            . 'AudienceRuleResolverInterface. #999 adds NAMED USER GROUPS, and a group is not a '
            . 'membership table — it is one of these same rule expressions given a name and stored '
            . 'once, so that "the instructors" is ONE node referenced from many places rather than a '
            . 'thousand nodes for a thousand instructors. A stored list goes stale the first time '
            . 'somebody is hired: it omits them, still renders, and still reports success. A rule '
            . 'includes them because they exist. THE GENERALISATION IS A SUBTYPE, NOT A WIDENING — '
            . 'RoutingRuleContext now extends AudienceRuleContext, so every property a 1.37 resolver '
            . 'reads is still present with the same type (nothing became nullable) and every call '
            . 'site still compiles. A resolver that never touches the document, the route or the '
            . 'step declares resolve(AudienceRuleContext), which satisfies BOTH interfaces from one '
            . 'body, and its kind then becomes usable as a group definition. The two alternatives '
            . 'were rejected for one reason: making the routing fields nullable changes the type '
            . 'under plugin code already shipped, and passing zeros in them is a lie the type system '
            . 'would endorse. ONE REGISTRY STILL — PluginRoutingRulesInterface is unchanged and '
            . 'remains the only way to contribute a kind, because a rule that can name a set of '
            . 'people can name the recipients of a step. Additive; every tree that validated under '
            . '1.37 still validates. '
            . 'SDK 1.37 adds DOCUMENT ROUTING RULES: PluginRoutingRulesInterface joins the optional '
            . 'capability interfaces, with RoutingRuleResolverInterface, RoutingRuleContext and '
            . 'ResolvedRecipient as its contract. #947 item 3 puts a document routing engine in core, '
            . 'and the load-bearing decision is that a route STEP NAMES A RULE, NEVER A PERSON: the '
            . 'rule is resolved at send time against the organisation as it stands then, so a unit '
            . 'created last week is included because it exists rather than because somebody '
            . 'remembered to add it. A stored recipient list omits it, the document still renders, '
            . 'every step still completes and the run reports SUCCESS. Core owns `role` and '
            . '`role_below_actor`, both generic in the strong sense (every deployment has roles, '
            . 'every deployment has a unit tree); anything narrower is what one organisation happens '
            . 'to mean by "the next people" and arrives through the interface instead. A RESOLVER '
            . 'RETURNS SUGGESTIONS AND WRITES NOTHING — the host filters every profile against the '
            . 'ACTIVE MEMBERSHIPS of the tenant owning the route before an inbox row exists, so a '
            . 'resolver cannot reach another tenant and that check is not a rule every plugin author '
            . 'has to remember. BARE SLUGS ONLY: declare `committee`, get `acme:committee`, and no '
            . 'plugin can mint a bare kind — so a step already stored naming `role` cannot be '
            . 're-pointed by installing a plugin. Additive; every tree that validated under 1.36 '
            . 'still validates. '
            . 'SDK 1.36 adds the DOCUMENT VIEWER `documentViewer`, which declares no source and no '
            . 'new prop-rule kind. #947 item 1 gave core an issued document — a record with an '
            . 'identity and one immutable artifact per render — and nothing in the contract could '
            . 'show one. COMPOSING WAS NOT AVAILABLE, and not for want of pieces: every source and '
            . 'recordPath here is ownership-checked against the routes the DECLARING plugin '
            . 'registered, so the core route /api/v1/documents/{id} cannot be named by any plugin, and the '
            . 'only composition that would work is a plugin republishing the core document reads '
            . 'through a route of its own, gated however that plugin chose — the trade '
            . 'ouScopePicker already refused for the OU tree, worth more here because the thing '
            . 'being republished is an audit record. So the host fetches the core endpoints under '
            . 'the caller session and the documents:read gate they already carry, and there is no '
            . 'prop with which to point the viewer anywhere else. WHICH DOCUMENT is a contextPath '
            . '(documentIdFrom) with deliberately NO literal twin: the heading/text/badge/stat rule '
            . 'keeps a literal because an unresolved binding should still render A title, a duller '
            . 'version of the right answer, and inverted here the fallback is not duller but A '
            . 'DIFFERENT DOCUMENT rendered with full confidence. WHICH ARTIFACT is the question a '
            . 're-render creates: artifactIdFrom PINS one, which is what an append-only trail needs '
            . 'so an event saying "this is what circulated on the 4th" shows what circulated on the '
            . '4th; absent, the viewer shows the CURRENT artifact and SAYS so with the count of the '
            . 'others, because showing the newest silently lets a corrected document read as though '
            . 'it had always said that. The renderer never falls back between the two — a pinned '
            . 'artifact that is not on the record is an explicit failure state, since substituting '
            . 'the current one answers a question about the past with a fact about the present. '
            . 'Not offered, deliberately: a prop to hide the version history, a prop to suppress the '
            . 'download, and any height or zoom prop; '
            . 'SDK 1.35 adds the GRAPH BLOCK `flow`, with a declared node ceiling '
            . '(BlockContract::FLOW_MAX_NODES) and no new prop-rule kind. The whitelist covered '
            . 'tables, lists, timelines, inboxes, charts, forms, overlays and master-detail, and not '
            . 'one of its types could render a set of nodes and the edges between them — so anything '
            . 'modelling an ordered or branching process could be EDITED natively (form, fieldArray) '
            . 'and LISTED natively (table) while the DIAGRAM, which for that class of feature is '
            . 'usually what makes it legible to a non-technical reader, was the one part every '
            . 'product shipped as bespoke React. It is not a new renderer: core already runs '
            . '@xyflow/react behind the OU hierarchy view and the relations family graph, and both '
            . 'are the same composition the block reuses. ONE FETCH, AND ROWS THAT ARE NODES: source '
            . 'is an ordinary ownership-checked apiPath returning a collection exactly as '
            . 'dataTable\'s is, and a row IS a node. Edges are references off the node rows to OTHER '
            . 'nodes\' ids — edgeFromField a predecessor pointer, edgeToField a successor pointer, '
            . 'either optionally holding a LIST so a step can branch — and with neither declared the '
            . 'nodes are a linear sequence in payload order, so an ordered process models no edges at '
            . 'all. A second source for edges is deliberately not offered: it doubles the ownership '
            . 'checks and the loading states and moves a join the plugin already made into the '
            . 'renderer. A reference to an id no row declared is dropped rather than materialised, '
            . 'since a box the plugin never described, labelled with a raw id, is not information. '
            . 'READ-ONLY BY CONSTRUCTION like timeline — no endpoint, no verb, nothing to submit — '
            . 'with editing done by a form opened FROM a node through nodeActions, which is the SAME '
            . 'rowActionList kind dataTable.rowActions uses, resolved by the same validator and '
            . 'rendered by the same code path, because a node\'s actions are a row\'s actions and a '
            . 'second spelling of one shape is two shapes that drift; clicking a node runs its first '
            . 'open action, since a diagram whose only affordance is a dropdown is one nobody clicks. '
            . 'THE NODE CEILING IS THE POINT OF DECLARING IT: #192 is open against the two existing '
            . 'graph screens because a canvas of several hundred boxes, most with no edges, has '
            . 'stopped conveying anything, and any block on the same renderer inherits that the '
            . 'moment a tenant\'s data grows — so the limit is in the contract and the behaviour '
            . 'above it is defined (draw the first maxNodes in payload order with the edges among '
            . 'them, and SAY on screen that the graph was truncated, because a partial graph that '
            . 'looks complete is worse than a tangle). maxNodes may LOWER the ceiling and not raise '
            . 'it: a plugin knows its labels are long or its target is a phone, but it cannot know '
            . 'that 400 nodes are legible on every platform the tree may render on; '
            . 'SDK 1.34 makes `visibleWhen` CONTEXT-AWARE and adds the `accessGate` container, with '
            . 'the accessCheck prop-rule kind and the contract\'s first second child slot, '
            . 'otherwise. #883 made a record page describable and found the half it could not '
            . 'describe: RecordPageShell takes main: {editor, readOnly} — two renderings, and it '
            . 'picks — while visibleWhen matched a sibling FORM FIELD and nothing else, so a '
            . 'described record page rendered its editor unconditionally and a caller who may not '
            . 'write got a disabled Save button beside fully editable inputs, which is precisely '
            . 'the greyed-out form #882 exists to make unshippable. visibleWhen now names one of '
            . 'THREE subjects — field (a sibling input, unchanged), from (a master-detail '
            . 'reference: the record the page is about) or access (an accessGate id) — and is '
            . 'declared ONCE as a universal facet rather than type by type, because a facet only '
            . 'some blocks may carry is one that granular per-section gating has to route around. '
            . 'accessGate declares one question about the CALLER, publishes the host\'s answer '
            . 'under its id, and renders children when the answer is yes and otherwise when it is '
            . 'no. WHO ANSWERS IT is the design: check names a concrete {method, endpoint} request '
            . 'and the host resolves it through POST /api/v1/me/permitted-actions, the same route '
            . 'lookup feeding the same RoleChecker calls RbacMiddleware makes — so a plugin never '
            . 'states which permission gates a region, exactly as an inbox action never states the '
            . 'permission of the endpoint it calls, and there is no second copy of that answer to '
            . 'drift when someone re-gates the route. A permission SLUG is deliberately not '
            . 'offered: it is a restatement whose disagreement with the route nothing detects. TWO '
            . 'SLOTS RATHER THAN TWO NEGATED CONDITIONS, because conditions meant to be each '
            . 'other\'s negation drift and it is the editable half that ends up showing; declared '
            . 'as one node they cannot disagree. hidden/read-only/editable composes as two NESTED '
            . 'gates — the outer on the read request with no otherwise, the inner on the write '
            . 'request with both — which also yields #897\'s "first refusal wins" structurally. '
            . 'READING ACCESS DOES NOT RE-OPEN #895: #908 established that FACT bindings are '
            . 'guarded and CONTROL bindings are not, and a gate\'s answer is published into a '
            . 'namespace of its own that resolveContextRef — the single resolver behind textFrom/'
            . 'valueFrom/labelFrom/hintFrom and behind defaultFrom/params.from/source tokens — '
            . 'does not read, so a page can ACT on what the caller may do and still cannot SAY it '
            . 'about the record. GET joined the methods permitted-actions accepts, since "may I '
            . 'see this at all?" is a read and is the only authority the hidden state can ask; the '
            . 'endpoint\'s identity is method-agnostic. Child slots are now DECLARED by the '
            . 'contract rather than assumed, because a walker that hard-codes children silently '
            . 'skips a slot, and for the host loader that is a source that never got '
            . 'ownership-checked; '
            . 'SDK 1.33 adds the RECORD BLOCKS `dataRecord` and `recordFields` (with the recordPath '
            . 'and recordFactList prop-rule kinds) plus the optional textFrom/valueFrom/labelFrom/'
            . 'hintFrom twins on heading/text/badge/stat. Every data-bound leaf in the block '
            . 'contract assumed a COLLECTION at source, so a record page could not be DESCRIBED at '
            . 'all, only hand-written in React: keyValue took literals baked into the declaration '
            . 'and dataStat yielded one scalar per block, so a twelve-field record header was '
            . 'twelve blocks and twelve fetches. dataRecord fetches ONE resource, publishes it '
            . 'into the master-detail context under its id, and owns the loading and failure '
            . 'states for its whole subtree — a CONTAINER rather than a leaf so it can be all '
            . 'three at once. Publishing through the EXISTING context is what collapses the '
            . 'page-level-record gap into the record-bound-block gap: defaultFrom and params.from '
            . 'already address a row as {targetId}.{field}, and a published record is addressed the '
            . 'same way by the same resolver, so no second mechanism was invented. source is a '
            . 'recordPath — an owned apiPath that may carry {token} segments in that same '
            . 'addressing — which is how the block says WHICH record: from a selector, from the row '
            . 'an overlay was opened with, or from {record}, the one reserved name a host seeds '
            . 'with the record its ROUTE is about and which a selector may therefore not claim. The '
            . 'block does not fetch until every token resolves, since a half-substituted path '
            . 'requests a different resource than the one meant and would render it as the record. '
            . 'fields IS THE #895 GUARD and is the reason for this shape rather than a simpler one: '
            . 'the roles record page derived "is this a global role" from manageable, the server\'s '
            . 'answer to "may YOU write this?", which is true of every role for a tenant-0 caller — '
            . 'so the system tenant, the one caller whose edit reaches every tenant, saw a '
            . 'deployment-wide role labelled "Your tenant\'s role". #897 made that unwriteable in '
            . 'TypeScript; a block tree is runtime data rather than a type, so the same split is '
            . 'enforced twice. STRUCTURALLY: a dataRecord publishes ONLY the fields its declaration '
            . 'names, so a payload\'s manageable, canEdit or mayModify is unreachable from the tree '
            . 'because it was never published, whatever it is called. BY NAME: declaring one of '
            . '#897\'s eleven caller-decision words as a fact, or binding one through a ...From '
            . 'twin, is REFUSED and drops the feature, matched on a case- and separator-folded form '
            . 'so canEdit, can_edit and is_editable are one name. defaultFrom and params.from are '
            . 'deliberately NOT guarded — seeding a control the server re-validates, or narrowing a '
            . 'fetch, is plumbing rather than a statement, and #897 draws the line in the same '
            . 'place; a guard that refuses correct programs gets removed. recordFields is the '
            . 'data-bound keyValue and carries no labels of its own, because a record page shows '
            . 'the same field in more than one place and a label restated per placement drifts per '
            . 'placement; '
            . 'SDK 1.32 adds the organizational-unit SCOPE PICKER block type '
            . '(`ouScopePicker`, with the ouScopeList and ouTypeKey prop-rule kinds): a form '
            . 'input whose value is a RULE over the OU tree — {unit, scope, type} — rather than '
            . 'a pinned list of unit ids, which goes stale the first time a unit is reparented, '
            . 'silently, and is the parallel unit-id map #822 exists to delete. `scope` is one of '
            . 'unit/subtree/children and is ALWAYS written: "this unit" and "this unit and its '
            . 'subtree" are different answers and nothing else in the object tells them apart, so the '
            . 'discriminator is never inferred. The type declares NO source, and that is '
            . 'structural rather than a convenience — every source in the block contract is '
            . 'ownership-checked by the loader against the routes the declaring plugin '
            . 'registered, so a plugin cannot name the OU endpoint core owns at all, and the only way '
            . 'to satisfy that gate would be to republish the hierarchy through a plugin route, '
            . 'which is the drift being prevented. The host renderer reads the units and the '
            . 'vocabulary from core under the ous:read gate the caller already holds, so a caller '
            . 'who may not read the org chart cannot build a rule over it. anchorType and the type '
            . 'carried in the value are deliberately separate questions — which unit may ANCHOR the rule versus '
            . 'which units the resolved SET keeps — because "every department under a faculty" '
            . 'needs both; and a memberType declared beside a scopes list of exactly [unit] is '
            . 'refused, since a kind filter over the single unit the user just picked can only '
            . 'ever remove it; '
            . 'SDK 1.31 adds the workflow block types `timeline` and `inbox`, plus the '
            . 'itemActionList prop-rule kind behind inbox.actions. timeline is the '
            . 'audit-trail shape every product was hand-rolling, so the same history '
            . 'rendered differently on every screen; it declares no endpoint and no verb, '
            . 'so read-only is a property of the contract rather than a convention. inbox '
            . 'is the half with a seam: core has no notion of a task queue, so the PLUGIN '
            . 'supplies the items, and CORE resolves which of the declared actions the '
            . 'caller may take on each — from the ROUTE the action calls, with the same '
            . 'RoleChecker calls RbacMiddleware makes, through '
            . 'POST /api/v1/me/permitted-actions. An action therefore does NOT declare the '
            . 'permission its endpoint is gated on: a restated slug is a second answer to a '
            . 'question the route table already answers, and it drifts the day someone '
            . 're-gates the route and updates one of the two places. scopedPermission is '
            . 'the one authorization fact a plugin CAN contribute, because it is the one '
            . 'the route table cannot express — the per-record predicate a handler applies '
            . 'inside the request, resolved through the resource-scoped grants of 1.17/1.22 '
            . 'as an ADDITIONAL conjunct, so it can only ever remove an action from the '
            . 'permitted set and never add one; '
            . 'SDK 1.30 adds the organizational-unit TYPE contribution point '
            . '(Ou\PluginOuTypesInterface): an OU carried no kind, so the only thing a '
            . 'plugin could filter the OU tree on was DEPTH — which names a different '
            . 'kind of unit on every installation (a single-campus institution has its '
            . 'faculties at depth 0, a multi-campus one at depth 1) and shifts the moment '
            . 'somebody inserts a parent above an existing unit, silently, with nothing to '
            . 'tell a consumer it happened. The workaround two deployments reached '
            . 'independently was a parallel unit-id → kind map in their own schema, which '
            . 'drifts on every reparent. Slugs are declared BARE and the host stamps the '
            . 'plugin prefix, as it already does for resource types, jobs and settings keys: '
            . 'two plugins may both declare `clinic` and neither can mint a BARE key, since '
            . 'the unprefixed namespace belongs to core and to the vocabulary a tenant owns '
            . '— which is what stops an install-wide plugin squatting on a name a tenant '
            . 'wants. A declaration is a CATALOGUE ENTRY, not a write: it makes a key '
            . 'ADOPTABLE, and an administrator adopts it into their tenant explicitly, '
            . 'inheriting the declared label and rank as overridable defaults. Force-seeding '
            . 'instead would have been a cross-tenant write driven by an install-wide '
            . 'plugin, putting a clinic type in the picker of a university tenant. A malformed '
            . 'declaration costs that plugin the one type rather than all of them; '
            . 'SDK 1.29 adds the audited-event contribution point (PluginEventsInterface): '
            . 'the audit writer subscribed to a HARDCODED map of core event names, so a '
            . 'plugin\'s own domain events reached the platform audit trail never — an '
            . 'operator opening the one screen that answers "who did what" saw core\'s '
            . 'mutations and a silence where every plugin-side action had been. Both '
            . 'workarounds are worse than the gap: writing to audit_log directly puts a '
            . 'second writer on a table whose whole design is that it has one, and a private '
            . 'activity table is a second audit surface nobody is looking at. Names are '
            . 'declared BARE and the host stamps the plugin prefix onto BOTH halves of the '
            . 'record (action acme:task.completed, target type acme:task), because an '
            . 'attributable action beside a target type of `user` still reads as something '
            . 'core did to a core record. The TRIGGER is namespaced too, which is the '
            . 'load-bearing part: the hook manager tells a listener nothing about who '
            . 'dispatched, so listening on the bare name would write a row for every plugin '
            . 'declaring task.completed whenever any one of them fired it, and a trail that '
            . 'records an event which did not happen is worse than one that records nothing. '
            . 'A declaration that throws or is malformed costs that plugin its subscriptions '
            . 'and costs core\'s own auditing nothing, whole-declaration rather than per '
            . 'entry, because a plugin with half its events audited ships a trail that looks '
            . 'complete; '
            . 'SDK 1.28 adds the async-job contribution point (PluginJobsInterface): '
            . 'JobInterface has been public since 1.0 and the host job registry has always '
            . 'taken a handler, but nothing DISCOVERED a plugin\'s — so the shipped '
            . 'queue:work worker knew only the core handlers and dead-lettered anything a '
            . 'plugin enqueued as "No handler registered for job". The workaround was for '
            . 'every plugin to ship a queue:work of its own that re-registered the core '
            . 'handlers beside its own, which puts one worker per plugin in front of one '
            . 'queue. Declared names are BARE and the host stamps the plugin prefix on, as '
            . 'it already does for resource types, health probes and settings keys: two '
            . 'plugins declaring `sync` get different canonical names and none can produce '
            . 'a core.-prefixed one. Submittability is declared separately and fails '
            . 'CLOSED, matching core — a handler the worker can run is not thereby '
            . 'reachable from the public submission API. A declaration that throws or is '
            . 'malformed costs that plugin its jobs and costs the worker nothing, because '
            . 'the worker also delivers core\'s notifications and error alerts; '
            . 'SDK 1.27 adds the offline-host conformance kit '
            . '(Testing\OfflinePluginHostConformanceTestCase): the tenant-isolation kit '
            . '(1.3) proves a plugin\'s queries stay tenant-scoped, but says nothing about '
            . 'whether the plugin actually BOOTS under an offline PHP host with no server '
            . 'framework behind it — no JWT/memberships/OU hierarchy, a single fixed device '
            . 'role, a narrow SQLite dialect shim — the shape the Tauri desktop template\'s '
            . 'bundled FrankenPHP host runs plugins under. Every real gap that host surfaced '
            . '(a migration using SERIAL that SQLite silently mis-parses, an un-seeded admin '
            . 'role that left existing grant migrations silently inert, a route requiring a '
            . 'permission its plugin never declared) was found only by manually running the '
            . 'host and watching it fail. The new base case catches that class of defect in a '
            . 'plugin author\'s own CI instead: migrations apply cleanly on the same dialect '
            . 'shim, declared permissions are well-formed and match every route\'s '
            . 'requiredPermission, a role granted one permission holds exactly that one, and '
            . 'every declared hook runs cleanly on a synthetic payload — a generic Throwable '
            . 'fails the test loudly, since the real host\'s per-plugin error boundary would '
            . 'otherwise swallow it silently and ship the bug invisibly; '
            . 'SDK 1.26 adds the undeclared-reference linter (Schema\UndeclaredReferenceLinter '
            . 'and Schema\ReferenceDeclarations): it flags an *_id column that points at a '
            . 'table that really exists, carries NO foreign key, and appears in NEITHER '
            . 'blocks_delete NOR cascade_delete — a relationship core cannot see, which is '
            . 'the orphaning bug. It deliberately does NOT flag an *_id column merely for '
            . 'lacking a foreign key: no FKs between plugin tables is the convention here, '
            . 'and a rule that fires on the intended design is muted within a day; '
            . 'SDK 1.25 adds the portable writes: Sql\Upsert, whose tenantScoped() '
            . 'takes the tenant id as a required separate argument and prepends it to '
            . 'the conflict target, so an ON CONFLICT (client_uuid) that should have '
            . 'been ON CONFLICT (tenant_id, client_uuid) — cross-tenant data loss '
            . 'written as an ordinary create — cannot be expressed; and '
            . 'Sql\SequenceAllocator, the host contract that deletes the '
            . 'read-then-write counter a plugin would otherwise migrate and maintain '
            . 'for itself; '
            . 'SDK 1.24 adds the lifecycle WRITE contract (DataType\DataTypeLifecycle '
            . 'and the DataType\LifecycleOutcome it answers with). Core told adopters '
            . 'to route their lifecycle writes through core and then published only '
            . 'the read-only DataType\DataTypeGuard, so a plugin that needed to '
            . 'actually trash a record duck-typed Whity\Core\DataType\DataTypeLifecycleService '
            . '— a host internal with no contract and no compatibility promise. That '
            . 'is core\'s fault, not theirs. Reads keep their guarantee: the guard is '
            . 'untouched and gains no mutators, because "holding this confers no '
            . 'authority" is the one sentence that makes it safe to hand out. Writes '
            . 'get a SECOND contract, bound to the same object the generated endpoints '
            . 'authorize through — so an in-process call cannot skip a check the '
            . 'endpoint enforces: a type the caller may not read is reported UNKNOWN '
            . 'rather than forbidden (holding the contract must not become a way to '
            . 'enumerate the catalogue), an action the type does not offer is refused, '
            . 'and the action\'s declared permission is resolved through the same '
            . 'RoleChecker the RBAC middleware uses. $actorProfileId is REQUIRED for '
            . 'that reason — it is the subject of the permission check, so an optional '
            . 'one could only fail closed or run ungated. The outcome is the vocabulary '
            . 'the HTTP layer already publishes (reason as the stable key, message as '
            . 'the fallback, blockers, and the status), so a plugin calling in-process '
            . 'and a client calling over HTTP branch on ONE contract. Bulk lifecycle '
            . 'work is a LOOP over these calls — a bulk UPDATE bypasses every guard, '
            . 'veto and hook at once — and no bulk API ships here, because "does one '
            . 'veto abort the batch or is it skipped and reported" is a decision that '
            . 'has not been made; '
            . 'SDK 1.23 adds the portable schema predicates (Schema\SchemaInspector '
            . 'and the Schema\MigrationSchema trait). The SDK asks every migration '
            . 'to be idempotent and the host runs them on PostgreSQL and SQLite, '
            . 'but ALTER TABLE … ADD COLUMN IF NOT EXISTS is a PostgreSQL extension '
            . 'SQLite rejects — so every plugin author ends up hand-writing the same '
            . 'driver branch over information_schema and PRAGMA table_info, and a '
            . 'wrong answer there gates DDL: it passes on the engine the author '
            . 'develops against and fails on the other one, at enable time, on '
            . 'somebody else\'s deployment; '
            . 'SDK 1.22 makes the ROLE side of PermissionResolver resource-scoped: '
            . 'hasRole() takes the same optional $resourceType/$resourceId pair '
            . '1.17 gave hasPermission(). Host role resolution has honoured a '
            . 'resource scope since 1.17 (getEffectiveRolesForProfile accepts '
            . 'one), but hasRole() called it with no resource arguments, so a '
            . 'role granted at ONE record through resource_role_assignments was '
            . 'fully representable in storage and fully resolvable — yet '
            . 'unreachable through the method any caller would use, which reads '
            . 'as needing a memberships schema change it does not need. Additive: '
            . 'omitting them preserves 1.21 behaviour exactly (WC-712 §2); '
            . 'SDK 1.21 adds plugin-declared SETTINGS (Settings\PluginSettingsInterface, '
            . '#713 item 1): a plugin contributes typed, validated, defaulted '
            . 'configuration keys to the settings store the HOST already owns — the same '
            . 'two tables, the same per-tenant ?? global ?? default chain, the same write '
            . 'validation — instead of rebuilding the settings layer as a private table '
            . 'with no declared keys and no validation. Host-namespaced under the plugin '
            . 'name the loader supplies, so two plugins cannot collide and none can '
            . 'shadow a core key. Publication on the core settings screens is an explicit '
            . '`admin => true` opt-in, because those screens are gated on CORE settings '
            . 'permissions rather than on the declaring plugin permissions. '
            . 'Secret-shaped declarations are REFUSED rather than downgraded to a '
            . 'readable string; '
            . 'SDK 1.20 adds the plugin-owned data-type contracts (WC-723 Door 2): '
            . 'Tenant\PluginTablesInterface, by which a plugin declares WHICH '
            . 'tables it owns while the host stamps WHO owns them; '
            . 'DataType\PluginDataTypesInterface, by which it declares a record\'s '
            . 'lifecycle and reference graph as DATA rather than code; and the '
            . 'read-only DataType\DataTypeGuard, so a plugin\'s own delete route '
            . 'enforces through the same evaluator the generated one uses. All '
            . 'three OPTIONAL; trashed (reversible, pending removal) and retired '
            . '(permanent, closed to new references) stay distinct states; '
            . 'SDK 1.19 adds PluginHealthProbesInterface — the OPTIONAL contract by '
            . 'which a plugin contributes a status-page probe for a dependency it '
            . 'owns, sampled and published beside core\'s database/queue/scheduler/'
            . 'render probes instead of on a private status surface nobody watches. '
            . 'Host-namespaced under the plugin name the loader supplies, so probes '
            . 'cannot collide or shadow a core one (WC-status-probes); '
            . 'SDK 1.18 adds PluginResourceTypesInterface — the OPTIONAL contract '
            . 'by which a plugin declares the resource types it owns. The host '
            . 'namespaces them under the plugin name it supplies, so two plugins '
            . 'cannot collide and none can shadow a core type (WC-712 §2); '
            . 'SDK 1.17 makes PermissionResolver RESOURCE-SCOPED: optional '
            . '$resourceType/$resourceId on hasPermission() and '
            . 'effectivePermissions(), honoured against resource_role_assignments '
            . 'so a plugin can ask "may this caller act on THIS record?" instead '
            . 'of keeping a private grant table (WC-712 §2). Additive — omitting '
            . 'them preserves 1.16 behaviour exactly; '
            . 'SDK 1.16 adds the read-only permission-resolution contract '
            . '(Rbac\PermissionResolver, WC-712) so a plugin asks the host the same '
            . 'authorization question the RBAC middleware answers instead of '
            . 're-deriving it in hand-written SQL; '
            . 'SDK 1.15 adds the hook VETO contract (HookVetoException — the one '
            . 'Throwable the host error boundary re-throws, so a plugin can refuse '
            . 'a deletion and have it rolled back, WC-713); '
            . '1.14 adds the notification transport contract (NotificationTransport '
            . '+ NotificationMessage/SendResult value objects, WC-notifications); '
            . '1.13 adds the embed frontend-feature screen and multipart '
            . 'action file uploads (WC-246/WC-247); 1.12 adds the optional '
            . 'theme-override contribution point '
            . '(PluginThemeInterface, WC-242); 1.11 adds inline sort/filter/pagination '
            . 'to dataTable/dataList (the dataColumnList prop-rule kind, WC-241); '
            . '1.10 adds the chart data-bound block type and the chartSeriesList '
            . 'prop-rule kind (WC-240); 1.9 adds the MCP prompt contribution point '
            . '(PluginMcpInterface, WC-7abb732f); 1.8 added interactive block types '
            . '(form, inputs, submitButton, actionButton) and the '
            . 'inputName/selectOptions/submitSpec prop-rule kinds (WC-233)'
        );
    }

    /**
     * SDK 1.15 (WC-712): the read-only permission-resolution contract. It must
     * live in the SDK — not in core — so an out-of-repo plugin, which depends on
     * whity/plugin-sdk alone, can type-hint the service it resolves from the
     * host container.
     */
    public function testPermissionResolverContractLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\Rbac\PermissionResolver::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\Rbac\PermissionResolver::class))->getMethods()
        );
        sort($methods);

        // Read-only by construction: three questions, and deliberately no
        // cache-invalidation or database surface (which is why the host
        // registers a narrow facade rather than RoleChecker itself).
        $this->assertSame(['effectivePermissions', 'hasPermission', 'hasRole'], $methods);

        $this->assertSame(
            'bool',
            (string) (new \ReflectionMethod(\Whity\Sdk\Rbac\PermissionResolver::class, 'hasPermission'))
                ->getReturnType()
        );
        $this->assertSame(
            'array',
            (string) (new \ReflectionMethod(\Whity\Sdk\Rbac\PermissionResolver::class, 'effectivePermissions'))
                ->getReturnType()
        );

        // SDK 1.22: the resource scope is on the ROLE side too. Every method that
        // can be asked at a resource must take the SAME pair, or the contract is
        // asymmetric again — a plugin could narrow a permission question to one
        // record and not the role question about that same record.
        $signature = static fn (string $method): array => array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(\Whity\Sdk\Rbac\PermissionResolver::class, $method))->getParameters()
        );

        $this->assertSame(
            ['profileId', 'tenantId', 'permission', 'resourceType', 'resourceId'],
            $signature('hasPermission')
        );
        $this->assertSame(
            ['profileId', 'tenantId', 'role', 'resourceType', 'resourceId'],
            $signature('hasRole')
        );
        $this->assertSame(
            ['profileId', 'tenantId', 'resourceType', 'resourceId'],
            $signature('effectivePermissions')
        );

        // Additive only: a plugin written against SDK 1.21 must keep compiling
        // and keep receiving the tenant-wide answer.
        $hasRole = new \ReflectionMethod(\Whity\Sdk\Rbac\PermissionResolver::class, 'hasRole');
        $this->assertTrue(
            $hasRole->getParameters()[3]->isOptional() && $hasRole->getParameters()[4]->isOptional(),
            'The role resource arguments must be optional so three-argument callers are unaffected.'
        );
    }

    /**
     * SDK 1.24: the lifecycle WRITE contract, and the read-only guarantee it
     * deliberately does not touch.
     *
     * `DataTypeGuard` is documented as read-only — "every method answers a
     * question; none trashes, retires or deletes anything" — and that sentence
     * is what makes it safe to hand out. Adding mutators to it would falsify the
     * one property it rests on, so the write surface is a SECOND contract. Both
     * halves are pinned here: the new one has exactly the four mutating verbs,
     * and the old one still has exactly its four questions.
     */
    public function testTheLifecycleWriteContractLivesInTheSdkAndTheGuardStaysReadOnly(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\DataType\DataTypeLifecycle::class));
        $this->assertTrue(interface_exists(\Whity\Sdk\DataType\LifecycleOutcome::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\DataType\DataTypeLifecycle::class))->getMethods()
        );
        sort($methods);
        $this->assertSame(['delete', 'restore', 'retire', 'trash'], $methods);

        $readOnly = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\DataType\DataTypeGuard::class))->getMethods()
        );
        sort($readOnly);
        $this->assertSame(
            ['blockingReferences', 'canDelete', 'isReferenceable', 'stateOf'],
            $readOnly,
            'DataTypeGuard must stay read-only: its whole guarantee is that holding it confers no '
            . 'authority a plugin does not already have.'
        );
    }

    /**
     * The ACTOR is required on every write, and the outcome carries the same
     * refusal vocabulary the HTTP layer publishes.
     *
     * An optional actor would be a trap: the profile is the SUBJECT of the
     * permission check, so an omitted one could only fail closed (a parameter
     * that always fails) or run ungated (the bypass this contract exists to
     * remove).
     */
    public function testEveryWriteTakesARequiredActorAndAnswersInTheHttpVocabulary(): void
    {
        foreach (['trash', 'restore', 'retire', 'delete'] as $action) {
            $method = new \ReflectionMethod(\Whity\Sdk\DataType\DataTypeLifecycle::class, $action);

            $this->assertSame(
                ['dataType', 'tenantId', 'id', 'actorProfileId'],
                array_map(
                    static fn (\ReflectionParameter $p): string => $p->getName(),
                    $method->getParameters()
                ),
                "{$action}() must take the acting profile explicitly."
            );
            $this->assertFalse(
                $method->getParameters()[3]->isOptional(),
                "{$action}()'s actor must be REQUIRED — it is what the permission check runs against."
            );
            $this->assertSame(
                \Whity\Sdk\DataType\LifecycleOutcome::class,
                (string) $method->getReturnType()
            );
        }

        $outcome = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\DataType\LifecycleOutcome::class))->getMethods()
        );
        sort($outcome);
        $this->assertSame(
            ['blockers', 'httpStatus', 'isOk', 'message', 'reason', 'state', 'toArray'],
            $outcome,
            'The outcome publishes the same {reason, message} vocabulary — plus blockers and the '
            . 'status — so a plugin calling in-process and a client calling over HTTP branch on ONE '
            . 'contract.'
        );
    }

    /**
     * SDK 1.19 (WC-status-probes): the health-probe contribution point. It must
     * live in the SDK — not in core — so an out-of-repo plugin, which depends on
     * whity/plugin-sdk alone, can declare a probe and build its results without
     * referencing a single host type (core's HealthStatus enum notably included:
     * ProbeResult carries the state as a plain string the host maps).
     */
    public function testHealthProbeContributionPointLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\Health\PluginHealthProbesInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\Health\PluginHealthProbesInterface::class))->getMethods()
        );
        $this->assertSame(['getHealthProbes'], $methods);
        $this->assertSame(
            'array',
            (string) (new \ReflectionMethod(
                \Whity\Sdk\Health\PluginHealthProbesInterface::class,
                'getHealthProbes'
            ))->getReturnType()
        );

        $this->assertTrue(class_exists(\Whity\Sdk\Health\HealthProbeDefinition::class));
        $this->assertTrue(class_exists(\Whity\Sdk\Health\ProbeResult::class));

        // A result can only be built through the three factories, so `status` is
        // always one of the three states the host knows how to render — a plugin
        // cannot mint a fourth.
        $this->assertFalse(
            (new \ReflectionClass(\Whity\Sdk\Health\ProbeResult::class))
                ->getConstructor()?->isPublic() ?? true,
            'ProbeResult must not be constructible with an arbitrary status string'
        );
        $this->assertSame(
            'operational',
            \Whity\Sdk\Health\ProbeResult::operational()->status
        );
        $this->assertSame('degraded', \Whity\Sdk\Health\ProbeResult::degraded('slow')->status);
        $this->assertSame('down', \Whity\Sdk\Health\ProbeResult::down('gone')->status);
    }

    /**
     * SDK 1.21 (#713 item 1): the settings contribution point. It must live in
     * the SDK — not in core — so an out-of-repo plugin, which depends on
     * whity/plugin-sdk alone, can declare its configuration keys without
     * referencing a single host type. The declaration is plain arrays for
     * exactly that reason: the host owns SettingDefinition, the plugin owns
     * nothing but data.
     */
    public function testSettingsContributionPointLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\Settings\PluginSettingsInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\Settings\PluginSettingsInterface::class))->getMethods()
        );
        $this->assertSame(['getSettings'], $methods);
        $this->assertSame(
            'array',
            (string) (new \ReflectionMethod(
                \Whity\Sdk\Settings\PluginSettingsInterface::class,
                'getSettings'
            ))->getReturnType()
        );
    }

    public function testPluginMcpInterface_existsWithGetMcpPromptsMethod(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginMcpInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginMcpInterface::class))->getMethods()
        );
        $this->assertSame(['getMcpPrompts'], $methods);

        $return = (new \ReflectionMethod(\Whity\Sdk\PluginMcpInterface::class, 'getMcpPrompts'))
            ->getReturnType();
        $this->assertSame('array', (string) $return);
    }

    /**
     * SDK 1.6 (WC-225): the platform-neutral plugin-UI block contract +
     * validator ship in the SDK so distributable plugins can declare a
     * server-driven `screen: 'blocks'` tree with only whity/plugin-sdk
     * installed.
     */
    public function testBlockContractAndValidatorLiveInTheSdk(): void
    {
        $this->assertTrue(
            class_exists(\Whity\Sdk\Frontend\Blocks\BlockContract::class),
            'The block whitelist/contract must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Frontend\Blocks\BlockValidator::class),
            'The block validator must live in the SDK'
        );

        $this->assertSame(32, \Whity\Sdk\Frontend\Blocks\BlockContract::MAX_DEPTH);
        $this->assertSame(500, \Whity\Sdk\Frontend\Blocks\BlockContract::MAX_NODES);

        $validate = new \ReflectionMethod(\Whity\Sdk\Frontend\Blocks\BlockValidator::class, 'validate');
        $this->assertTrue($validate->isStatic(), 'validate() is a pure static gate');
        $this->assertSame('array', (string) $validate->getReturnType());
    }

    /**
     * WC-194: the conformance kit ships in the SDK so out-of-repo plugins (which
     * depend only on whity/plugin-sdk) can run it. The scanner engine and the
     * shared base test case must live under the SDK autoload root.
     */
    public function testTenantConformanceKitLivesInTheSdk(): void
    {
        $this->assertTrue(
            class_exists(\Whity\Sdk\Tenant\TenantPredicateScanner::class),
            'The portable tenant-predicate scanner must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Tenant\MigrationTenantColumnLinter::class),
            'The migration linter must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Tenant\TenantTableRegistry::class),
            'The portable tenant-table registry must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Testing\TenantIsolationConformanceTestCase::class),
            'The shared base conformance test case must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Testing\RealEnginePdo::class),
            'The real-engine PDO harness must live in the SDK: a plugin can only '
            . 'run its conformance/data-layer tests against the engine it actually '
            . 'ships against if the harness ships with the contract it depends on'
        );
    }

    /**
     * The engine a plugin's real-engine tests run against must be an ENVIRONMENT
     * decision, not a code one.
     *
     * A plugin that has to override `makePdo()` to reach Postgres will not do
     * it, and its whole suite stays on SQLite — where an INTEGER-vs-VARCHAR
     * comparison silently passes and then 500s in production. So the base case's
     * default must itself honour PHPUNIT_PG_DSN: setting one variable moves the
     * suite onto the real dialect with no subclass edit anywhere.
     */
    public function testConformanceCaseDefaultsToPostgresWhenADsnIsConfigured(): void
    {
        $source = (string) file_get_contents(
            self::SDK_DIR . '/src/Testing/TenantIsolationConformanceTestCase.php'
        );
        $this->assertStringContainsString(
            'RealEnginePdo::make()',
            $source,
            'makePdo() must delegate to the shared harness rather than hard-coding SQLite'
        );

        $harness = (string) file_get_contents(self::SDK_DIR . '/src/Testing/RealEnginePdo.php');
        foreach (['PHPUNIT_PG_DSN', 'PHPUNIT_PG_USER', 'PHPUNIT_PG_PASSWORD'] as $var) {
            $this->assertStringContainsString(
                $var,
                $harness,
                "The harness must honour {$var} — the same variable name whity-core's "
                . 'own real-engine suites use, so one environment drives both'
            );
        }

        // Absent a DSN the harness must stay on SQLite: the fast local loop is
        // what keeps the dual-engine habit affordable. Asserted with the variable
        // explicitly cleared, because this very suite may itself be running under
        // a DSN.
        $saved = $_ENV['PHPUNIT_PG_DSN'] ?? null;
        unset($_ENV['PHPUNIT_PG_DSN']);
        $savedProcess = getenv('PHPUNIT_PG_DSN');
        putenv('PHPUNIT_PG_DSN');

        try {
            $this->assertFalse(
                \Whity\Sdk\Testing\RealEnginePdo::isPostgres(),
                'With no PHPUNIT_PG_DSN set, the harness must fall back to SQLite'
            );
            $this->assertSame(
                'sqlite',
                \Whity\Sdk\Testing\RealEnginePdo::make()->getAttribute(\PDO::ATTR_DRIVER_NAME)
            );
        } finally {
            if ($saved !== null) {
                $_ENV['PHPUNIT_PG_DSN'] = $saved;
            }
            if (is_string($savedProcess)) {
                putenv('PHPUNIT_PG_DSN=' . $savedProcess);
            }
        }
    }

    /**
     * SDK 1.23: the portable schema predicates. These have to live in the SDK
     * rather than in core, because the code that needs them — a plugin's
     * migration — depends on the SDK and nothing else.
     */
    public function testSchemaPredicatesLiveInTheSdk(): void
    {
        $this->assertTrue(
            class_exists(\Whity\Sdk\Schema\SchemaInspector::class),
            'The portable schema inspector must live in the SDK'
        );
        $this->assertTrue(
            trait_exists(\Whity\Sdk\Schema\MigrationSchema::class),
            'The migration schema trait must live in the SDK'
        );

        // A trait rather than a base class: MigrationInterface is an interface
        // precisely so a plugin keeps its one inheritance slot.
        $this->assertTrue(
            (new \ReflectionClass(\Whity\Sdk\Schema\MigrationSchema::class))->isTrait()
        );

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\Schema\MigrationSchema::class))->getMethods()
        );
        sort($methods);
        $this->assertSame(
            [
                'addColumnIfMissing',
                'columnExists',
                'dropColumnIfExists',
                'indexExists',
                'tableColumns',
                'tableExists',
            ],
            $methods
        );
    }

    /**
     * SDK 1.26: the undeclared-reference linter. It belongs in the SDK for the
     * same reason the tenant conformance kit does — an out-of-repo plugin has
     * to be able to run it in its own CI with nothing but `whity/plugin-sdk`.
     */
    public function testUndeclaredReferenceLinterLivesInTheSdk(): void
    {
        $this->assertTrue(
            class_exists(\Whity\Sdk\Schema\UndeclaredReferenceLinter::class),
            'The undeclared-reference linter must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Schema\ReferenceDeclarations::class),
            'The declared-reference set must live in the SDK'
        );

        // Both halves of the reference graph are read. `blocks_delete` and
        // `cascade_delete` are opposite answers to what happens to a record's
        // children, but identical answers to "does core know this edge exists?"
        // — and an edge in neither list is the bug.
        $source = (string) file_get_contents(self::SDK_DIR . '/src/Schema/ReferenceDeclarations.php');
        $this->assertStringContainsString("'blocks_delete'", $source);
        $this->assertStringContainsString("'cascade_delete'", $source);

        // The escape hatch demands a reason. A tag that silences a finding
        // without recording why is a muted alarm, which is how a linter stops
        // being worth running.
        $this->assertSame(
            '@reference-lint-ignore',
            \Whity\Sdk\Schema\UndeclaredReferenceLinter::IGNORE_TAG
        );
    }

    /**
     * SDK 1.24: the portable write shapes. `Upsert` is pure SQL construction
     * and belongs in the SDK; `SequenceAllocator` is an INTERFACE here because
     * the storage behind it is the host's, and a plugin must be able to name
     * the contract without depending on core.
     */
    public function testPortableWriteShapesLiveInTheSdk(): void
    {
        $this->assertTrue(
            class_exists(\Whity\Sdk\Sql\Upsert::class),
            'The portable upsert builder must live in the SDK'
        );
        $this->assertTrue(
            interface_exists(\Whity\Sdk\Sql\SequenceAllocator::class),
            'The sequence-allocation contract must live in the SDK — a plugin references '
            . 'the interface, and the host registers its own implementation.'
        );

        // The tenant id is a REQUIRED, separately typed parameter. If it were
        // ever folded into the $values array the unscoped conflict target would
        // become reachable by omission, which is the whole failure this guards.
        $tenantScoped = new \ReflectionMethod(\Whity\Sdk\Sql\Upsert::class, 'tenantScoped');
        $parameters = $tenantScoped->getParameters();
        $this->assertSame('tenantId', $parameters[2]->getName());
        $this->assertSame('int', (string) $parameters[2]->getType());
        $this->assertFalse($parameters[2]->isOptional(), 'The tenant id must not be omittable.');
    }

    /**
     * The conformance kit is part of the standalone contract: it must not drag
     * in any host namespace, so an out-of-repo plugin can run it with only the
     * SDK (and phpunit) installed.
     */
    public function testTenantConformanceKitDependsOnlyOnTheSdkAndPhpunit(): void
    {
        $files = [
            self::SDK_DIR . '/src/Tenant/TenantPredicateScanner.php',
            self::SDK_DIR . '/src/Tenant/TenantTableRegistry.php',
            self::SDK_DIR . '/src/Tenant/MigrationTenantColumnLinter.php',
            self::SDK_DIR . '/src/Testing/TenantIsolationConformanceTestCase.php',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);
            foreach (['Whity\\Core', 'Whity\\Database', 'Whity\\Http\\', 'Whity\\Auth', 'Whity\\Api'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$path} must not reference {$forbidden} — the conformance kit is standalone"
                );
            }
        }
    }

    public function testHookEventConstantsLiveInTheSdk(): void
    {
        $this->assertSame('user.creating', \Whity\Sdk\Hooks\Events::USER_CREATING);
        $this->assertSame('user.created', \Whity\Sdk\Hooks\Events::USER_CREATED);
        $this->assertSame('tenant.deleted', \Whity\Sdk\Hooks\Events::TENANT_DELETED);
        $this->assertSame('navigation.register', \Whity\Sdk\Hooks\Events::NAVIGATION_REGISTER);
        $this->assertSame('worker.request.start', \Whity\Sdk\Hooks\Events::WORKER_REQUEST_START);
    }

    // ==================== HTTP shapes ====================

    public function testCoreRequestAndResponseAreSdkShapes(): void
    {
        $request = new \Whity\Core\Request('GET', '/api/x');
        $this->assertInstanceOf(\Whity\Sdk\Http\Request::class, $request);

        $response = new \Whity\Core\Response(200, 'ok');
        $this->assertInstanceOf(\Whity\Sdk\Http\Response::class, $response);
    }

    /**
     * Late static binding: the static factories must return the CALLED class,
     * or every core handler using Whity\Core\Response::json() would silently
     * start returning the SDK base type and break core-typed signatures.
     */
    public function testResponseFactoriesHonourLateStaticBinding(): void
    {
        $json = \Whity\Core\Response::json(['a' => 1]);
        $this->assertInstanceOf(\Whity\Core\Response::class, $json);

        $error = \Whity\Core\Response::error('nope', 400);
        $this->assertInstanceOf(\Whity\Core\Response::class, $error);

        $sdkJson = \Whity\Sdk\Http\Response::json(['a' => 1]);
        $this->assertSame(\Whity\Sdk\Http\Response::class, $sdkJson::class);
    }

    /**
     * The single-decode contract (WC-159) travels with the SDK Request shape:
     * the attribute bag and the well-known claims key are part of the contract
     * plugins may read.
     */
    public function testSdkRequestCarriesTheAttributeBag(): void
    {
        $request = new \Whity\Sdk\Http\Request('GET', '/api/x');
        $request->setAttribute(\Whity\Sdk\Http\Request::ATTR_JWT_CLAIMS, ['user_id' => 7]);

        $this->assertTrue($request->hasAttribute('jwt.claims'));
        $this->assertSame(['user_id' => 7], $request->getAttribute('jwt.claims'));
    }

    // ==================== shipped plugins use the SDK ====================

    public function testShippedPluginsImplementTheSdkContractDirectly(): void
    {
        require_once __DIR__ . '/../../plugins/HelloWorld/HelloWorldPlugin.php';

        foreach ([\HelloWorld\HelloWorldPlugin::class, \Whity\Plugins\ExamplePlugin::class] as $pluginClass) {
            $reflection = new \ReflectionClass($pluginClass);
            $this->assertContains(
                \Whity\Sdk\PluginInterface::class,
                $reflection->getInterfaceNames(),
                "{$pluginClass} must implement the SDK contract"
            );
            // The deprecated core alias was removed in WC-215; no shipped
            // plugin should report it among its implemented interfaces. Use a
            // string literal — the class no longer exists to reference.
            $this->assertNotContains(
                'Whity\Core\PluginInterface',
                $reflection->getInterfaceNames(),
                "{$pluginClass} must implement the SDK contract directly, not the deprecated core alias"
            );
        }
    }

    public function testHelloWorldMigrationImplementsTheSdkMigrationContract(): void
    {
        require_once __DIR__ . '/../../plugins/HelloWorld/Migrations/CreateHelloGreetingsTable.php';

        $reflection = new \ReflectionClass(\HelloWorld\Migrations\CreateHelloGreetingsTable::class);
        $this->assertTrue($reflection->implementsInterface(\Whity\Sdk\MigrationInterface::class));
    }

    /**
     * Production path regression: the loader wraps every plugin handler in an
     * error boundary, and that boundary must accept the SDK Response the
     * SDK-typed handler returns — not demand the host subclass. Caught live on
     * the dev stack as a 500 "Internal plugin error" before this test existed.
     */
    public function testWrappedSdkHandlerServesItsRouteThroughTheRouter(): void
    {
        $router = new Router('');
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            $router,
            null,
            new HookManager()
        );
        $loader->load();

        $match = $router->match(new \Whity\Core\Request('GET', '/api/hello'));
        $this->assertNotNull($match, 'The HelloWorld route must be registered');

        $response = ($match['handler'])(new \Whity\Core\Request('GET', '/api/hello'), $match['params']);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('Hello, World!', $payload['message'] ?? null);
    }

    /**
     * End to end through the loader: an SDK-typed plugin is discovered, loads,
     * and reaches the ACTIVE lifecycle state.
     */
    public function testSdkTypedPluginLoadsAndReachesActiveState(): void
    {
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            new Router(''),
            null,
            new HookManager()
        );
        $loader->load();

        $this->assertNotEmpty($loader->getPlugins(), 'The SDK-typed shipped plugins must load');

        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        $this->assertSame('active', $states['HelloWorld'] ?? null, 'HelloWorld must reach the active state');
    }
}
