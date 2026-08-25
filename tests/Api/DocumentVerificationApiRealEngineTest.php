<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentVerificationApiHandler;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Qr\QrRevocationReason;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * The PUBLIC verification endpoint (#1036) — what a stranger holding a printed
 * document is told, and what they can work out from being told it.
 *
 * TWO PROPERTIES ARE UNDER TEST AND THEY PULL AGAINST EACH OTHER:
 *
 *   IT MUST VERIFY. A courier or a ministry clerk needs to know the paper is
 *   real, and a page that says nothing useful is a page nobody uses.
 *
 *   IT MUST NOT BE AN ORACLE. An unknown token, a malformed one, a withdrawn one
 *   and a superseded one must be indistinguishable at the default disclosure
 *   level, so nobody can ask this endpoint whether a document exists.
 *
 * The oracle assertions compare the two responses to EACH OTHER rather than each
 * to a literal I wrote. A pair of literals would still pass if both drifted the
 * same way; comparing them makes "these are indistinguishable" the actual
 * assertion.
 *
 * The scan-trail assertions are the privacy half: an anonymous scan records that
 * a scan happened and nothing whatsoever about who scanned, because there is no
 * column that could hold it.
 */
final class DocumentVerificationApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const AUTHOR = 10;

    private PDO $pdo;
    private SettingsService $settings;
    private DocumentQrService $qr;
    private DocumentVerificationApiHandler $handler;
    private DocumentQrTokenRepository $tokens;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->tokens = new DocumentQrTokenRepository($this->pdo);
        $this->qr = new DocumentQrService(
            $this->pdo,
            $this->tokens,
            new DocumentQrScanRepository($this->pdo),
            'https://records.example.test'
        );
        $this->handler = new DocumentVerificationApiHandler(
            $this->qr,
            new DocumentRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            $this->settings,
            new DatabaseSharedStore($this->pdo),
        );

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── it verifies ──────────────────────────────────────────────────────────

    /**
     * A live code confirms the document with the MINIMUM that makes a
     * confirmation meaningful — and nothing else.
     *
     * The key SET is asserted exactly, because the risk here is a field being
     * added later that nobody weighed: every one of these had to earn its place
     * against "a stranger who may have found this paper in a bin".
     */
    public function testALiveCodeConfirmsTheDocumentAndDisclosesTheMinimum(): void
    {
        $token = $this->mintFor($this->raise('Graduation certificate for Layla Haddad'));

        $data = self::data($this->verify($token));

        self::assertSame(['verified', 'reference', 'issuer', 'issued_on'], array_keys($data));
        self::assertTrue($data['verified']);
        self::assertSame('Ministry of Records', $data['issuer'], 'the ORGANISATION, never a person');
    }

    /** The date is a DATE. A timestamp would add a fact with no verification value. */
    public function testTheIssueDateIsADateAndNotATimestamp(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));

        $issuedOn = self::data($this->verify($token))['issued_on'];

        self::assertIsString($issuedOn);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $issuedOn);
    }

    /**
     * The reference is a prefix of the token the caller already holds, so it
     * discloses nothing — and it matches what is printed under the code.
     *
     * Derived from the token here rather than from the service, so the two are
     * compared rather than one being restated.
     */
    public function testTheReferenceIsAReadableFormOfTheTokenTheHolderAlreadyHas(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));

        $reference = self::data($this->verify($token))['reference'];

        self::assertIsString($reference);
        self::assertSame(
            strtoupper(substr($token, 0, 12)),
            str_replace('-', '', $reference)
        );
    }

    // ── it is not an oracle ──────────────────────────────────────────────────

    /**
     * A WITHDRAWN code and an unknown one are byte-identical at the default
     * disclosure level, with the same status.
     *
     * This is the assertion #1036 asks for in so many words. Comparing the two
     * responses to each other — rather than each to a literal — is what makes it
     * an indistinguishability test rather than two independent shape checks that
     * could drift together.
     */
    public function testAWithdrawnCodeIsIndistinguishableFromAnUnknownOne(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));
        $this->qr->revoke(self::TENANT, $this->documentOf($token), self::AUTHOR);

        $withdrawn = $this->verify($token);
        $unknown = $this->verify(str_repeat('a', 64));

        self::assertSame($unknown->getStatusCode(), $withdrawn->getStatusCode());
        self::assertSame($unknown->getBody(), $withdrawn->getBody());
    }

    /** So is a SUPERSEDED one — a rotation must not announce itself either. */
    public function testASupersededCodeIsIndistinguishableFromAnUnknownOne(): void
    {
        $documentId = $this->raise('Minutes');
        $old = $this->mintFor($documentId);
        $this->qr->mint(self::TENANT, $documentId, self::AUTHOR);

        self::assertSame($this->verify(str_repeat('b', 64))->getBody(), $this->verify($old)->getBody());
    }

    /** And so is a MALFORMED one — the shape check must not leak either. */
    public function testAMalformedCodeIsIndistinguishableFromAnUnknownOne(): void
    {
        self::assertSame(
            $this->verify(str_repeat('c', 64))->getBody(),
            $this->verify('not-a-token')->getBody()
        );
    }

    /**
     * A tenant that switches the feature OFF closes its public surface, and the
     * closure is indistinguishable from an unknown code.
     *
     * Closing must not announce that the organisation has a surface to close.
     * This is also the reversible kill-switch: revocation is permanent and
     * per-document, and a tenant wanting the whole surface shut should not have
     * to withdraw codes one at a time to get it.
     */
    public function testATenantThatSwitchesTheFeatureOffAnswersLikeAnUnknownCode(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));
        self::assertTrue(self::data($this->verify($token))['verified'], 'fixture: it verifies while on');

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'false');

        self::assertSame($this->verify(str_repeat('d', 64))->getBody(), $this->verify($token)->getBody());
    }

    /** Every answer is 200 — a status code must not restore the distinction. */
    public function testEveryAnswerIsTwoHundred(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));

        self::assertSame(200, $this->verify($token)->getStatusCode());
        self::assertSame(200, $this->verify(str_repeat('e', 64))->getStatusCode());
        self::assertSame(200, $this->verify('rubbish')->getStatusCode());
    }

    // ── the tenant's own choice ──────────────────────────────────────────────

    /**
     * At `stage`, a withdrawn code says so — because a tenant that chose to tell
     * holders where a document sits has already accepted that a holder learns it
     * exists, and "this printing has been replaced" is far more useful to them
     * than "unrecognised", which reads as "you scanned it wrong".
     */
    public function testTheStageLevelTellsAHolderTheirCopyWasWithdrawn(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, 'stage');
        $token = $this->mintFor($this->raise('Minutes'));
        $this->qr->revoke(self::TENANT, $this->documentOf($token), self::AUTHOR);

        $data = self::data($this->verify($token));

        self::assertFalse($data['verified']);
        self::assertSame(QrRevocationReason::WITHDRAWN, $data['reason']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['revoked_on']);
    }

    /**
     * At `stage`, a document that was never circulated reads as `issued` — the
     * honest word for it, and not a fabricated routing event.
     */
    public function testTheStageLevelReportsAnUncirculatedDocumentAsIssued(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, 'stage');
        $token = $this->mintFor($this->raise('Minutes'));

        $data = self::data($this->verify($token));

        self::assertSame('issued', $data['stage']);
        self::assertSame($data['issued_on'], $data['stage_on']);
    }

    /**
     * The stage NEVER names the unit or the person a document is with, at either
     * level.
     *
     * "Awaiting the Dean's approval" is the leak #1036 names, and it is the unit
     * that makes it one. Asserted against the raw body so a field nested one
     * level deeper would still be caught.
     */
    public function testNoLevelEverNamesAUnitOrAPerson(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, 'stage');
        $documentId = $this->raise('Minutes');
        $token = $this->mintFor($documentId);
        $this->appendTrailEvent($documentId, 'forwarded');

        $body = $this->verify($token)->getBody();

        self::assertStringContainsString('forwarded', $body, 'fixture: the stage must be reported at all');
        self::assertStringNotContainsString('Office of the Dean', $body);
        self::assertStringNotContainsString('to_ou_id', $body);
        self::assertStringNotContainsString('actor', $body);
    }

    // ── the scan trail ───────────────────────────────────────────────────────

    /**
     * An anonymous scan is recorded, and records NOTHING about the scanner.
     *
     * The columns are asserted exhaustively on purpose: the guarantee is not
     * "we do not currently write an IP", it is "there is no column that could
     * hold one", and a test that only checked `scanner_profile_id` would not
     * notice one being added.
     */
    public function testAnAnonymousScanIsRecordedAndSaysNothingAboutTheScanner(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));

        $this->verify($token);

        $row = $this->pdo->query('SELECT * FROM document_qr_scans ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertNull($row['scanner_profile_id']);
        self::assertSame('verified', $row['outcome']);
        self::assertSame(
            ['id', 'tenant_id', 'document_id', 'qr_token_id', 'scanner_profile_id', 'outcome', 'scanned_at'],
            array_keys($row),
            'the scan row must have no column that could identify a member of the public'
        );
    }

    /**
     * A scan of a WITHDRAWN code is recorded as refused — the most interesting
     * scan there is, because paper the organisation has stopped standing behind
     * is still in circulation and somebody just tried to rely on it.
     */
    public function testAScanOfAWithdrawnCodeIsRecordedAsRefused(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));
        $this->qr->revoke(self::TENANT, $this->documentOf($token), self::AUTHOR);

        $this->verify($token);

        self::assertSame('refused', $this->pdo->query(
            'SELECT outcome FROM document_qr_scans ORDER BY id DESC LIMIT 1'
        )->fetchColumn());
    }

    /**
     * An UNKNOWN code writes no row at all.
     *
     * There is nowhere to put it — `qr_token_id` is NOT NULL — and that is the
     * point: recording guesses would hand an anonymous caller an unbounded
     * write, one row per attempt, on an endpoint whose job is to be cheap for
     * strangers.
     */
    public function testAnUnknownCodeWritesNoRow(): void
    {
        $this->verify(str_repeat('f', 64));

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM document_qr_scans')->fetchColumn());
    }

    /**
     * A reload inside the coalescing window is ONE scan.
     *
     * A phone that opens the page, rotates and reloads has scanned the paper
     * once. Without this the trail is a page-view log — useless to the person
     * reading it, and an amplification surface.
     */
    public function testAReloadInsideTheWindowCountsOnce(): void
    {
        $token = $this->mintFor($this->raise('Minutes'));

        $this->verify($token);
        $this->verify($token);
        $this->verify($token);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM document_qr_scans')->fetchColumn());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function verify(string $token): Response
    {
        TenantContext::reset();
        $request = new Request(
            'GET',
            '/api/v1/document-verifications/' . $token,
            ['REMOTE_ADDR' => '198.51.100.4'],
            ''
        );

        return $this->handler->verify($request, ['token' => $token]);
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
            ':created_by' => self::AUTHOR,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function mintFor(int $documentId): string
    {
        $token = $this->qr->mint(self::TENANT, $documentId, self::AUTHOR);
        self::assertIsArray($token, 'fixture: minting must succeed');

        return (string) $token['token'];
    }

    private function documentOf(string $token): int
    {
        $row = $this->tokens->findByToken($token);
        self::assertIsArray($row);

        return (int) $row['document_id'];
    }

    /** A trail row, inserted directly — the router's own behaviour is not the subject. */
    private function appendTrailEvent(int $documentId, string $action): void
    {
        $this->pdo->prepare(
            'INSERT INTO document_routes (id, tenant_id, document_id, title, created_at)
             VALUES (5, :tenant_id, :document_id, :title, CURRENT_TIMESTAMP)'
        )->execute([
            ':tenant_id' => self::TENANT,
            ':document_id' => $documentId,
            ':title' => 'Circulation',
        ]);

        $this->pdo->prepare(
            'INSERT INTO document_route_events
                 (tenant_id, document_id, route_id, action, to_ou_id, occurred_at)
             VALUES (:tenant_id, :document_id, 5, :action, 7, CURRENT_TIMESTAMP)'
        )->execute([
            ':tenant_id' => self::TENANT,
            ':document_id' => $documentId,
            ':action' => $action,
        ]);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Ministry of Records', 'ministry')");
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
                    VALUES (7, 1, 'Office of the Dean', 'dean', CURRENT_TIMESTAMP)");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Fatima Al-Amin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        return $pdo;
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
