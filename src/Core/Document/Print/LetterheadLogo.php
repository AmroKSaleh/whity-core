<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Storage\MimeTypes;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;

/**
 * The tenant's logo for a printed letterhead (stage 6, 6.2: logo, address and
 * tax number at the start side), as the data: URI Whity Render accepts. The
 * editor's page view shows the same logo through TenantLogo; this puts it on
 * the PDF too.
 *
 * The document theme chooses which (`letterhead.logo`, editor M4d): the wide
 * logo, then the square one if there is no wide one; the square logo, then the
 * wide one; or none. Each is resolved tenant → global like the rest of the
 * branding. No logo, an unreadable object, a type
 * Whity Render doesn't draw or one too big for its image limit all answer null:
 * the letterhead then prints the name alone rather than failing the render.
 */
final class LetterheadLogo
{
    /** Whity Render's own ceiling on an image's data: URI (RENDER_FLOW_MAX_IMAGE_BYTES). */
    public const MAX_URI_LENGTH = 512 * 1024;

    /** The types Whity Render draws in a letterhead slot. */
    private const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly StorageDriverInterface $storage,
    ) {
    }

    /** @param string $choice the theme's `letterhead.logo`: wide, square or none */
    public function dataUri(int $tenantId, string $choice = 'wide'): ?string
    {
        if ($choice === 'none') {
            return null;
        }
        $effective = $this->settings->effective($tenantId);
        $order = $choice === 'square'
            ? [SettingsRegistry::BRANDING_LOGO_SQUARE, SettingsRegistry::BRANDING_LOGO_WIDE]
            : [SettingsRegistry::BRANDING_LOGO_WIDE, SettingsRegistry::BRANDING_LOGO_SQUARE];
        foreach ($order as $key) {
            $storageKey = trim((string) ($effective[$key] ?? ''));
            if ($storageKey === '') {
                continue;
            }

            return $this->read($storageKey);
        }

        return null;
    }

    private function read(string $storageKey): ?string
    {
        $type = MimeTypes::forKey($storageKey);
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }
        try {
            $bytes = $this->storage->get($storageKey);
        } catch (StorageException) {
            return null;
        }
        $uri = 'data:' . $type . ';base64,' . base64_encode($bytes);

        return strlen($uri) > self::MAX_URI_LENGTH ? null : $uri;
    }
}
