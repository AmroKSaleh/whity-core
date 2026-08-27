<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * Turns a validated answer set into a SUBMISSION, a DOCUMENT, and — when the
 * form names a route template — a live circulation of that document.
 *
 * THE WHOLE POINT: A SUBMISSION INHERITS THE DOCUMENT SUBSYSTEM RATHER THAN
 * REBUILDING IT
 * -------------------------------------------------------------------------
 * The moment a submission becomes a `documents` row it acquires, for free and
 * without a line of new code here: routing and approval with quorums and
 * branching (migrations 112/118/119/125), an inbox entry in front of whoever
 * must act ({@see \Whity\Core\Document\Routing\DocumentRoutingInboxSource}),
 * QR verification (migration 122), immutable artifacts (migration 108), and
 * row-level visibility ({@see \Whity\Core\Document\DocumentVisibilityPolicy}).
 *
 * This class writes NO routing logic. It reads the design, hands it to
 * {@see RouteTemplateInstantiation} — the same converter
 * {@see \Whity\Api\DocumentRoutingApiHandler} uses, not a second one — and calls
 * {@see DocumentRouter::issue()}. Any behaviour anybody wants from routing is
 * changed in the routing engine, once, and this path gets it.
 *
 * `raise()` AND NOT `issue()`
 * ---------------------------
 * {@see DocumentIssuer::raise()} mints the record with NO artifact, which is
 * exactly right here and is worth being explicit about, because the sibling
 * method's name is more inviting. A submission has no bytes at the moment it is
 * made: rendering it to PDF requires the render container, which is optional and
 * absent on a fresh install (`documents.render_enabled` defaults to FALSE).
 * Demanding an artifact would mean the only way to submit a form is to run a
 * headless-Chromium service. `POST /api/v1/documents/{id}/render` mints one later
 * from the values on the row, whenever somebody wants a printable copy.
 *
 * ONE TRANSACTION, AND WHICH TORN STATE IS REFUSED
 * -------------------------------------------------
 * The document, the submission row, the attachments and the route are written
 * inside ONE transaction, opened here unless a caller already holds one (the
 * convention {@see DocumentIssuer} and migration 105 both follow). Nothing in
 * this path touches object storage — see the attachment section below for why
 * that stays true now that submissions carry files — so, unlike
 * `DocumentIssuer::issue()`, there is no unjoinable write to reason about and
 * the transaction is total.
 *
 * The state being refused is a SUBMISSION WITH NO DOCUMENT on a form that has a
 * route template. That row would sit in a list looking submitted, reaching
 * nobody, forever, and reporting success — the failure class this codebase keeps
 * writing against. If the route cannot be issued, nothing is written and the
 * person is told, so they still have what they typed.
 *
 * THE ORDER IS DOCUMENT → SUBMISSION → ATTACHMENTS → ROUTE
 * ---------------------------------------------------------
 * The submission needs the document's id; the attachments need both (the
 * artifact hangs off the document, the claim is recorded against the
 * submission); and the route needs the document row. Writing the submission
 * LAST would leave a window in which a route exists carrying a document that no
 * submission explains — harmless inside a transaction, but the ordering also
 * decides what a partial failure looks like to anyone reading the log, and "the
 * route failed, so nothing exists" is the sentence worth being able to write.
 *
 * A `file` ANSWER BECOMES A `document_artifacts` ROW, AND THAT IS THE POINT
 * -------------------------------------------------------------------------
 * A form can ask "upload your paper", and the answer is the storage key of an
 * object {@see FormUploadStore} already wrote (migration 134). Storing that
 * string in `form_submissions.data` and stopping there would leave the file
 * reachable only by somebody who knows to look inside a jsonb column and parse
 * it — so every downstream reader that wants the EVIDENCE (an accreditation
 * report that must produce twelve papers when it says twelve papers) would have
 * to grow its own knowledge of how form answers are shaped.
 *
 * `document_artifacts` is the platform's existing answer to "the stored bytes
 * belonging to this record", with a download route, tenant + document binding
 * and an append-only guarantee already built. So each `file` answer gets a row
 * there, and the evidence is reachable through the same address as every other
 * artifact in the install.
 *
 * THE ANSWER IS NOT TRUSTED, IT IS CLAIMED
 * -----------------------------------------
 * {@see SubmissionValidator} checks a `file` answer for SHAPE and says so — it
 * has no database handle and cannot check anything else. The real check is here,
 * and it is not "does this key look right": it is
 * {@see FormUploadRepository::claim()}, a conditional UPDATE binding this
 * tenant, this form, this uploader and `claimed_at IS NULL`.
 *
 * Which means a `file` answer naming a key under ANOTHER TENANT'S prefix matches
 * no row and is refused. Without that, a caller could submit their own form with
 * `tenants/9/documents/…` as the answer, mint an artifact row on THEIR document
 * pointing at tenant 9's object, and read it back through the ordinary
 * artifact-download route — which would check the document (theirs), the tenant
 * (theirs) and the permission (held) and correctly let them through. Every gate
 * would report success. The claim is what makes the key not a capability.
 *
 * NO BYTES ARE MOVED, WHICH IS WHAT KEEPS THE TRANSACTION TOTAL
 * --------------------------------------------------------------
 * The artifact row keeps the key the upload already has, under
 * `tenants/{t}/form-uploads/…` rather than `tenants/{t}/documents/{id}/…`.
 * Copying the object to a tidier address would put an unjoinable write inside
 * this transaction: roll back and the row is gone while the copy remains. A
 * storage key is an opaque address by {@see \Whity\Storage\StorageDriverInterface}'s
 * own contract, the read path takes it off the row rather than deriving it, and
 * the tenant segment every reader actually reasons about is present either way.
 * So the tidier address would buy nothing and cost the property this class
 * advertises.
 */
