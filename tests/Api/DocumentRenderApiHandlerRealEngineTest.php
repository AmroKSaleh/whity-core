<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentRenderApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Storage\LocalStorageDriver;

/**
 * Real-engine tests for {@see DocumentRenderApiHandler} (ADR 0012 /
 * WC-docdesigner Track 2):
 *
 *  - the feature-flag-off path returns a clean 503 and NEVER even attempts to
 *    call the render service (asserted via the fake client's call log);
 *  - RBAC/tenant-scoping: a caller can never render another tenant's
 *    template, nor one hidden from it by the document access policy (both
 *    404, never a 403 that would confirm existence);
 *  - the happy path calls the render service with the expected payload shape
 *    (template data, a default single sample-data row when none is supplied,
 *    resolved blockInstance references) and streams back the PDF bytes with
 *    the right Content-Type;
 *  - batch limits (max rows / max total render units / max template bytes)
 *    are enforced as 422s, sourced from settings (not hardcoded);
 *  - persistence (#947 item 1) is OPT-IN: the default render writes nothing at
 *    all — asserted as the absence of a document row AND of a storage
 *    directory, since "returns PDF bytes" would pass either way — and
 *    `persist: true` produces a record, an artifact and a JSON body.
 *
 * The actual whity_render Docker round-trip (a real Puppeteer render
 * producing real PDF bytes) is proven separately — see
 * tests/Integration/DocumentRenderServiceDockerTest.php — since standing up a
 * throwaway Chromium-bearing container on every `phpunit` invocation would
 * make this suite slow/flaky; here the render SERVICE ITSELF is faked so the
 * handler's own logic (flag/RBAC/limits/payload-assembly) is exercised in
 * isolation.
 */
