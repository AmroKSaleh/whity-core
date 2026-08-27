<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FormsApiHandler;
use Whity\Api\PublicFormsApiHandler;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\FormUploadRepository;
use Whity\Core\Form\FormUploadStore;
use Whity\Core\Form\PrefillResolver;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Storage\LocalStorageDriver;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * The UNAUTHENTICATED end of a public form (migration 132).
 *
 * Two properties are under test and they pull against each other, exactly as
 * they do on {@see \Tests\Api\DocumentVerificationApiRealEngineTest}:
 *
 *   IT MUST WORK. An external applicant with no account has to be able to read
 *   the form and file it, and a submission that reaches nobody is the failure
 *   this whole subsystem is written against.
 *
 *   IT MUST NOT BE AN ORACLE. A wrong slug, a malformed one, a form whose link
 *   was closed and a form that is not published must be indistinguishable, so
 *   nobody can ask this endpoint which forms exist, which organisations use it,
 *   or whether a withdrawn link used to work.
 *
 * The oracle assertions compare the four responses TO EACH OTHER rather than
 * each to a literal written here. A set of literals would still pass if all four
 * drifted the same way; comparing them makes "these are indistinguishable" the
 * actual assertion.
 *
 * The second tenant in the fixture is not decoration. Half the point of the
 * feature is that the tenant comes from the SLUG and never from the caller, and
 * that claim is only testable when there is more than one tenant for a request
 * to be aimed at.
 */
