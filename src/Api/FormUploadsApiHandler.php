<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormStatus;
use Whity\Core\Form\FormUploadPolicy;
use Whity\Core\Form\FormUploadStore;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\UploadedFilePart;
use Whity\Http\UploadedFilePartException;
use Whity\Storage\StorageException;

/**
 * `POST /api/v1/forms/{id}/uploads` — attach a file to a form you are filling
 * in.
 *
 * Gated `forms:submit`, the SAME permission as the submit itself. Uploading is
 * half of answering, and a separate permission would let an administrator grant
 * one without the other, producing a person who can submit a form they cannot
 * complete. It is not `forms:manage`: authoring a form and filling one in are
 * different jobs done by different people, and the fill-it-in audience is the
 * large one.
 *
 * WHY MULTIPART AND NOT BASE64 JSON
 * ----------------------------------
 * Base64-in-JSON is the shape the rest of this codebase reaches for, and it is
 * the wrong shape here. Base64 inflates by a third, so a 9 MB paper arrives as a
 * 12 MB request body that PHP must hold as a STRING, decode into a second
 * string, and keep both alive at once — against a 128 MB default `memory_limit`,
 * with eight FrankenPHP workers that may each be doing it. Multipart parts are
 * spilled to a temp file by {@see \Whity\Sdk\Http\MultipartParser}, so the
 * request never becomes a string at all.
 *
 * It is also not a new path this repository has to learn: `POST
 * /api/v1/plugins/upload` and the branding-asset upload are both multipart, both
 * read through {@see Request::getUploadedFiles()}, and
 * {@see \Whity\Http\Middleware\RequestBodyValidator} already passes a multipart
 * body through untouched for exactly this reason. The base64 sites in this
 * codebase carry payloads that are genuinely small — a signature, a token, a
 * logo — and the argument that suits them does not survive a megabyte.
 *
 * The cost is honest and worth stating: a multipart endpoint cannot be driven
 * from a JSON-only client without building a body, and its errors arrive from a
 * parser rather than from `json_decode`. Both are one-time costs paid by the
 * client library; the memory cost of base64 would be paid on every request
 * forever.
 *
 * WHAT COMES BACK, AND WHY THE KEY IS SAFE TO HAND OVER
 * ------------------------------------------------------
 * `{ reference, filename, content_type, byte_size, checksum_sha256 }`. The
 * `reference` IS the storage key, and it is what the `file` answer carries — the
 * shape {@see \Whity\Core\Form\SubmissionValidator} has always described.
 *
 * Handing a caller a storage key is safe here because a key is not a capability
 * anywhere in this platform: NO route accepts one as input. Bytes are read at
 * `GET /api/v1/documents/{id}/artifacts/{artifactId}/content`, which resolves
 * the document through {@see \Whity\Core\Document\DocumentVisibilityPolicy},
 * binds the artifact to that document AND that tenant, and takes the key OFF THE
 * ROW — the request never supplies one. And a key from elsewhere cannot become
 * such a row: {@see \Whity\Core\Form\SubmissionIssuer} accepts a `file` answer
 * only by CLAIMING an unspent `form_uploads` row bound to this tenant, this form
 * and this uploader (migration 134).
 *
 * WHY THE FORM MUST HAVE A `file` FIELD
 * --------------------------------------
 * Refusing an upload against a form that asks for no file keeps this from being
 * a general-purpose write into a tenant's storage that any holder of
 * `forms:submit` can aim at any form id. The permission is broad on purpose —
 * it is the everyday permission of the largest audience in the tenant — so the
 * endpoint narrows itself by what the form actually asks for.
 */
