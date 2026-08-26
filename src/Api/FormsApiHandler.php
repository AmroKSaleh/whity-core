<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRenderer;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormStatus;
use Whity\Core\Form\FormSubmissionRepository;
use Whity\Core\Form\LocalizedLabel;
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
 * PERMISSIONS: reading on `forms:read`, authoring and lifecycle on
 * `forms:manage`, rendering on `forms:submit`.
 */
final class FormsApiHandler
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormRenderer $renderer,
        private readonly FormSubmissionRepository $submissions,
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
                'data' => $this->forms->listForTenant(
                    $tenantId,
                    $status,
                    self::intQuery($query, 'limit') ?? 100,
                    self::intQuery($query, 'offset') ?? 0,
                ),
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
                'data' => $form + [
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

            return Response::json(['data' => $this->forms->find($tenantId, $id)], 201);
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

            return Response::json(['data' => $this->forms->find($tenantId, $id)]);
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

            return Response::json([
                'data' => $this->renderer->render($tenantId, self::actorProfileId($request), $form),
            ]);
        } catch (\Exception $e) {
            error_log('[FormsApiHandler] render failed: ' . $e->getMessage());

            return Response::error('Failed to render form', 500);
        }
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
                return Response::json(['data' => $form]);
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

            return Response::json(['data' => $this->forms->find($tenantId, $id)]);
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
