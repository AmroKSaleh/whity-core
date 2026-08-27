<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormStatus;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\LocalizedLabel;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Form\PublicFormView;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * The forms themselves: listing, creating, editing, publishing, archiving, and
 * RENDERING one for the person about to fill it in.
 *
 * THERE IS NO DELETE, AND THAT IS DELIBERATE. A form is what somebody's
 * submission was an answer TO; destroying it leaves every submission against it
 * as a bag of keys with nothing to say what they meant. `POST .../archive` stops
 * new submissions, which is the thing that actually gets asked for, and it is
 * reversible. Same posture {@see TimeWindowsApiHandler} takes toward a period.
 *
 * PUBLISH AND ARCHIVE ARE ENDPOINTS, NOT A `status` FIELD ON PATCH
 * ----------------------------------------------------------------
 * A PATCH carrying `status` would make an accidental status change one stray key
 * away from a body that meant to fix a typo, and it would put the transition
 * rules ({@see FormStatus}) behind a field-update path that has no natural place
 * to enforce them. Two named endpoints say what they do, gate on the same
 * permission as authoring, and cannot be triggered by a client that did not mean
 * to. It also makes the audit read correctly: "published this form" is an act,
 * not an attribute assignment.
 *
 * RENDER IS GATED ON `forms:submit`, NOT `forms:read`
 * ----------------------------------------------------
 * Rendering IS the act of preparing to submit, and its response carries the
 * caller's OWN prefilled details. Gating it on `forms:read` would hand the
 * catalogue-reading audience a personalised payload they have no business
 * receiving, and would deny it to the much larger audience whose only job is to
 * fill the thing in.
 *
 * THE PUBLIC LINK IS OPENED AND CLOSED HERE, NOT ON THE PUBLIC HANDLER
 * ---------------------------------------------------------------------
 * `POST` and `DELETE /api/v1/forms/{id}/public-link` (migration 132) are
 * ordinary tenant-scoped, `forms:manage`-gated routes and they live beside the
 * other things an author does to a form. {@see \Whity\Api\PublicFormsApiHandler}
 * is the ANONYMOUS surface and holds nothing that can change a form's state —
 * which is what makes "who can open a form to the internet" a question with one
 * answer, in one place, in the route table.
 *
 * They are POST/DELETE on a sub-resource rather than a `public_enabled` field on
 * PATCH, for exactly the reason publish and archive are endpoints: opening a form
 * to the entire internet must not be one stray key away from a body that meant to
 * fix a typo, and the audit must read "opened this form to the public" as an act
 * rather than an attribute assignment.
 *
 * PERMISSIONS: reading on `forms:read`, authoring and lifecycle on
 * `forms:manage`, rendering on `forms:submit`.
 */
