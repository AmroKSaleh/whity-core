<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentQrApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Qr\QrRevocationReason;
use Whity\Core\Document\Routing\RouteRecipientRepository;
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
use Whity\Sdk\Http\Response;

/**
 * WHAT THE RECORD PANEL IS TOLD ABOUT A CODE'S LIFE (#1052, over #1036's API).
 *
 * THE DEFECT THIS FILE EXISTS TO CATCH
 * ------------------------------------
 * `GET /api/v1/documents/{id}/qr` used to answer `token: null` in two states
 * that are not the same fact:
 *
 *   A. nothing was ever minted for this document, and
 *   B. a code WAS minted and has been withdrawn.
 *
 * A screen that cannot separate them says "this document has no verification
 * code" to an operator who withdrew one thirty seconds ago — a true-sounding
 * sentence that hides the one state the whole feature exists for: paper in the
 * field carrying a symbol the server has stopped honouring. So the two are
 * asserted TOGETHER below, and the second assertion is what gives the first its
 * meaning. Testing only the withdrawn case would pass just as happily against a
 * handler that reported every document as having withdrawn codes.
 *
 * THE FIXTURE TRAP THIS FILE AVOIDS. `documents.qr_enabled` defaults to `false`,
 * so a test that never sets it is a test of a feature that is off. Every fixture
 * here turns it on explicitly and the `enabled` flag is asserted true in the
 * happy path, so a regression that switched the panel off wholesale could not
 * hide behind an expectation that matched the default.
 *
 * WHAT IS NOT UNDER TEST HERE. That holding a token grants nothing is
 * {@see DocumentQrTokenGrantsNothingTest}'s subject; that the PUBLIC page is not
 * an existence oracle is {@see DocumentVerificationApiRealEngineTest}'s. This
 * file only asks whether an operator who is already entitled to the record is
 * told the truth about its code.
 */
