<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Submitting a form, and reading back what was submitted.
 *
 * THREE READ SURFACES, AND THE SPLIT IS AN AUTHORIZATION DECISION MADE IN THE
 * ROUTE TABLE RATHER THAN INSIDE A HANDLER
 * ---------------------------------------------------------------------------
 *   `GET /api/v1/form-submissions`         `forms:read`   — the tenant's
 *                                                            submissions.
 *   `GET /api/v1/form-submissions/{id}`    `forms:read`   — one of them.
 *   `GET /api/v1/me/form-submissions`      `forms:submit` — ONLY the caller's own.
 *
 * The alternative — one endpoint gated on `forms:submit` that narrows itself to
 * the caller's own rows when they lack `forms:read` — would move the decision
 * INSIDE the handler, where it is a line of code that can be edited without
 * anybody reviewing an authorization change. Two routes put the rule in the route
 * table beside every other rule, where `RbacMiddleware` enforces it and the
 * route-catalogue gate lists it.
 *
 * `/me/…` needs no permission of its own beyond `forms:submit` because the rows
 * it returns already name exactly one person — a tenant-wide permission has
 * nothing left to decide. Same argument migration 113 makes about routing
 * ("being a recipient IS the authorization") and #978 about the inbox.
 *
 * THERE IS NO PATCH AND NO DELETE
 * --------------------------------
 * A submission is what somebody declared, at a moment, under their own name, and
 * other people have already acted on it — the document it produced may be halfway
 * through an approval whose trail records approvals of a thing that would no
 * longer be what was approved. Somebody who got it wrong submits again; two
 * submissions with two timestamps is a true account, one edited submission is
 * not. See {@see FormSubmissionRepository}.
 */
final class FormSubmissionsApiHandler
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly FormSubmissionRepository $submissions,
        private readonly SubmissionIssuer $issuer,
    ) {
    }

    /**
     * POST /api/v1/forms/{id}/submissions — submit a form.
     *
     * The answers arrive under `data`, keyed by `field_key`. Everything else in
     * the body is ignored: a submission is answers and nothing else, and a body
     * that could also set `form_version` or `submitted_by_profile_id` would let a
     * caller sign a declaration in somebody else's name.
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
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

            $body = JsonBody::parsed($request);
            $data = $body['data'] ?? [];
            if (!is_array($data)) {
                return Response::error('data must be an object of answers keyed by field key', 422);
            }

            /** @var array<string, mixed> $answers */
            $answers = [];
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $answers[$key] = $value;
                }
            }

            $result = $this->issuer->submit(
                $tenantId,
                self::actorProfileId($request),
                $form,
                $this->fields->listForForm($tenantId, $formId),
                $answers,
            );

            return Response::json([
                'data' => $result['submission'],
                'meta' => [
                    // Whether the submission actually started circulating. A form
                    // with no route template records the answers and stops there,
                    // which is a legitimate configuration — and a client that
                    // cannot tell the two apart would tell the submitter their
                    // request is "on its way" when nothing is moving.
                    'routed' => $result['routed'],
                    // Answer keys that matched no field on the form. Reported
                    // rather than swallowed: the realistic cause is a stale client
                    // whose author removed a field mid-session, and refusing the
                    // whole submission would throw away everything the person
                    // typed to punish them for a race they did not cause.
                    'ignored_keys' => $result['ignored'],
                ],
            ], 201);
        } catch (FormRejectedException $e) {
            // ->clientMessage, never ->getMessage() (WC-186). The messages this
            // path produces name the person's OWN field label — "Contact number
            // is required" — because they are written for whoever is looking at
            // the form, not for its author.
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] submit failed: ' . $e->getMessage());

            return Response::error('Failed to record the submission', 500);
        }
    }

    /**
     * GET /api/v1/form-submissions — the tenant's submissions, newest first,
     * optionally narrowed to one form or one submitter.
     */
    public function list(Request $request): Response
    {
        return $this->listing($request, null);
    }

    /**
     * GET /api/v1/me/form-submissions — only the caller's own.
     *
     * A caller with no profile (a service principal) gets an EMPTY list rather
     * than a 403. It is a true answer — a principal that is not a person has made
     * no submissions — and it keeps an integration polling this endpoint from
     * looking like an authorization failure somebody has to investigate.
     */
    public function listMine(Request $request): Response
    {
        $profileId = self::actorProfileId($request);
        if ($profileId === null) {
            return Response::json(['data' => []]);
        }

        return $this->listing($request, $profileId);
    }

    /**
     * GET /api/v1/form-submissions/{id} — one submission, with the fields it was
     * answering.
     *
     * The fields travel with the submission because the two facts are useless
     * apart: `{"applicant_ref": 41}` means nothing without the field that says
     * what was asked. They are TODAY's fields — see
     * {@see \Whity\Core\Form\FormStatus} for exactly what `form_version` does and
     * does not promise about that — and the version is returned beside them so a
     * reader can see when the two do not line up.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $submission = $this->submissions->find($tenantId, (int) ($params['id'] ?? 0));
            if ($submission === null) {
                return Response::error('Submission not found', 404);
            }

            $form = $this->forms->find($tenantId, (int) $submission['form_id']);
            $fields = $this->fields->listForForm($tenantId, (int) $submission['form_id']);

            return Response::json([
                'data' => $submission + [
                    'fields' => $fields,
                    // Stated rather than left for a client to compare: an answer
                    // set given against version 3 and read through version 5's
                    // fields is drift, not a bug, and a reader who cannot see the
                    // mismatch will report it as one.
                    'form_version_now' => $form === null ? null : (int) $form['version'],
                ],
            ]);
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the submission', 500);
        }
    }

    /**
     * The shared body of {@see list()} and {@see listMine()}.
     *
     * `$forcedProfileId` is not a filter the caller supplies — it is the route's
     * decision, and passing it here rather than reading it from the query string
     * is what makes `/me/…` unable to return anybody else's rows however the
     * request is spelled.
     */
    private function listing(Request $request, ?int $forcedProfileId): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $query = self::queryParams($request);

            $formId = self::intQuery($query, 'form_id');
            if ($formId !== null && $this->forms->find($tenantId, $formId) === null) {
                return Response::error('form_id does not name a form in this tenant', 422);
            }

            $profileId = $forcedProfileId ?? self::intQuery($query, 'submitted_by');

            return Response::json([
                'data' => $this->submissions->listForTenant(
                    $tenantId,
                    $formId,
                    $profileId,
                    self::intQuery($query, 'limit') ?? 50,
                    self::intQuery($query, 'offset') ?? 0,
                ),
            ]);
        } catch (\Exception $e) {
            error_log('[FormSubmissionsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch submissions', 500);
        }
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), path last — the same precedence {@see TimeWindowsApiHandler} uses.
     *
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    /**
     * @param array<string, string> $query
     */
    private static function intQuery(array $query, string $name): ?int
    {
        $raw = $query[$name] ?? null;

        return is_string($raw) && preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }
}
