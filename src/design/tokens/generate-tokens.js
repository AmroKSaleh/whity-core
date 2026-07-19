#!/usr/bin/env node

/**
 * Design Token Generator
 *
 * Single source of truth: ./base.json
 *
 * Generates platform token files deterministically:
 *   - generated/tokens.json   (flat JSON for programmatic / white-label tooling)
 *   - generated/theme.json    (colors-ONLY master reference: every semantic
 *                              color name, its light/dark oklch value, and a
 *                              resolved hex preview of each — the one file to
 *                              open to see the whole palette at a glance)
 *   - generated/tokens.dart   (Flutter Material 3 constants)
 *   - ../../../web/app/globals.css  (CSS custom properties, synced between sentinel markers)
 *
 * Determinism: output is a pure function of base.json (no timestamps), so
 * regenerating without source changes produces byte-identical files.
 *
 * Usage:
 *   node generate-tokens.js [all|json|dart|web|theme|check]
 *     all   - regenerate every target (default)
 *     json  - regenerate generated/tokens.json
 *     dart  - regenerate generated/tokens.dart
 *     web   - sync web/app/globals.css
 *     theme - regenerate generated/theme.json
 *     check - regenerate in-memory and fail (exit 1) if any target is stale
 */

'use strict';

const fs = require('fs');
const path = require('path');

const BASE_PATH = path.join(__dirname, 'base.json');
const OUTPUT_DIR = path.join(__dirname, 'generated');
const GLOBALS_CSS_PATH = path.join(__dirname, '..', '..', '..', 'web', 'app', 'globals.css');

// Sentinel markers delimiting the generated region inside globals.css.
const CSS_BEGIN = '/* === AUTO-GENERATED TOKENS: do not edit between markers — run `npm run tokens:generate` === */';
const CSS_END = '/* === END AUTO-GENERATED TOKENS === */';

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

class TokenValidationError extends Error {}

const OKLCH_RE =
  /^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+%?))?\s*\)$/;

/**
 * Parse and sanity-check an OKLCH string. Returns { L, C, H, alpha }.
 * Throws TokenValidationError on malformed input.
 */
function parseOklch(value, context) {
  if (typeof value !== 'string') {
    throw new TokenValidationError(
      `${context}: expected an OKLCH string, got ${typeof value} (${JSON.stringify(value)})`
    );
  }
  const match = value.match(OKLCH_RE);
  if (!match) {
    throw new TokenValidationError(
      `${context}: malformed OKLCH value "${value}". Expected "oklch(L C H)" or "oklch(L C H / alpha)".`
    );
  }
  const L = parseFloat(match[1]);
  const C = parseFloat(match[2]);
  const H = parseFloat(match[3]);
  let alpha = 1;
  if (match[4] !== undefined) {
    alpha = match[4].endsWith('%')
      ? parseFloat(match[4]) / 100
      : parseFloat(match[4]);
  }
  if (L < 0 || L > 1) {
    throw new TokenValidationError(`${context}: lightness ${L} out of range [0, 1] in "${value}".`);
  }
  if (C < 0 || C > 0.5) {
    throw new TokenValidationError(`${context}: chroma ${C} out of sane range [0, 0.5] in "${value}".`);
  }
  if (H < 0 || H > 360) {
    throw new TokenValidationError(`${context}: hue ${H} out of range [0, 360] in "${value}".`);
  }
  if (alpha < 0 || alpha > 1) {
    throw new TokenValidationError(`${context}: alpha ${alpha} out of range [0, 1] in "${value}".`);
  }
  return { L, C, H, alpha };
}

/**
 * Validate the loaded base token object. Throws TokenValidationError listing
 * the first problem found. Returns the validated object unchanged.
 */
