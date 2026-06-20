<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Branding;

use PHPUnit\Framework\TestCase;
use Whity\Core\Branding\BrandingAssetRejectedException;
use Whity\Core\Branding\BrandingAssetValidator;

final class BrandingAssetValidatorTest extends TestCase
{
    private BrandingAssetValidator $v;

    protected function setUp(): void
    {
        $this->v = new BrandingAssetValidator(new \Whity\Core\Branding\SvgSanitizer());
    }

    private function png(): string
    {
        // 8-byte PNG signature + filler.
        return "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32);
    }

    private function ico(): string
    {
        return "\x00\x00\x01\x00" . str_repeat("\0", 32);
    }

    public function testAcceptsPngLogo(): void
    {
        $a = $this->v->validate('logo_wide', $this->png());
        self::assertSame('png', $a->ext);
    }

    public function testAcceptsIcoFavicon(): void
    {
        $a = $this->v->validate('favicon', $this->ico());
        self::assertSame('ico', $a->ext);
    }

    public function testAcceptsAndSanitizesSvgLogo(): void
    {
        $a = $this->v->validate('logo_wide', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>');
        self::assertSame('svg', $a->ext);
        self::assertStringNotContainsStringIgnoringCase('onload', $a->bytes);
    }

    public function testRejectsSvgFavicon(): void
    {
        // favicon accepts only ICO/PNG.
        $this->expectException(BrandingAssetRejectedException::class);
        $this->v->validate('favicon', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    public function testRejectsUnknownMagic(): void
    {
        $this->expectException(BrandingAssetRejectedException::class);
        $this->v->validate('logo_wide', 'GIF89a-not-allowed');
    }

    public function testRejectsOversize(): void
    {
        $this->expectException(BrandingAssetRejectedException::class);
        $this->v->validate('favicon', $this->ico() . str_repeat('A', 1024 * 1024 + 1));
    }
}
