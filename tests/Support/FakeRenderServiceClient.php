<?php

declare(strict_types=1);

namespace Tests\Support;

use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\RenderedDocument;
use Whity\Core\Document\Render\RenderServiceClientInterface;
use Whity\Core\Document\Render\RenderServiceUnavailableException;

/**
 * A no-network double of {@see \Whity\Core\Document\Render\RenderServiceClient}
 * for {@see \Tests\Api\DocumentRenderApiHandlerRealEngineTest}: records every
 * payload it was asked to render (so a test can assert the handler built the
 * right {template, dataRows, sheet, blocks} shape and — just as importantly —
 * assert it was NEVER called on the feature-flag-off / RBAC / not-found
 * paths) and returns canned PDF bytes, or throws when configured to simulate
 * the render service being unreachable.
 */
final class FakeRenderServiceClient implements RenderServiceClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * The FLOWING calls only, recorded in addition to `calls` rather than
     * instead of it.
     *
     * `calls` deliberately mixes both modes so a "the service was never
     * reached" assertion keeps holding whichever mode the code under test uses
     * (see renderFlow() below). That property is worth keeping and is exactly
     * what makes `calls` useless for the opposite assertion — that the flowing
     * mode, specifically, was the one that ran. Two lists answer both without
     * either having to be interpreted.
     *
     * @var list<array<string, mixed>>
     */
    public array $flowCalls = [];

    public bool $throwOnRender = false;

    /**
     * When set, the next render is REJECTED with this client-safe message
     * instead of succeeding — the double for the render service answering 422.
     *
     * Distinct from `throwOnRender`, and the distinction is the point: that one
     * simulates an outage (a 503, retry later), this one simulates a payload
     * the service will never accept (a 422, fix the request). A handler that
     * collapses the two is the defect this field exists to catch.
     */
    public ?string $rejectWith = null;

    /**
     * The bytes the next render returns. MUTABLE (#947 item 1): a test proving
     * that a re-render APPENDS an artifact rather than replacing one has to be
     * able to tell the two payloads apart, and a fixed body makes "the stored
     * bytes changed" and "the stored bytes were never touched" look identical.
     */
    public string $pdfBytes;

    /** What the flowing mode reports as its page count. */
    public int $flowPageCount = 1;

    /**
     * How many of those pages the flowing mode reports as generated front
     * matter. Separate from the total because a caller storing both must not
     * be able to pass a test by conflating them.
     */
    public int $flowFrontMatterPages = 0;

    public function __construct(string $pdfBytes = "%PDF-1.4\nfake\n%%EOF")
    {
        $this->pdfBytes = $pdfBytes;
    }

    /**
     * Whether the double reports itself usable.
     *
     * A field rather than a hardcoded `true` because "configured" and "enabled"
     * are two different ways to have no rendering, and a test that cannot
     * express the first cannot prove they stay distinguishable.
     */
    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function render(array $payload): string
    {
        $this->calls[] = $payload;
        $this->failIfConfigured();

        return $this->pdfBytes;
    }

    /**
     * Recorded on the SAME `calls` list as the fixed-canvas mode, so a test
     * asserting "the handler never called the render service" keeps holding
     * when the thing under test switches modes.
     */
    public function renderFlow(array $payload): RenderedDocument
    {
        $this->calls[] = $payload;
        $this->flowCalls[] = $payload;
        $this->failIfConfigured();

        return new RenderedDocument($this->pdfBytes, $this->flowPageCount, $this->flowFrontMatterPages);
    }

    /**
     * The two configured failures, in the order a real client meets them: the
     * transport gives out before any status exists to read, so an outage wins
     * over a rejection when a test has set both.
     */
    private function failIfConfigured(): void
    {
        if ($this->throwOnRender) {
            throw new RenderServiceUnavailableException('simulated render-service failure');
        }

        if ($this->rejectWith !== null) {
            throw DocumentRenderRejectedException::because($this->rejectWith);
        }
    }
}
