<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

use Whity\Core\Audit\AuditContext;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrStamp;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Render\DocumentRenderer as SdkRenderer;
use Whity\Sdk\Render\FlowDocument;
use Whity\Sdk\Render\IssuedDocument;
use Whity\Sdk\Render\RenderedDocument as SdkRenderedDocument;
use Whity\Sdk\Render\RenderRejectedException;
use Whity\Sdk\Render\RenderUnavailableException;
use Whity\Storage\StorageException;

/**
 * The host side of the SDK's document-render seam (#1072, SDK 1.41).
 *
 * Registered in the container under {@see SdkRenderer}, so a plugin resolves
 * the CONTRACT and never this class:
 *
 *     \Whity\app(\Whity\Sdk\Render\DocumentRenderer::class)
 *
 * It owns four jobs and no others.
 *
 * 1. TENANT AND ACTOR, FROM THE HOST'S OWN STATE. The SDK signatures carry
 *    neither, deliberately — see the interface's note. Both are read here from
 *    the request-scoped context the host sets and clears
 *    ({@see TenantContext}, {@see AuditContext}), which is the same pair the
 *    audit trail and every tenant predicate in the codebase read. A plugin
 *    cannot pass a tenant id because there is nowhere to pass one, so the
 *    failure where a document is built from one tenant's content and filed in
 *    another's storage has no expression in this API.
 *
 * 2. EXCEPTION TRANSLATION. Core's rejection and unavailability types become
 *    the SDK's. This is not ceremony: the SDK forbids referencing
 *    core namespaces at all, so a plugin that caught a core exception would be
 *    catching a class its own contract says does not exist — and would break
 *    the first time core reorganised. The rejection's `clientMessage` is
 *    carried across intact, because that sentence is the entire value of a 422.
 *
 * 3. ISSUANCE POLICY AND ORDER. `documents.persist_enabled` and this tenant's
 *    ceilings are checked BEFORE anything is written, so a refusal leaves no
 *    row and does not first spend a headless-browser page it is going to
 *    discard. The full ordering, and why the record is committed before the
 *    render, is on {@see issue()}.
 *
 * 4. THE VERIFICATION CODE. When the tenant has codes switched on, one is
 *    minted against the document's own id and placed at the end of the
 *    document. A plugin cannot do this and cannot fake it — see
 *    {@see withVerificationCode()}.
 *
 * WHAT IT DOES NOT OWN
 * --------------------
 * Ceilings and the render call belong to {@see FlowDocumentRenderer}; the
 * record and the artifact belong to {@see DocumentIssuer}. Both are shared with
 * the HTTP path, which is the point: a plugin rendering a document and a person
 * rendering one go through the same ceilings, the same storage routing and the
 * same immutability rule. A second implementation for plugins is how one of the
 * two ends up enforcing something the other does not.
 *
 * A PLUGIN-ISSUED DOCUMENT HAS NO TEMPLATE
 * -----------------------------------------
 * `document_template_id` is null and `template_name` is empty, and that is
 * accurate rather than missing: the content came from a plugin's own data, not
 * from a stored designer template, and there is nothing to snapshot. It is left
 * empty instead of being filled with a plausible label because this seam has no
 * trustworthy way to learn WHICH plugin is calling — the container hands one
 * shared instance to every caller — and a source field that a caller could
 * simply assert would be worth less than a blank one.
 */
final class SdkDocumentRenderer implements SdkRenderer, HostWiredService
{
    public function __construct(
        private readonly FlowDocumentRenderer $flow,
        private readonly DocumentIssuer $issuer,
        private readonly SettingsService $settings,
        /**
         * Nullable because verification codes are an OPTIONAL subsystem
         * (#1036): `documents.qr_enabled` defaults to false, and an instance
         * that never turns it on has no signing key configured for one. Null
         * here means documents are issued without a code, which is an ordinary
         * state, not a degraded one.
         */
        private readonly ?DocumentQrService $qr = null,
    ) {
    }

    public function isAvailable(): bool
    {
        $tenantId = TenantContext::getTenantId();

        // No tenant context is "no" rather than an exception. This method exists
        // to be called speculatively, before the expensive assembly, and a
        // predicate that throws is one every caller has to wrap.
        return $tenantId !== null && $this->flow->isEnabled($tenantId);
    }

    public function render(FlowDocument $document): SdkRenderedDocument
    {
        $tenantId = $this->requireTenant();
        $payload = $document->toPayload();

        $this->requireEnabled($tenantId);

        $rendered = $this->callRenderer($tenantId, $payload);

        return SdkRenderedDocument::of(
            $rendered->bytes,
            $rendered->pageCount,
            $rendered->frontMatterPages,
        );
    }