final class DocumentRenderApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    // Seeded profiles (tenant 1 unless noted).
    private const OWNER = 10; // admin role -> documents:read/write/publish (migration 060)
    private const VIEWER = 11; // read only, no publish, no contracts tag
    private const MANAGER = 13; // read + documents:use:contracts (the gated tag)
    private const OTHER_TENANT_ADMIN = 20; // admin role, but in TENANT 2

    private const CONTRACTS_PERM = 'documents:use:contracts';

    private PDO $pdo;
    private FakeRenderServiceClient $fakeRender;
    private SettingsService $settingsService;
    private DocumentTemplateRepository $templateRepo;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->templateRepo = new DocumentTemplateRepository($this->pdo);
        $this->fakeRender = new FakeRenderServiceClient();
        $this->settingsService = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        // #947 item 1 moved the render MECHANICS into DocumentRenderer and gave
        // the handler an issuer for the opt-in `persist` path. The ephemeral
        // behaviour every test below asserts is unchanged; the storage root is a
        // throwaway directory that nothing here should ever write to, which
        // {@see testEphemeralRenderWritesNothingToStorageOrTheDatabase} makes explicit.
        $this->storageRoot = sys_get_temp_dir() . '/whity-doc-render-' . bin2hex(random_bytes(6));
        $renderer = new DocumentRenderer(
            new DocumentBlockRepository($this->pdo),
            $this->settingsService,
            $this->fakeRender
        );
        $documents = new DocumentRepository($this->pdo);
        $artifacts = new DocumentArtifactRepository($this->pdo);
        $this->handler = new DocumentRenderApiHandler(
            $this->templateRepo,
            new DocumentAccessPolicy(),
            new RoleChecker($db, new PermissionRegistry()),
            $this->settingsService,
            $renderer,
            new DocumentIssuer(
                $this->pdo,
                $documents,
                $artifacts,
                new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot))
            ),
            new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        self::removeTree($this->storageRoot);
    }

    /** Remove a throwaway storage root, if the run created one. */
    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    private DocumentRenderApiHandler $handler;

    // ── feature flag ────────────────────────────────────────────────────────

    public function testFeatureFlagOffReturns503AndNeverCallsRenderService(): void
    {
        // Default is 'false' — nothing enabled here.
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => ['version' => 2, 'placeholders' => [], 'pages' => [['id' => 'p1', 'elements' => []]]]]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls, 'the render service must never be called while the flag is off');
    }

    public function testFeatureFlagOffEvenWhenTemplateMissingStillChecksFlagFirst(): void
    {
        // Order doesn't strictly matter for correctness, but a disabled instance
        // must 503 rather than leak a 404 that would imply the flag check ran
        // AFTER an (unnecessary) template lookup and found nothing.
        $res = $this->render(self::OWNER, 999999);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    // ── RBAC / tenant scoping ────────────────────────────────────────────────

    public function testCannotRenderAnotherTenantsTemplate(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        // Same profile id acting under a DIFFERENT tenant context must 404, not
        // see/render tenant 1's template.
        $res = $this->renderAs(self::OTHER_TENANT_ADMIN, self::OTHER_TENANT, $id);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    public function testCannotRenderATemplateHiddenByAccessPolicy(): void
    {
        $this->enableRendering();
        // Owner publishes a tenant-wide template gated on the contracts tag.
        $id = $this->createTemplate(self::OWNER, [
            'name' => 'Contract',
            'data' => $this->minimalTemplateData(),
            'scope' => 'tenant',
            'required_permission' => self::CONTRACTS_PERM,
        ]);

        // The viewer lacks the tag -> 404 (never 403 — never confirm existence).
        $hidden = $this->render(self::VIEWER, $id);
        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);

        // The manager HOLDS the tag -> may render.
        $visible = $this->render(self::MANAGER, $id);
        self::assertSame(200, $visible->getStatusCode());
        self::assertCount(1, $this->fakeRender->calls);
    }

    public function testCannotRenderAPersonalTemplateBelongingToSomeoneElse(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Mine', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::VIEWER, $id);

        self::assertSame(404, $res->getStatusCode());
    }

    // ── happy path / payload shape ───────────────────────────────────────────

    public function testRenderSucceedsAndStreamsPdfBytes(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'My Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        // Response header names are stored normalised (lowercase) by the base
        // SDK Response — see HeaderUtil::normalize().
        self::assertSame('application/pdf', $res->getHeaders()['content-type'] ?? null);
        self::assertStringContainsString('my-label.pdf', $res->getHeaders()['content-disposition'] ?? '');
        self::assertStringStartsWith('%PDF-', $res->getBody());
        self::assertCount(1, $this->fakeRender->calls);
    }

    public function testDefaultDataRowsFallsBackToPlaceholderSamples(): void
    {
        $this->enableRendering();
        $data = $this->minimalTemplateData();
        $data['placeholders'] = [['key' => 'sku', 'label' => 'SKU', 'sample' => 'ABC-123']];
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $data]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        $payload = $this->fakeRender->calls[0];
        self::assertSame([['sku' => 'ABC-123']], $payload['dataRows']);
    }

    /**
     * A template that binds NO placeholders defaults to one EMPTY data row.
     * An empty PHP array json_encode()s as a JSON ARRAY (`[]`), not the `{}`
     * object the render harness's per-row `Record<string, string>` shape
     * needs — regression coverage for exactly that ambiguity (caught by the
     * real Docker round-trip test, which rejected `[[]]` as a malformed row
     * shape before this was fixed).
     */
    public function testEmptyDefaultDataRowSerialisesAsAJsonObjectNotArray(): void
    {
        $this->enableRendering();
        // minimalTemplateData() already has empty placeholders.
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        $payload = $this->fakeRender->calls[0];
        self::assertCount(1, $payload['dataRows']);
        self::assertInstanceOf(\stdClass::class, $payload['dataRows'][0]);
        self::assertSame('[{}]', json_encode($payload['dataRows']));
    }

    public function testExplicitDataRowsArePassedThrough(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => [['sku' => 'A'], ['sku' => 'B']]]);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([['sku' => 'A'], ['sku' => 'B']], $this->fakeRender->calls[0]['dataRows']);
    }

    public function testBlockInstanceReferencesAreResolvedIntoThePayload(): void
    {
        $this->enableRendering();
        $blockRepo = new DocumentBlockRepository($this->pdo);
        $blockId = $blockRepo->create(self::TENANT, [
            'name' => 'Header',
            'data' => [['id' => 'el1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'text' => 'Hi', 'style' => []]],
        ]);

        $data = $this->minimalTemplateData();
        $data['pages'][0]['elements'] = [
            ['id' => 'inst1', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $blockId],
        ];
        $id = $this->createTemplate(self::OWNER, ['name' => 'WithBlock', 'data' => $data]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        $blocks = $this->fakeRender->calls[0]['blocks'];
        self::assertArrayHasKey((string) $blockId, (array) $blocks);
    }

    /**
     * NESTED blocks reach the payload too (#1186 slice 3).
     *
     * The resolution used to scan the TEMPLATE tree once. A block nested inside
     * another block is referenced from the parent BLOCK'S data — somewhere that
     * scan never looked — so its id never entered the map, the render harness
     * looked it up, found nothing, and drew nothing. The PDF would have printed
     * with a hole in it and no error raised anywhere along the way.
     */
    public function testNestedBlockReferencesAreResolvedTransitively(): void
    {
        $this->enableRendering();
        $blockRepo = new DocumentBlockRepository($this->pdo);

        $logoId = $blockRepo->create(self::TENANT, [
            'name' => 'Logo',
            'data' => [['id' => 'el1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'text' => 'ACME', 'style' => []]],
        ]);
        $headId = $blockRepo->create(self::TENANT, [
            'name' => 'Letterhead',
            'data' => [['id' => 'el2', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $logoId]],
        ]);

        // The TEMPLATE names only the letterhead. The logo is reachable solely
        // through it — which is exactly the case the single pass missed.
        $data = $this->minimalTemplateData();
        $data['pages'][0]['elements'] = [
            ['id' => 'inst1', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $headId],
        ];
        $id = $this->createTemplate(self::OWNER, ['name' => 'Nested', 'data' => $data]);

        self::assertSame(200, $this->render(self::OWNER, $id)->getStatusCode());

        $blocks = (array) $this->fakeRender->calls[0]['blocks'];
        self::assertArrayHasKey((string) $headId, $blocks);
        self::assertArrayHasKey((string) $logoId, $blocks, 'the nested block must reach the render payload');
    }

    /**
     * A library that contains a cycle must still render. Resolution visits each
     * block once, so a malformed pointer costs a wrong-looking document rather
     * than a request that never returns.
     */
    public function testACycleBetweenBlocksDoesNotHangTheRender(): void
    {
        $this->enableRendering();
        $blockRepo = new DocumentBlockRepository($this->pdo);

        $aId = $blockRepo->create(self::TENANT, ['name' => 'A', 'data' => []]);
        $bId = $blockRepo->create(self::TENANT, [
            'name' => 'B',
            'data' => [['id' => 'el2', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $aId]],
        ]);
        // Close the loop: A points back at B.
        $blockRepo->update($aId, self::TENANT, [
            'data' => [['id' => 'el1', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $bId]],
        ]);

        $data = $this->minimalTemplateData();
        $data['pages'][0]['elements'] = [
            ['id' => 'inst1', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $aId],
        ];
        $id = $this->createTemplate(self::OWNER, ['name' => 'Cyclic', 'data' => $data]);

        self::assertSame(200, $this->render(self::OWNER, $id)->getStatusCode());

        $blocks = (array) $this->fakeRender->calls[0]['blocks'];
        self::assertArrayHasKey((string) $aId, $blocks);
        self::assertArrayHasKey((string) $bId, $blocks);
    }

    // ── batch limits ─────────────────────────────────────────────────────────

    public function testTooManyDataRowsIsRejectedWith422(): void
    {
        $this->enableRendering();
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_MAX_ROWS, '2');
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => [['a' => '1'], ['a' => '2'], ['a' => '3']]]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    public function testTooManyTotalUnitsIsRejectedWith422(): void
    {
        $this->enableRendering();
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_MAX_PAGES, '2');
        $data = $this->minimalTemplateData();
        // 2 pages per row x 2 rows = 4 units > the max of 2.
        $data['pages'][] = ['id' => 'p2', 'elements' => []];
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $data]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => [['a' => '1'], ['a' => '2']]]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    public function testInvalidDataRowsShapeIsRejectedWith422(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => 'not-a-list']);

        self::assertSame(422, $res->getStatusCode());
    }

    // ── render-service failure ───────────────────────────────────────────────

    public function testRenderServiceFailureReturns503NotARawException(): void
    {
        $this->enableRendering();
        $this->fakeRender->throwOnRender = true;
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(503, $res->getStatusCode());
        self::assertStringNotContainsString('simulated render-service failure', $res->getBody());
    }

    // ── persistence (#947 item 1) ────────────────────────────────────────────

    /**
     * The default must stay the ephemeral one. The designer previews on every
     * meaningful edit, and a preview that silently became a stored record would
     * fill a tenant's storage with drafts — so this asserts the ABSENCE of a
     * write, not merely the presence of PDF bytes.
     */
    public function testEphemeralRenderWritesNothingToStorageOrTheDatabase(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('application/pdf', $res->getHeaders()['content-type'] ?? null);
        self::assertSame(0, $this->countDocuments(), 'a preview render must not create a document record');
        self::assertDirectoryDoesNotExist($this->storageRoot, 'a preview render must not touch storage at all');
    }

    /** `persist: false` is spelled out, not merely omitted — same outcome. */
    public function testExplicitPersistFalseIsStillEphemeral(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['persist' => false]);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    public function testPersistCreatesARecordAnArtifactAndReturnsJson(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'My Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['persist' => true]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(1, $this->countDocuments());

        $body = json_decode($res->getBody(), true);
        self::assertIsArray($body);
        $doc = $body['data'];
        self::assertSame(self::TENANT, $doc['tenant_id']);
        self::assertSame($id, $doc['document_template_id']);
        // The snapshot, so a deleted template still leaves a legible record.
        self::assertSame('My Label', $doc['template_name']);
        self::assertSame('My Label', $doc['title']);
        self::assertSame(self::OWNER, $doc['created_by']);
        self::assertCount(1, $doc['artifacts']);

        $artifact = $doc['artifacts'][0];
        self::assertSame('application/pdf', $artifact['content_type']);
        self::assertSame(hash('sha256', $this->fakeRender->pdfBytes), $artifact['checksum_sha256']);
        self::assertSame(strlen($this->fakeRender->pdfBytes), $artifact['byte_size']);
        // VERSIONED, because this is a URL a browser fetches and the router
        // serves these bytes at '/api/v1/...'. This assertion used to expect the
        // unversioned form and passed for exactly as long as the viewer was
        // broken (#1016) — it compared the presenter against itself rather than
        // against the route table, so it could only ever agree with whatever the
        // presenter happened to emit. Tests\Core\Document\DocumentContentUrlTest
        // is the one that actually resolves these against the live routes.
        self::assertSame(
            "/api/v1/documents/{$doc['id']}/artifacts/{$artifact['id']}/content",
            $artifact['content_url']
        );

        // The storage key is an internal address and must never reach a client.
        self::assertArrayNotHasKey('storage_key', $artifact);
    }

    public function testPersistUsesAnExplicitTitleWhenSupplied(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Contract Template', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['persist' => true, 'title' => 'Contract 2026-014']);

        self::assertSame(201, $res->getStatusCode());
        $doc = json_decode($res->getBody(), true)['data'];
        self::assertSame('Contract 2026-014', $doc['title']);
        // The provenance snapshot is the TEMPLATE's name, independent of the title.
        self::assertSame('Contract Template', $doc['template_name']);
    }

    /**
     * The persistence gate is checked BEFORE the render, so a refusal does not
     * first burn a Chromium page it is going to throw away.
     */
    public function testPersistIsRefusedWith503WhenPersistenceIsDisabledAndNeverRenders(): void
    {
        $this->enableRendering();
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['persist' => true]);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * Turning persistence off must not take the preview down with it — they are
     * separate settings precisely so an operator can keep one and drop the other.
     */
    public function testEphemeralRenderStillWorksWhilePersistenceIsDisabled(): void
    {
        $this->enableRendering();
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('application/pdf', $res->getHeaders()['content-type'] ?? null);
    }

    /** Persisting is refused for the same reasons an ephemeral render is. */
    public function testPersistCannotReachAnotherTenantsTemplate(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->renderAs(self::OTHER_TENANT_ADMIN, self::OTHER_TENANT, $id, ['persist' => true]);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** How many document records exist, across every tenant. */
    private function countDocuments(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM documents');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function enableRendering(): void
    {
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalTemplateData(): array
    {
        return [
            'version' => 2,
            'page' => ['widthMm' => 50, 'heightMm' => 25, 'marginMm' => 2, 'background' => '#fff'],
            'placeholders' => [],
            'pages' => [['id' => 'p1', 'elements' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createTemplate(int $userId, array $body): int
    {
        return $this->templateRepo->create(self::TENANT, [
            'name' => (string) $body['name'],
            'data' => $body['data'],
            'scope' => $body['scope'] ?? 'personal',
            'required_permission' => $body['required_permission'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function render(int $userId, int $templateId, array $body = []): \Whity\Sdk\Http\Response
    {
        return $this->renderAs($userId, self::TENANT, $templateId, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function renderAs(int $userId, int $tenantId, int $templateId, array $body = []): \Whity\Sdk\Http\Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request('POST', "/api/document-templates/{$templateId}/render", [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];

        return $this->handler->render($req, ['id' => (string) $templateId]);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");

        // admin role (1) is seeded + granted documents:* by migration 060.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (103, 'manager', '', 1, datetime('now'))");

        $this->grant($pdo, 101, 'documents:read');
        $this->grant($pdo, 103, 'documents:read');
        $this->grant($pdo, 103, self::CONTRACTS_PERM);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'manager', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'other-admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1,   'active', datetime('now')),
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1003, 13, 1, 103, 'active', datetime('now')),
                (1004, 20, 2, 1,   'active', datetime('now'))
        ");
        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pid = (int) $sel->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $pid]);
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        return $db;
    }
}
