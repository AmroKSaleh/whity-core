<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

/**
 * The tenant colour (decision 2.8): one colour in, the rest derived from its
 * hue in OKLCH. A PHP port of `Whity.tenantTheme(colour, mode)` in
 * docs/design/components/bundle.js, so the server hands every client the same
 * set the design system's own preview computes.
 *
 * - 2.8a: the label on the colour is white or near-black, whichever reaches
 *   4.5:1. If neither does, the colour is darkened until white does, and
 *   `adjusted` says so, so the admin can see what changed.
 * - 2.8b: on dark at L 0.70 (chroma up to 0.15), the tint at L 0.96 (up to
 *   0.03; 0.30 on dark), the plane at L 0.30 (up to 0.10; 0.42 on dark).
 *
 * Greys and status colours never change. Pure and stateless.
 */
final class TenantTheme
{
    public const NEAR_BLACK = '#171717';
    private const WHITE = '#ffffff';
    private const MIN_CONTRAST = 4.5;

    /**
     * @return array{color: string, adjusted: bool, light: array<string, string>, dark: array<string, string>}
     */
    public static function derive(string $hex): array
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            throw new \InvalidArgumentException('The main colour must be a #rrggbb hex colour.');
        }
        [$l, $c, $h] = self::toOklch($hex);
        $main = strtolower($hex);
        $adjusted = false;
        if (self::contrast(self::WHITE, $main) < self::MIN_CONTRAST && self::contrast(self::NEAR_BLACK, $main) < self::MIN_CONTRAST) {
            // 2.8a: neither label passes, so lower the lightness until white does.
            while ($l > 0.05 && self::contrast(self::WHITE, $main) < self::MIN_CONTRAST) {
                $l -= 0.01;
                $main = self::oklchHex($l, $c, $h);
            }
            $adjusted = true;
        }

        return [
            'color' => $main,
            'adjusted' => $adjusted,
            'light' => self::mode($main, $l, $c, $h, false),
            'dark' => self::mode($main, $l, $c, $h, true),
        ];
    }

    /** WCAG contrast ratio between two #rrggbb colours. */
    public static function contrast(string $a, string $b): float
    {
        $x = self::luminance($a);
        $y = self::luminance($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** @return array<string, string> token name (without `--`) => value */
    private static function mode(string $main, float $l, float $c, float $h, bool $dark): array
    {
        $color = $dark ? self::oklchHex(0.70, min($c, 0.15), $h) : $main;
        $label = $dark ? self::NEAR_BLACK : (self::contrast(self::WHITE, $color) >= self::MIN_CONTRAST ? self::WHITE : self::NEAR_BLACK);
        $tint = $dark ? self::oklchHex(0.30, min($c, 0.06), $h) : self::oklchHex(0.96, min($c, 0.03), $h);
        $plane = $dark ? self::oklchHex(0.42, min($c, 0.13), $h) : self::oklchHex(0.30, min($c, 0.10), $h);

        return [
            'primary' => $color,
            'brand' => $color,
            'ring' => $color,
            'chart-1' => $color,
            'primary-foreground' => $label,
            'brand-foreground' => $label,
            'primary-tint' => $tint,
            'primary-tint-foreground' => $color,
            'sidebar-primary' => $tint,
            'sidebar-primary-foreground' => $color,
            'plane-deep' => $plane,
            'plane-rest' => '-3px 3px 0 0 ' . $color,
            'plane-hover' => '-5px 5px 0 0 ' . $color,
            'plane-primary-rest' => '-3px 3px 0 0 ' . $plane,
            'plane-primary-hover' => '-5px 5px 0 0 ' . $plane,
        ];
    }

    /** @return array{0: float, 1: float, 2: float} L, C, H */
    private static function toOklch(string $hex): array
    {
        [$r, $g, $b] = self::linearRgb($hex);
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);
        $lightness = 0.2104542553 * $l + 0.793617785 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.428592205 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.808675766 * $s;
        $hue = fmod(rad2deg(atan2($bb, $a)) + 360.0, 360.0);

        return [$lightness, sqrt($a * $a + $bb * $bb), $hue];
    }

    /** OKLCH to #rrggbb, lowering chroma until the colour fits in sRGB (as the reference does). */
    private static function oklchHex(float $l, float $c, float $h): string
    {
        $rgb = self::oklchToLinear($l, $c, $h);
        while ($c > 0 && self::outOfGamut($rgb)) {
            $c -= 0.002;
            $rgb = self::oklchToLinear($l, max($c, 0.0), $h);
        }

        $hex = '#';
        foreach ($rgb as $x) {
            $x = min(1.0, max(0.0, $x));
            $x = $x <= 0.0031308 ? 12.92 * $x : 1.055 * ($x ** (1 / 2.4)) - 0.055;
            $hex .= str_pad(dechex((int) round($x * 255)), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function oklchToLinear(float $l, float $c, float $h): array
    {
        $a = $c * cos(deg2rad($h));
        $b = $c * sin(deg2rad($h));
        $ll = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($l - 0.0894841775 * $a - 1.291485548 * $b) ** 3;

        return [
            4.0767416621 * $ll - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $ll + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $ll - 0.7034186147 * $m + 1.707614701 * $s,
        ];
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    private static function outOfGamut(array $rgb): bool
    {
        foreach ($rgb as $x) {
            if ($x < -0.0001 || $x > 1.0001) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function linearRgb(string $hex): array
    {
        $out = [];
        foreach ([1, 3, 5] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $out[] = $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return [$out[0], $out[1], $out[2]];
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::linearRgb($hex);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