    /**
     * THE ORDER HERE IS THE DESIGN, so it is worth stating before the code.
     *
     *   1. Everything that can refuse WITHOUT writing runs first: tenant
     *      context, the feature switch, the persist switch, and this tenant's
     *      ceilings. Nothing exists yet, so nothing has to be cleaned up.
     *   2. The RECORD is raised, with no artifact. It gets an id.
     *   3. The verification code is minted AGAINST THAT ID and appended to the
     *      document. This is the step that forces the whole ordering: a code
     *      encodes a document id, and an id only exists once a row does.
     *   4. The render happens.
     *   5. The artifact is appended to the record from (2).
     *
     * WHY THE RECORD IS COMMITTED BEFORE THE RENDER. The alternative is one
     * transaction spanning all five steps, which would be atomic and would hold
     * a database transaction open across a multi-second HTTP call to a
     * headless-browser container — on a hundred-page document, tens of seconds
     * of a held connection per render. `POST /api/documents` already made this
     * call in the same direction, deliberately and for the same reason.
     *
     * WHAT THAT MEANS WHEN THE RENDER FAILS AT STEP 4. The record survives and
     * this returns an {@see IssuedDocument} whose `contentUrl` is null, rather
     * than throwing. Throwing would be worse than either option: the row exists
     * either way, and an exception would leave the caller with no id for it —
     * an orphan nothing can find. `hasArtifact()` is therefore LOAD-BEARING on
     * this path, not a convenience. The bytes can be minted later against the
     * same id; the document's identity, which is what routing and verification
     * key off, is already real.
     */
    public function issue(FlowDocument $document, string $title): IssuedDocument
    {
        $tenantId = $this->requireTenant();
        // Built BEFORE the persist check so a malformed document is reported as
        // malformed even on an instance where persisting is off — otherwise the
        // same call answers two different complaints depending on a setting the
        // caller cannot see, and the one it hears first is the less useful.
        $payload = $document->toPayload();

        $this->requireEnabled($tenantId);

        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            throw RenderRejectedException::because(
                'Storing rendered documents is disabled for this tenant'
            );
        }

        // Step 1, the last refusal that costs nothing.
        try {
            $this->flow->check($tenantId, $payload);
        } catch (DocumentRenderRejectedException $e) {
            throw RenderRejectedException::because($e->clientMessage);
        }

        $actorId = AuditContext::getActorUserId();
        $recordTitle = mb_substr(trim($title) !== '' ? trim($title) : 'Untitled document', 0, 255);

        // Step 2.
        try {
            $record = $this->issuer->raise(
                $tenantId,
                $actorId,
                // No template: see the class note. The shape is the normalised
                // template row DocumentIssuer reads, with both fields absent.
                ['id' => null, 'name' => ''],
                $recordTitle,
            );
        } catch (\Throwable $e) {
            error_log('[SdkDocumentRenderer] raising a document failed: ' . $e->getMessage());

            throw new RenderUnavailableException('The document could not be recorded');
        }

        $documentId = (int) $record['id'];

        // Step 3.
        $payload = $this->withVerificationCode($payload, $tenantId, $documentId, $actorId, $effective);

        // Steps 4 and 5. Past this point the record EXISTS, so a failure is
        // reported as a document without an artifact rather than as an
        // exception the caller cannot attach to anything.
        try {
            $rendered = $this->callRenderer($tenantId, $payload);
        } catch (RenderRejectedException | RenderUnavailableException $e) {
            error_log(
                '[SdkDocumentRenderer] document ' . $documentId
                . ' was raised but could not be rendered: ' . $e->getMessage()
            );

            return IssuedDocument::of($documentId, $recordTitle, 0, 0, 0, null);
        }

        try {
            $artifact = $this->issuer->appendArtifact($tenantId, $actorId, $record, $rendered->bytes);
        } catch (StorageException $e) {
            error_log('[SdkDocumentRenderer] storing document ' . $documentId . ' failed: ' . $e->getMessage());

            return IssuedDocument::of($documentId, $recordTitle, $rendered->pageCount, $rendered->frontMatterPages, 0, null);
        } catch (\Throwable $e) {
            error_log('[SdkDocumentRenderer] recording the artifact for document ' . $documentId . ' failed: ' . $e->getMessage());

            return IssuedDocument::of($documentId, $recordTitle, $rendered->pageCount, $rendered->frontMatterPages, 0, null);
        }

