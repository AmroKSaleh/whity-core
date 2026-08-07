<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * The `whity_render` internal-HTTP contract {@see DocumentRenderApiHandler}
 * depends on (ADR 0012). Extracted as an interface (rather than a concrete
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
     * still fail safely if {@see render()} is called anyway.
     */
    public function isConfigured(): bool;

    /**
     * POST the render payload to `whity_render` and return the raw PDF bytes.
     *
     * @param array<string, mixed> $payload {template, dataRows, sheet, blocks}
     * @throws RenderServiceUnavailableException On any transport failure, a
     *         non-200 response, or a 200 body that is not a PDF.
     */
    public function render(array $payload): string;
}
