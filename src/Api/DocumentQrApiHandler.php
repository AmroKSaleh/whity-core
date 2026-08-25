<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;

/**
 * The AUTHENTICATED half of document QR verification (#1036):
 *
 *   GET    /api/v1/documents/{id}/qr             — the record page's panel
 *   POST   /api/v1/documents/{id}/qr             — issue a new code, retiring the old
 *   DELETE /api/v1/documents/{id}/qr             — stop honouring the current code
 *   GET    /api/v1/documents/by-verification/{token} — which record is this?
 *
 * THE LAST ROUTE IS THE POINT OF THE WHOLE FEATURE, AND ITS RISK
 * --------------------------------------------------------------
 * It is the "scan through to the record" half: somebody with a session scans a
 * printed document and lands on `/admin/document-library/{id}`. And it is the
 * one place where a token could conceivably be mistaken for authority, so it is
 * written to make that mistake impossible to make quietly:
 *
 *   - the ROUTE is gated on `documents:read`, exactly like every other read of a
 *     document, so a caller without it never reaches this class;
 *   - the ROW is gated on {@see DocumentVisibilityPolicy::canView()}, UNCHANGED,
 *     which is the same call `GET /api/v1/documents/{id}` makes;
 *   - a caller who fails it gets `404 Document not found` — the SAME sentence,
 *     the same status, and the same absence of any hint that the document
 *     exists, as they get today from the id route.
 *
 * The token is used for exactly one thing: turning a string into a document id.
 * It is not consulted by the visibility policy, it is not passed to it, and the
 * policy has no parameter that could receive it. So a person holding a
 * photograph of somebody else's document gets what they would get by guessing
 * the id: nothing. `DocumentQrTokenGrantsNothingTest` proves it by mutation —
 * weakening the visibility call there turns the test red.
 *
 * WHY THIS IS A SEPARATE ROUTE FROM THE PUBLIC ONE
 * ------------------------------------------------
 * The obvious design is one endpoint that returns more when the caller is signed
 * in. It was rejected: an endpoint whose disclosure depends on who is asking has
 * to authenticate on a path that is also reachable anonymously, which means the
 * anonymous branch and the authorised branch share a code path, a tenant
 * resolution and a set of assumptions — and the anonymous branch is the one that
 * must never learn a document id. Two routes cost one extra registration and
 * make each one's audience a fact about the route rather than a branch inside
 * it. {@see DocumentVerificationApiHandler} is the anonymous one and it cannot
 * return an id, because it has no code that could.
 */