final class SubmissionIssuer
{
    /**
     * `$uploads` and `$artifacts` are REQUIRED rather than optional, and the two
     * travel together.
     *
     * An optional collaborator here would mean a construction in which a `file`
     * answer is accepted by the validator, stored in `data`, and quietly
     * attached to nothing — a submission that looks complete and carries no
     * evidence. That is the failure this whole change exists to remove, so it
     * must not be reachable by forgetting an argument. There are three call
     * sites in the repository; all three pass both.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly FormSubmissionRepository $submissions,
        private readonly DocumentIssuer $documents,
        private readonly RouteTemplateRepository $routeTemplates,
        private readonly DocumentRouter $router,
        private readonly FormUploadRepository $uploads,
        private readonly DocumentArtifactRepository $artifacts,
    ) {
    }

    /**
     * Record one submission.
     *
     * @param array<string, mixed>       $form   A normalized `forms` row.
     * @param list<array<string, mixed>> $fields The form's fields, in order.
     * @param array<string, mixed>       $data   The RAW submitted answers.
     *
     * @return array{submission: array<string, mixed>, ignored: list<string>, routed: bool}
     *
     * @throws FormRejectedException When the form is not accepting submissions,
     *         when an answer fails validation, or when the form's route template
     *         cannot be run as drawn.
     */
    public function submit(int $tenantId, ?int $actorProfileId, array $form, array $fields, array $data): array
    {
        $status = (string) ($form['status'] ?? FormStatus::DRAFT);
        if (!FormStatus::acceptsSubmissions($status)) {
            // Named for the person, not for the state machine: "draft" is a word
            // about the author's workflow and means nothing to a submitter.
            throw new FormRejectedException('This form is not accepting submissions right now');
        }

        // Validation BEFORE anything is written, so somebody fixing a ten-field
        // form is told which field is wrong rather than watching a half-recorded
        // submission appear — the same discipline DocumentRouter::issue() applies
        // to its steps.
        $checked = SubmissionValidator::validate($fields, $data);
        $values = $checked['values'];

        // References are resolved against the CALLER'S TENANT, which is what
        // makes a `profile_ref` answer naming somebody in another organisation
        // come back as "not a record in this tenant" rather than being stored as
        // a cross-tenant pointer nothing would ever notice.
        $this->assertReferencesExist($tenantId, $fields, $values);

        $formId = (int) $form['id'];
        $routeTemplateId = $form['route_template_id'] ?? null;
        $routeTemplateId = is_int($routeTemplateId) ? $routeTemplateId : null;

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $title = $this->titleFor($form);

            // The `template` argument is a `document_templates` ROW everywhere
            // else; here there is no such row, and passing a bare `['name' => …]`
            // is exactly right rather than a workaround. DocumentIssuer stores
            // `document_template_id` from `$template['id'] ?? null` (so: NULL) and
            // `template_name` from `$template['name'] ?? ''` as a SNAPSHOT — its
            // own docblock says the name is snapshotted precisely because the
            // template "may be renamed or deleted and the record still has to be
            // able to say what it came from". A form is the same kind of origin.
            $document = $this->documents->raise(
                $tenantId,
                $actorProfileId,
                ['name' => 'form:' . (string) ($form['form_key'] ?? '')],
                $title,
                // `variable_data` stays null. It is the render subsystem's
                // key/value list for a label sheet, not a general side-channel,
                // and duplicating the answers into it would create a second copy
                // of the submission free to drift from the first. The submission
                // row is the authority on what was answered; the document is the
                // authority on where it went.
                null,
            );

            $submissionId = $this->submissions->create(
                $tenantId,
                $formId,
                (int) ($form['version'] ?? 1),
                $actorProfileId,
                (int) $document['id'],
                $values,
            );

            // Attachments BEFORE the route: an artifact that could not be
            // claimed must stop the submission, and stopping it before anybody
            // has been sent an inbox entry is the difference between "nothing
            // happened" and "somebody was asked to approve a paper that is not
            // there". Inside the transaction either way — this is about which
            // sentence the log gets to write.
            $this->attach($tenantId, $actorProfileId, $formId, (int) $document['id'], $submissionId, $fields, $values);

            $routed = false;
            if ($routeTemplateId !== null) {
                $this->route($tenantId, $actorProfileId, $document, $title, $routeTemplateId);
                $routed = true;
            }

            $submission = $this->submissions->find($tenantId, $submissionId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($submission === null) {
            // Written and read back inside one transaction; a null here would
            // mean the row vanished between the two, which is not a state this
            // method can report meaningfully. Same posture DocumentIssuer takes.
            throw new \RuntimeException('The submission was recorded but could not be read back.');
        }

        return ['submission' => $submission, 'ignored' => $checked['ignored'], 'routed' => $routed];
    }

    /**
     * Turn every `file` answer into a `document_artifacts` row on the new
     * document.
     *
     * THE CLAIM IS THE AUTHORIZATION. Nothing here inspects the shape of the
     * key, compares its tenant segment, or asks storage whether an object is
     * there. It asks {@see FormUploadRepository::claim()} to spend an upload
     * belonging to THIS tenant, on THIS form, made by THIS caller, and not yet
     * spent — and a null is every one of those failing. Parsing the key instead
     * would be a check written in this class about a format owned by another,
     * and the day the format gains a segment the check would still pass.
     *
     * ONE REFUSAL SENTENCE, and it is about the file rather than about the
     * system. "We could not find that file — please attach it again" is true for
     * an expired upload, a re-submitted browser tab whose upload was already
     * spent, and a fabricated key alike, and it tells the honest majority
     * exactly what to do. It also declines to tell the dishonest minority which
     * of those they hit.
     *
     * THE ARTIFACT'S NUMBERS COME FROM THE UPLOAD ROW, NEVER FROM THE REQUEST.
     * `byte_size` and `checksum_sha256` were computed by the server over the
     * bytes it stored ({@see FormUploadStore::put()}). A submission body cannot
     * influence them, which is what makes the checksum worth recording at all.
     *
     * `rendered_by` is the SUBMITTER. The column names whoever caused the bytes
     * to exist on this document, which for a render is the person who rendered
     * and for an attachment is the person who attached — null on the public
     * path, exactly as `documents.created_by` and
     * `form_submissions.submitted_by_profile_id` are null there.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $values The NORMALIZED answers.
     *
     * @throws FormRejectedException On the first `file` answer that is not a
     *         live, unspent upload of this caller's on this form.
     */
    private function attach(
        int $tenantId,
        ?int $actorProfileId,
        int $formId,
        int $documentId,
        int $submissionId,
        array $fields,
        array $values,
    ): void {
        foreach ($fields as $field) {
            if ((string) ($field['field_type'] ?? '') !== FieldType::FILE) {
                continue;
            }
            $key = (string) ($field['field_key'] ?? '');
            $answer = $values[$key] ?? null;
            if (!is_string($answer) || $answer === '') {
                // An optional `file` field nobody filled in. The validator has
                // already refused a blank REQUIRED one, so there is nothing to
                // decide here.
                continue;
            }

            $upload = $this->uploads->claim($tenantId, $formId, $answer, $actorProfileId, $submissionId);
            if ($upload === null) {
                /** @var array<string, string> $label */
                $label = is_array($field['label'] ?? null) ? $field['label'] : [];
                $name = LocalizedLabel::preferred($label);
                throw new FormRejectedException(
                    'We could not find the file attached to '
                        . ($name !== '' ? $name : $key)
                        . ' — please attach it again.',
                    'form upload claim failed on field ' . $key . ' in tenant ' . $tenantId,
                );
            }

            $this->artifacts->create($tenantId, [
                'document_id'     => $documentId,
                'storage_key'     => $upload['storage_key'],
                'content_type'    => $upload['content_type'],
                'byte_size'       => $upload['byte_size'],
                'checksum_sha256' => $upload['checksum_sha256'],
                'rendered_by'     => $actorProfileId,
            ]);
        }
    }

    /**
     * Convert the form's route template into a live circulation of the document.
     *
     * No routing logic here — see the class docblock. Both exceptions are
     * re-thrown as {@see FormRejectedException} carrying the ORIGINAL client
     * message: the engine's wording ("this route template has no stages yet…")
     * is already written for the person who can fix it, and rewording it here
     * would replace a specific, actionable sentence with a vague one.
     *
     * @param array<string, mixed> $document
     */
    private function route(int $tenantId, ?int $actorProfileId, array $document, string $title, int $templateId): void
    {
        $template = $this->routeTemplates->findById($templateId, $tenantId);
        if ($template === null) {
            // The template was deleted between the form being authored and this
            // submission. `forms.route_template_id` is ON DELETE SET NULL so this
            // is rare, but a race is still a race — and refusing beats silently
            // recording a submission the author believes will circulate.
            throw new FormRejectedException(
                'This form points at a route template that no longer exists — ask an administrator to update it'
            );
        }

        try {
            $steps = RouteTemplateInstantiation::toRouteSteps(
                $this->routeTemplates->stepsFor($templateId, $tenantId),
                $this->routeTemplates->edgesFor($templateId, $tenantId),
            );
        } catch (RouteTemplateRejectedException $e) {
            throw new FormRejectedException($e->clientMessage, 'route template not runnable: ' . $e->getMessage(), $e);
        }

        try {
            $this->router->issue(
                $tenantId,
                $actorProfileId,
                $document,
                $title,
                $steps,
                $templateId,
                (string) $template['name'],
            );
        } catch (RoutingRejectedException $e) {
            throw new FormRejectedException($e->clientMessage, 'route issue failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * What the resulting document is CALLED.
     *
     * The form's own name, which is what a reader scanning a document list or an
     * inbox needs to see — "Equipment request", not "Submission #4821". The
     * submitter is not in the title: `documents.created_by` already records them
     * and a title that repeated it would be redundant in every list that shows
     * both, while being wrong in the one case that matters (a submission made by
     * a service principal, which has no profile at all).
     *
     * Truncated to 255 to match `documents.title`, so the column and this
     * function agree instead of the database silently truncating what this
     * accepted.
     *
     * @param array<string, mixed> $form
     */
    private function titleFor(array $form): string
    {
        /** @var array<string, string> $name */
        $name = is_array($form['name'] ?? null) ? $form['name'] : [];
        $title = LocalizedLabel::preferred($name);

        if ($title === '') {
            $title = (string) ($form['form_key'] ?? 'Form submission');
        }

        return mb_substr($title, 0, 255);
    }

    /**
     * Refuse a `profile_ref` / `ou_ref` answer naming a row this tenant does not
     * have.
     *
     * Both reads bind `tenant_id`, so an id from another tenant is ABSENT — the
     * answer is refused as "not a record in this tenant" rather than stored as a
     * pointer across an isolation boundary. `profiles` is global, so the tenant
     * predicate goes on `memberships`: what makes a person a record OF this
     * tenant is that they hold an active membership in it, which is a stronger
     * and more accurate question than "does this profile id exist".
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $values
     *
     * @throws FormRejectedException
     */
    private function assertReferencesExist(int $tenantId, array $fields, array $values): void
    {
        foreach ($fields as $field) {
            $type = (string) ($field['field_type'] ?? '');
            if (!FieldType::isReference($type)) {
                continue;
            }
            $key = (string) ($field['field_key'] ?? '');
            $value = $values[$key] ?? null;
            if (!is_int($value)) {
                continue;
            }

            $exists = $type === FieldType::PROFILE_REF
                ? $this->profileIsMember($tenantId, $value)
                : $this->ouExists($tenantId, $value);

            if (!$exists) {
                /** @var array<string, string> $label */
                $label = is_array($field['label'] ?? null) ? $field['label'] : [];
                $name = LocalizedLabel::preferred($label);
                throw new FormRejectedException(
                    ($name !== '' ? $name : $key) . ' must name a record in this tenant'
                );
            }
        }
    }

    private function profileIsMember(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = :status
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId, ':status' => 'active']);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function ouExists(int $tenantId, int $ouId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM organizational_units WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $ouId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
