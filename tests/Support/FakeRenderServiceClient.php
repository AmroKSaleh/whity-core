<?php

declare(strict_types=1);

namespace Tests\Support;

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

    public bool $throwOnRender = false;

    public function __construct(private readonly string $pdfBytes = "%PDF-1.4\nfake\n%%EOF")
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function render(array $payload): string
    {
        $this->calls[] = $payload;
        if ($this->throwOnRender) {
            throw new RenderServiceUnavailableException('simulated render-service failure');
        }

        return $this->pdfBytes;
    }
}
