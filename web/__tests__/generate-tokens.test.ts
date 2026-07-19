/**
 * Tests for the design-token generator (src/design/tokens/generate-tokens.js).
 *
 * Covers WC-28 hardening guarantees:
 *  - base.json loads and validates
 *  - OKLCH parsing + conversion is correct and sane
 *  - malformed input produces clear, typed errors
 *  - generated outputs are deterministic / idempotent
 *  - the brand accent + semantic + type-scale tokens are present
 */

import * as path from 'path';

interface Oklch {
  L: number;
  C: number;
  H: number;
  alpha: number;
}

interface Target {
  path: string;
  content: string;
}

interface GeneratorModule {
  TokenValidationError: new (message?: string) => Error;
  parseOklch: (value: unknown, context: string) => Oklch;
  validateBase: (base: unknown) => Record<string, unknown>;
  loadBase: () => Record<string, unknown>;
  oklchToLinearSrgb: (L: number, C: number, H: number) => [number, number, number];
  oklchToArgbHex: (value: string) => string;
  oklchToCssHex: (value: string) => string;
  toCamelCase: (value: string) => string;
  remToPx: (value: string) => number;
  generateTokensJson: (base: unknown) => string;
  generateThemeJson: (base: unknown) => string;
  generateDart: (base: unknown) => string;
  generateCssRegion: (base: unknown) => string;
  syncGlobalsCss: (existing: string, base: unknown) => string;
  buildTargets: (base: unknown, opts?: Record<string, boolean>) => Target[];
  findStaleTargets: (targets: Target[]) => string[];
  CSS_BEGIN: string;
  CSS_END: string;
}

// The generator is a CommonJS module living outside the web/ rootDir; load it
// at runtime so it is not pulled into the TS project's type graph.
// eslint-disable-next-line @typescript-eslint/no-require-imports
const gen: GeneratorModule = require(
  path.join(__dirname, '..', '..', 'src', 'design', 'tokens', 'generate-tokens.js')
);

describe('parseOklch', () => {
  it('parses a plain OKLCH triple', () => {
    expect(gen.parseOklch('oklch(0.5 0.16 255)', 'test')).toEqual({
      L: 0.5,
      C: 0.16,
      H: 255,
      alpha: 1,
    });
  });

  it('parses an OKLCH value with a percentage alpha channel', () => {
    const parsed = gen.parseOklch('oklch(1 0 0 / 10%)', 'test');
    expect(parsed.alpha).toBeCloseTo(0.1, 5);
  });

  it('throws a typed error for non-OKLCH input', () => {
    expect(() => gen.parseOklch('#ff0000', 'test')).toThrow(gen.TokenValidationError);
  });

  it('throws for out-of-range lightness', () => {
    expect(() => gen.parseOklch('oklch(1.5 0 0)', 'test')).toThrow(/lightness/);
  });

  it('throws for non-string input', () => {
    expect(() => gen.parseOklch(42, 'test')).toThrow(gen.TokenValidationError);
  });
});

describe('oklch conversion', () => {
  it('converts the light brand blue to its expected sRGB hex', () => {
    // oklch(0.5 0.16 255) -> #0961BB (verified WCAG AA brand accent)
    expect(gen.oklchToArgbHex('oklch(0.5 0.16 255)')).toBe('0xFF0961BB');
  });

  it('produces an alpha byte for translucent colors', () => {
    expect(gen.oklchToArgbHex('oklch(1 0 0 / 10%)')).toBe('0x1AFFFFFF');
  });

  it('maps pure white and black correctly', () => {
    expect(gen.oklchToArgbHex('oklch(1 0 0)')).toBe('0xFFFFFFFF');
    expect(gen.oklchToArgbHex('oklch(0 0 0)')).toBe('0xFF000000');
  });

  it('converts the light brand blue to its expected CSS hex', () => {
    expect(gen.oklchToCssHex('oklch(0.5 0.16 255)')).toBe('#0961bb');
  });

  it('appends an alpha byte only for translucent colors', () => {
    expect(gen.oklchToCssHex('oklch(1 0 0)')).toBe('#ffffff');
    expect(gen.oklchToCssHex('oklch(1 0 0 / 10%)')).toBe('#ffffff1a');
  });
});

describe('helpers', () => {
  it('camel-cases hyphenated token keys', () => {
    expect(gen.toCamelCase('sidebar-primary-foreground')).toBe('sidebarPrimaryForeground');
    expect(gen.toCamelCase('chart-1')).toBe('chart1');
  });

  it('converts rem to logical pixels', () => {
    expect(gen.remToPx('0.625rem')).toBeCloseTo(10, 5);
    expect(gen.remToPx('1rem')).toBeCloseTo(16, 5);
  });
});

