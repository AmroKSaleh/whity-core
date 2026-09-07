<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\VerificationPresenter;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;

/**
 * The PUBLIC end of document QR verification (#1036):
 *
 *   GET /api/v1/document-verifications/{token}
 *
 * ONE ROUTE, ONE METHOD, NO STATE THE CALLER CHOSE
 * ------------------------------------------------
 * `GET` only. There is no POST, PUT or DELETE on this surface and there is no
 * body — the whole request is a path segment. The one write it performs (a scan
 * row) is the SERVER's record of having been asked, not a state change the
 * caller requested or can shape: they cannot choose its contents, cannot address
 * a different document with it, and cannot make more than one happen per minute
 * per code (see {@see DocumentQrService::recordScan()}).
 *
 * On CSRF: {@see \Whity\Http\Middleware\CsrfGuard} exempts reads because a read
 * has nothing to forge, so a `GET` route needs no accommodation from it — and
 * emphatically not the `X-Auth-Mode: token` exemption, which is the NATIVE
 * CLIENT escape hatch for cookie-less desktop callers and would be the wrong
 * tool wearing a plausible name. Nothing here is weakened; the guard simply does
 * not apply to the shape of request this route accepts.
 *
 * On tenant resolution: the route sits on
 * {@see \Whity\Http\Middleware\EnforceTenantIsolation}'s public-path list as an
 * ANCHORED PATTERN, not an open prefix, so it cannot make anything deeper public
 * by accident — the lesson `/api/v1/translations/` records in that file. There
 * is no session to resolve a tenant from and there never will be; the token
 * carries its own tenant and every read after the lookup binds it.
 *
 * THE THING THIS ROUTE MUST NOT BECOME
 * ------------------------------------
 * An oracle. Four states — a malformed token, an unknown one, a withdrawn one
 * and a superseded one — produce ONE answer at the default disclosure level,
 * with the same HTTP status, so a caller working through guesses learns nothing
 * about which values name a real document. A tenant may choose to distinguish
 * them ({@see VerificationPresenter}), which is a decision about their own
 * documents rather than a default anybody inherits.
 *
 * The per-IP throttle is counted BEFORE the token is looked at, for the same
 * reason and by the same construction as
 * {@see InvitationAcceptHandler::throttle()}: a boundary that arrived earlier
 * for real tokens than for invented ones would put back the distinction the body
 * removed.
 *
 * WHAT IT NEVER RETURNS
 * ---------------------
 * The document id, the title, any content, any recipient, any note, any
 * attachment, and any person's or unit's name. A signed-in caller who wants the
 * RECORD asks `GET /api/v1/documents/by-verification/{token}`, which is an
 * ordinary gated route where {@see \Whity\Core\Document\DocumentVisibilityPolicy}
 * decides exactly as it does today — 404 for a caller without reach, and not a
 * hint beyond what this page already said. Scanning confers nothing.
 */
final class DocumentVerificationApiHandler
{
    /**
     * Throttle: fixed window, per-IP, shared with nothing else.
     *
     * 60 an hour is generous for the real use — a person scanning documents at a
     * counter — and useless for enumeration: at that rate, working through a
     * 2^256 namespace is not a threat model, it is a joke. The limit is defence
     * in DEPTH behind the token's entropy, exactly as it is on the invitation
     * accept endpoint, and its real job is bounding the write and read work one
     * address can commission.
     */
    private const WINDOW_SECONDS = 3600;
    private const IP_MAX = 60;

    public function __construct(
        private readonly DocumentQrService $qr,
        private readonly DocumentRepository $documents,
        private readonly RouteEventRepository $events,
        private readonly SettingsService $settings,
        private readonly SharedStoreInterface $store,
    ) {
    }

    /**
     * `GET /api/v1/document-verifications/{token}` — is this paper genuine?
     *
     * Always 200, whatever the answer. A 404 for an unknown token beside a 200
     * for a withdrawn one would restore the distinction the body deliberately
     * removes, and it is the kind of mistake that gets made when the status code
     * and the payload are decided in different places. They are decided here,
     * together, once.
     *
     * @param array<string, string> $params
     */
    public function verify(Request $request, array $params): Response
    {
        $throttled = $this->throttle($request);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        $token = $params['token'] ?? '';

        try {
            return $this->answer($token);
        } catch (\Throwable $e) {
            // The generic sentence, not the exception's. A public endpoint that
            // echoed a driver message would leak schema to the one audience that
            // should learn least from it, and ExceptionLeakageTest refuses it
            // structurally.
            error_log('[DocumentVerificationApiHandler] verification failed: ' . $e->getMessage());

            return Response::error('Verification is temporarily unavailable', 503);
        }
    }