final class DocumentQrPanelRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** Raised every document below, so `canView` admits them as its creator. */
    private const OWNER = 10;

    private const PUBLIC_BASE = 'https://records.example.test';

    private PDO $pdo;
    private SettingsService $settings;
    private DocumentQrService $qr;
    private DocumentQrTokenRepository $tokens;
    private DocumentQrApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->tokens = new DocumentQrTokenRepository($this->pdo);
        $this->qr = new DocumentQrService(
            $this->pdo,
            $this->tokens,
            new DocumentQrScanRepository($this->pdo),
            self::PUBLIC_BASE
        );

        $this->handler = new DocumentQrApiHandler(
            new DocumentRepository($this->pdo),
            new DocumentTemplateRepository($this->pdo),
            new DocumentVisibilityPolicy(
                new RouteRecipientRepository($this->pdo),
                new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
            ),
            $this->qr,
            new DocumentQrScanRepository($this->pdo),
            new RoleChecker($db, new PermissionRegistry()),
            $this->settings,
        );

        // NOT the default. See the class docblock: the registry default for
        // `documents.qr_enabled` is `false`, so a fixture that left it alone
        // would be exercising a switched-off feature and every expectation
        // below would match by accident.
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ── the two nulls are two different facts ────────────────────────────────

    /**
     * A document that never carried a code says so, with nothing retired.
     *
     * Half of a pair. On its own it proves very little; read beside
     * {@see testAWithdrawnCodeIsReportedAsWithdrawnRatherThanAsAbsence} it is
     * the assertion that the panel can TELL THE TWO APART, which is the entire
     * change.
     */
    public function testADocumentThatNeverCarriedACodeReportsNothingRetired(): void
    {
        $data = $this->panel($this->raise('Internal memo'));

        self::assertNull($data['token']);
        self::assertSame(0, $data['retired']['total']);
        self::assertSame([], $data['retired']['recent']);
        // The fixture's own precondition, asserted rather than assumed: with the
        // switch off this test would pass for the wrong reason.
        self::assertTrue($data['enabled']);
        self::assertTrue($data['configured']);
    }

    /**
     * A withdrawn code is reported as WITHDRAWN, naming the reference that is
     * printed on the paper still in circulation.
     *
     * The reference is compared against the one derived from the token that was
     * actually minted, not against a literal — a literal would pass against a
     * handler that returned the same fixed string for every retired code.
     */
    public function testAWithdrawnCodeIsReportedAsWithdrawnRatherThanAsAbsence(): void
    {
        $documentId = $this->raise('Disciplinary decision 44/2026');
        $reference = $this->qr->reference($this->mint($documentId));

        self::assertTrue($this->qr->revoke(self::TENANT, $documentId, self::OWNER));

        $data = $this->panel($documentId);

        self::assertNull($data['token'], 'a withdrawn code is not live');
        self::assertSame(1, $data['retired']['total']);
        $retired = $data['retired']['recent'][0];
        self::assertSame(QrRevocationReason::WITHDRAWN, $retired['reason']);
        self::assertSame($reference, $retired['reference']);
        self::assertNotNull($retired['revoked_at']);
        self::assertSame(self::OWNER, $retired['revoked_by']);
    }

    // ── rotation is a different act from withdrawal ──────────────────────────

    /**
     * Minting rotates: the new code is live, the previous is retired as
     * SUPERSEDED, and the panel can say which verb applied.
     *
     * The two verbs mean opposite things to the person holding the paper — one
     * says the organisation stopped standing behind the document, the other says
     * their copy is simply an older printing — so collapsing them into a boolean
     * would lose the only fact that distinguishes them.
     */
    public function testRotatingRetiresThePreviousAsSupersededAndLeavesTheNewOneLive(): void
    {
        $documentId = $this->raise('Graduation certificate');
        $first = $this->qr->reference($this->mint($documentId));
        $second = $this->qr->reference($this->mint($documentId));

        $data = $this->panel($documentId);

        self::assertIsArray($data['token']);
        self::assertSame($second, $data['token']['reference'], 'the newest code is the live one');
        self::assertSame(1, $data['retired']['total']);
        self::assertSame(QrRevocationReason::SUPERSEDED, $data['retired']['recent'][0]['reason']);
        self::assertSame($first, $data['retired']['recent'][0]['reference']);
    }

    /**
     * REVOCATION IS A ONE-WAY LATCH, AT THE ROUTE.
     *
     * A second withdrawal is a no-op: the row keeps the first timestamp and the
     * first actor, and no second retired entry appears. This is the server half
     * of the invariant the panel must not contradict; the client half — that the
     * screen never offers a revoke on a code that has none — is pinned in
     * `web/__tests__/document-record-qr-panel.test.tsx`.
     */
    public function testWithdrawingTwiceChangesNothing(): void
    {
        $documentId = $this->raise('Rescinded circular');
        $this->mint($documentId);

        $first = $this->handler->revoke($this->request(), ['id' => (string) $documentId]);
        $before = $this->panel($documentId)['retired']['recent'][0];

        $second = $this->handler->revoke($this->request(), ['id' => (string) $documentId]);
        $after = $this->panel($documentId)['retired']['recent'][0];

        self::assertSame(204, $first->getStatusCode());
        // NOT an error. An operator clicking twice would otherwise see a failure
        // for a state that is exactly what they asked for, and the route would
        // report whether a document has a code — which the public endpoint is
        // careful never to say.
        self::assertSame(204, $second->getStatusCode());
        self::assertSame($before, $after, 'the first revocation is the one that survives');
        self::assertSame(1, $this->panel($documentId)['retired']['total']);
    }

    // ── what a retired entry may and may not carry ───────────────────────────

    /**
     * A retired entry names the code and never re-publishes it.
     *
     * Asserted against the SERIALISED body rather than against a key list: a key
     * list would pass if a future change nested the token one level deeper, and
     * the thing being ruled out is the value appearing anywhere at all.
     *
     * The URL is withheld for a reason that is not secrecy — the caller has
     * already passed `canView` and can download the artifact the code is printed
     * on. It is that following it would be an internal user scanning their own
     * document, which appends a `refused` row to the very trail they are reading.
     */
    public function testARetiredEntryCarriesNeitherTheTokenNorAVerificationUrl(): void
    {
        $documentId = $this->raise('Superseded contract');
        $retiredToken = $this->mint($documentId);
        $this->mint($documentId);

        $body = $this->handler
            ->show($this->request(), ['id' => (string) $documentId])
            ->getBody();

        self::assertStringNotContainsString($retiredToken, $body);
        self::assertStringNotContainsString(
            self::PUBLIC_BASE . '/verify/' . $retiredToken,
            $body
        );
        // The LIVE code's URL is present — the panel draws the symbol from it —
        // so the assertion above is about the retired one specifically and not
        // about the response having no URLs in it.
        $data = self::data($this->handler->show($this->request(), ['id' => (string) $documentId]));
        self::assertIsArray($data['token']);
        self::assertStringStartsWith(self::PUBLIC_BASE . '/verify/', $data['token']['verification_url']);
    }

    /**
     * The retired list is capped and the total beside it is EXACT.
     *
     * A truncated list with no total reads as the whole list, which is the
     * "unknown is not zero" failure one directory over (#1022) wearing a
     * different hat: an operator counting eleven rotations and being shown ten
     * has been told something false about their own document.
     */
    public function testTheRetiredListIsCappedWhileTheTotalStaysExact(): void
    {
        $documentId = $this->raise('Much-corrected decision');
        for ($i = 0; $i < 12; $i++) {
            $this->mint($documentId);
        }

        $retired = $this->panel($documentId)['retired'];

        self::assertSame(11, $retired['total'], '12 mints retire 11 codes; the 12th is live');
        self::assertCount(10, $retired['recent'], 'capped');
    }

    /**
     * Newest first, so the code most recently taken out of service is the one an
     * operator reads without scrolling.
     */
    public function testRetiredCodesAreNewestFirst(): void
    {
        $documentId = $this->raise('Twice-rotated notice');
        $oldest = $this->qr->reference($this->mint($documentId));
        $middle = $this->qr->reference($this->mint($documentId));
        $this->mint($documentId);

        $recent = $this->panel($documentId)['retired']['recent'];

        self::assertSame([$middle, $oldest], array_column($recent, 'reference'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function panel(int $documentId): array
    {
        $response = $this->handler->show($this->request(), ['id' => (string) $documentId]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        return self::data($response);
    }

    private function request(): Request
    {
        // Reset first: the context LOCKS on set, deliberately, so a handler
        // cannot walk out of its tenant mid-request. A test that calls the
        // handler twice therefore has to end the previous "request" itself.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request('GET', '/api/v1/documents/1/qr', [], '');
        $request->user = (object) ['profile_id' => self::OWNER];

        return $request;
    }

    private function mint(int $documentId): string
    {
        $token = $this->qr->mint(self::TENANT, $documentId, self::OWNER);
        self::assertIsArray($token, 'fixture: minting must succeed');

        return (string) $token['token'];
    }

    private function raise(string $title): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO documents (tenant_id, template_name, title, created_by, created_at)
             VALUES (:tenant_id, :template_name, :title, :created_by, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':tenant_id' => self::TENANT,
            ':template_name' => 'Template',
            ':title' => $title,
            ':created_by' => self::OWNER,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Ministry of Records', 'ministry')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (1, 'admin', '', NULL, CURRENT_TIMESTAMP)");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Fatima Al-Amin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
            VALUES (1000, 10, 1, 1, NULL, true, 'active', CURRENT_TIMESTAMP)
        ");

        return $pdo;
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
