<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

/**
 * Immutable effective branding for one tenant (Tenant Branding). The single
 * typed contract every caller consumes — never raw setting keys. URLs point at
 * the public asset route; null means "unset" (the frontend falls back to text
 * for logos and omits the favicon link).
 */
final readonly class Branding
{
    public function __construct(
        public string $siteName,
        public ?string $logoWideUrl,
        public ?string $logoSquareUrl,
        public ?string $faviconUrl,
    ) {
    }

    /**
     * @return array{siteName: string, logoWideUrl: ?string, logoSquareUrl: ?string, faviconUrl: ?string}
     */
    public function toArray(): array
    {
        return [
            'siteName' => $this->siteName,
            'logoWideUrl' => $this->logoWideUrl,
            'logoSquareUrl' => $this->logoSquareUrl,
            'faviconUrl' => $this->faviconUrl,
        ];
    }
}