    /**
     * The answer, with every refusal path collapsing to one call.
     *
     * Written as a single method so the four ways to say "no" cannot drift into
     * four slightly different bodies — which is how an oracle appears in code
     * that was written not to be one.
     */
    private function answer(string $token): Response
    {
        $tokenRow = $this->qr->resolve($token);
        if ($tokenRow === null) {
            // Nothing resolved, so there is no tenant and therefore no tenant
            // preference to consult. `minimal` is the only honest level here and
            // it is also the one every other refusal collapses to by default.
            return $this->refuse(null, VerificationPresenter::DETAIL_MINIMAL);
        }

        $tenantId = (int) $tokenRow['tenant_id'];
        $effective = $this->settings->effective($tenantId);

        // THE TENANT SWITCH GATES HONOURING, NOT ONLY MINTING — and that is a
        // decision worth stating, because the opposite reading is defensible
        // too. Turning `documents.qr_enabled` off closes this tenant's public
        // surface immediately and REVERSIBLY: turn it back on and every code
        // that was live is live again. Revocation is the permanent tool and it
        // is per-document; a tenant that wants the whole surface shut should not
        // have to withdraw codes one at a time to get it, and should not be
        // unable to undo it when they change their mind.
        //
        // The refusal is the generic one, so a closed tenant is indistinguishable
        // from an unknown code — closing the surface must not announce that the
        // organisation has one.
        if (!DocumentQrPolicy::enabledForTenant($effective)) {
            return $this->refuse(null, VerificationPresenter::DETAIL_MINIMAL);
        }

        $detail = VerificationPresenter::detailLevel($effective);

        if (($tokenRow['revoked_at'] ?? null) !== null) {
            // Recorded BEFORE the refusal is returned, and recorded on purpose:
            // a scan of a withdrawn code is the most interesting scan there is —
            // paper the organisation has stopped standing behind is still in
            // circulation, and somebody just tried to rely on it.
            $this->qr->recordScan($tokenRow, null);

            return $this->refuse($tokenRow, $detail);
        }

        $document = $this->documents->findById((int) $tokenRow['document_id'], $tenantId);
        if ($document === null) {
            // Unreachable under the schema — the token cascades with its
            // document — so this is the branch that catches a future migration
            // that changes that. It refuses generically rather than crashing,
            // because a public page is the worst place to discover an invariant
            // broke.
            return $this->refuse(null, VerificationPresenter::DETAIL_MINIMAL);
        }

        $this->qr->recordScan($tokenRow, null);

        return Response::json([
            'data' => VerificationPresenter::verified(
                $tokenRow,
                $document,
                $this->qr->reference((string) $tokenRow['token']),
                $detail,
                $detail === VerificationPresenter::DETAIL_STAGE
                    ? $this->latestEvent($tenantId, (int) $tokenRow['document_id'])
                    : null,
            ),
        ], 200);
    }

    /**
     * @param array<string, mixed>|null $tokenRow
     */
    private function refuse(?array $tokenRow, string $detail): Response
    {
        return Response::json(['data' => VerificationPresenter::refusal($tokenRow, $detail)], 200);
    }

    /**
     * The newest trail row for a document, or null when it has never been routed.
     *
     * Two reads rather than one because
     * {@see RouteEventRepository::listForDocument()} is oldest-first — a trail is
     * read as a history from the beginning — and the routing subsystem's
     * repository is not this feature's to widen. A count plus a one-row window
     * is cheap on the composite index migration 112 declares, and it keeps the
     * trail's own contract untouched.
     *
     * @return array<string, mixed>|null
     */
    private function latestEvent(int $tenantId, int $documentId): ?array
    {
        $total = $this->events->countForDocument($documentId, $tenantId);
        if ($total <= 0) {
            return null;
        }

        $rows = $this->events->listForDocument($documentId, $tenantId, 1, $total - 1);

        return $rows[0] ?? null;
    }

    /**
     * Per-IP ceiling, counted before the token is examined.
     *
     * Counted first so the boundary carries no information about whether a token
     * was real — the same discipline, and the same shared-store counter shape,
     * as {@see InvitationAcceptHandler::throttle()}.
     *
     * A request with no derivable client IP is NOT throttled here rather than
     * being bucketed together: the platform's own pre-auth per-IP limiter
     * ({@see \Whity\Core\RateLimit\RateLimitRule::ip()}) already fails closed on
     * that case with a shared `unknown` bucket, and duplicating the decision in
     * two places is how the two come to disagree.
     */
    private function throttle(Request $request): ?Response
    {
        $ip = ClientIp::fromRequest($request);
        if ($ip === null) {
            return null;
        }

        $key = 'docqr:verify:ip:' . $ip;
        if ($this->store->count($key) >= self::IP_MAX) {
            return Response::error('Too many attempts. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }

        $this->store->increment($key, self::WINDOW_SECONDS);

        return null;
    }
}
