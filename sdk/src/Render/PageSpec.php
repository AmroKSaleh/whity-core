<?php

declare(strict_types=1);

namespace Whity\Sdk\Render;

/**
 * The physical page a flowing document is printed onto (SDK 1.41).
 *
 * Immutable, and small enough that it is one of the few things in this seam
 * worth making a value object rather than an array: page geometry is where a
 * silent unit mistake does the most damage. Everything here is MILLIMETRES.
 * A caller that means A4 says so by name and never types 210 at all.
 *
 * WHY THE PRESETS ARE NAMED HERE AS WELL AS IN THE RENDER SERVICE
 * ---------------------------------------------------------------
 * The service resolves the same four names, and this class does not consult
 * it — it cannot, being dependency-free by construction. What it publishes is
 * the NAME, not the size: {@see a4()} sends `preset: "a4"` and no dimensions,
 * so the millimetres are still decided in exactly one place. The constants
 * below exist only so a caller that asks for a custom size has something to
 * measure against, and are never sent.
 *
 * Margins default to nothing at all, which is deliberate: the renderer applies
 * its own defaults (25/20/25/20 mm) and a copy of those numbers here would be
 * a second answer to a question that already has one.
 */
final class PageSpec
{
    public const PRESET_A4 = 'a4';
    public const PRESET_A5 = 'a5';
    public const PRESET_LETTER = 'letter';
    public const PRESET_LEGAL = 'legal';

    /** Reference only — never sent. See the class note. */
    public const A4_WIDTH_MM = 210.0;
    public const A4_HEIGHT_MM = 297.0;

    /**
     * @param string|null $preset   One of the PRESET_* names, or null when the
     *                              size is given explicitly.
     * @param float|null  $widthMm  Ignored when $preset is set.
     * @param float|null  $heightMm Ignored when $preset is set.
     * @param array{top?: float, right?: float, bottom?: float, left?: float} $marginMm
     *        Any subset; the renderer supplies the rest.
     */
    private function __construct(
        public readonly ?string $preset,
        public readonly ?float $widthMm,
        public readonly ?float $heightMm,
        public readonly array $marginMm = [],
    ) {
    }

    public static function a4(): self
    {
        return new self(self::PRESET_A4, null, null);
    }

    public static function a5(): self
    {
        return new self(self::PRESET_A5, null, null);
    }

    public static function letter(): self
    {
        return new self(self::PRESET_LETTER, null, null);
    }

    public static function legal(): self
    {
        return new self(self::PRESET_LEGAL, null, null);
    }

    /**
     * A page of an explicit size, in millimetres.
     *
     * @throws RenderRejectedException When either dimension is not a positive,
     *         finite number. Refused here rather than forwarded because a
     *         zero-width page is not a document the renderer can fail
     *         informatively on — it is a layout that never terminates.
     */
    public static function ofSize(float $widthMm, float $heightMm): self
    {
        foreach (['widthMm' => $widthMm, 'heightMm' => $heightMm] as $name => $value) {
            if (!is_finite($value) || $value <= 0.0) {
                throw RenderRejectedException::because(
                    'PageSpec ' . $name . ' must be a positive number of millimetres'
                );
            }
        }

        return new self(null, $widthMm, $heightMm);
    }

    /**
     * The same page with these margins, in millimetres.
     *
     * Every argument is optional and an omitted one is left to the renderer
     * rather than defaulted to zero here: `withMargins(top: 30)` should change
     * the top margin and nothing else, and a caller who wanted no side margin
     * has to say `0`.
     *
     * The renderer CLAMPS a margin that would leave no content box rather than
     * refusing it, so nothing here has to guess at a maximum.
     */
    public function withMargins(
        ?float $top = null,
        ?float $right = null,
        ?float $bottom = null,
        ?float $left = null,
    ): self {
        $margins = $this->marginMm;
        foreach (['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left] as $side => $value) {
            if ($value !== null) {
                $margins[$side] = $value;
            }
        }

        return new self($this->preset, $this->widthMm, $this->heightMm, $margins);
    }

    /**
     * The wire shape the render service's `page` field expects.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $page = $this->preset !== null
            ? ['preset' => $this->preset]
            : ['widthMm' => $this->widthMm, 'heightMm' => $this->heightMm];

        if ($this->marginMm !== []) {
            $margin = [];
            foreach ($this->marginMm as $side => $value) {
                // The service reads `topMm`/`rightMm`/`bottomMm`/`leftMm`; the
                // suffix is added here so a caller never types a unit into a
                // key name and gets it silently ignored.
                $margin[$side . 'Mm'] = $value;
            }
            $page['margin'] = $margin;
        }

        return $page;
    }
}