function validateBase(base) {
  if (!base || typeof base !== 'object') {
    throw new TokenValidationError('base.json: root must be an object.');
  }
  if (typeof base.version !== 'string') {
    throw new TokenValidationError('base.json: missing string "version".');
  }
  const colors = base.colors;
  if (!colors || typeof colors !== 'object' || !colors.light || !colors.dark) {
    throw new TokenValidationError('base.json: "colors" must contain "light" and "dark" objects.');
  }

  // Every light/dark color must be valid OKLCH.
  for (const mode of ['light', 'dark']) {
    for (const [key, value] of Object.entries(colors[mode])) {
      parseOklch(value, `colors.${mode}.${key}`);
    }
  }

  // Light/dark must define exactly the same set of color tokens (parity).
  const lightKeys = Object.keys(colors.light).sort();
  const darkKeys = Object.keys(colors.dark).sort();
  if (lightKeys.join(',') !== darkKeys.join(',')) {
    const onlyLight = lightKeys.filter((k) => !darkKeys.includes(k));
    const onlyDark = darkKeys.filter((k) => !lightKeys.includes(k));
    throw new TokenValidationError(
      `base.json: light/dark color parity mismatch. ` +
        `Only in light: [${onlyLight.join(', ')}]. Only in dark: [${onlyDark.join(', ')}].`
    );
  }

  // Every *-foreground must reference an existing base token, and every
  // semantic / brand base token must have a matching *-foreground.
  const FOREGROUND_REQUIRED = [
    'primary', 'secondary', 'accent', 'brand',
    'success', 'warning', 'error', 'info', 'card', 'popover', 'sidebar',
  ];
  for (const base of FOREGROUND_REQUIRED) {
    if (lightKeys.includes(base) && !lightKeys.includes(`${base}-foreground`)) {
      throw new TokenValidationError(
        `base.json: token "${base}" is missing its "${base}-foreground" pair.`
      );
    }
  }

  // Typography validation.
  const typo = base.typography;
  if (!typo || typeof typo !== 'object') {
    throw new TokenValidationError('base.json: missing "typography".');
  }
  for (const k of ['sans', 'mono', 'heading']) {
    if (typeof typo.fontFamily?.[k] !== 'string') {
      throw new TokenValidationError(`base.json: typography.fontFamily.${k} must be a string.`);
    }
    if (typeof typo.fontFamilyDart?.[k] !== 'string') {
      throw new TokenValidationError(`base.json: typography.fontFamilyDart.${k} must be a string.`);
    }
  }
  if (!typo.fontSize || Object.keys(typo.fontSize).length === 0) {
    throw new TokenValidationError('base.json: typography.fontSize must be a non-empty object.');
  }
  for (const [name, def] of Object.entries(typo.fontSize)) {
    if (typeof def?.rem !== 'string' || typeof def?.px !== 'number') {
      throw new TokenValidationError(
        `base.json: typography.fontSize.${name} must be { rem: string, px: number }.`
      );
    }
  }
  for (const group of ['fontWeight', 'lineHeight', 'letterSpacing']) {
    if (!typo[group] || Object.keys(typo[group]).length === 0) {
      throw new TokenValidationError(`base.json: typography.${group} must be a non-empty object.`);
    }
  }

  if (!base.borderRadius || typeof base.borderRadius.base !== 'string') {
    throw new TokenValidationError('base.json: borderRadius.base must be a string (e.g. "0.625rem").');
  }

  return base;
}

// ---------------------------------------------------------------------------
// Color conversion (OKLCH -> sRGB hex), accurate within float precision.
// ---------------------------------------------------------------------------

function clamp01(x) {
  return Math.max(0, Math.min(1, x));
}

function linearToSrgb(c) {
  c = clamp01(c);
  return c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
}

function oklchToLinearSrgb(L, C, H) {
  const hRad = (H * Math.PI) / 180;
  const a = C * Math.cos(hRad);
  const b = C * Math.sin(hRad);

  // OKLab -> approximate cube-root LMS
  const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
  const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
  const s_ = L - 0.0894841775 * a - 1.291485548 * b;

  const l = l_ * l_ * l_;
  const m = m_ * m_ * m_;
  const s = s_ * s_ * s_;

  return [
    4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  ];
}

function toHexByte(value) {
  return Math.round(clamp01(value) * 255)
    .toString(16)
    .padStart(2, '0');
}

/**
 * Convert an OKLCH string to an ARGB hex (0xAARRGGBB) for Flutter Color().
 */
function oklchToArgbHex(value) {
  const { L, C, H, alpha } = parseOklch(value, 'oklchToArgbHex');
  const [r, g, b] = oklchToLinearSrgb(L, C, H).map(linearToSrgb);
  const a = toHexByte(alpha);
  return `0x${a}${toHexByte(r)}${toHexByte(g)}${toHexByte(b)}`.toUpperCase().replace('0X', '0x');
}

