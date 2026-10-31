<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Branding;

use PHPUnit\Framework\TestCase;
use Whity\Core\Branding\TenantTheme;

/**
 * The tenant colour (decision 2.8). The expected values were produced by the
 * design system's own `Whity.tenantTheme()` in docs/design/components/bundle.js,
 * not by this port, so a drift in the maths shows up here.
 */
final class TenantThemeTest extends TestCase
{
    public function testTheDemoTenantColourMatchesTheDesignSystem(): void
    {
        $theme = TenantTheme::derive('#B74C05');

        self::assertSame('#b74c05', $theme['color']);
        self::assertFalse($theme['adjusted']);
        self::assertSame([
            'primary' => '#b74c05',
            'brand' => '#b74c05',
            'ring' => '#b74c05',
            'chart-1' => '#b74c05',
            'primary-foreground' => '#ffffff',
            'brand-foreground' => '#ffffff',
            'primary-tint' => '#feeee7',
            'primary-tint-foreground' => '#b74c05',
            'sidebar-primary' => '#feeee7',
            'sidebar-primary-foreground' => '#b74c05',
            'plane-deep' => '#4e1c01',
            'plane-rest' => '-3px 3px 0 0 #b74c05',
            'plane-hover' => '-5px 5px 0 0 #b74c05',
            'plane-primary-rest' => '-3px 3px 0 0 #4e1c01',
            'plane-primary-hover' => '-5px 5px 0 0 #4e1c01',
        ], $theme['light']);
        self::assertSame('#e97c47', $theme['dark']['primary'], '2.8c: the demo colour on dark');
        self::assertSame('#171717', $theme['dark']['primary-foreground'], '2.2: buttons on dark take a dark label');
        self::assertSame('#462312', $theme['dark']['primary-tint']);
        self::assertSame('#7f3202', $theme['dark']['plane-deep']);
    }

    public function testWhityBlueDerivesItsOwnDarkColour(): void
    {
        $theme = TenantTheme::derive('#0961bb');

        self::assertSame('#eaf3fe', $theme['light']['primary-tint']);
        self::assertSame('#012d5d', $theme['light']['plane-deep']);
        self::assertSame('#59a0f9', $theme['dark']['primary'], '2.2: Whity Blue on dark');
        self::assertSame('#182e4b', $theme['dark']['primary-tint']);
    }

    public function testALightColourTakesTheNearBlackLabel(): void
    {
        $theme = TenantTheme::derive('#f7e017');

        self::assertFalse($theme['adjusted']);
        self::assertSame('#171717', $theme['light']['primary-foreground']);
        self::assertSame('#f6f3dc', $theme['light']['primary-tint']);
        self::assertSame('#b2a002', $theme['dark']['primary']);
    }

    public function testAColourNeitherLabelCanReadOnIsDarkenedAndSaysSo(): void
    {
        // 2.8a: on #7a7a7a white is 4.29:1 and near-black 4.17:1, so neither passes.
        self::assertLessThan(4.5, TenantTheme::contrast('#ffffff', '#7a7a7a'));
        self::assertLessThan(4.5, TenantTheme::contrast(TenantTheme::NEAR_BLACK, '#7a7a7a'));

        $theme = TenantTheme::derive('#7a7a7a');

        self::assertTrue($theme['adjusted']);
        self::assertNotSame('#7a7a7a', $theme['color']);
        self::assertGreaterThanOrEqual(4.5, TenantTheme::contrast('#ffffff', $theme['color']));
        self::assertSame('#ffffff', $theme['light']['primary-foreground']);
        self::assertSame($theme['color'], $theme['light']['primary']);
    }

    public function testOnlyAHexColourIsAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantTheme::derive('orange');
    }
}