final class PublicFormsApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const AUTHOR = 10;
    private const APPROVER = 11;

    private PDO $pdo;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormsApiHandler $manage;
    private PublicFormsApiHandler $public;
    private RouteRecipientRepository $recipients;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();

        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $submissions = new FormSubmissionRepository($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/pubforms-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0o777, true);

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $rules = new RoutingRuleRegistry();
        // Wired exactly as public/index.php wires it. A stub would let a route
        // pass here that could not be authored in production.
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

        $this->recipients = new RouteRecipientRepository($this->pdo);

        // Migration 133's two collaborators. Wired here rather than stubbed
        // because the whole suite is about a handler behaving as it does in
        // production, and a SubmissionIssuer that could not attach a file is a
        // different object from the one public/index.php builds.
        $artifacts = new DocumentArtifactRepository($this->pdo);
        $storage = new LocalStorageDriver($this->storageRoot);
        $uploadRows = new FormUploadRepository($this->pdo);

        $issuer = new SubmissionIssuer(
            $this->pdo,
            $submissions,
            new DocumentIssuer(
                $this->pdo,
                new DocumentRepository($this->pdo),
                $artifacts,
                new DocumentArtifactStore($storage),
            ),
            new RouteTemplateRepository($this->pdo),
            new DocumentRouter(
                $this->pdo,
                new RouteRepository($this->pdo),
                new RouteStepRepository($this->pdo),
                new RouteEventRepository($this->pdo),
                $this->recipients,
                new RouteEdgeRepository($this->pdo),
                $rules,
                $settings,
                null
            ),
            $uploadRows,
            $artifacts,
        );

        $this->manage = new FormsApiHandler(
            $this->forms,
            $this->fields,
            new FormRenderer($this->fields, new PrefillResolver($this->pdo)),
            $submissions,
            new PublicFormLink('https://records.example.test'),
        );

        $this->public = new PublicFormsApiHandler(
            $this->forms,
            $this->fields,
            $issuer,
            new DatabaseSharedStore($this->pdo),
            new FormUploadStore($storage, $uploadRows),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── it works ─────────────────────────────────────────────────────────────

    /** A live slug renders the form a stranger is meant to fill in. */
    public function testALiveSlugRendersTheForm(): void
    {
        $slug = $this->openLink($this->publishedForm());

        $data = self::data($this->render($slug));

        self::assertSame($slug, $data['slug']);
        self::assertTrue($data['accepts_submissions']);
        self::assertSame(
            ['full_name', 'why'],
            array_column(self::asList($data['fields']), 'field_key')
        );
    }

    /**
     * AN ANONYMOUS SUBMIT SUCCEEDS, and the row it writes names nobody.
     *
     * `submitted_by_profile_id IS NULL` is asserted against the DATABASE rather
     * than against the response, because the response deliberately does not
     * carry it — the column is where the claim actually lives.
     */
    public function testAnAnonymousSubmissionIsRecordedWithNoSubmitter(): void
    {
        $formId = $this->publishedForm();
        $slug = $this->openLink($formId);

        $response = $this->submit($slug, ['full_name' => 'Layla Haddad', 'why' => 'Archive access']);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue(self::data($response)['received']);

        $row = $this->onlySubmission();
        self::assertNull($row['submitted_by_profile_id'], 'a public submission names nobody');
        self::assertSame(self::TENANT, (int) $row['tenant_id'], 'the tenant came from the slug');
        // Canonicalising, not assertSame: PostgreSQL stores jsonb PARSED and
        // normalises an object's key order (shortest first, then bytewise), so
        // `{full_name, why}` reads back as `{why, full_name}` there and in
        // insertion order on the SQLite the unit shard builds its schema on.
        // Migration 118's docblock records the same trap. Nothing reads these
        // keys positionally — an answer is looked up BY NAME — so the order is
        // not a fact worth asserting, and asserting it splits the two engines.
        self::assertEqualsCanonicalizing(
            ['full_name' => 'Layla Haddad', 'why' => 'Archive access'],
            json_decode((string) $row['data'], true)
        );
    }

    /**
     * A public submission CIRCULATES, and the person it reaches was chosen by
     * the organisation rather than by the caller.
     *
     * This is the whole argument for letting the public path route at all: the
     * external applicant files a request, and it lands in front of the approver
     * the tenant named on the form's route template. The caller supplied only
     * answers — there is no request field that could have named a template.
     */
    public function testAPublicSubmissionRoutesToTheRecipientTheFormNamed(): void
    {
        $formId = $this->publishedForm($this->routeTemplateNaming(self::APPROVER));
        $slug = $this->openLink($formId);

        $response = $this->submit($slug, ['full_name' => 'Layla Haddad', 'why' => 'Archive access']);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue(self::meta($response)['routed']);

        $documentId = (int) $this->onlySubmission()['document_id'];
        self::assertGreaterThan(0, $documentId);
        self::assertTrue(
            $this->recipients->hasAnyForProfile(self::TENANT, $documentId, self::APPROVER),
            'the approver the FORM named must have been reached'
        );
    }

    /**
     * The document a public submission mints has NO author, and that is what
     * keeps it out of the wrong hands rather than into them.
     *
     * `documents.created_by` is null, so
     * {@see \Whity\Core\Document\DocumentVisibilityPolicy}'s "is this mine" test
     * — an identity comparison against an int caller id — can never match, and
     * an anonymous document is never accidentally somebody's.
     */
    public function testTheDocumentAPublicSubmissionMintsHasNoAuthor(): void
    {
        $slug = $this->openLink($this->publishedForm($this->routeTemplateNaming(self::APPROVER)));
        $this->submit($slug, ['full_name' => 'Layla Haddad']);

        $statement = $this->pdo->query('SELECT created_by, origin_ou_id FROM documents');
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertNull($row['created_by']);
        self::assertNull($row['origin_ou_id'], 'no membership to derive a unit from, and none invented');
    }

    // ── it is not an oracle ──────────────────────────────────────────────────

    /**
     * FOUR REASONS THERE IS NOTHING HERE, ONE ANSWER.
     *
     * Compared to each other, not to a literal: the assertion being made is
     * "indistinguishable", and two literals that drifted together would still
     * satisfy a pair of literal comparisons.
     */
    public function testEveryWayThereIsNoPublicFormAnswersIdentically(): void
    {
        $unknown = $this->render(str_repeat('a', 64));
        $malformed = $this->render('not-a-slug');

        $closedFormId = $this->publishedForm();
        $closedSlug = $this->openLink($closedFormId);
        $this->manage->disablePublicLink($this->request('DELETE', '/x'), ['id' => (string) $closedFormId]);
        $closed = $this->render($closedSlug);

        $draftFormId = $this->publishedForm();
        $draftSlug = $this->openLink($draftFormId);
        $this->pdo->exec('UPDATE forms SET status = ' . "'draft'" . ' WHERE id = ' . $draftFormId);
        $unpublished = $this->render($draftSlug);

        foreach ([$malformed, $closed, $unpublished] as $other) {
            self::assertSame($unknown->getStatusCode(), $other->getStatusCode());
            self::assertSame($unknown->getBody(), $other->getBody());
        }
        self::assertSame(404, $unknown->getStatusCode());
    }

    /** An unpublished form 404s even while its slug is still on the row. */
    public function testAnUnpublishedFormIsNotServedPublicly(): void
    {
        $formId = $this->publishedForm();
        $slug = $this->openLink($formId);
        $this->pdo->exec('UPDATE forms SET status = ' . "'archived'" . ' WHERE id = ' . $formId);

        self::assertSame(404, $this->render($slug)->getStatusCode());
        self::assertSame(404, $this->submit($slug, ['full_name' => 'X'])->getStatusCode());
    }

    /** A wrong slug refuses the SUBMIT too, with the same 404 the render gives. */
    public function testAWrongSlugCannotSubmit(): void
    {
        $this->openLink($this->publishedForm());

        $response = $this->submit(str_repeat('b', 64), ['full_name' => 'X']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->submissionCount());
    }

    // ── what it does not disclose ────────────────────────────────────────────

    /**
     * THE KEY SET IS ASSERTED EXACTLY, because the risk is a field added later
     * that nobody weighed. Every key here had to earn its place against "a
     * stranger who found this link in a forwarded email".
     */
    public function testThePublicRenderDisclosesOnlyWhatDrawingTheFormNeeds(): void
    {
        $slug = $this->openLink($this->publishedForm($this->routeTemplateNaming(self::APPROVER)));

        $data = self::data($this->render($slug));

        self::assertSame(
            ['slug', 'name', 'description', 'fields', 'sections', 'accepts_submissions', 'opens_at', 'closes_at'],
            array_keys($data)
        );
        self::assertSame(
            [
                'field_key', 'field_type', 'label', 'help_text', 'is_required',
                'options', 'validation', 'section_key', 'position', 'multi_valued',
            ],
            array_keys(self::asList($data['fields'])[0])
        );
    }

    /**
     * AN ANONYMOUS CALLER GETS NO PREFILL — and the response carries no key that
     * could ever hold one.
     *
     * Asserted on the SHAPE rather than on emptiness: a `prefill` key that
     * happened to be empty today would be a key somebody could fill tomorrow.
     * The form under test names a backed prefill source that resolves to a real
     * value for a real person, so the absence is not because there was nothing
     * to resolve.
     */
    public function testAnAnonymousCallerIsGivenNoPrefillAndNoPlaceToPutOne(): void
    {
        $formId = $this->publishedForm();
        $this->addField($formId, 'contact', 'text', prefillSource: 'profile.display_name');
        $slug = $this->openLink($formId);

        $data = self::data($this->render($slug));

        self::assertArrayNotHasKey('prefill', $data);
        self::assertArrayNotHasKey('unresolved_prefill', $data);
        foreach (self::asList($data['fields']) as $field) {
            self::assertArrayNotHasKey('prefill_source', $field);
            self::assertArrayNotHasKey('prefill_backed', $field);
            self::assertArrayNotHasKey('default', $field);
        }

        // The same source DOES resolve for a signed-in person, so the emptiness
        // above is a property of the public path rather than of the fixture.
        self::assertSame(
            'Fatima Al-Amin',
            (new PrefillResolver($this->pdo))->resolveOne(self::TENANT, self::AUTHOR, 'profile.display_name')
        );
    }

    // ── the tenant comes from the slug ───────────────────────────────────────

    /**
     * A HEADER THE CALLER CHOSE CANNOT MOVE THE SUBMISSION.
     *
     * The request declares tenant 2 in every way an anonymous caller could; the
     * row lands in tenant 1, which is the tenant the SLUG named. There is no
     * assertion here about the middleware — the middleware never runs on a public
     * route, which is exactly why the handler has to be the thing that is right.
     */
    public function testADeclaredTenantHeaderCannotRedirectAPublicSubmission(): void
    {
        $slug = $this->openLink($this->publishedForm());

        $request = new Request(
            'POST',
            '/api/v1/public/forms/' . $slug . '/submissions?tenant_id=' . self::OTHER_TENANT,
            [
                'X-Whity-Client-Ip' => '198.51.100.7',
                'X-Tenant-Id' => (string) self::OTHER_TENANT,
                'Host' => 'other-tenant.example.test',
            ],
            json_encode(['data' => ['full_name' => 'Layla Haddad']], JSON_THROW_ON_ERROR)
        );
        TenantContext::reset();
        $response = $this->public->submit($request, ['slug' => $slug]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(self::TENANT, (int) $this->onlySubmission()['tenant_id']);
    }

    /** The other tenant's identical form is untouched by the first tenant's slug. */
    public function testASlugResolvesToExactlyOneTenantsForm(): void
    {
        $slug = $this->openLink($this->publishedForm());
        $this->publishedForm(tenantId: self::OTHER_TENANT);

        $this->submit($slug, ['full_name' => 'Layla Haddad']);

        $statement = $this->pdo->query('SELECT tenant_id FROM form_submissions');
        self::assertNotFalse($statement);
        self::assertSame([self::TENANT], array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    // ── the fields a stranger may be asked ───────────────────────────────────

    /**
     * A PERSON FIELD IS NOT SERVED, AND ITS ANSWER IS NOT CHECKED.
     *
     * The check is the point. {@see SubmissionIssuer} refuses a `profile_ref`
     * naming a row this tenant does not have — correct on the authenticated
     * path, a membership oracle on this one. So the field never reaches the
     * validator: it is absent from the render and its answer is reported as
     * ignored rather than refused, which means an anonymous caller gets the SAME
     * answer for a profile id that is a member and one that is not.
     */
    public function testAPersonFieldIsNeitherServedNorCheckedOnThePublicPath(): void
    {
        $formId = $this->publishedForm();
        $slug = $this->openLink($formId);
        // Added AFTER the link was opened — the enable-time refusal cannot have
        // caught it, so this is the structural half doing the work.
        $this->addField($formId, 'sponsor', 'profile_ref');

        $rendered = self::data($this->render($slug));
        self::assertNotContains('sponsor', array_column(self::asList($rendered['fields']), 'field_key'));

        $member = $this->submit($slug, ['full_name' => 'A', 'sponsor' => self::APPROVER]);
        $stranger = $this->submit($slug, ['full_name' => 'A', 'sponsor' => 999999]);

        self::assertSame($member->getStatusCode(), $stranger->getStatusCode());
        self::assertSame(201, $member->getStatusCode());
        self::assertSame(['sponsor'], self::meta($member)['ignored_keys']);
        self::assertSame(self::meta($member)['ignored_keys'], self::meta($stranger)['ignored_keys']);

        foreach ($this->allSubmissions() as $row) {
            self::assertArrayNotHasKey(
                'sponsor',
                json_decode((string) $row['data'], true),
                'nothing a stranger sent for an unserved field is stored'
            );
        }
    }

    /**
     * A LIVE PUBLIC FORM THAT HAS ENDED UP ASKING NOTHING REFUSES, rather than
     * collecting empty submissions and reporting success.
     *
     * Reachable only after the link is open — opening one on a form with no
     * answerable field is refused — but an author retyping the last text field
     * as a person field gets there, and the surface must fail closed when they
     * do. Asserted on BOTH ends, because a render that draws a submit button
     * over an endpoint that refuses is its own failure.
     */
    public function testALivePublicFormWithNothingLeftToAskRefusesInsteadOfCollectingNothing(): void
    {
        $formId = $this->publishedForm();
        $slug = $this->openLink($formId);

        // Every answerable field becomes one this surface cannot serve.
        $this->pdo->exec(
            "UPDATE form_fields SET field_type = 'profile_ref' WHERE form_id = {$formId}"
        );

        $rendered = self::data($this->render($slug));
        self::assertSame([], $rendered['fields']);
        self::assertFalse($rendered['accepts_submissions'], 'no questions means no submit button');

        self::assertSame(422, $this->submit($slug, [])->getStatusCode());
        self::assertSame(0, $this->submissionCount());
    }

    /** Opening a link on a form carrying a person field is refused, naming it. */
    public function testOpeningALinkIsRefusedOnAFormThatAsksForAPerson(): void
    {
        $formId = $this->publishedForm();
        $this->addField($formId, 'sponsor', 'profile_ref');

        $response = $this->manage->enablePublicLink($this->request('POST', '/x'), ['id' => (string) $formId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('sponsor', (string) $response->getBody());
        self::assertNull($this->formRow($formId)['public_slug']);
    }

    /**
     * A `file` FIELD NO LONGER BLOCKS THE DOOR (migration 133).
     *
     * It used to, and the reason it did was never that a file input is unsafe on
     * a public form — it was that no anonymous upload route existed, so the
     * field would have rendered above a submit button that refused. That premise
     * died with `POST /api/v1/public/forms/{slug}/uploads`, and this asserts the
     * exclusion died with it: an external applicant attaching their published
     * paper is the case public forms exist for, and it was the one case they
     * could not serve.
     *
     * The person field beside it is UNCHANGED and still refused — the two were
     * never the same case. A picker of real people is a read of the tenant's
     * data; a file input is a write of the caller's own.
     */
    public function testOpeningALinkIsAllowedOnAFormThatAsksForAFile(): void
    {
        $formId = $this->publishedForm();
        $this->addField($formId, 'passport', 'file');

        $response = $this->manage->enablePublicLink($this->request('POST', '/x'), ['id' => (string) $formId]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsString($this->formRow($formId)['public_slug']);
    }

    /** And on a form that is not published, because such a link answers 404. */
    public function testOpeningALinkIsRefusedOnAFormThatIsNotPublished(): void
    {
        $formId = $this->draftForm();

        $response = $this->manage->enablePublicLink($this->request('POST', '/x'), ['id' => (string) $formId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($this->formRow($formId)['public_enabled']);
    }

    // ── the window ───────────────────────────────────────────────────────────

    /**
     * A CLOSED WINDOW RENDERS AND REFUSES, rather than 404ing.
     *
     * The deliberate departure from the blanket 404, and it applies to the window
     * ONLY: somebody holding a genuine link that closed yesterday must be told
     * they are late, not that the link is wrong. It is safe to say so because
     * reaching this branch required a live 256-bit slug.
     */
    public function testAFormOutsideItsWindowStillRendersButRefusesSubmissions(): void
    {
        $formId = $this->publishedForm();
        $slug = $this->openLink($formId, closesAt: '2000-01-01 00:00:00');

        $rendered = self::data($this->render($slug));
        self::assertFalse($rendered['accepts_submissions']);
        self::assertSame('2000-01-01 00:00:00', $rendered['closes_at']);

        $submitted = $this->submit($slug, ['full_name' => 'Layla Haddad']);
        self::assertSame(422, $submitted->getStatusCode());
        self::assertSame(0, $this->submissionCount());
    }

    /** A window that has not opened yet behaves the same way. */
    public function testAFormBeforeItsWindowRefusesSubmissions(): void
    {
        $slug = $this->openLink($this->publishedForm(), opensAt: '2999-01-01 00:00:00');

        self::assertFalse(self::data($this->render($slug))['accepts_submissions']);
        self::assertSame(422, $this->submit($slug, ['full_name' => 'X'])->getStatusCode());
    }

    /** A form with no window at all is inside it — both columns null means open. */
    public function testAFormWithNoWindowIsInsideIt(): void
    {
        $slug = $this->openLink($this->publishedForm());

        self::assertTrue(self::data($this->render($slug))['accepts_submissions']);
    }

    // ── the link itself ──────────────────────────────────────────────────────

    /** 64 hex characters from a CSPRNG, and never the same twice. */
    public function testEachLinkIsAFresh256BitSlug(): void
    {
        $first = $this->openLink($this->publishedForm());
        $second = $this->openLink($this->publishedForm());

        foreach ([$first, $second] as $slug) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $slug);
        }
        self::assertNotSame($first, $second);
    }

    /**
     * CLOSING DESTROYS THE ADDRESS, and re-opening does not resurrect it.
     *
     * A withdrawn link that came back when somebody re-opened the form would
     * make "I revoked that" untrue for every copy already in circulation.
     */
    public function testReopeningMintsADifferentAddressAndTheOldOneStaysDead(): void
    {
        $formId = $this->publishedForm();
        $first = $this->openLink($formId);

        $this->manage->disablePublicLink($this->request('DELETE', '/x'), ['id' => (string) $formId]);
        self::assertNull($this->formRow($formId)['public_slug']);

        $second = $this->openLink($formId);

        self::assertNotSame($first, $second);
        self::assertSame(404, $this->render($first)->getStatusCode());
        self::assertSame(200, $this->render($second)->getStatusCode());
    }

    /** Closing an already-closed link is a success that says it changed nothing. */
    public function testClosingIsIdempotent(): void
    {
        $formId = $this->publishedForm();
        $this->openLink($formId);

        $first = $this->manage->disablePublicLink($this->request('DELETE', '/x'), ['id' => (string) $formId]);
        $second = $this->manage->disablePublicLink($this->request('DELETE', '/x'), ['id' => (string) $formId]);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertTrue(self::meta($first)['closed']);
        self::assertFalse(self::meta($second)['closed']);
    }

    /** The address is composed from this instance's own origin, not stored. */
    public function testTheFormCarriesTheAbsolutePublicUrl(): void
    {
        $formId = $this->publishedForm();
        $slug = $this->openLink($formId);

        $response = $this->manage->show($this->request('GET', '/x'), ['id' => (string) $formId]);

        self::assertSame(
            // THE PAGE, not the endpoint. This URL is pasted into an email and
            // opened in a browser, so it has to be an address that renders a
            // form; the API is what that page calls. Changed with the public
            // fill page — before it, a recipient was handed raw JSON.
            'https://records.example.test/f/' . $slug,
            self::data($response)['public_url']
        );
    }

    // ── throttling ───────────────────────────────────────────────────────────

    /**
     * The per-IP submit ceiling bites, and it bites with a `Retry-After`.
     *
     * Defence in depth behind the slug's entropy — at 256 bits nobody is
     * guessing — so what this really bounds is the number of documents and route
     * steps one address can make a tenant store.
     */
    public function testThePublicSubmitIsThrottledPerAddress(): void
    {
        $slug = $this->openLink($this->publishedForm());

        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = $this->submit($slug, ['full_name' => 'Applicant ' . $i]);
        }

        self::assertInstanceOf(Response::class, $last);
        self::assertSame(429, $last->getStatusCode());
        // Header names are normalised to lower case by the SDK response.
        self::assertArrayHasKey('retry-after', $last->getHeaders());
        self::assertSame(20, $this->submissionCount(), 'the ceiling is a ceiling, not a ceiling minus one');
    }

    /**
     * THE THROTTLE IS COUNTED BEFORE THE SLUG IS LOOKED AT.
     *
     * If it were counted after, a limiter that fired later for genuine links
     * than for invented ones would put back — in timing and in reachable
     * request count — exactly the distinction the shared 404 removes from the
     * body.
     */
    public function testAnInventedSlugConsumesTheSameBudgetAsARealOne(): void
    {
        $slug = $this->openLink($this->publishedForm());

        for ($i = 0; $i < 20; $i++) {
            $this->render(str_repeat('c', 64));
        }
        // 100 render attempts on garbage slugs, then the real one: the ceiling is
        // 120 per hour, so the budget must already be 20 lower.
        for ($i = 0; $i < 100; $i++) {
            $this->render('not-a-slug');
        }

        self::assertSame(429, $this->render($slug)->getStatusCode());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('data', $decoded);
        self::assertIsArray($decoded['data']);

        return $decoded['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('meta', $decoded);
        self::assertIsArray($decoded['meta']);

        return $decoded['meta'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function asList(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return array_values($value);
    }

    private function render(string $slug): Response
    {
        TenantContext::reset();

        return $this->public->render(
            new Request('GET', '/api/v1/public/forms/' . $slug, ['X-Whity-Client-Ip' => '198.51.100.4'], ''),
            ['slug' => $slug]
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function submit(string $slug, array $answers): Response
    {
        TenantContext::reset();

        return $this->public->submit(
            new Request(
                'POST',
                '/api/v1/public/forms/' . $slug . '/submissions',
                ['X-Whity-Client-Ip' => '198.51.100.4'],
                json_encode(['data' => $answers], JSON_THROW_ON_ERROR)
            ),
            ['slug' => $slug]
        );
    }

    /**
     * A tenant-scoped request for the MANAGEMENT handler, with the tenant bound
     * the way the middleware would have bound it.
     */
    private function request(string $method, string $path): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $request = new Request($method, $path, [], '');
        $request->user = (object) ['profile_id' => self::AUTHOR];

        return $request;
    }

    private function openLink(int $formId, ?string $opensAt = null, ?string $closesAt = null): string
    {
        $body = [];
        if ($opensAt !== null) {
            $body['opens_at'] = $opensAt;
        }
        if ($closesAt !== null) {
            $body['closes_at'] = $closesAt;
        }

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request('POST', '/x', [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->user = (object) ['profile_id' => self::AUTHOR];

        $response = $this->manage->enablePublicLink($request, ['id' => (string) $formId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $slug = self::data($response)['public_slug'];
        self::assertIsString($slug);

        return $slug;
    }

    /**
     * The stored row, asserted present — so a `?array` never has to be indexed
     * into with a shrug.
     *
     * @return array<string, mixed>
     */
    private function formRow(int $formId, int $tenantId = self::TENANT): array
    {
        $row = $this->forms->find($tenantId, $formId);
        self::assertIsArray($row);

        return $row;
    }

    private function draftForm(int $tenantId = self::TENANT): int
    {
        $id = $this->forms->create(
            $tenantId,
            'research-request-' . bin2hex(random_bytes(4)),
            ['en' => 'Research access request'],
            'Apply for access to the archive.',
            null,
            self::AUTHOR,
        );
        $this->addField($id, 'full_name', 'text', tenantId: $tenantId, required: true);
        $this->addField($id, 'why', 'textarea', tenantId: $tenantId);

        return $id;
    }

    private function publishedForm(?int $routeTemplateId = null, int $tenantId = self::TENANT): int
    {
        $id = $this->draftForm($tenantId);
        if ($routeTemplateId !== null) {
            $this->forms->update($tenantId, $id, ['route_template_id' => $routeTemplateId]);
        }
        $this->forms->transition($tenantId, $id, 'draft', 'published');

        return $id;
    }

    private function addField(
        int $formId,
        string $key,
        string $type,
        int $tenantId = self::TENANT,
        bool $required = false,
        ?string $prefillSource = null,
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
            $prefillSource,
            null,
            null,
        );
    }

    /**
     * A one-stage template naming one person explicitly — the smallest design
     * that actually reaches somebody.
     */
    private function routeTemplateNaming(int $profileId): int
    {
        $templates = new RouteTemplateRepository($this->pdo);
        $id = $templates->create(self::TENANT, 'Archive approval ' . bin2hex(random_bytes(3)), null, self::AUTHOR);
        $templates->replaceGraph($id, self::TENANT, [[
            'position' => 1,
            'rule_kind' => 'explicit',
            'rule_config' => ['profile_ids' => [$profileId]],
            'label' => 'Approval',
            'decision' => false,
            'decision_quorum' => null,
            'satisfied_by' => 'act',
            'canvas_x' => 0,
            'canvas_y' => 0,
        ]], []);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlySubmission(): array
    {
        $rows = $this->allSubmissions();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allSubmissions(): array
    {
        $statement = $this->pdo->query('SELECT * FROM form_submissions ORDER BY id ASC');
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function submissionCount(): int
    {
        return count($this->allSubmissions());
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Ministry of Records', 'ministry')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other Ministry', 'other')");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Fatima Al-Amin', 'x', false, 0, 0, NOW(), NOW()),
                   (11, 'Omar Nasser',    'x', false, 0, 0, NOW(), NOW())
        ");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (100, 'archivist', '', 1, NOW())");
        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
            VALUES (10, 1, 100, NULL, true, 'active', NOW()),
                   (11, 1, 100, NULL, true, 'active', NOW())
        ");

        return $pdo;
    }
}