/**
 * Convert an OKLCH string to a standard CSS hex color: `#rrggbb`, or
 * `#rrggbbaa` when the color carries alpha < 1. Used only for the
 * human-readable preview in theme.json — the CSS/Dart outputs above keep the
 * original oklch/ARGB representations they've always used.
 */
function oklchToCssHex(value) {
  const { L, C, H, alpha } = parseOklch(value, 'oklchToCssHex');
  const [r, g, b] = oklchToLinearSrgb(L, C, H).map(linearToSrgb);
  const rgb = `#${toHexByte(r)}${toHexByte(g)}${toHexByte(b)}`;
  return alpha < 1 ? `${rgb}${toHexByte(alpha)}` : rgb;
}

// ---------------------------------------------------------------------------
// Naming utilities
// ---------------------------------------------------------------------------

function toCamelCase(str) {
  return str.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase());
}

function remToPx(rem) {
  const n = parseFloat(rem);
  return Number.isNaN(n) ? 0 : n * 16;
}

// ---------------------------------------------------------------------------
// Generators (each returns a string; none touch the filesystem)
// ---------------------------------------------------------------------------

function generateTokensJson(base) {
  const typo = base.typography;
  const output = {
    version: base.version,
    light: base.colors.light,
    dark: base.colors.dark,
    typography: {
      fontFamily: typo.fontFamily,
      fontSize: typo.fontSize,
      fontWeight: typo.fontWeight,
      lineHeight: typo.lineHeight,
      letterSpacing: typo.letterSpacing,
    },
    spacing: base.spacing,
    borderRadius: base.borderRadius,
  };
  return JSON.stringify(output, null, 2) + '\n';
}

/**
 * The master color theme: every semantic color name, once, with its light
 * and dark oklch values plus a resolved hex preview of each — colors ONLY
 * (no typography/spacing/radius; see tokens.json for the full bundle).
 * Keys are sorted alphabetically so the file is easy to scan/diff by hand.
 */
function generateThemeJson(base) {
  const names = Object.keys(base.colors.light).sort();
  const colors = {};
  for (const name of names) {
    colors[name] = {
      light: base.colors.light[name],
      dark: base.colors.dark[name],
      lightHex: oklchToCssHex(base.colors.light[name]),
      darkHex: oklchToCssHex(base.colors.dark[name]),
    };
  }
  const output = {
    version: base.version,
    description:
      'Master color theme — every semantic color token used across the design system, ' +
      'light + dark, oklch (source of truth) plus a resolved hex preview. ' +
      'Generated from base.json; do not edit by hand — run `npm run tokens:generate`.',
    colors,
  };
  return JSON.stringify(output, null, 2) + '\n';
}