        return IssuedDocument::of(
            $documentId,
            $recordTitle,
            $rendered->pageCount,
            $rendered->frontMatterPages,
            (int) ($artifact['byte_size'] ?? strlen($rendered->bytes)),
            DocumentPresenter::documentContentUrl($documentId),
        );
    }

    /**
     * Append the platform's verification code to the document, when this tenant
     * has them switched on.
     *
     * A PLUGIN CANNOT DO THIS AND CANNOT FAKE IT. {@see FlowDocument} offers no
     * method that produces a `qr` block — the builder is the only way content
     * reaches this method, so the one mark on a document that attests to where
     * it came from is minted by the platform, against a real document id, or it
     * is absent. A seam that let a caller supply its own verification code
     * would let a caller print a document that looks verified and resolves to
     * nothing, which is worse than one that carries no code at all.
     *
     * PLACED AT THE END, because that is the only position that exists. The
     * fixed-canvas mode puts the code where a designer dropped it, which it can
     * because it knows the page count and what is on each page before it prints.
     * Here neither is known until after layout — that is what "flowing" means —
     * so the code is an ordinary block and lands where the document ends, which
     * is where a signature would go.
     *
     * NOT COUNTED AGAINST THE TENANT'S CEILING, and that is deliberate. The
     * ceilings bound what the CALLER asked for; this block is the platform's,
     * is one block of a few hundred bytes, and re-measuring after appending it
     * would make "is this document verifiable" depend on how near a limit its
     * author happened to land — for a document core has already accepted and
     * already written a row for.
     *
     * A FAILURE HERE IS NOT A FAILURE OF THE DOCUMENT. If minting throws, the
     * document renders without a code and the reason is logged. Losing the
     * document over its verification code would be the wrong trade: the record
     * exists, `DocumentQrService::ensure()` is idempotent, and a code can be
     * minted against the same id afterwards.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $effective
     * @return array<string, mixed>
     */
    private function withVerificationCode(
        array $payload,
        int $tenantId,
        int $documentId,
        ?int $actorId,
        array $effective,
    ): array {
        if ($this->qr === null || !DocumentQrPolicy::enabledForTenant($effective)) {
            return $payload;
        }

        try {
            $token = $this->qr->ensure($tenantId, $documentId, $actorId);
        } catch (\Throwable $e) {
            error_log(
                '[SdkDocumentRenderer] minting the verification code for document '
                . $documentId . ' failed: ' . $e->getMessage()
            );

            return $payload;
        }

        if ($token === null) {
            return $payload;
        }

        $stamp = DocumentQrStamp::forToken($this->qr, (string) $token['token']);

        $content = $payload['content'];
        $content[] = ['type' => 'qr', 'value' => $stamp->url, 'reference' => $stamp->reference];
        $payload['content'] = $content;

        return $payload;
    }

    /**
     * Render, translating core's failure vocabulary into the SDK's.
     *
     * @param array<string, mixed> $payload
     *
     * @throws RenderRejectedException
     * @throws RenderUnavailableException
     */
    private function callRenderer(int $tenantId, array $payload): RenderedDocument
    {
        try {
            return $this->flow->render($tenantId, $payload);
        } catch (DocumentRenderRejectedException $e) {
            // ->clientMessage, never ->getMessage(): the first is a sentence
            // written for a reader, the second is whatever the nearest throw
            // site put there and may wrap a cause.
            throw RenderRejectedException::because($e->clientMessage);
        } catch (RenderServiceUnavailableException $e) {
            error_log('[SdkDocumentRenderer] render failed: ' . $e->getMessage());

            throw new RenderUnavailableException('Document rendering is temporarily unavailable');
        } catch (\Throwable $e) {
            // Anything unforeseen is an outage from the caller's side, not a
            // rejection: telling a plugin its document is malformed when the
            // truth is that something else broke would send it rewriting
            // content that was never the problem.
            error_log('[SdkDocumentRenderer] unexpected render failure: ' . $e->getMessage());

            throw new RenderUnavailableException('Document rendering is temporarily unavailable');
        }
    }

    /**
     * @throws RenderRejectedException When there is no tenant context.
     */
    private function requireTenant(): int
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            // A rejection, not an outage. There is no tenant to render for and
            // no amount of retrying produces one — this is a call made outside
            // a request or a job, where the host never set the context.
            throw RenderRejectedException::because(
                'Rendering requires a tenant context; call this from a request or a queued job'
            );
        }

        return $tenantId;
    }

    /**
     * @throws RenderUnavailableException When rendering is off or unconfigured.
     */
    private function requireEnabled(int $tenantId): void
    {
        if (!$this->flow->isEnabled($tenantId)) {
            throw new RenderUnavailableException(
                'Server-side document rendering is disabled or not configured on this instance'
            );
        }
    }
}
