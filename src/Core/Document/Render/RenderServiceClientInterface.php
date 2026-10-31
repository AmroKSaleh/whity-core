<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * The `whity_render` internal-HTTP contract the document renderers depend on
 * (ADR 0012). Extracted as an interface (rather than a concrete
 * final class) purely for TESTABILITY: a real-engine test can inject a fake
 * that returns canned PDF bytes without any network I/O, so the handler's
 * feature-flag/RBAC/tenant-scoping/limits logic is exercised in isolation from
 * the actual render service — the real round-trip (Docker build + run +
 * genuine Puppeteer render) is proven separately (see
 * tests/Integration/DocumentRenderServiceDockerTest.php).
 */
interface RenderServiceClientInterface
{
    /**
     * Whether this client is usable (a configured base URL + a shared secret
     * meeting the minimum length). A disabled/misconfigured client should
     * still fail safely if a render is called anyway.
     */
    public function isConfigured(): bool;

    /**
     * The service's OTHER mode: a content tree with no positions, paginated by
     * the renderer.
     *
     * Kept as a separate method rather than a flag on {@see render()} because
     * the two modes share no payload field and no response shape — a caller
     * that guessed wrong would get a 422 describing a schema it never meant to
     * send. The return type differs for the same reason: only this mode has a
     * page count that the caller could not have worked out for itself.
     *
     * The service validates the whole content TREE and names the offending
     * field when it refuses one, so this mode is the main reason a 422 is
     * distinguished from an outage at all — see the note in
     * {@see RenderServiceClient}. Core does NOT re-implement those shape rules;
     * it enforces the tenant CEILINGS the service cannot know about
     * ({@see FlowDocumentRenderer}) and lets the service own shape.
     *
     * @param array<string, mixed> $payload {page, direction, content, ...}
     * @throws DocumentRenderRejectedException   The service answered 422: the
     *         content tree is not acceptable and retrying it cannot help.
     * @throws RenderServiceUnavailableException On any transport failure, any
     *         other non-200 response, or a 200 body that is not a PDF.
     */
    public function renderFlow(array $payload): RenderedDocument;

    /**
     * Label sheets (E.43): `POST /render/labels`, one label per entry of
     * `labels` on a fixed grid, as many sheets as it takes. The service owns
     * the shape (sheet, grid, each label's blocks) and names what it refuses.
     *
     * @param array<string, mixed> $payload {sheet, grid, labels, direction, ...}
     * @throws DocumentRenderRejectedException   The service answered 422.
     * @throws RenderServiceUnavailableException On any transport failure, any
     *         other non-200 response, or a 200 body that is not a PDF.
     */
    public function renderLabels(array $payload): RenderedDocument;
}