function generateDart(base) {
  const typo = base.typography;
  const lines = [];
  lines.push('// Generated token definitions for Flutter / Material Design 3.');
  lines.push(`// Source of truth: src/design/tokens/base.json (version ${base.version}).`);
  lines.push('// Do not edit manually - run: npm run tokens:generate');
  lines.push('');
  lines.push("import 'package:flutter/material.dart';");
  lines.push('');
  lines.push('class AppTokens {');

  const emitColorMap = (name, colors) => {
    lines.push(`  static const ${name} = <String, Color>{`);
    for (const [key, value] of Object.entries(colors)) {
      lines.push(`    '${toCamelCase(key)}': Color(${oklchToArgbHex(value)}),`);
    }
    lines.push('  };');
    lines.push('');
  };

  lines.push('  // Light mode colors');
  emitColorMap('lightColors', base.colors.light);
  lines.push('  // Dark mode colors');
  emitColorMap('darkColors', base.colors.dark);

  // Font families
  lines.push('  // Font families');
  lines.push(`  static const String fontSans = '${typo.fontFamilyDart.sans}';`);
  lines.push(`  static const String fontMono = '${typo.fontFamilyDart.mono}';`);
  lines.push(`  static const String fontHeading = '${typo.fontFamilyDart.heading}';`);
  lines.push('');

  // Font sizes (logical pixels)
  lines.push('  // Font sizes (logical pixels)');
  lines.push('  static const fontSize = <String, double>{');
  for (const [name, def] of Object.entries(typo.fontSize)) {
    lines.push(`    '${name}': ${def.px.toFixed(1)},`);
  }
  lines.push('  };');
  lines.push('');

  // Font weights -> FontWeight
  lines.push('  // Font weights');
  lines.push('  static const fontWeight = <String, FontWeight>{');
  for (const [name, weight] of Object.entries(typo.fontWeight)) {
    const w = Math.max(1, Math.min(9, Math.round(weight / 100)));
    lines.push(`    '${name}': FontWeight.w${w}00,`);
  }
  lines.push('  };');
  lines.push('');

  // Line heights (unitless multipliers)
  lines.push('  // Line heights (unitless multipliers)');
  lines.push('  static const lineHeight = <String, double>{');
  for (const [name, value] of Object.entries(typo.lineHeight)) {
    lines.push(`    '${name}': ${Number(value).toFixed(3)},`);
  }
  lines.push('  };');
  lines.push('');

  // Border radius (logical pixels)
  lines.push('  // Border radius (logical pixels)');
  lines.push('  static const radius = <String, double>{');
  lines.push(`    'base': ${remToPx(base.borderRadius.base).toFixed(1)},`);
  for (const [name, value] of Object.entries(base.borderRadius)) {
    if (name === 'base') continue;
    const px = value.endsWith('rem') ? remToPx(value) : parseFloat(value);
    lines.push(`    '${name}': ${px.toFixed(1)},`);
  }
  lines.push('  };');
  lines.push('');

  // Spacing (logical pixels)
  lines.push('  // Spacing scale (logical pixels)');
  lines.push('  static const spacing = <String, double>{');
  for (const [name, value] of Object.entries(base.spacing.scale)) {
    lines.push(`    '${name}': ${parseFloat(value).toFixed(1)},`);
  }
  lines.push('  };');
  lines.push('');

  lines.push('  // Helper to resolve theme colors');
  lines.push('  static Map<String, Color> colors(bool isDark) {');
  lines.push('    return isDark ? darkColors : lightColors;');
  lines.push('  }');
  lines.push('}');
  lines.push('');

  return lines.join('\n');
}

/**
 * Build the generated CSS region: :root, .dark, and the typography
 * @theme inline declarations. The static (hand-authored) parts of
 * globals.css live outside the sentinel markers.
 */
function generateCssRegion(base) {
  const typo = base.typography;
  const lines = [];
  lines.push(CSS_BEGIN);

  lines.push(':root {');
  for (const [key, value] of Object.entries(base.colors.light)) {
    lines.push(`  --${key}: ${value};`);
  }
  lines.push(`  --radius: ${base.borderRadius.base};`);
  // Typography custom properties (shared across modes).
  for (const [name, def] of Object.entries(typo.fontSize)) {
    lines.push(`  --text-${name}: ${def.rem};`);
  }
  for (const [name, value] of Object.entries(typo.fontWeight)) {
    lines.push(`  --font-weight-${name}: ${value};`);
  }
  for (const [name, value] of Object.entries(typo.lineHeight)) {
    lines.push(`  --leading-${name}: ${value};`);
  }
  for (const [name, value] of Object.entries(typo.letterSpacing)) {
    lines.push(`  --tracking-${name}: ${value};`);
  }
  lines.push('}');
  lines.push('');

  lines.push('.dark {');
  for (const [key, value] of Object.entries(base.colors.dark)) {
    lines.push(`  --${key}: ${value};`);
  }
  lines.push('}');

  lines.push(CSS_END);
  return lines.join('\n');
}

/**
 * Splice the generated CSS region into the existing globals.css content,
 * replacing any prior generated region between the sentinel markers. If no
 * markers exist yet, the region is appended. Returns the new file content.
 */
function syncGlobalsCss(existingContent, base) {
  const region = generateCssRegion(base);
  const beginIdx = existingContent.indexOf(CSS_BEGIN);
  const endIdx = existingContent.indexOf(CSS_END);

  if (beginIdx !== -1 && endIdx !== -1 && endIdx > beginIdx) {
    const before = existingContent.slice(0, beginIdx);
    const after = existingContent.slice(endIdx + CSS_END.length);
    return before + region + after;
  }

  // No markers yet: append region at the end, separated by a blank line.
  const trimmed = existingContent.replace(/\s*$/, '');
  return `${trimmed}\n\n${region}\n`;
}

