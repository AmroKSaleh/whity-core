<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Render;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\SdkDocumentRenderer;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Render\DocumentRenderer as SdkRenderer;
use Whity\Sdk\Render\FlowDocument;
use Whity\Sdk\Render\RenderRejectedException;
use Whity\Sdk\Render\RenderUnavailableException;
use Whity\Storage\LocalStorageDriver;

/**
 * The host side of the SDK rendering seam (#1072, SDK 1.41).
 *
 * Three properties are worth proving here and they are all about the BOUNDARY
 * rather than about rendering:
 *
 *   1. A plugin cannot name a tenant. There is no parameter for one, so the
 *      tenant comes from the host's request-scoped context or the call is
 *      refused — the failure where a document is built from one tenant's
 *      content and filed in another's storage has no expression in this API.
 *   2. A plugin never sees a core exception type. The SDK forbids referencing
 *      core namespaces, so a plugin catching one would be catching a class its
 *      own contract says does not exist.
 *   3. Rejection and unavailability stay APART. A plugin that cannot tell them
 *      apart either retries a malformed document forever or gives up on a
 *      container that was restarting.
 */
final class SdkDocumentRendererTest extends TestCase
{
    private const TENANT = 4;
    private const ACTOR = 11;

    private \PDO $pdo;
    private SettingsService $settings;
    private FakeRenderServiceClient $client;
    private SdkDocumentRenderer $renderer;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $pdo = $this->pdo;
        // The tenant has to EXIST. Both `tenant_settings.tenant_id` and
        // `documents.tenant_id` carry foreign keys that PostgreSQL enforces and
        // SQLite (which does not turn on `PRAGMA foreign_keys` by default) does
        // not — so writing an override or raising a document against an id
        // nobody created passes on the SQLite shards and fails on the
        // real-engine ones, where the constraint is real.
        $pdo->exec(
            'INSERT INTO tenants (id, name, slug) VALUES (' . self::TENANT . ", 'tenant-issuer', 'tenant-issuer')"
            . ' ON CONFLICT DO NOTHING'
        );
        // And the ACTOR, for the same reason: `documents.created_by` and
        // `document_artifacts.rendered_by` both reference profiles. The actor
        // is read from AuditContext rather than passed in, so a test that did
        // not seed one would be proving the seam stamps provenance while
        // stamping an id that resolves to nobody.
        $pdo->exec(
            'INSERT INTO profiles (id, display_name, password_hash) VALUES ('
            . self::ACTOR . ", 'Issuing Actor', 'x')"
            . ' ON CONFLICT DO NOTHING'
        );

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );
        $this->client = new FakeRenderServiceClient();
        $this->storageRoot = sys_get_temp_dir() . '/whity-sdk-render-' . bin2hex(random_bytes(6));

        $this->renderer = new SdkDocumentRenderer(
            new FlowDocumentRenderer($this->settings, $this->client),
            new DocumentIssuer(
                $pdo,
                new DocumentRepository($pdo),
                new DocumentArtifactRepository($pdo),
                new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot))
            ),
            $this->settings,
            // A configured QR service: the tenant switch, not this, decides
            // whether a document actually carries a code.
            new DocumentQrService(
                $pdo,
                new DocumentQrTokenRepository($pdo),
                new DocumentQrScanRepository($pdo),
                'https://whity.test'
            )
        );

        TenantContext::setTenantId(self::TENANT);
        AuditContext::set(self::ACTOR, null);
    }

    protected function tearDown(): void
    {
        // Both are request-scoped statics the host clears between requests; a
        // test that left one set would hand the next test a tenant it never
        // chose — the worker-mode failure these classes are careful about.
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testIsTheSdkContract(): void
    {
        self::assertInstanceOf(SdkRenderer::class, $this->renderer);
    }

    public function testIsNotAvailableUntilRenderingIsTurnedOn(): void
    {
        self::assertFalse($this->renderer->isAvailable());

        $this->enableRendering();

        self::assertTrue($this->renderer->isAvailable());
    }

    public function testAvailabilityAnswersNoRatherThanThrowingWithoutATenant(): void
    {
        // This method exists to be called speculatively, before a plugin spends
        // its own queries assembling a hundred-page tree. A predicate that
        // throws is one every caller has to wrap.
        $this->enableRendering();
        TenantContext::reset();

        self::assertFalse($this->renderer->isAvailable());
    }

    public function testRefusesToRenderWithNoTenantContext(): void
    {
        $this->enableRendering();
        TenantContext::reset();

        // A REJECTION, not an outage: there is no tenant and no amount of
        // retrying produces one.
        $this->expectException(RenderRejectedException::class);
        $this->expectExceptionMessage('requires a tenant context');

        $this->renderer->render($this->document());
    }

    public function testRenderingDisabledIsAnOutageAndNotTheCallersFault(): void
    {
        // The default state of every fresh install. A plugin told its document
        // was malformed here would go rewriting content that was never wrong.
        $this->expectException(RenderUnavailableException::class);

        $this->renderer->render($this->document());
    }

    public function testReturnsTheSdkResultTypeCarryingBothPageCounts(): void
    {
        $this->enableRendering();
        $this->client->flowPageCount = 12;
        $this->client->flowFrontMatterPages = 2;

        $rendered = $this->renderer->render($this->document());

        self::assertSame('%PDF-1.4', substr($rendered->bytes, 0, 8));
        self::assertSame(12, $rendered->pageCount);
        self::assertSame(2, $rendered->frontMatterPages);
        // The derived one, which is what a caller actually prints: "page 3 of
        // 10 of the body" is not the same as page 3 of the file.
        self::assertSame(10, $rendered->bodyPageCount());
    }

    public function testTranslatesACoreRejectionIntoTheSdkOneKeepingTheSentence(): void
    {
        // The sentence IS the value of a rejection: it names a tenant ceiling
        // or the field the render service refused, neither of which a caller
        // can work out from a status alone.
        $this->enableRendering();
        $this->client->rejectWith = '"content[3].level" must be a whole number 1-6';

        try {
            $this->renderer->render($this->document());
            self::fail('Expected a rejection');
        } catch (RenderRejectedException $e) {
            self::assertSame('"content[3].level" must be a whole number 1-6', $e->clientMessage);
        }
    }

    public function testTranslatesACoreOutageIntoTheSdkOne(): void
    {
        $this->enableRendering();
        $this->client->throwOnRender = true;

        $this->expectException(RenderUnavailableException::class);

        $this->renderer->render($this->document());
    }

    public function testIssuesAFirstClassDocumentOwnedByTheContextTenantAndActor(): void
    {
        $this->enableRendering();
        $this->client->flowPageCount = 9;
        $this->client->flowFrontMatterPages = 1;

        $issued = $this->renderer->issue($this->document(), 'Compliance submission');

        self::assertGreaterThan(0, $issued->documentId);
        self::assertSame('Compliance submission', $issued->title);
        self::assertSame(9, $issued->pageCount);
        self::assertSame(1, $issued->frontMatterPages);
        self::assertTrue($issued->hasArtifact());
        self::assertSame(
            '/api/v1/documents/' . $issued->documentId . '/content',
            $issued->contentUrl
        );
        // The bytes are deliberately NOT on this result — they are already
        // stored, and a plugin holding a second copy would be keeping two of a
        // thing whose defining property is that there is one. That absence is
        // asserted in SdkPackageContractTest against the class rather than here
        // against an instance: static analysis already proves the property does
        // not exist, so a runtime check for it is a test that cannot fail.
    }

    public function testARefusedPersistNeverSpendsARender(): void
    {
        // Checked BEFORE the render, so a tenant with persisting switched off
        // does not first pay for a headless-browser page that is then thrown
        // away. Proven by the service never being reached.
        $this->enableRendering();
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');

        try {
            $this->renderer->issue($this->document(), 'Nope');
            self::fail('Expected the persist gate to refuse this');
        } catch (RenderRejectedException $e) {
            self::assertStringContainsString('disabled', $e->clientMessage);
        }

        self::assertSame([], $this->client->calls, 'A refused persist must not reach the render service');
    }

    public function testAMalformedDocumentIsReportedAsMalformedEvenWhenPersistingIsOff(): void
    {
        // Order matters here for a reason that is easy to get backwards: if the
        // persist gate ran first, the same call would answer two different
        // complaints depending on a setting the caller cannot see, and the one
        // it heard would be the less useful of the two.
        $this->enableRendering();
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');

        $this->expectException(RenderRejectedException::class);
        $this->expectExceptionMessage('at least one block of content');

        $this->renderer->issue(FlowDocument::create(), 'Empty');
    }

    public function testARenderFailureAfterTheRecordExistsReturnsTheRecordRatherThanThrowing(): void
    {
        // The consequence of having to create the record BEFORE the render (a
        // verification code encodes a document id, and an id needs a row).
        // Throwing here would leave the caller with no id for a row that
        // exists — an orphan nothing can find. So the document comes back
        // WITHOUT an artifact, which is a state the platform already models.
        $this->enableRendering();
        $this->client->throwOnRender = true;

        $issued = $this->renderer->issue($this->document(), 'Half a document');

        self::assertGreaterThan(0, $issued->documentId);
        self::assertFalse($issued->hasArtifact());
        self::assertNull($issued->contentUrl);
    }

    public function testACeilingBreachLeavesNoRecordAtAll(): void
    {
        // The other side of the same ordering. Everything that CAN refuse
        // without writing runs before the raise, so a refused document does not
        // litter the tenant's list with rows that were never going to render.
        $this->enableRendering();
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_FLOW_MAX_BLOCKS, '1');

        try {
            $this->renderer->issue($this->document(), 'Too big');
            self::fail('Expected the block ceiling to refuse this');
        } catch (RenderRejectedException $e) {
            self::assertStringContainsString('max 1', $e->clientMessage);
        }

        self::assertSame([], $this->client->calls);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM documents'), 'A refused document must not leave a record behind');
    }

    public function testStampsAVerificationCodeMintedAgainstTheRealDocumentId(): void
    {
        $this->enableRendering();
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');

        $issued = $this->renderer->issue($this->document(), 'Verifiable');

        $content = $this->client->flowCalls[0]['content'];
        $qr = end($content);

        // Appended at the END: the fixed-canvas mode can put the code where a
        // designer dropped it because it knows the page count before printing.
        // A flowing document does not, so the code goes where the document
        // ends — where a signature would.
        self::assertSame('qr', $qr['type']);
        self::assertStringContainsString('/verify/', $qr['value']);
        // The reference is the hand-copyable fallback for every reader who
        // cannot scan, so it has to be there and has to be grouped.
        self::assertMatchesRegularExpression('/^[0-9A-F]{4}(-[0-9A-F]{4})+$/', $qr['reference']);

        // Minted against THIS document, not a free-floating token: the row is
        // what the public verification page resolves.
        self::assertSame(
            $issued->documentId,
            $this->scalar('SELECT document_id FROM document_qr_tokens WHERE tenant_id = ' . self::TENANT)
        );
    }

    /**
     * One integer out of the test database.
     *
     * A helper rather than an inline `query(...)->fetchColumn()` because
     * PDO::query() is typed `PDOStatement|false` and level-8 analysis refuses
     * the chained call — the check belongs in one place instead of at every
     * assertion that wants to look at a row count.
     */
    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, 'Query failed: ' . $sql);

        return (int) $statement->fetchColumn();
    }

    public function testCarriesNoVerificationCodeWhenTheTenantHasThemOff(): void
    {
        // The default. Switching them on publishes an unauthenticated
        // verification surface for that tenant's documents, which is a decision
        // somebody makes rather than inherits.
        $this->enableRendering();

        $this->renderer->issue($this->document(), 'Plain');

        foreach ($this->client->flowCalls[0]['content'] as $block) {
            self::assertNotSame('qr', $block['type']);
        }
    }

    public function testAPluginCannotAuthorAVerificationCodeItself(): void
    {
        // The security property, and it holds by CONSTRUCTION rather than by a
        // check: FlowDocument is the only way content reaches this renderer,
        // and it publishes no method that emits a `qr` block. A caller that
        // could supply its own would be able to print a document that looks
        // verified and resolves to nothing — worse than one carrying no code.
        $authoring = array_map(
            static fn (\ReflectionMethod $m): string => strtolower($m->getName()),
            (new \ReflectionClass(FlowDocument::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        foreach (['qr', 'verificationcode', 'barcode', 'code'] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $authoring,
                'FlowDocument must not let a caller author its own verification code'
            );
        }
    }

    private function enableRendering(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
    }

    private function document(): FlowDocument
    {
        return FlowDocument::create()
            ->withTitle('Report')
            ->heading(1, 'Summary')
            ->paragraph('Body text.');
    }
}