describe('validateBase', () => {
  function makeValidBase(): Record<string, unknown> {
    // Round-trip the real base.json through load to get a known-valid object.
    return gen.loadBase();
  }

  it('accepts the real base.json', () => {
    expect(() => gen.validateBase(makeValidBase())).not.toThrow();
  });

  it('rejects light/dark parity mismatches', () => {
    const base = makeValidBase() as { colors: { light: Record<string, string>; dark: Record<string, string> } };
    delete base.colors.dark.primary;
    expect(() => gen.validateBase(base)).toThrow(/parity mismatch/);
  });

  it('rejects a missing *-foreground pair', () => {
    const base = makeValidBase() as { colors: { light: Record<string, string>; dark: Record<string, string> } };
    delete base.colors.light['brand-foreground'];
    delete base.colors.dark['brand-foreground'];
    expect(() => gen.validateBase(base)).toThrow(/brand-foreground/);
  });

  it('rejects malformed OKLCH in the source', () => {
    const base = makeValidBase() as { colors: { light: Record<string, string> } };
    base.colors.light.primary = 'rgb(0,0,0)';
    expect(() => gen.validateBase(base)).toThrow(gen.TokenValidationError);
  });
});

describe('generated outputs', () => {
  const base = gen.loadBase();

  it('emits the brand accent and semantic tokens in CSS', () => {
    const css = gen.generateCssRegion(base);
    expect(css).toContain('--brand: oklch(0.5 0.16 255)');
    expect(css).toContain('--brand-foreground:');
    for (const sem of ['success', 'warning', 'error', 'info']) {
      expect(css).toContain(`--${sem}:`);
      expect(css).toContain(`--${sem}-foreground:`);
    }
  });

  it('emits the full type scale in CSS', () => {
    const css = gen.generateCssRegion(base);
    for (const size of ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl']) {
      expect(css).toContain(`--text-${size}:`);
    }
    expect(css).toContain('--font-weight-bold: 700');
    expect(css).toContain('--leading-normal: 1.5');
    expect(css).toContain('--tracking-tight: -0.025em');
  });

  it('emits valid Dart with no template-literal leakage', () => {
    const dart = gen.generateDart(base);
    expect(dart).not.toContain('$camelKey');
    expect(dart).not.toContain('oklch(');
    expect(dart).toContain("'brand': Color(0xFF0961BB)");
    expect(dart).toContain("static const String fontMono = 'Geist Mono'");
  });

  it('produces parseable, versioned JSON', () => {
    const json = JSON.parse(gen.generateTokensJson(base)) as {
      version: string;
      light: Record<string, string>;
      dark: Record<string, string>;
      typography: { fontSize: Record<string, unknown> };
    };
    expect(typeof json.version).toBe('string');
    expect(json.light.brand).toBe('oklch(0.5 0.16 255)');
    expect(json.dark.brand).toBeDefined();
    expect(Object.keys(json.typography.fontSize).length).toBeGreaterThan(0);
  });

  it('emits a colors-only master theme.json with light/dark oklch + resolved hex', () => {
    const theme = JSON.parse(gen.generateThemeJson(base)) as {
      version: string;
      colors: Record<string, { light: string; dark: string; lightHex: string; darkHex: string }>;
    };
    expect(typeof theme.version).toBe('string');
    expect(theme.colors.brand).toEqual({
      light: 'oklch(0.5 0.16 255)',
      dark: 'oklch(0.7 0.15 255)',
      lightHex: '#0961bb',
      darkHex: expect.stringMatching(/^#[0-9a-f]{6}$/),
    });
    // Colors-only: no typography/spacing/radius leak into this file.
    expect(theme).not.toHaveProperty('typography');
    expect(theme).not.toHaveProperty('spacing');
    // Every color in base.json's light palette appears, alphabetically.
    const expectedNames = Object.keys(
      (base as { colors: { light: Record<string, string> } }).colors.light
    ).sort();
    expect(Object.keys(theme.colors)).toEqual(expectedNames);
  });

  it('is idempotent: the generated CSS region round-trips through syncGlobalsCss', () => {
    const region = gen.generateCssRegion(base);
    const wrapped = `header\n\n${region}\nfooter\n`;
    const once = gen.syncGlobalsCss(wrapped, base);
    const twice = gen.syncGlobalsCss(once, base);
    expect(twice).toBe(once);
    expect(once).toContain('header');
    expect(once).toContain('footer');
  });

  it('reports no stale targets immediately after a fresh build', () => {
    // buildTargets reads current on-disk files for the diff comparison.
    const targets = gen.buildTargets(base);
    // After `npm run tokens:generate` the checked-in files should match;
    // this guards the committed outputs against drift.
    const stale = gen.findStaleTargets(targets);
    expect(stale).toEqual([]);
  });
});
