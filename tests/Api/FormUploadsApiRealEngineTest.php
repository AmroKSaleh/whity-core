<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormsApiHandler;
use Whity\Api\FormSubmissionsApiHandler;
use Whity\Api\FormUploadsApiHandler;
use Whity\Api\PublicFormsApiHandler;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormUploadPolicy;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\FormUploadStore;
use Whity\Core\Form\FormUploadSweeper;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;

/**
 * ATTACHING A FILE TO A FORM, END TO END (migration 134).
 *
 * The claim this suite exists to hold is ONE sentence: an applicant uploads a
 * paper with their application, and the document that submission mints carries a
 * `document_artifacts` row naming those exact bytes. Without that row the file
 * is a string inside a jsonb column, and an accreditation report that says
 * "twelve papers" cannot produce twelve papers.
 *
 * So {@see self::testAnUploadedPaperBecomesAnArtifactOnTheDocument} asserts
 * against the DATABASE and against the STORAGE BACKEND, not against a response
 * body. A handler returning 201 proves that a handler returned 201.
 *
 * The rest of the suite is about the ways this could be true and still be wrong:
 *
 *   A STORAGE KEY MUST NOT BE A CAPABILITY. Two tenants exist in the fixture for
 *   the same reason they do in {@see PublicFormsApiRealEngineTest}: the claim
 *   that a key from tenant 2 cannot become evidence in tenant 1 is only testable
 *   when there is a tenant 2 for the key to come from.
 *
 *   A ROLLED-BACK SUBMISSION MUST LEAVE NOTHING. An artifact row that survived a
 *   failed submission would point at bytes attached to a document that does not
 *   exist — and would make the upload unspendable, so the person could not
 *   re-submit what they typed.
 *
 *   THE LIMITS MUST BE THE APPLICATION'S. An oversized or wrong-typed file must
 *   be refused with a sentence somebody can act on, at the endpoint, not by a
 *   parser or an ini setting somewhere behind it.
 */