final class DocumentQrApiHandler
{
    /** Scans returned with the panel. A page, not a log; the total is exact. */
    private const RECENT_SCANS = 25;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly DocumentQrService $qr,
        private readonly DocumentQrScanRepository $scans,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * `GET /api/v1/documents/{id}/qr` — the record page's verification panel.
     *
     * Returns the live code (including its URL, so the client can draw the
     * symbol itself with the barcode renderer already in `@amroksaleh/ui`), how
     * many times the document has been scanned, and the most recent scans.
     *
     * THE URL — and therefore the token — IS RETURNED HERE, to a caller who has
     * already passed `canView`. That is not a widening: the same caller can
     * download the artifact, which has the code printed on it. Refusing to show
     * it in the panel while serving the PDF that carries it would be theatre.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $resolved = $this->resolve($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;
        $documentId = (int) $document['id'];

        try {
            $token = $this->qr->active($tenantId, $documentId);

            return Response::json([
                'data' => [
                    'enabled' => $this->enabledFor($tenantId, $document),
                    // Distinguished from `enabled` deliberately: "the operator
                    // never told this instance its own public address" and "this
                    // tenant switched the feature off" are different problems
                    // with different fixes, and one flag for both would send
                    // somebody to the wrong settings page.
                    'configured' => $this->qr->isConfigured(),
                    'token' => $token === null ? null : [
                        'reference' => $this->qr->reference((string) $token['token']),
                        'verification_url' => $this->qr->verificationUrl((string) $token['token']),
                        'issued_at' => $token['issued_at'] ?? null,
                        'issued_by' => $token['issued_by'] ?? null,
                    ],
                    'scans' => [
                        'total' => $this->scans->countForDocument($tenantId, $documentId),
                        'recent' => $this->scans->listForDocument(
                            $tenantId,
                            $documentId,
                            self::RECENT_SCANS,
                            0,
                        ),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[DocumentQrApiHandler] reading the verification panel failed: ' . $e->getMessage());

            return Response::error('The verification code could not be read', 503);
        }
    }

    /**
     * `POST /api/v1/documents/{id}/qr` — issue a new code.
     *
     * ALWAYS ROTATES, and says so in its own docblock rather than in a comment
     * on the button: the previous code is retired as
     * {@see \Whity\Core\Document\Qr\QrRevocationReason::SUPERSEDED} in the same
     * transaction. Anybody holding an older printing stops being able to confirm
     * it, which is the entire reason an operator would press this — and which is
     * why it is a deliberate act rather than something a re-render does behind
     * their back.
     *
     * Refuses when the feature is off for this document rather than minting a
     * code nothing would print. A code that exists and appears nowhere is the
     * mirror image of the failure #1036 forbids.
     *
     * @param array<string, string> $params
     */
    public function mint(Request $request, array $params): Response
    {
        $resolved = $this->resolve($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        if (!$this->qr->isConfigured()) {
            return Response::error(
                'This instance has no public address configured, so a verification code could not be used',
                503
            );
        }
        if (!$this->enabledFor($tenantId, $document)) {
            return Response::error(
                'QR verification is switched off for this template or this organisation',
                409
            );
        }

        try {
            $token = $this->qr->mint($tenantId, (int) $document['id'], $callerId);
        } catch (\Throwable $e) {
            error_log('[DocumentQrApiHandler] minting a verification code failed: ' . $e->getMessage());

            return Response::error('The verification code could not be issued', 503);
        }

        if ($token === null) {
            return Response::error('The verification code could not be issued', 503);
        }

        return Response::json([
            'data' => [
                'reference' => $this->qr->reference((string) $token['token']),
                'verification_url' => $this->qr->verificationUrl((string) $token['token']),
                'issued_at' => $token['issued_at'] ?? null,
                'issued_by' => $token['issued_by'] ?? null,
            ],
        ], 201);
    }

    /**
     * `DELETE /api/v1/documents/{id}/qr` — stop honouring the current code.
     *
     * THE ANSWER TO "PAPER CANNOT BE RECALLED". The symbol stays legible on every
     * copy in the world and stops confirming anything; the row survives with its
     * timestamps, so somebody can still answer "was this code live when the
     * letter was received".
     *
     * 204 whether or not a code was live. Reporting "there was nothing to
     * withdraw" as an error would make an operator clicking twice see a failure
     * for a state that is exactly what they wanted, and it would tell a caller
     * probing the route whether a document has a code — which is a thing the
     * public endpoint is careful not to say.
     *
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        $resolved = $this->resolve($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        try {
            $this->qr->revoke($tenantId, (int) $document['id'], $callerId);
        } catch (\Throwable $e) {
            error_log('[DocumentQrApiHandler] withdrawing a verification code failed: ' . $e->getMessage());

            return Response::error('The verification code could not be withdrawn', 503);
        }

        return Response::json([], 204);
    }

    /**
     * `GET /api/v1/documents/by-verification/{token}` — which record is this?
     *
     * THE SCAN-THROUGH. A signed-in person scans a printed document and this
     * turns the code into the id their browser navigates to.
     *
     * WHAT MAKES IT SAFE, stated plainly because it is the security claim of the
     * whole feature: the token selects a ROW, and
     * {@see DocumentVisibilityPolicy::canView()} then decides — with no
     * knowledge that a token was involved, because it takes no parameter that
     * could carry one. A caller without reach gets `404 Document not found`,
     * the same sentence `GET /api/v1/documents/{id}` gives them today, so
     * holding the paper has told them nothing they did not already know from the
     * public verification page.
     *
     * THE SCAN IS RECORDED WITH THE PRINCIPAL, and only after the check. An
     * authenticated scanner is a user of the tenant, already named in the audit
     * log and the routing trail for far more consequential acts, so naming them
     * here is consistent rather than new — and recording BEFORE the check would
     * let a caller with no reach write rows against a document they cannot see.
     *
     * @param array<string, string> $params
     */
    public function resolveToken(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $callerId = $this->callerId($request);
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        try {
            $tokenRow = $this->qr->resolve($params['token'] ?? '');

            // The tenant check is a plain equality rather than a policy call:
            // the token names its own tenant, and a code minted in another
            // tenant is not this caller's document by any reading. Collapsed
            // into the same 404 as "no reach", so a cross-tenant probe cannot
            // tell the two apart either.
            $document = null;
            if ($tokenRow !== null && (int) $tokenRow['tenant_id'] === $tenantId) {
                $document = $this->documents->findById((int) $tokenRow['document_id'], $tenantId);
            }

            if ($document === null
                || !$this->visibility->canView(
                    $document,
                    $callerId,
                    ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId),
                )) {
                return Response::error('Document not found', 404);
            }

            if ($tokenRow !== null) {
                $this->qr->recordScan($tokenRow, $callerId);
            }

            return Response::json([
                'data' => [
                    'id' => (int) $document['id'],
                    // Whether the code that got them here is still honoured. A
                    // person who scanned a superseded printing should be told
                    // so even though they can read the record — otherwise they
                    // walk away believing the paper in their hand is current.
                    'code_honoured' => $tokenRow !== null && ($tokenRow['revoked_at'] ?? null) === null,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[DocumentQrApiHandler] resolving a verification code failed: ' . $e->getMessage());

            return Response::error('The verification code could not be read', 503);
        }
    }

    /**
     * Scopes 1 and 2 for this document, re-read rather than remembered.
     *
     * The template is re-read because scope 2 lives inside its JSON and a
     * document whose template was deleted (`document_template_id → SET NULL`)
     * inherits only scope 1.
     *
     * @param array<string, mixed> $document
     */
    private function enabledFor(int $tenantId, array $document): bool
    {
        $templateId = $document['document_template_id'];
        $template = is_int($templateId) ? $this->templates->findById($templateId, $tenantId) : null;
        $templateData = is_array($template['data'] ?? null) ? $template['data'] : [];

        return DocumentQrPolicy::enabled($this->settings->effective($tenantId), $templateData);
    }

    /**
     * Tenant, caller and the document — or the 404 that says nothing.
     *
     * The SAME pair of checks `GET /api/v1/documents/{id}` makes, in the same
     * order, producing the same sentence. Not a re-implementation: the row gate
     * is one call into the one policy, and the message is copied verbatim so a
     * caller cannot tell which of this API's document routes refused them.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolve(Request $request, array $params): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $callerId = $this->callerId($request);
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        $document = $this->documents->findById((int) ($params['id'] ?? 0), $tenantId);
        if ($document === null
            || !$this->visibility->canView(
                $document,
                $callerId,
                ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId),
            )) {
            return Response::error('Document not found', 404);
        }

        return [$tenantId, $callerId, $document];
    }

    private function callerId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }
}
