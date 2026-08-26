<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\PublicFormLink;
use Whity\Core\Form\PublicFormView;
use Whity\Core\Form\SubmissionIssuer;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The UNAUTHENTICATED end of a public form (migration 132):
 *
 *   GET  /api/v1/public/forms/{slug}              — what am I being asked?
 *   POST /api/v1/public/forms/{slug}/submissions  — here is my answer.
 *
 * The caller has no account, no session and no tenant. That is not a limitation
 * to work around — it is the entire feature, and every decision below follows
 * from it.
 *
 * 1. THE TENANT COMES FROM THE SLUG, NEVER FROM THE CALLER
 * ---------------------------------------------------------
 * There is no `TenantContext` on this path: the routes sit on
 * {@see \Whity\Http\Middleware\EnforceTenantIsolation}'s public list, so the
 * middleware returns before it resolves anything. The tenant therefore has to be
 * derived, and there is exactly one source that is not attacker-chosen.
 *
 * NOT the `X-Tenant-Id` header. NOT a `?tenant=` parameter. NOT the Host header.
 * All three are values the anonymous caller types, and reading a tenant off any
 * of them would let a stranger aim a submission at an organisation that never
 * published a link. {@see FormRepository::findByPublicSlug()} — the one read in
 * the subsystem with no tenant predicate — resolves the slug to exactly one row
 * (migration 132's global partial unique index) and that row's `tenant_id` is
 * bound by every read and write afterwards. {@see resolve()} is the ONLY place
 * either handler obtains a tenant, so there is no second path to keep correct.
 *
 * The resolved id is then locked into {@see TenantContext} before any work
 * happens, so anything downstream that consults the context — a hook, an audit
 * record, a job the routing engine enqueues — sees the tenant the SLUG named
 * rather than nothing. That is the same thing the MCP dispatcher does after
 * validating its own credential, and for the same reason.
 *
 * 2. ONE 404, FOR EVERY REASON A FORM IS NOT PUBLICLY SERVED
 * -----------------------------------------------------------
 * A malformed slug, an unknown slug, a form whose link was closed, and a form
 * that is not `published` all produce THE SAME 404 with the same sentence, from
 * {@see refuse()}. Nothing distinguishes them — not the status, not the body,
 * not the timing (the throttle is counted BEFORE the slug is examined, for the
 * reason {@see DocumentVerificationApiHandler::throttle()} spells out).
 *
 * So this endpoint cannot be asked which slugs name a real form, whether a
 * particular organisation uses public forms at all, or whether the link somebody
 * withdrew last week used to work. A caller who does not hold a live slug learns
 * one thing: nothing.
 *
 * 3. WHAT IS DISCLOSED WHEN THE ANSWER IS YES
 * --------------------------------------------
 * {@see PublicFormView} is the whole of it, as an allow-list. No id, no tenant
 * id, no `form_key`, no author, no route template, no submission count, no
 * status, no version, no prefill. See that class for the argument on each.
 *
 * 4. NO PREFILL CAN RESOLVE HERE, AND IT IS TRUE TWICE
 * -----------------------------------------------------
 * A prefill value IS, by definition, the submitter's own saved details resolved
 * against their profile ({@see \Whity\Core\Form\PrefillResolver}). An anonymous
 * caller has no profile, so there is nothing to resolve — and this handler does
 * not call {@see \Whity\Core\Form\FormRenderer} at all, so the resolver is never
 * reached. Belt and braces: even if it were, `forFields()` short-circuits on a
 * null profile id and issues no query, and `PublicFormView` emits no `prefill`
 * key regardless of what it is handed. Three independent reasons a stranger
 * cannot be shown somebody else's details.
 *
 * 5. THE SUBMISSION HAS NO SUBMITTER, AND THE COLUMN ALREADY SAID SO
 * --------------------------------------------------------------------
 * `form_submissions.submitted_by_profile_id` is nullable (migration 127) and
 * NULL is what a public submission stores. No sentinel profile, no "anonymous"
 * row in `profiles` — a sentinel would be a fake person that every membership
 * check, every permission resolution and every "who submitted this" read would
 * have to know to special-case, and the ones that did not would treat it as
 * real.
 *
 * NULL was already reachable before this change (a service principal has no
 * profile), so every reader tolerates it: `listForTenant()` and `find()` apply
 * the submitter predicate only when one is given, `normalizeRow()` maps null to
 * null, `GET /api/v1/me/form-submissions` returns an empty list for a caller
 * with no profile, and the OpenAPI component already declares the field
 * nullable. The document minted alongside it carries `created_by = NULL` too,
 * which {@see \Whity\Core\Document\DocumentVisibilityPolicy} handles correctly
 * by construction — its "is this mine" test compares against an `int` caller id,
 * which null can never equal, so an anonymous document is never accidentally
 * somebody's.
 *
 * 6. A PUBLIC SUBMISSION DOES ROUTE, AND HERE IS WHY THAT IS SAFE
 * ----------------------------------------------------------------
 * The authenticated path turns a submission into a document and, when the form
 * names a route template, circulates it. This path does the same, through the
 * same {@see SubmissionIssuer} — not a reduced copy of it.
 *
 * The thing that must never be possible is an unauthenticated caller INJECTING
 * WORK INTO AN ARBITRARY ROUTE TEMPLATE, and it is not possible here because THE
 * CALLER DOES NOT CHOOSE THE TEMPLATE AND CANNOT NAME ONE. `route_template_id`
 * lives on the FORM, is set only by `forms:manage`, is validated against the
 * tenant's own templates when set, and is never read from a request body — the
 * submit endpoint takes answers and nothing else. The slug names one form; the
 * form names at most one template; both were chosen by the organisation. An
 * anonymous caller's entire influence over routing is "did I submit this form or
 * not".
 *
 * Refusing to route would have been the more cautious-looking choice and it is
 * the wrong one: the case this feature exists for — an external applicant filing
 * a request that somebody inside must approve — is a submission that MUST reach
 * an approver. A public form that collected into a table nobody is notified
 * about is the "renders fine, does nothing" failure with extra steps.
 *
 * What the tenant is exposed to is VOLUME, and volume is bounded rather than
 * argued away: the per-IP throttle below, a per-form ceiling that holds even
 * across many addresses, the platform's own pre-auth per-IP limiter
 * ({@see \Whity\Core\RateLimit\RateLimitRule::ip()}), the opt-in itself, and the
 * submission window.
 *
 * 7. CSRF AND THE POST
 * ---------------------
 * {@see \Whity\Http\Middleware\CsrfGuard} requires its custom header only for
 * requests carrying an AMBIENT credential (an auth cookie) or targeting the auth
 * POSTs. An anonymous submit has no cookie and is not an auth route, so the
 * guard does not apply — and nothing here is weakened to make that true. A
 * signed-in browser that happens to post to this route is still ambient and
 * still checked, exactly as it is everywhere else.
 */
final class PublicFormsApiHandler
{
    /**
     * Per-IP throttles: fixed window, one namespace each, shared with nothing.
     *
     * DEFENCE IN DEPTH BEHIND THE SLUG'S ENTROPY, exactly as the same shape is on
     * {@see DocumentVerificationApiHandler} and {@see InvitationAcceptHandler}.
     * At 256 bits, working through the namespace is not a threat model, so these
     * numbers are not sized against guessing — their real job is bounding the
     * work one address can commission, and in the submit case the number of
     * documents and route steps one address can cause a tenant to store.
     *
     * The render ceiling is the looser of the two: a person filling in a form
     * reloads it, goes away, comes back, opens it on a phone. The submit ceiling
     * is tight because the honest use is once — a person who legitimately files
     * twelve applications in an hour is not a case this feature was built for,
     * and 429 with a `Retry-After` tells them exactly what to do.
     */
    private const WINDOW_SECONDS = 3600;
    private const RENDER_IP_MAX = 120;
    private const SUBMIT_IP_MAX = 20;

    /**
     * Per-FORM ceiling on submissions, across every address.
     *
     * The per-IP limit alone is not enough for the one thing that actually
     * matters here: a distributed flood does not reuse an address, and each
     * accepted submission costs the tenant a `documents` row, a route, and an
     * inbox entry in front of a real person. This bounds what ONE open form can
     * do to the organisation that opened it, whoever is sending.
     *
     * Counted per hour per form, generously — a genuinely busy application
     * deadline is dozens an hour, not hundreds — and the refusal is a 429, which
     * is honest: this is throttling, not rejection, and the person should come
     * back.
     *
     * Keyed on the tenant and form ID rather than the slug: a shared-store key is
     * readable by anybody with database access, and putting the credential that
     * IS the link into one would leave copies of it somewhere nobody thinks of as
     * holding secrets.
     */
    private const SUBMIT_FORM_MAX = 300;

    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
        private readonly SubmissionIssuer $issuer,
        private readonly SharedStoreInterface $store,
    ) {
    }

    /**
     * `GET /api/v1/public/forms/{slug}` — the form, as a stranger may see it.
     *
     * A form outside its submission window still RENDERS, with
     * `accepts_submissions: false` and the window dates beside it. That is a
     * deliberate departure from the blanket 404 above and it applies to the
     * WINDOW ONLY: somebody holding a genuine link that opens on the 1st, or a
     * poster printed in March being read in November, must be told which it is
     * rather than shown a page that says the link is wrong. The same argument
     * {@see FormsApiHandler::render()} makes about an archived form.
     *
     * It is safe to distinguish precisely because the caller had to hold a live
     * 256-bit slug to reach this branch at all: the states that could be probed
     * WITHOUT one are the states collapsed into {@see refuse()}.
     *
     * @param array<string, string> $params
     */
    public function render(Request $request, array $params): Response
    {
        $throttled = $this->throttle($request, 'render', self::RENDER_IP_MAX);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        try {
            $form = $this->resolve($params);
            if ($form === null) {
                return self::refuse();
            }

            // The ANSWERABLE fields only, and the same filter the submit path
            // applies — see PublicFormView::answerableFields(). A field a
            // stranger is not shown must not be a field their submission is
            // validated against.
            $fields = PublicFormView::answerableFields(
                $this->fields->listForForm((int) $form['tenant_id'], (int) $form['id'])
            );

            return Response::json(['data' => PublicFormView::form($form, $fields)]);
        } catch (\Throwable $e) {
            // The generic sentence, never the exception's. A public endpoint that
            // echoed a driver message would leak schema to the audience that
            // should learn least from it, and ExceptionLeakageTest refuses it
            // structurally.
            error_log('[PublicFormsApiHandler] render failed: ' . $e->getMessage());

            return Response::error('This form is temporarily unavailable', 503);
        }
    }

    /**
     * `POST /api/v1/public/forms/{slug}/submissions` — record an answer from
     * somebody with no account.
     *
     * The body is `{"data": {...}}` and NOTHING ELSE IS READ. Not
     * `submitted_by_profile_id`, not `form_version`, not `tenant_id`, not
     * `route_template_id` — the same rule {@see FormSubmissionsApiHandler::submit()}
     * states for authenticated callers, and it matters more here: a body that
     * could set any of those would let an anonymous stranger sign a declaration
     * in somebody's name or aim it at a flow the organisation did not choose.
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        $throttled = $this->throttle($request, 'submit', self::SUBMIT_IP_MAX);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        try {
            $form = $this->resolve($params);
            if ($form === null) {
                return self::refuse();
            }

            $tenantId = (int) $form['tenant_id'];
            $formId = (int) $form['id'];

            // The SAME filter the render applied, computed once and used for
            // both the gate below and the validation. Passing the full field list
            // to the issuer would validate the caller against questions they were
            // never shown, and would put a `profile_ref` answer back on the path
            // to SubmissionIssuer's existence check — the membership oracle
            // PublicFormView exists to remove.
            $fields = PublicFormView::answerableFields($this->fields->listForForm($tenantId, $formId));

            if (!PublicFormLink::acceptsPublicSubmissions($form) || $fields === []) {
                // The form is real and the caller holds its live link — they got
                // past resolve() — so this refusal says WHICH state it is in
                // rather than collapsing to the 404. Somebody who arrives the day
                // after a deadline needs to know they missed it; telling them
                // "not found" would send them looking for a broken link that is
                // not broken.
                //
                // `$fields === []` is the same third condition
                // {@see PublicFormView::form()} applies to `accepts_submissions`,
                // and it is here so the WRITE cannot disagree with the READ. A
                // form that has ended up asking a stranger nothing must refuse
                // rather than record an empty submission and report success —
                // and the sentence is the same one, because "why" is the
                // organisation's business, not this caller's.
                return Response::error('This form is not accepting submissions right now', 422);
            }

            $ceiling = $this->throttleForm($tenantId, $formId);
            if ($ceiling instanceof Response) {
                return $ceiling;
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
                // NO SUBMITTER. See the class docblock, point 5 — null rather
                // than a sentinel profile, and every reader of the column already
                // tolerates it.
                null,
                $form,
                $fields,
                $answers,
            );

            $submission = $result['submission'];

            return Response::json([
                // NOT the submission row. It carries `id`, `tenant_id`,
                // `form_id`, `document_id` and `form_version` — internal
                // identifiers that would hand an anonymous caller a set of
                // integers to try elsewhere, and a document id is the one that
                // would hurt. What a submitter needs is confirmation and
                // something to quote if they have to ask about it later; the
                // timestamp is that, and it is a fact about their own act.
                'data' => [
                    'received' => true,
                    'submitted_at' => $submission['submitted_at'] ?? null,
                ],
                'meta' => [
                    // Whether it actually started circulating. A form with no
                    // route template records the answers and stops there, which
                    // is a legitimate configuration — and a client that could not
                    // tell would tell the submitter their request is on its way
                    // when nothing is moving.
                    'routed' => $result['routed'],
                    // Answer keys that matched no field they were shown. Reported
                    // rather than swallowed, for the reason the authenticated path
                    // reports it — and here it also names the fields this surface
                    // deliberately does not serve, which is the honest way to say
                    // "that question was not asked of you".
                    'ignored_keys' => $result['ignored'],
                ],
            ], 201);
        } catch (FormRejectedException $e) {
            // ->clientMessage, never ->getMessage() (WC-186). These messages name
            // the person's OWN field label — "Contact number is required" — which
            // is written for whoever is looking at the form. They disclose only
            // labels the caller was already shown.
            return Response::error($e->clientMessage, 422);
        } catch (\Throwable $e) {
            error_log('[PublicFormsApiHandler] submit failed: ' . $e->getMessage());

            return Response::error('Your submission could not be recorded. Please try again.', 503);
        }
    }

    /**
     * Slug → form, or null for every reason there is no publicly-served form
     * behind it.
     *
     * THE ONLY PLACE EITHER HANDLER OBTAINS A TENANT. One function, so a second
     * path cannot be written that resolves it from somewhere else, and so the
     * shape check, the lookup and the public gate are applied in one order that
     * cannot vary between the two endpoints.
     *
     * The tenant is locked into {@see TenantContext} on success — see the class
     * docblock, point 1. It is safe to lock here because the public-route
     * exemption means the middleware never resolved (and therefore never locked)
     * anything, and {@see \Whity\Http\HttpKernel} resets the context at the start
     * of every request.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function resolve(array $params): ?array
    {
        $slug = $params['slug'] ?? '';
        if (!PublicFormLink::looksLikeSlug($slug)) {
            return null;
        }

        $form = $this->forms->findByPublicSlug($slug);
        if ($form === null || !PublicFormLink::servesPublicly($form)) {
            return null;
        }

        TenantContext::setTenantId((int) $form['tenant_id']);

        return $form;
    }

    /**
     * THE one refusal, for every way there is nothing here.
     *
     * Written as a single function so the several ways to say "no" cannot drift
     * into several slightly different bodies — which is how an oracle appears in
     * code that was written not to be one. The sentence names no organisation, no
     * form and no reason.
     */
    private static function refuse(): Response
    {
        return Response::error('This form link is not valid, or is no longer open', 404);
    }

    /**
     * Per-IP ceiling, counted BEFORE the slug is examined.
     *
     * Counted first so the boundary carries no information about whether a slug
     * was real: a limiter that fired later for genuine links than for invented
     * ones would put back, in timing, exactly the distinction {@see refuse()}
     * removes from the body. Same construction as
     * {@see InvitationAcceptHandler::throttle()} and
     * {@see DocumentVerificationApiHandler::throttle()}.
     *
     * A request with no derivable client IP is NOT bucketed together here. The
     * platform's own pre-auth limiter ({@see \Whity\Core\RateLimit\RateLimitRule::ip()})
     * already fails closed on that case with a shared `unknown` bucket, and
     * duplicating the decision in two places is how the two come to disagree.
     */
    private function throttle(Request $request, string $namespace, int $max): ?Response
    {
        $ip = ClientIp::fromRequest($request);
        if ($ip === null) {
            return null;
        }

        return $this->count('pubform:' . $namespace . ':ip:' . $ip, $max);
    }

    /**
     * Per-FORM ceiling, counted after the form is known — which is the only time
     * it CAN be counted, and costs nothing, because reaching it requires a live
     * slug.
     */
    private function throttleForm(int $tenantId, int $formId): ?Response
    {
        return $this->count('pubform:submit:form:' . $tenantId . ':' . $formId, self::SUBMIT_FORM_MAX);
    }

    /**
     * One fixed-window counter, checked then incremented.
     *
     * Checked BEFORE incrementing so the limit is a ceiling rather than a
     * ceiling-minus-one, matching the two existing public throttles exactly.
     */
    private function count(string $key, int $max): ?Response
    {
        if ($this->store->count($key) >= $max) {
            return Response::error('Too many attempts. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }

        $this->store->increment($key, self::WINDOW_SECONDS);

        return null;
    }
}