final class FormUploadsApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const APPLICANT = 10;
    private const SECOND_MEMBER = 11;

    /** A file with a real PDF signature — the type check reads the bytes. */
    private const PAPER = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormUploadsApiHandler $uploads;
    private FormSubmissionsApiHandler $submissions;
    private PublicFormsApiHandler $public;
    private FormsApiHandler $manage;
    private DocumentArtifactRepository $artifacts;
    private FormUploadRepository $uploadRows;
    private LocalStorageDriver $storage;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $submissionRepository = new FormSubmissionRepository($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/formuploads-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0o777, true);
        // The REAL local driver, not a stub. A stub would let a key pass here
        // that no backend would accept, and the bytes-round-trip assertion below
        // would be asserting against the stub's memory rather than against a
        // file that exists.
        $this->storage = new LocalStorageDriver($this->storageRoot);

        $this->uploadRows = new FormUploadRepository($this->pdo);
        $uploadStore = new FormUploadStore($this->storage, $this->uploadRows);
        $this->artifacts = new DocumentArtifactRepository($this->pdo);

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $rules = new RoutingRuleRegistry();
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $rules
            ))
        );

        $issuer = new SubmissionIssuer(
            $this->pdo,
            $submissionRepository,
            new DocumentIssuer(
                $this->pdo,
                new DocumentRepository($this->pdo),
                $this->artifacts,
                new DocumentArtifactStore($this->storage),
            ),
            new RouteTemplateRepository($this->pdo),
            new DocumentRouter(
                $this->pdo,
                new RouteRepository($this->pdo),
                new RouteStepRepository($this->pdo),
                new RouteEventRepository($this->pdo),
                new RouteRecipientRepository($this->pdo),
                new RouteEdgeRepository($this->pdo),
                $rules,
                $settings,
                null
            ),
            $this->uploadRows,
            $this->artifacts,
        );

        $this->uploads = new FormUploadsApiHandler(
            $this->forms,
            $this->fields,
            $uploadStore,
            new DatabaseSharedStore($this->pdo),
        );
        $this->submissions = new FormSubmissionsApiHandler(
            $this->forms,
            $this->fields,
            $submissionRepository,
            $issuer,
        );
        $this->public = new PublicFormsApiHandler(
            $this->forms,
            $this->fields,
            $issuer,
            new DatabaseSharedStore($this->pdo),
            $uploadStore,
        );
        $this->manage = new FormsApiHandler(
            $this->forms,
            $this->fields,
            new FormRenderer($this->fields, new PrefillResolver($this->pdo)),
            $submissionRepository,
            new PublicFormLink('https://records.example.test'),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        self::removeTree($this->storageRoot);
    }

    // ── the whole point ──────────────────────────────────────────────────────

    /**
     * AN UPLOADED PAPER BECOMES AN ARTIFACT ON THE DOCUMENT THE SUBMISSION
     * MINTS — with the right bytes, the right size and the right checksum.
     *
     * This is the assertion the feature exists for. Every number is compared
     * against the SOURCE BYTES rather than against the upload response, so a
     * handler that echoed a checksum it never computed would fail here.
     */
    public function testAnUploadedPaperBecomesAnArtifactOnTheDocument(): void
    {
        $formId = $this->publishedFormWithFileField();

        $reference = $this->upload($formId, self::PAPER);

        $response = $this->submit($formId, ['title' => 'On sandstone', 'paper' => $reference]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $documentId = (int) $this->onlySubmission()['document_id'];
        self::assertGreaterThan(0, $documentId);

        $rows = $this->artifacts->listForDocument($documentId, self::TENANT);
        self::assertCount(1, $rows, 'the file answer must have produced exactly one artifact');

        $artifact = $rows[0];
        self::assertSame($reference, $artifact['storage_key']);
        self::assertSame('application/pdf', $artifact['content_type']);
        self::assertSame(strlen(self::PAPER), $artifact['byte_size']);
        self::assertSame(hash('sha256', self::PAPER), $artifact['checksum_sha256']);
        // Whoever caused the bytes to exist on this document. For a render that
        // is the renderer; for an attachment it is the person who attached.
        self::assertSame(self::APPLICANT, $artifact['rendered_by']);
    }

    /**
     * The artifact is REACHABLE: the key on the row addresses the bytes that
     * were uploaded.
     *
     * Read back through the same store the document subsystem reads with, so
     * this exercises the actual download path's mechanics rather than the test's
     * own idea of where a file went. A checksum that matched while the object
     * was missing would be a report that produces no papers.
     */
    public function testTheArtifactsBytesAreTheBytesThatWereUploaded(): void
    {
        $formId = $this->publishedFormWithFileField();
        $reference = $this->upload($formId, self::PAPER);
        $this->submit($formId, ['title' => 'On sandstone', 'paper' => $reference]);

        $documentId = (int) $this->onlySubmission()['document_id'];
        $artifact = $this->artifacts->listForDocument($documentId, self::TENANT)[0];

        $store = new DocumentArtifactStore($this->storage);
        self::assertSame(self::PAPER, $store->get((string) $artifact['storage_key']));
    }

    /** The answer stored on the submission is the reference, so nothing is lost. */
    public function testTheSubmissionRecordsTheReferenceItAttached(): void
    {
        $formId = $this->publishedFormWithFileField();
        $reference = $this->upload($formId, self::PAPER);
        $this->submit($formId, ['title' => 'On sandstone', 'paper' => $reference]);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->onlySubmission()['data'], true);
        self::assertSame($reference, $data['paper']);
    }

    // ── a storage key is not a capability ────────────────────────────────────

    /**
     * A KEY FROM ANOTHER TENANT CANNOT BE ATTACHED, so it can never be read back
     * through this tenant's document.
     *
     * The attack it rules out is not exotic. Every gate on the artifact-download
     * route asks about the DOCUMENT — is it this tenant's, may this caller see
     * it — and the document really would be theirs. The only thing standing
     * between a caller and another organisation's file is the refusal to mint
     * the artifact row in the first place.
     */
    public function testAKeyBelongingToAnotherTenantCannotBecomeEvidenceHere(): void
    {
        $foreignForm = $this->publishedFormWithFileField(tenantId: self::OTHER_TENANT);
        $foreignReference = $this->upload($foreignForm, self::PAPER, self::OTHER_TENANT, self::SECOND_MEMBER);

        $formId = $this->publishedFormWithFileField();
        $response = $this->submit($formId, ['title' => 'Borrowed', 'paper' => $foreignReference]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('could not find the file', (string) $response->getBody());

        self::assertSame(0, $this->countRows('form_submissions', self::TENANT));
        self::assertSame(0, $this->countRows('document_artifacts', self::TENANT));
        // And the other tenant's upload is untouched — not consumed, not moved.
        self::assertNull($this->uploadRow($foreignReference)['claimed_at']);
    }

    /**
     * A key minted for ONE FORM cannot be spent on another, even inside the same
     * tenant.
     *
     * Narrower than the tenant boundary and cheap to hold: the claim binds
     * `form_id` too, so a broad permission cannot be used to move an attachment
     * between the forms it applies to.
     */
    public function testAKeyMintedForAnotherFormIsRefused(): void
    {
        $otherForm = $this->publishedFormWithFileField();
        $reference = $this->upload($otherForm, self::PAPER);

        $formId = $this->publishedFormWithFileField();
        $response = $this->submit($formId, ['title' => 'Wrong form', 'paper' => $reference]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countRows('document_artifacts', self::TENANT));
    }

    /**
     * ONE MEMBER'S UPLOAD IS NOT ANOTHER MEMBER'S TO ATTACH.
     *
     * Both hold `forms:submit` in the same tenant, so nothing but the uploader
     * predicate separates them. The realistic value is not defence against a
     * colleague — it is that an upload can only ever be spent by the session
     * that made it, so a leaked reference is inert in anybody else's hands.
     */
    public function testAnotherMembersUploadCannotBeAttached(): void
    {
        $formId = $this->publishedFormWithFileField();
        $reference = $this->upload($formId, self::PAPER, self::TENANT, self::SECOND_MEMBER);

        $response = $this->submit($formId, ['title' => 'Not mine', 'paper' => $reference]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countRows('document_artifacts', self::TENANT));
        self::assertNull($this->uploadRow($reference)['claimed_at']);
    }

    /**
     * A FABRICATED KEY IS REFUSED, including one shaped exactly like a real one
     * in this tenant.
     *
     * The shape check in {@see \Whity\Core\Form\SubmissionValidator} passes it
     * happily — by design, that class has no database handle and says so. This
     * asserts that the shape check is not the check.
     */
    public function testAKeyNobodyEverUploadedIsRefused(): void
    {
        $formId = $this->publishedFormWithFileField();
        $invented = 'tenants/1/form-uploads/' . $formId . '/' . str_repeat('ab', 16) . '.pdf';

        $response = $this->submit($formId, ['title' => 'Invented', 'paper' => $invented]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countRows('document_artifacts', self::TENANT));
    }

    /** An upload is spent once. A replayed submission gets nothing. */
    public function testAnUploadIsSingleUse(): void
    {
        $formId = $this->publishedFormWithFileField();
        $reference = $this->upload($formId, self::PAPER);

        self::assertSame(201, $this->submit($formId, ['title' => 'First', 'paper' => $reference])->getStatusCode());
        $replay = $this->submit($formId, ['title' => 'Second', 'paper' => $reference]);

        self::assertSame(422, $replay->getStatusCode());
        self::assertSame(1, $this->countRows('form_submissions', self::TENANT));
        self::assertSame(1, $this->countRows('document_artifacts', self::TENANT));
    }

    // ── nothing survives a rollback ──────────────────────────────────────────

    /**
     * A SUBMISSION THAT ROLLS BACK LEAVES NO ARTIFACT ROW — and leaves the
     * upload spendable, so the person can submit again.
     *
     * The failure is injected where a real one lives: the form names a route
     * template with no stages, so {@see SubmissionIssuer} refuses AFTER the
     * document, the submission and the artifact have been written inside the
     * transaction. That is the exact ordering an artifact row would have to
     * survive to be a bug, which is why the failure is arranged to happen after
     * it rather than before.
     */
    public function testARolledBackSubmissionLeavesNoArtifactRow(): void
    {
        $formId = $this->publishedFormWithFileField($this->emptyRouteTemplate());
        $reference = $this->upload($formId, self::PAPER);

        $response = $this->submit($formId, ['title' => 'Doomed', 'paper' => $reference]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());

        self::assertSame(0, $this->countRows('document_artifacts', self::TENANT), 'no artifact may survive');
        self::assertSame(0, $this->countRows('form_submissions', self::TENANT));
        self::assertSame(0, $this->countRows('documents', self::TENANT));

        // The CLAIM rolled back with everything else. If it had not, the person
        // could never re-submit: their file would be permanently spent on a
        // submission that does not exist.
        self::assertNull($this->uploadRow($reference)['claimed_at']);

        // And the proof that it is genuinely re-usable: fix the form, submit
        // again with the SAME reference, and it lands.
        $this->forms->update(self::TENANT, $formId, ['route_template_id' => null]);
        self::assertSame(
            201,
            $this->submit($formId, ['title' => 'Retried', 'paper' => $reference])->getStatusCode()
        );
        self::assertSame(1, $this->countRows('document_artifacts', self::TENANT));
    }

    // ── the limits are the application's ─────────────────────────────────────

    /**
     * AN OVERSIZED FILE IS REFUSED AT THE ENDPOINT, with the ceiling named.
     *
     * Driven against the PUBLIC surface so the body allocated here is 5 MiB
     * rather than 10 — the exact-boundary arithmetic is held in
     * {@see \Tests\Unit\Core\Form\FormUploadPolicyTest}, which needs no HTTP
     * request to do it. What THIS test adds is that the endpoint applies the
     * policy at all, and that the refusal is a 422 with a sentence rather than a
     * parser error or an ini failure.
     */
    public function testAnOversizedUploadIsRefusedByTheEndpoint(): void
    {
        $slug = $this->openPublicLink($this->publishedFormWithFileField());
        $tooBig = self::PAPER . str_repeat("\0", FormUploadPolicy::PUBLIC_MAX_BYTES);

        self::leaveTenant();
        $response = $this->public->upload(
            self::multipart($tooBig, 'enormous.pdf', 'application/pdf'),
            ['slug' => $slug],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('too large', (string) $response->getBody());
        self::assertSame(0, $this->countRows('form_uploads', self::TENANT));
    }

    /**
     * A WRONG CONTENT TYPE IS REFUSED, and the label is not what makes it wrong.
     *
     * The bytes are a ZIP — which is also what a .docx, a .jar and a zip bomb
     * are — and the part declares `application/pdf`. Nothing about the label
     * gets it through.
     */
    public function testAWrongContentTypeIsRefused(): void
    {
        $formId = $this->publishedFormWithFileField();

        $this->enterTenant(self::TENANT);
        $response = $this->uploads->upload(
            self::multipart("PK\x03\x04\x14\x00\x00\x00zip", 'paper.pdf', 'application/pdf', self::APPLICANT),
            ['id' => (string) $formId],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('PDF, PNG or JPEG', (string) $response->getBody());
        self::assertSame(0, $this->countRows('form_uploads', self::TENANT));
    }

    /**
     * An upload against a form that asks for no file is refused.
     *
     * `forms:submit` is the everyday permission of the largest audience in the
     * tenant, so without this the endpoint is a general write into the tenant's
     * storage that any of them can aim at any form id.
     */
    public function testUploadingToAFormThatAsksForNoFileIsRefused(): void
    {
        $formId = $this->publishedFormWithoutFileField();

        $this->enterTenant(self::TENANT);
        $response = $this->uploads->upload(
            self::multipart(self::PAPER, 'paper.pdf', 'application/pdf', self::APPLICANT),
            ['id' => (string) $formId],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('does not ask for a file', (string) $response->getBody());
        self::assertSame(0, $this->countRows('form_uploads', self::TENANT));
    }

    /** A form that is not accepting submissions is not accepting attachments either. */
    public function testUploadingToADraftFormIsRefused(): void
    {
        $formId = $this->draftFormWithFileField();

        $this->enterTenant(self::TENANT);
        $response = $this->uploads->upload(
            self::multipart(self::PAPER, 'paper.pdf', 'application/pdf', self::APPLICANT),
            ['id' => (string) $formId],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countRows('form_uploads', self::TENANT));
    }

    /** A form in another tenant is a 404, not a storage write. */
    public function testUploadingToAnotherTenantsFormIsNotFound(): void
    {
        $foreign = $this->publishedFormWithFileField(tenantId: self::OTHER_TENANT);

        $this->enterTenant(self::TENANT);
        $response = $this->uploads->upload(
            self::multipart(self::PAPER, 'paper.pdf', 'application/pdf', self::APPLICANT),
            ['id' => (string) $foreign],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->countRows('form_uploads', self::OTHER_TENANT));
    }

    // ── the public surface ───────────────────────────────────────────────────

    /**
     * AN EXTERNAL APPLICANT ATTACHES THEIR PAPER TO A PUBLIC FORM, and the
     * document it mints carries the artifact.
     *
     * This is the case the whole feature was built for and the one a public form
     * could not serve while `file` fields were stripped from it.
     */
    public function testAnAnonymousApplicantCanAttachAPaperAndItBecomesEvidence(): void
    {
        $formId = $this->publishedFormWithFileField();
        $slug = $this->openPublicLink($formId);

        self::leaveTenant();
        $uploaded = $this->public->upload(
            self::multipart(self::PAPER, 'paper.pdf', 'application/pdf'),
            ['slug' => $slug],
        );
        self::assertSame(201, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        $reference = self::data($uploaded)['reference'];
        self::assertIsString($reference);

        self::leaveTenant();
        $submitted = $this->public->submit(
            self::json(['data' => ['title' => 'On sandstone', 'paper' => $reference]]),
            ['slug' => $slug],
        );
        self::assertSame(201, $submitted->getStatusCode(), (string) $submitted->getBody());

        $submission = $this->onlySubmission();
        self::assertNull($submission['submitted_by_profile_id'], 'a public submission names nobody');

        $rows = $this->artifacts->listForDocument((int) $submission['document_id'], self::TENANT);
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', self::PAPER), $rows[0]['checksum_sha256']);
        self::assertSame(strlen(self::PAPER), $rows[0]['byte_size']);
        // No submitter, so nobody to name as having produced the bytes — the
        // same null `documents.created_by` carries on the public path.
        self::assertNull($rows[0]['rendered_by']);
    }

    /**
     * A `file` FIELD IS NOW SERVED PUBLICLY, and a form carrying one may be
     * opened to the public.
     *
     * Both halves are asserted because they are two different mechanisms: the
     * author-facing refusal in `enablePublicLink` and the structural filter in
     * {@see \Whity\Core\Form\PublicFormView}. Either one left behind would make
     * the feature unreachable from the outside for a different reason.
     */
    public function testAPublicFormMayAskForAFile(): void
    {
        $slug = $this->openPublicLink($this->publishedFormWithFileField());

        self::leaveTenant();
        $rendered = $this->public->render(self::json([]), ['slug' => $slug]);
        self::assertSame(200, $rendered->getStatusCode());

        $types = array_column(self::asList(self::data($rendered)['fields']), 'field_type');
        self::assertContains(FieldType::FILE, $types, 'a file field must reach the public renderer');
    }

    /** An anonymous upload cannot be spent by a signed-in member, or vice versa. */
    public function testAnAnonymousUploadCannotBeAttachedByAMember(): void
    {
        $formId = $this->publishedFormWithFileField();
        $slug = $this->openPublicLink($formId);

        self::leaveTenant();
        $uploaded = $this->public->upload(
            self::multipart(self::PAPER, 'paper.pdf', 'application/pdf'),
            ['slug' => $slug],
        );
        $reference = (string) self::data($uploaded)['reference'];

        $response = $this->submit($formId, ['title' => 'Adopted', 'paper' => $reference]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countRows('document_artifacts', self::TENANT));
    }

    // ── the orphan sweep ─────────────────────────────────────────────────────

    /**
     * THE SWEEP DELETES WHAT NOBODY SUBMITTED AND SPARES WHAT SOMEBODY DID —
     * rows and objects alike.
     *
     * The spared half is the assertion that matters. A sweep that deleted a
     * claimed upload's object would leave a `document_artifacts` row pointing at
     * an empty address: a record that says a paper exists, and a 404 the first
     * time anybody opens it.
     */
    public function testTheSweepDeletesOnlyWhatNobodyEverSubmitted(): void
    {
        $formId = $this->publishedFormWithFileField();

        $attached = $this->upload($formId, self::PAPER);
        $this->submit($formId, ['title' => 'Kept', 'paper' => $attached]);

        $abandoned = $this->upload($formId, self::PAPER . 'second');

        // BOTH rows are backdated past the TTL, so age is not what separates
        // them — `claimed_at IS NULL` is, which is the predicate under test. A
        // fixture where only the abandoned row were old would pass even if the
        // sweep ignored `claimed_at` entirely.
        $this->backdateUploads(7200);

        $result = (new FormUploadSweeper($this->uploadRows, $this->storage))->sweep(3600);

        self::assertSame(1, $result['swept']);
        self::assertSame(0, $result['unreachable']);

        self::assertFalse($this->storage->exists($abandoned), 'the abandoned object must be gone');
        self::assertTrue($this->storage->exists($attached), 'the attached object must survive');

        self::assertSame(1, $this->countRows('form_uploads', self::TENANT));
        self::assertSame(1, $this->countRows('document_artifacts', self::TENANT));
    }

    /** A fresh upload is not swept out from under somebody still filling the form in. */
    public function testTheSweepSparesAnUploadInsideItsTtl(): void
    {
        $formId = $this->publishedFormWithFileField();
        $reference = $this->upload($formId, self::PAPER);

        $result = (new FormUploadSweeper($this->uploadRows, $this->storage))
            ->sweep(FormUploadSweeper::DEFAULT_TTL_SECONDS);

        self::assertSame(0, $result['swept']);
        self::assertTrue($this->storage->exists($reference));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Upload one file through the authenticated endpoint and return its
     * reference, asserting the call succeeded.
     */
    private function upload(
        int $formId,
        string $bytes,
        int $tenantId = self::TENANT,
        int $profileId = self::APPLICANT,
    ): string {
        $this->enterTenant($tenantId);
        $response = $this->uploads->upload(
            self::multipart($bytes, 'paper.pdf', 'application/pdf', $profileId),
            ['id' => (string) $formId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $reference = self::data($response)['reference'];
        self::assertIsString($reference);
        self::assertStringStartsWith('tenants/' . $tenantId . '/form-uploads/' . $formId . '/', $reference);

        return $reference;
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function submit(int $formId, array $answers, int $tenantId = self::TENANT): Response
    {
        $this->enterTenant($tenantId);
        $request = self::json(['data' => $answers]);
        $request->user = (object) ['profile_id' => self::APPLICANT];

        return $this->submissions->submit($request, ['id' => (string) $formId]);
    }

    /**
     * Enter one tenant's context for the next handler call.
     *
     * {@see TenantContext::setTenantId()} LOCKS on first use — deliberately, so
     * nothing downstream in a real request can move the boundary underneath a
     * handler. A test that drives several requests in one process therefore has
     * to reset between them, exactly as {@see \Whity\Http\HttpKernel} does at the
     * start of every request. Doing it in one helper rather than at each call
     * site is what keeps a forgotten reset from showing up as a 403 that reads
     * like a permissions bug.
     */
    private function enterTenant(int $tenantId): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
    }

    /**
     * Leave any tenant context, for a call that must resolve its own.
     *
     * The public handler derives the tenant FROM THE SLUG and locks it itself,
     * so arriving with a context already locked is not a state a real
     * unauthenticated request can be in.
     */
    private static function leaveTenant(): void
    {
        TenantContext::reset();
    }

    /**
     * A multipart request carrying one part named `file`.
     *
     * Built as a raw body rather than through $_FILES, which is the path
     * {@see \Whity\Sdk\Http\Request::getUploadedFiles()} takes when the body is
     * present — the same parser production uses when PHP has not already drained
     * the input.
     */
    private static function multipart(
        string $bytes,
        string $filename,
        string $contentType,
        ?int $profileId = null,
    ): Request {
        $boundary = '----WhityFormUpload' . bin2hex(random_bytes(6));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: {$contentType}\r\n"
            . "\r\n"
            . $bytes
            . "\r\n--{$boundary}--\r\n";

        $request = new Request('POST', '/x', [
            'Content-Type' => "multipart/form-data; boundary={$boundary}",
        ], $body);
        if ($profileId !== null) {
            $request->user = (object) ['profile_id' => $profileId];
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body): Request
    {
        return new Request(
            'POST',
            '/x',
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private function publishedFormWithFileField(
        ?int $routeTemplateId = null,
        int $tenantId = self::TENANT,
    ): int {
        $id = $this->draftFormWithFileField($tenantId);
        if ($routeTemplateId !== null) {
            $this->forms->update($tenantId, $id, ['route_template_id' => $routeTemplateId]);
        }
        $this->forms->transition($tenantId, $id, 'draft', 'published');

        return $id;
    }

    private function draftFormWithFileField(int $tenantId = self::TENANT): int
    {
        $id = $this->forms->create(
            $tenantId,
            'paper-registration-' . bin2hex(random_bytes(4)),
            ['en' => 'Register a published paper'],
            'Attach the published version.',
            null,
            self::APPLICANT,
        );
        $this->addField($id, 'title', FieldType::TEXT, $tenantId, true);
        $this->addField($id, 'paper', FieldType::FILE, $tenantId, true);

        return $id;
    }

    private function publishedFormWithoutFileField(int $tenantId = self::TENANT): int
    {
        $id = $this->forms->create(
            $tenantId,
            'no-file-' . bin2hex(random_bytes(4)),
            ['en' => 'A form with nothing to attach'],
            null,
            null,
            self::APPLICANT,
        );
        $this->addField($id, 'title', FieldType::TEXT, $tenantId, true);
        $this->forms->transition($tenantId, $id, 'draft', 'published');

        return $id;
    }

    private function addField(
        int $formId,
        string $key,
        string $type,
        int $tenantId,
        bool $required,
    ): void {
        $this->fields->create(
            $tenantId,
            $formId,
            $key,
            $type,
            ['en' => ucfirst(str_replace('_', ' ', $key))],
            null,
            $required,
            [],
            [],
            null,
            null,
            null,
        );
    }

    /**
     * A route template with NO STAGES — the smallest way to make issuing fail
     * after the submission and its artifacts are already written.
     */
    private function emptyRouteTemplate(): int
    {
        return (new RouteTemplateRepository($this->pdo))
            ->create(self::TENANT, 'Unfinished ' . bin2hex(random_bytes(3)), null, self::APPLICANT);
    }

    private function openPublicLink(int $formId): string
    {
        $this->enterTenant(self::TENANT);
        $request = self::json([]);
        $request->user = (object) ['profile_id' => self::APPLICANT];

        $response = $this->manage->enablePublicLink($request, ['id' => (string) $formId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $slug = self::data($response)['public_slug'];
        self::assertIsString($slug);

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlySubmission(): array
    {
        $statement = $this->pdo->query('SELECT * FROM form_submissions ORDER BY id ASC');
        self::assertNotFalse($statement);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadRow(string $storageKey): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM form_uploads WHERE storage_key = :key');
        $statement->execute([':key' => $storageKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * Age every upload row by $seconds.
     *
     * Written as a plain UPDATE with a computed literal rather than by
     * manipulating a clock: the sweep reads `created_at` from the database and
     * compares it against a string this process formats, so moving the rows is
     * the only move that exercises the same comparison both engines will make.
     */
    private function backdateUploads(int $seconds): void
    {
        $when = date('Y-m-d H:i:s', time() - $seconds);
        $statement = $this->pdo->prepare('UPDATE form_uploads SET created_at = :when');
        $statement->execute([':when' => $when]);
    }

    private function countRows(string $table, int $tenantId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE tenant_id = :tenant_id");
        $statement->execute([':tenant_id' => $tenantId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $decoded);
        /** @var array<string, mixed> $data */
        $data = $decoded['data'];

        return $data;
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function asList(mixed $value): array
    {
        self::assertIsArray($value);
        /** @var list<array<string, mixed>> $list */
        $list = array_values($value);

        return $list;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? self::removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Institute of Records', 'institute')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Institute', 'other')");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Layla Haddad', 'x', false, 0, 0, NOW(), NOW()),
                   (11, 'Omar Nasser',  'x', false, 0, 0, NOW(), NOW())
        ");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (100, 'researcher', '', 1, NOW()),
                           (101, 'researcher', '', 2, NOW())");
        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
            VALUES (10, 1, 100, NULL, true, 'active', NOW()),
                   (11, 1, 100, NULL, false, 'active', NOW()),
                   (11, 2, 101, NULL, true, 'active', NOW())
        ");

        return $pdo;
    }
}