final class FormsApiHandler
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly FormRenderer $renderer,
        private readonly FormSubmissionRepository $submissions,
        private readonly PublicFormLink $links,
    ) {
    }

    /**
     * GET /api/v1/forms — the tenant's forms, newest first, optionally narrowed
     * to one lifecycle state.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $query = self::queryParams($request);

            $status = null;
            if (isset($query['status']) && $query['status'] !== '') {
                $status = (string) $query['status'];
                if (!FormStatus::isValid($status)) {
                    return Response::error(
                        'status must be one of: ' . implode(', ', FormStatus::all()),
                        422
                    );
                }
            }

            return Response::json([
                'data' => array_map([$this, 'present'], $this->forms->listForTenant(
                    $tenantId,
                    $status,
                    self::intQuery($query, 'limit') ?? 100,
                    self::intQuery($query, 'offset') ?? 0,
                )),
            ]);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch forms', 500);
        }
    }

    /**
     * GET /api/v1/forms/{id} — one form, with its fields and how many
     * submissions it has already received.
     *
     * The submission count travels with the form rather than living behind a
     * second request because the two facts are never wanted apart when they
     * matter: an author about to change a published form needs to know that
     * thirty people have already answered it, and a count they have to go and
     * fetch is a count they will not fetch.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $id);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            $rendered = $this->renderer->render($tenantId, null, $form);

            return Response::json([
                'data' => $this->present($form) + [
                    'fields' => $rendered['fields'],
                    'sections' => $rendered['sections'],
                    'submission_count' => $this->submissions->countForForm($tenantId, $id),
                ],
            ]);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch form', 500);
        }
    }

    /**
     * POST /api/v1/forms — author a new form. Always born `draft`.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $body = JsonBody::parsed($request);

            $formKey = trim((string) ($body['form_key'] ?? ''));
            if ($formKey === '') {
                return Response::error('form_key is required', 422);
            }
            if (strlen($formKey) > FormRepository::KEY_MAX) {
                return Response::error(
                    'form_key must be ' . FormRepository::KEY_MAX . ' characters or fewer',
                    422
                );
            }
            if (preg_match(FormRepository::KEY_PATTERN, $formKey) !== 1) {
                return Response::error(
                    'form_key must start with a letter and contain only lowercase letters, '
                    . 'digits, hyphens and underscores',
                    422
                );
            }

            $name = LocalizedLabel::fromInput($body['name'] ?? null, 'name');

            $description = self::optionalText($body, 'description');
            if ($description instanceof Response) {
                return $description;
            }

            $routeTemplateId = self::routeTemplateId($tenantId, $body);
            if ($routeTemplateId instanceof Response) {
                return $routeTemplateId;
            }

            $id = $this->forms->create(
                $tenantId,
                $formKey,
                $name,
                $description,
                $routeTemplateId,
                self::actorProfileId($request),
            );

            return Response::json(['data' => $this->presentFresh($tenantId, $id)], 201);
        } catch (FormRejectedException $e) {
            // ->clientMessage, never ->getMessage(): only text somebody wrote FOR
            // a caller reaches a caller, and giving it its own field is what makes
            // that structural rather than a habit. See the exception's docblock,
            // and WC-186. Every catch site below follows suit.
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] create failed: ' . $e->getMessage());

            return Response::error('Failed to create form', 500);
        }
    }

    /**
     * PATCH /api/v1/forms/{id} — rename a form, retitle it, or point it at a
     * different route template.
     *
     * `form_key` and `status` are both REFUSED rather than ignored. A caller who
     * sent one meant something by it, and silently dropping the field would leave
     * them believing a change happened. See the class docblock for why status has
     * its own endpoints, and {@see FormRepository::update()} for why a key is
     * immutable.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $id);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            $body = JsonBody::parsed($request);

            if (array_key_exists('form_key', $body)) {
                return Response::error(
                    'form_key cannot be changed — code and links bind to it. Create a new form instead.',
                    422
                );
            }
            if (array_key_exists('status', $body)) {
                return Response::error(
                    'status is changed with POST /api/v1/forms/{id}/publish or /archive, not by editing the form',
                    422
                );
            }

            $changes = [];
            if (array_key_exists('name', $body)) {
                $changes['name'] = LocalizedLabel::fromInput($body['name'], 'name');
            }
            if (array_key_exists('description', $body)) {
                $description = self::optionalText($body, 'description');
                if ($description instanceof Response) {
                    return $description;
                }
                $changes['description'] = $description;
            }
            if (array_key_exists('route_template_id', $body)) {
                $routeTemplateId = self::routeTemplateId($tenantId, $body);
                if ($routeTemplateId instanceof Response) {
                    return $routeTemplateId;
                }
                $changes['route_template_id'] = $routeTemplateId;
            }

            if ($changes === []) {
                return Response::error('No updatable fields supplied', 422);
            }

            $this->forms->update($tenantId, $id, $changes);

            return Response::json(['data' => $this->presentFresh($tenantId, $id)]);
        } catch (FormRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] update failed: ' . $e->getMessage());

            return Response::error('Failed to update form', 500);
        }
    }

    /**
     * POST /api/v1/forms/{id}/publish — make the form live and mint a version.
     *
     * A form with no fields is refused. Publishing one would produce a live form
     * that collects nothing, renders as an empty page with a submit button, and
     * reports success on every submission — which is precisely the failure this
     * codebase keeps writing against, and the same argument
     * {@see \Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation} makes
     * about a route template with no stages.
     *
     * @param array<string, string> $params
     */
    public function publish(Request $request, array $params): Response
    {
        return $this->transition($params, FormStatus::PUBLISHED, requireFields: true);
    }

    /**
     * POST /api/v1/forms/{id}/archive — stop accepting submissions.
     *
     * Everything already submitted stays exactly where it is; only the door
     * closes. Reversible, because retiring a form at the end of a cycle and
     * wanting it back at the start of the next one is the ordinary case.
     *
     * @param array<string, string> $params
     */
    public function archive(Request $request, array $params): Response
    {
        return $this->transition($params, FormStatus::ARCHIVED, requireFields: false);
    }

    /**
     * GET /api/v1/forms/{id}/render — the form as it should be DRAWN for the
     * caller: fields in order, grouped into sections, plus the caller's own
     * prefilled values.
     *
     * A form that is not accepting submissions still renders. Refusing would
     * leave a person who followed a link to an archived form staring at a 404
     * with no way to tell "this never existed" from "this closed last week", and
     * the response says which it is (`accepts_submissions`).
     *
     * @param array<string, string> $params
     */
    public function render(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $id);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            $rendered = $this->renderer->render($tenantId, self::actorProfileId($request), $form);
            // The nested form gets the same treatment every other form-shaped
            // response gets, so `public_url` is not a key that exists on one read
            // and not another.
            $rendered['form'] = $this->present($form);

            return Response::json(['data' => $rendered]);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] render failed: ' . $e->getMessage());

            return Response::error('Failed to render form', 500);
        }
    }

    /**
     * POST /api/v1/forms/{id}/public-link — open this form to people who have no
     * account, minting the unguessable address they will use.
     *
     * Gated `forms:manage`. This is the single most consequential thing that
     * permission can do, so the refusals below are not politeness — each one
     * closes a way for the resulting link to be broken, useless, or a leak.
     *
     * THE FORM MUST ALREADY BE PUBLISHED. A link minted on a draft answers the
     * generic 404 to everybody who follows it ({@see PublicFormLink::servesPublicly()}),
     * so the author would hand out an address that does not work and find out
     * from the people who could not use it. Refusing here says so while there is
     * something to do about it. The reverse — archiving a form with a live link —
     * needs no equivalent guard: archiving IS how a person stops submissions, and
     * the public gate reads `status` on every request, so the link dies with the
     * archive and comes back if the form is republished.
     *
     * THE FORM MUST ASK ONLY QUESTIONS A STRANGER CAN ANSWER. A `profile_ref` or
     * `ou_ref` field would make the submit endpoint a membership oracle —
     * {@see PublicFormView::isPubliclyAnswerable()} has the full argument. Those
     * are refused HERE, at the moment the door opens, naming the offending
     * fields, rather than silently serving a shorter form. A field added AFTER
     * the link is open is omitted rather than refused, because closing a live
     * public form because somebody edited it is a worse failure than serving the
     * rest of it — so this check is the author-facing half and
     * {@see PublicFormView} is the structural half, and neither is load-bearing
     * alone.
     *
     * A `file` FIELD IS NO LONGER ONE OF THEM. It was, while there was no
     * anonymous upload route and the field would have rendered above a submit
     * button that refused. Migration 134 added
     * `POST /api/v1/public/forms/{slug}/uploads`, so a public form may now ask
     * an external applicant to attach their paper — which is the case the whole
     * feature exists for. The list this check consults is
     * {@see PublicFormView::unanswerableFieldKeys()}, so it changed with the
     * policy rather than needing to be remembered separately.
     *
     * `opens_at` / `closes_at` are optional and either may be null. They are
     * interpreted in the INSTANCE'S OWN CLOCK — the same naive `TIMESTAMP` every
     * other date in this schema uses — and an offset suffix is refused rather
     * than silently dropped, because a deadline that moved by three hours without
     * anybody saying so is exactly the kind of quiet wrongness this subsystem is
     * written against.
     *
     * @param array<string, string> $params
     */
    public function enablePublicLink(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $id);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            if ((string) $form['status'] !== FormStatus::PUBLISHED) {
                return Response::error(
                    'Publish this form before opening it to the public — a public link to a form '
                    . 'that is not published answers "not found" to everyone who follows it.',
                    422
                );
            }

            $fields = $this->fields->listForForm($tenantId, $id);
            $unanswerable = PublicFormView::unanswerableFieldKeys($fields);
            if ($unanswerable !== []) {
                return Response::error(
                    'A public form can only ask for values somebody outside the organisation can '
                    . 'actually give. These fields cannot be: ' . implode(', ', $unanswerable)
                    . '. A person or unit field would ask an anonymous caller to name one of your '
                    . 'records. Remove them, or collect this form from signed-in members instead.',
                    422
                );
            }
            if (PublicFormView::answerableFields($fields) === []) {
                return Response::error(
                    'This form has no fields a member of the public could fill in, so a public link '
                    . 'to it would collect nothing while reporting every submission as successful.',
                    422
                );
            }

            $body = JsonBody::parsed($request);
            $opensAt = self::optionalTimestamp($body, 'opens_at');
            if ($opensAt instanceof Response) {
                return $opensAt;
            }
            $closesAt = self::optionalTimestamp($body, 'closes_at');
            if ($closesAt instanceof Response) {
                return $closesAt;
            }
            if ($opensAt !== null && $closesAt !== null && $closesAt <= $opensAt) {
                return Response::error('closes_at must be after opens_at', 422);
            }

            $this->forms->enablePublicLink(
                $tenantId,
                $id,
                PublicFormLink::newSlug(),
                $opensAt,
                $closesAt,
                self::actorProfileId($request),
            );

            return Response::json(['data' => $this->presentFresh($tenantId, $id)]);
        } catch (FormRejectedException $e) {
            // 409: every way enablePublicLink() refuses is a CONFLICT with the
            // form's current state (it already has a link, or it moved under this
            // request), not a malformed request — the same status the lifecycle
            // transitions use for the same reason.
            return Response::error($e->clientMessage, 409);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] enablePublicLink failed: ' . $e->getMessage());

            return Response::error('Failed to open a public link for this form', 500);
        }
    }

    /**
     * DELETE /api/v1/forms/{id}/public-link — close it.
     *
     * Gated `forms:manage`. IDEMPOTENT: closing a link that is already closed is
     * a 200, because "the door you asked me to shut is shut" is a success and a
     * client that lost a response must be able to retry. `meta.closed` says
     * whether this call was the one that changed anything, which is the honest
     * way to be idempotent without pretending nothing happened either way.
     *
     * The slug is DESTROYED rather than parked beside a false flag — see
     * {@see FormRepository::disablePublicLink()}. Re-opening later mints a new
     * address, so a link an organisation withdrew stays withdrawn even if
     * somebody re-opens the form afterwards.
     *
     * @param array<string, string> $params
     */
    public function disablePublicLink(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            if ($this->forms->find($tenantId, $id) === null) {
                return Response::error('Form not found', 404);
            }

            $closed = $this->forms->disablePublicLink($tenantId, $id);

            return Response::json([
                'data' => $this->presentFresh($tenantId, $id),
                'meta' => ['closed' => $closed],
            ]);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] disablePublicLink failed: ' . $e->getMessage());

            return Response::error('Failed to close this form\'s public link', 500);
        }
    }

    /**
     * Re-read a form and present it.
     *
     * Every write path in this handler reads the row back rather than echoing
     * what it sent, so a client sees what the database actually holds —
     * `public_enabled_at`, `updated_at` and the computed window state are all
     * server-side facts a request body could not know.
     *
     * @return array<string, mixed>|null
     */
    private function presentFresh(int $tenantId, int $id): ?array
    {
        $form = $this->forms->find($tenantId, $id);

        return $form === null ? null : $this->present($form);
    }

    /**
     * Add the one fact a `forms` row cannot carry: the ABSOLUTE address its
     * public link is served from.
     *
     * It is composed here rather than stored, because it is a function of the
     * slug and of the instance's own `APP_URL` — and an instance that is renamed,
     * moved behind a different domain, or deployed twice from one database would
     * otherwise serve a column pointing at wherever it used to live. The same
     * argument {@see \Whity\Core\Document\Qr\DocumentQrService::verificationUrl()}
     * makes about a QR payload.
     *
     * Null when the form has no link, or when this instance has never been told
     * its own address — see {@see PublicFormLink::publicUrl()} for why null
     * rather than a relative path.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function present(array $form): array
    {
        $slug = $form['public_slug'] ?? null;

        return $form + [
            'public_url' => $this->links->publicUrl(is_string($slug) ? $slug : null),
        ];
    }

    /**
     * Validate an optional `YYYY-MM-DD[ HH:MM[:SS]]` boundary, returning the
     * value to store, null, or a 422.
     *
     * STRICT, and it rejects a date that merely looks like one: `2026-02-30`
     * parses in a lenient reader and rolls forward into March, silently moving a
     * deadline a person typed. {@see \Whity\Core\TimeWindow\TimeWindowRepository::normalizeDate()}
     * refuses the same thing for the same reason.
     *
     * A bare date means the START of that day, which is what somebody typing
     * "opens 2026-03-01" means. A `closes_at` of a bare date therefore closes at
     * MIDNIGHT THAT MORNING rather than at the end of the day — stated because
     * the opposite reading is just as natural, and the way to say "all of the
     * 30th" is `2026-03-31` or an explicit time.
     *
     * An offset or `Z` suffix is REFUSED rather than dropped: the column is a
     * naive `TIMESTAMP` compared against the database's `NOW()`, so accepting
     * `2026-03-01T00:00:00+05:00` would store a boundary three hours from where
     * the caller put it, silently, and correctly-rendered.
     *
     * @param array<string, mixed> $body
     */
    private static function optionalTimestamp(array $body, string $field): string|null|Response
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        if (!is_string($body[$field])) {
            return Response::error("{$field} must be a date-time string or null", 422);
        }

        // 'T' is accepted as the separator because that is what every JSON client
        // emits; it is normalised to a space so the stored value matches the
        // format the rest of the schema uses.
        $raw = trim(str_replace('T', ' ', $body[$field]));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $raw .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw) === 1) {
            $raw .= ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw) !== 1) {
            return Response::error(
                "{$field} must be YYYY-MM-DD or YYYY-MM-DD HH:MM[:SS], in this instance's own "
                . 'time zone. A UTC offset is not accepted, because storing one would move the '
                . 'boundary you typed without saying so.',
                422
            );
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $raw) {
            return Response::error("{$field} is not a date and time that exists", 422);
        }

        return $raw;
    }

    /**
     * The shared body of {@see publish()} and {@see archive()}.
     *
     * Written once because the two differ in exactly two ways — the target state
     * and whether an empty form is acceptable — and two copies of the
     * find/validate/transition/read-back sequence would be two places for the
     * 404 posture and the transition check to drift.
     *
     * @param array<string, string> $params
     */
    private function transition(array $params, string $to, bool $requireFields): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $form = $this->forms->find($tenantId, $id);
            if ($form === null) {
                return Response::error('Form not found', 404);
            }

            $from = (string) $form['status'];
            if ($from === $to) {
                // Idempotent rather than a 409: asking for the state a form is
                // already in is not an error, and returning the form lets a
                // client that lost a response retry safely.
                return Response::json(['data' => $this->present($form)]);
            }
            if (!FormStatus::canTransition($from, $to)) {
                return Response::error(
                    "A form that is {$from} cannot become {$to}. "
                    . 'Allowed from here: ' . (implode(', ', FormStatus::transitionsFrom($from)) ?: 'nothing'),
                    422
                );
            }

            if ($requireFields) {
                $rendered = $this->renderer->render($tenantId, null, $form);
                if ($rendered['fields'] === []) {
                    return Response::error(
                        'This form has no fields yet, so publishing it would collect nothing while '
                        . 'reporting every submission as successful. Add at least one field first.',
                        422
                    );
                }
            }

            $this->forms->transition($tenantId, $id, $from, $to);

            return Response::json(['data' => $this->presentFresh($tenantId, $id)]);
        } catch (FormRejectedException $e) {
            return Response::error($e->clientMessage, 409);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] transition failed: ' . $e->getMessage());

            return Response::error('Failed to change the form state', 500);
        }
    }

    /**
     * Validate an optional `route_template_id`, returning the id, null, or a 422.
     *
     * Existence is checked in the CALLER'S tenant, so a template id from another
     * organisation is refused as absent rather than stored — a form cannot be
     * wired to somebody else's approval flow by guessing an integer.
     *
     * @param array<string, mixed> $body
     */
    private function routeTemplateId(int $tenantId, array $body): int|null|Response
    {
        if (!array_key_exists('route_template_id', $body) || $body['route_template_id'] === null) {
            return null;
        }

        $raw = $body['route_template_id'];
        $id = null;
        if (is_int($raw)) {
            $id = $raw;
        } elseif (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            $id = (int) $raw;
        }
        if ($id === null || $id <= 0) {
            return Response::error('route_template_id must be an integer or null', 422);
        }
        if (!$this->forms->routeTemplateExists($tenantId, $id)) {
            return Response::error('route_template_id does not name a route template in this tenant', 422);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalText(array $body, string $field): string|null|Response
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        if (!is_string($body[$field])) {
            return Response::error("{$field} must be text or null", 422);
        }
        $value = trim($body[$field]);
        if ($value === '') {
            return null;
        }
        if ($violation = InputLimits::firstViolation([$field => [$value, InputLimits::TEXT_MAX]])) {
            return $violation;
        }

        return $value;
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), path last — the same precedence {@see TimeWindowsApiHandler} uses,
     * so a test that puts params in the path and production traffic resolve
     * identically.
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

    private static function tenantId(): int|Response
    {
        $tenantId = TenantContext::getTenantId();

        return $tenantId ?? Response::error('Tenant context is required', 403);
    }

    /**
     * Who is performing the act.
     *
     * Null is tolerated rather than refused: a service principal has no profile,
     * and a form authored with an absent author is strictly more than no form at
     * all. The route gate has already established that the caller may do this.
     */
    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }
}