final class FormUploadsApiHandler
{
    /**
     * Per-caller ceiling, per hour.
     *
     * Not sized against abuse of the FORM — a signed-in member is a known person
     * — but against the storage bill one credential can run up before anybody
     * notices, whether through malice or a client stuck in a retry loop. Twenty
     * uploads an hour is far past honest use of a form with one or two
     * attachments; at the 10 MiB ceiling it bounds one caller to 200 MiB an hour.
     *
     * Same fixed-window construction as {@see PublicFormsApiHandler}'s throttles,
     * deliberately: two rate limiters with two shapes is two behaviours to keep
     * correct.
     */
    private const WINDOW_SECONDS = 3600;
    private const UPLOAD_MAX_PER_ACTOR = 20;

    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly FormUploadStore $store,
        private readonly SharedStoreInterface $rateStore,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $formId = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $formId);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            if (!FormStatus::acceptsSubmissions((string) ($form['status'] ?? FormStatus::DRAFT))) {
                // The same sentence the submit path uses for the same state. A
                // caller told "you may upload" and then refused at submit would
                // have wasted the upload and the tenant's storage on it.
                return Response::error('This form is not accepting submissions right now', 422);
            }

            if (!self::asksForAFile($this->fields->listForForm($tenantId, $formId))) {
                return Response::error('This form does not ask for a file', 422);
            }

            $actorProfileId = self::actorProfileId($request);
            $throttled = $this->throttle($actorProfileId, $tenantId);
            if ($throttled instanceof Response) {
                return $throttled;
            }

            $part = UploadedFilePart::read($request, 'file');

            $contentType = FormUploadPolicy::assertAcceptable(
                $part['bytes'],
                $part['media_type'],
                FormUploadPolicy::MAX_BYTES,
            );

            $stored = $this->store->put(
                $tenantId,
                $formId,
                $part['bytes'],
                $contentType,
                $part['filename'],
                $actorProfileId,
            );

            return Response::json([
                'data' => [
                    // Named `reference` rather than `storage_key`: it is the
                    // value the `file` ANSWER carries, and a client that thinks
                    // of it as an address is a client one step from trying to
                    // fetch it. Nothing serves it.
                    'reference'       => $stored['storage_key'],
                    'filename'        => $stored['filename'],
                    'content_type'    => $stored['content_type'],
                    'byte_size'       => $stored['byte_size'],
                    // Returned so a client that wants to can verify the server
                    // received what it sent, before committing to a submission.
                    'checksum_sha256' => $stored['checksum_sha256'],
                ],
            ], 201);
        } catch (UploadedFilePartException $e) {
            // The status travels with the message: "no file part" is a malformed
            // request, "too big for PHP itself" is a refused entity.
            error_log('[FormUploadsApiHandler] ' . $e->getMessage());

            return Response::error($e->clientMessage, $e->status);
        } catch (FormRejectedException $e) {
            // ->clientMessage, never ->getMessage() (WC-186). These name the
            // limit or the accepted kinds — sentences written for whoever is
            // looking at the form.
            return Response::error($e->clientMessage, 422);
        } catch (StorageException $e) {
            error_log('[FormUploadsApiHandler] storage write failed: ' . $e->getMessage());

            return Response::error('The file could not be stored. Please try again.', 503);
        } catch (\Throwable $e) {
            error_log('[FormUploadsApiHandler] upload failed: ' . $e->getMessage());

            return Response::error('The file could not be uploaded', 500);
        }
    }

    /**
     * Whether any of the form's fields is a `file` field.
     *
     * @param list<array<string, mixed>> $fields
     */
    private static function asksForAFile(array $fields): bool
    {
        foreach ($fields as $field) {
            if ((string) ($field['field_type'] ?? '') === FieldType::FILE) {
                return true;
            }
        }

        return false;
    }

    /**
     * One fixed-window counter per caller, checked then incremented — the same
     * construction as every other throttle in this subsystem.
     *
     * A caller with no profile (a service principal) is bucketed on the TENANT
     * instead of being let through unbounded. Failing open on the one caller
     * shape that is scripted by definition would be exactly backwards.
     */
    private function throttle(?int $actorProfileId, int $tenantId): ?Response
    {
        $bucket = $actorProfileId !== null
            ? 'formupload:profile:' . $actorProfileId
            : 'formupload:tenant:' . $tenantId;

        if ($this->rateStore->count($bucket) >= self::UPLOAD_MAX_PER_ACTOR) {
            return Response::error('Too many uploads. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->rateStore->ttl($bucket), 1)]);
        }
        $this->rateStore->increment($bucket, self::WINDOW_SECONDS);

        return null;
    }

    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }
}