// ---------------------------------------------------------------------------
// IO orchestration
// ---------------------------------------------------------------------------

function loadBase() {
  let raw;
  try {
    raw = fs.readFileSync(BASE_PATH, 'utf8');
  } catch (err) {
    throw new TokenValidationError(`Cannot read base.json at ${BASE_PATH}: ${err.message}`);
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (err) {
    throw new TokenValidationError(`base.json is not valid JSON: ${err.message}`);
  }
  return validateBase(parsed);
}

/**
 * Build the full set of target outputs as { path, content } records.
 */
function buildTargets(base, { json = true, dart = true, web = true, theme = true } = {}) {
  const targets = [];
  if (json) {
    targets.push({ path: path.join(OUTPUT_DIR, 'tokens.json'), content: generateTokensJson(base) });
  }
  if (theme) {
    targets.push({ path: path.join(OUTPUT_DIR, 'theme.json'), content: generateThemeJson(base) });
  }
  if (dart) {
    targets.push({ path: path.join(OUTPUT_DIR, 'tokens.dart'), content: generateDart(base) });
  }
  if (web) {
    const existing = fs.existsSync(GLOBALS_CSS_PATH)
      ? fs.readFileSync(GLOBALS_CSS_PATH, 'utf8')
      : '';
    targets.push({ path: GLOBALS_CSS_PATH, content: syncGlobalsCss(existing, base) });
  }
  return targets;
}

function writeTargets(targets) {
  if (!fs.existsSync(OUTPUT_DIR)) {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
  }
  for (const { path: p, content } of targets) {
    fs.writeFileSync(p, content, 'utf8');
  }
}

/**
 * Return the list of targets whose on-disk content differs from generated.
 *
 * The comparison is line-ending agnostic: on Windows checkouts with
 * core.autocrlf the committed outputs materialize as CRLF while the
 * generator emits LF — that is not drift, only content differences are.
 */
function findStaleTargets(targets) {
  const normalize = (s) => s.replace(/\r\n/g, '\n');
  const stale = [];
  for (const { path: p, content } of targets) {
    const current = fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : null;
    if (current === null || normalize(current) !== normalize(content)) {
      stale.push(p);
    }
  }
  return stale;
}

function main() {
  const command = process.argv[2] || 'all';
  let base;
  try {
    base = loadBase();
  } catch (err) {
    console.error(`✗ Token validation failed:\n  ${err.message}`);
    process.exit(1);
  }

  try {
    if (command === 'check') {
      const targets = buildTargets(base);
      const stale = findStaleTargets(targets);
      if (stale.length > 0) {
        console.error('✗ Generated token outputs are stale. Run `npm run tokens:generate`. Stale files:');
        for (const p of stale) console.error(`  - ${p}`);
        process.exit(1);
      }
      console.log('✓ All token outputs are up to date.');
      return;
    }

    const opts = {
      json: command === 'all' || command === 'json',
      dart: command === 'all' || command === 'dart',
      web: command === 'all' || command === 'web',
      theme: command === 'all' || command === 'theme',
    };
    if (!opts.json && !opts.dart && !opts.web && !opts.theme) {
      console.error(`✗ Unknown command "${command}". Use: all | json | dart | web | theme | check.`);
      process.exit(1);
    }

    const targets = buildTargets(base, opts);
    writeTargets(targets);
    for (const { path: p } of targets) {
      console.log(`✓ Generated: ${path.relative(process.cwd(), p)}`);
    }
    console.log('\nToken generation complete.');
  } catch (err) {
    console.error('✗ Error generating tokens:', err.message);
    process.exit(1);
  }
}

// Run only when invoked directly so the module can be imported by tests.
if (require.main === module) {
  main();
}

module.exports = {
  TokenValidationError,
  parseOklch,
  validateBase,
  loadBase,
  oklchToLinearSrgb,
  oklchToArgbHex,
  oklchToCssHex,
  toCamelCase,
  remToPx,
  generateTokensJson,
  generateThemeJson,
  generateDart,
  generateCssRegion,
  syncGlobalsCss,
  buildTargets,
  findStaleTargets,
  CSS_BEGIN,
  CSS_END,
  BASE_PATH,
  OUTPUT_DIR,
  GLOBALS_CSS_PATH,
};
