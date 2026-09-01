<?php

declare(strict_types=1);

namespace Whity\Sdk\Render;

/**
 * The platform's document press (SDK 1.41).
 *
 * A plugin declares content — sections, tables resolved from its own data,
 * attached images — and receives a document. Resolve it from the container:
 *
 *     $renderer = \Whity\app(\Whity\Sdk\Render\DocumentRenderer::class);
 *
 *     $doc = FlowDocument::rightToLeft()
 *         ->withTitle('تقرير الامتثال')
 *         ->withContents()
 *         ->withListOfTables()
 *         ->heading(1, 'الملخص التنفيذي')
 *         ->paragraph($summary)
 *         ->table(['البند', 'الحالة'], $rows, caption: 'نتائج التقييم');
 *
 *     $issued = $renderer->issue($doc, 'تقرير الامتثال 2026');
 *
 * WHY THIS EXISTS
 * ---------------
 * It did not, and the consequence was visible in this repository's own
 * consumers. A plugin holding structured content had no supported way to turn
 * it into a document, so it did the only thing left: shipped JSON and asked
 * someone to print a web page, or built a renderer of its own. That is not a
 * plugin author's mistake; it is what a missing seam produces — the same
 * reasoning that put the artifact store in the container. An invoice, a
 * certificate, a statement of account, a compliance submission and a contract
 * are one shape: plugin-owned content, platform-owned rendering.
 *
 * THERE IS NO TENANT ARGUMENT, AND THAT IS THE POINT
 * ---------------------------------------------------
 * Every method here works on the tenant of the CURRENT REQUEST, which the host
 * owns and a plugin cannot set. A tenant id in this signature would be a
 * parameter a plugin could get wrong — or be made to get wrong by its own
 * caller — and the failure would be a document built from one tenant's content,
 * counted against a second tenant's ceilings, and filed in a third's storage.
 * The seam does not offer that mistake. A plugin that needs to render for
 * another tenant does it the way everything else does: inside that tenant's
 * request, or a job the host runs in that tenant's context.
 *
 * TWO OUTCOMES
 * ------------
 *   {@see render()} — bytes back, NOTHING stored. For a preview, an email
 *      attachment, an export the user downloads and forgets.
 *   {@see issue()}  — a first-class platform document: a record with an id and
 *      an immutable artifact, which routing, verification and the organizer all
 *      already understand.
 *
 * Prefer `issue()` when the document is a thing that happened, and `render()`
 * when it is a view of something. The wrong choice in one direction fills a
 * tenant's storage with drafts; in the other it loses the audit trail for a
 * document somebody acted on.
 *
 * WHERE IT IS AVAILABLE (READ THIS BEFORE CALLING IT FROM A JOB)
 * --------------------------------------------------------------
 * As of SDK 1.41 the host registers this seam in the HTTP entry point only.
 * Resolving it from a CLI command or a queue worker throws the container's
 * ordinary "not registered" error, because issuing a document needs the
 * per-tenant storage stack and building a second copy of that in the CLI kernel
 * would be the split-backend hazard the host's storage factory warns about —
 * two drivers from one set of settings, disagreeing about where a tenant's
 * files live.
 *
 * This is a real limitation and not a permanent one: a long document assembled
 * from a plugin's own queries is exactly the work that belongs in a job. Until
 * it is wired there, render inside the request, or have the job enqueue the
 * document's INPUTS and render on the next request that needs it.
 *
 * FAILURE
 * -------
 *   {@see RenderRejectedException}    — the request will not be attempted: a
 *      ceiling exceeded, or a content tree the renderer refused. Its
 *      `clientMessage` is written to be shown. Retrying unchanged cannot help.
 *   {@see RenderUnavailableException} — the render tier could not do it, or is
 *      switched off for this instance. An ORDINARY outcome: rendering is an
 *      optional component and defaults to disabled, so every caller needs an
 *      answer for it. Check {@see isAvailable()} before assembling something
 *      expensive.
 */
interface DocumentRenderer
{
    /**
     * Whether a render could be attempted right now: the feature is enabled for
     * this instance and the render tier is configured.
     *
     * Advisory, not a guarantee — the container can stop between this call and
     * the next, so {@see RenderUnavailableException} still has to be handled.
     * What it is FOR is the expensive assembly: building a hundred-page tree
     * out of a plugin's own queries and then discovering the instance never had
     * rendering enabled is work nobody needed to do.
     */
    public function isAvailable(): bool;

    /**
     * Render to bytes. Nothing is stored and no record is created.
     *
     * @throws RenderRejectedException    The document will not be attempted.
     * @throws RenderUnavailableException The render tier could not do it.
     */
    public function render(FlowDocument $document): RenderedDocument;

    /**
     * Render and keep it: a platform document record plus an immutable
     * artifact, owned by the current tenant and raised by the current actor.
     *
     * THE PLATFORM MAY ADD A VERIFICATION CODE, and only the platform can. When
     * the tenant has verification codes switched on, the host mints one against
     * this document's id and places it at the end — a QR plus the short
     * reference printed beneath it, so a reader who cannot scan can still type
     * it in. {@see FlowDocument} deliberately offers no way to add one: a caller
     * that could supply its own could print a document that looks verified and
     * resolves to nothing, which is worse than one carrying no code at all.
     *
     * CHECK `hasArtifact()` ON THE RESULT. It is not a convenience. Creating the
     * record has to come BEFORE the render — a verification code encodes a
     * document id, and an id only exists once a row does — so if the render
     * itself fails, the record is already real. This returns it, with
     * `contentUrl` null, rather than throwing and leaving you no id for a row
     * that exists. The bytes can be minted later against the same id; the
     * identity, which is what routing and verification key off, is already
     * yours. Everything that CAN refuse without writing — a ceiling, a disabled
     * instance, a document with no content — still throws, before anything
     * exists.
     *
     * @param string $title What the document is recognised by in a list or an
     *                      inbox. Trimmed to the platform's title length.
     *
     * @throws RenderRejectedException    The document will not be attempted, or
     *         persisting is disabled for this tenant — both checked BEFORE
     *         anything is written, so a refusal leaves no row and does not
     *         spend a headless-browser page it is going to discard.
     * @throws RenderUnavailableException Rendering is off or unconfigured, or
     *         the record itself could not be written. Again: nothing exists
     *         when this throws.
     */
    public function issue(FlowDocument $document, string $title): IssuedDocument;
}
