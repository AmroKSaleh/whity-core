<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The tenant-policy layer in front of the render service's FLOWING mode
 * (#1072).
 *
 * The sibling of {@see DocumentRenderer}, which does the same job for the
 * fixed-canvas mode, and split from it for the reason that file's own docblock
 * gives: one place enforcing the ceilings, so a second entry point cannot end
 * up enforcing a limit the first does not. Everything HTTP — status codes,
 * RBAC, response shape — stays in the handler; everything plugin-facing stays
 * in {@see SdkDocumentRenderer}. What lives here is the part both need and
 * neither should own.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not validate the content tree. The render service does that — block
 * types, heading levels, table row shapes, figure sources, front-matter kinds —
 * and answers 422 naming the offending field, which
 * {@see RenderServiceClient} relays as a {@see DocumentRenderRejectedException}
 * rather than an outage. A second copy of those rules in PHP would be two
 * validators that drift, and the one that drifted would be the one nobody
 * exercised.
 *
 * So the division is: the SERVICE owns what a valid document is, and this owns
 * how much of one THIS TENANT may render. Neither can answer the other's
 * question — the service has no idea tenants exist, and core cannot know what
 * the paginator will accept.
 *
 * WHY THE CEILINGS BOUND THE INPUT AND NOT THE PAGE COUNT
 * -------------------------------------------------------
 * The fixed-canvas mode can refuse a render for producing too many pages,
 * because one template page is one PDF page and the total is arithmetic. A
 * flowing document is defined by not having that property: the page count is
 * decided by the paginator, from the content, at render time. There is no
 * honest pre-flight page ceiling to enforce, and a post-hoc one would refuse
 * work already paid for in full — the bytes exist by the time anyone can count
 * them. So the ceilings bound what CAN be measured in advance: how many blocks,
 * how large a table, how many bytes on the wire.
 */
final class FlowDocumentRenderer
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RenderServiceClientInterface $renderService,
    ) {
    }

    /**
     * Whether a flowing render could be attempted for this tenant: the
     * instance-wide feature is on AND the render client is configured.
     *
     * Both halves matter and they fail differently. The setting is an operator
     * DECISION (default false — a whole extra browser-bearing container is not
     * something a sovereign deployment gets by surprise); the client being
     * unconfigured is a deployment MISTAKE, typically a shared secret under the
     * 32-character minimum. A caller only needs to know it cannot render, but
     * the two are logged apart in {@see RenderServiceClient} so an operator can
     * tell which one they are looking at.
     */
    public function isEnabled(int $tenantId): bool
    {
        $effective = $this->settings->effective($tenantId);

        return ($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') === 'true'
            && $this->renderService->isConfigured();
    }

    /**
     * Apply this tenant's ceilings, then render.
     *
     * @param array<string, mixed> $payload A flowing-document payload — the
     *        shape {@see \Whity\Sdk\Render\FlowDocument::toPayload()} produces.
     *        Passed to the service verbatim; nothing here rewrites a caller's
     *        content.
     *
     * @throws DocumentRenderRejectedException   A ceiling was exceeded, or the
     *         service refused the tree (422, relayed with its own wording).
     * @throws RenderServiceUnavailableException The service could not do it.
     */
    public function render(int $tenantId, array $payload): RenderedDocument
    {
        $this->check($tenantId, $payload);

        return $this->renderService->renderFlow($payload);
    }

    /**
     * Apply this tenant's ceilings WITHOUT rendering.
     *
     * Split out because one caller has to know the answer before it writes
     * anything. {@see SdkDocumentRenderer::issue()} must create the document
     * record BEFORE the render — a verification code encodes a document id, and
     * an id only exists once a row does — so if the ceilings were only checked
     * on the way into the render, a payload that was going to be refused would
     * already have left a row behind.
     *
     * @param array<string, mixed> $payload
     *
     * @throws DocumentRenderRejectedException A ceiling was exceeded.
     */
    public function check(int $tenantId, array $payload): void
    {
        $effective = $this->settings->effective($tenantId);

        $content = $payload['content'] ?? null;
        if (!is_array($content) || $content === []) {
            // The one shape check made here rather than left to the service,
            // because every ceiling below is a statement about this array and a
            // missing one would otherwise be reported as "0 blocks, within the
            // limit" — a pass, for a payload that cannot render.
            throw DocumentRenderRejectedException::because('A document must have at least one block of content');
        }

        $maxBlocks = $this->ceiling($effective, SettingsRegistry::DOCUMENTS_FLOW_MAX_BLOCKS);
        if (count($content) > $maxBlocks) {
            throw DocumentRenderRejectedException::because(
                'Document has too many content blocks (' . count($content) . ", max {$maxBlocks})"
            );
        }

        $maxTableRows = $this->ceiling($effective, SettingsRegistry::DOCUMENTS_FLOW_MAX_TABLE_ROWS);
        $largestTable = self::largestTableRowCount($content);
        if ($largestTable > $maxTableRows) {
            throw DocumentRenderRejectedException::because(
                "A table has too many rows ({$largestTable}, max {$maxTableRows})"
            );
        }

        // Measured on the ENCODED payload, not estimated from the tree: figures
        // are `data:` URIs and a single embedded scan can be most of a
        // document's weight, so anything short of encoding it would be guessing
        // at the number the limit is actually about.
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw DocumentRenderRejectedException::because(
                'Document could not be encoded for rendering: ' . json_last_error_msg()
            );
        }

        $maxBytes = $this->ceiling($effective, SettingsRegistry::DOCUMENTS_FLOW_MAX_BYTES);
        if (strlen($encoded) > $maxBytes) {
            throw DocumentRenderRejectedException::because(
                'Document exceeds the maximum render size (' . strlen($encoded) . " bytes, max {$maxBytes})"
            );
        }
    }

    /**
     * The largest single table in the tree, in rows.
     *
     * The largest rather than the total, because the limit is about what one
     * table asks the paginator to fragment across pages — a document of fifty
     * ten-row tables is ordinary and a single fifty-thousand-row table is not,
     * and a summed ceiling would refuse the first to catch the second.
     *
     * @param array<mixed> $content
     */
    private static function largestTableRowCount(array $content): int
    {
        $largest = 0;
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'table') {
                continue;
            }
            if (is_array($block['rows'] ?? null)) {
                $largest = max($largest, count($block['rows']));
            }
        }

        return $largest;
    }

    /**
     * A ceiling from the effective settings, falling back to the registry
     * default — the tenant override / global / registry chain every other
     * tunable in this codebase resolves through.
     *
     * @param array<string, string> $effective
     */
    private function ceiling(array $effective, string $key): int
    {
        return (int) ($effective[$key] ?? SettingsRegistry::defaultFor($key));
    }
}
