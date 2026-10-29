/**
 * CI gate for the design-system rules editor milestone M1 makes true
 * (docs/design/PLAN.md, CLAUDE.md):
 *  - no font weight 700 anywhere in the product code (decision 3.6; bold is 600);
 *  - the components the editor reuses use tokens only: no Tailwind palette
 *    classes and no hard-coded colours;
 *  - the curated icon map matches docs/design/handoff/icons/icons.json.
 * The scanners report what they found, and fail when they find nothing to
 * scan, so a rename can't turn this into a test that always passes.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(__dirname, '..', '..');
const UI = path.join(ROOT, 'packages', 'ui', 'src');

/** The components milestone M1 brings to their cards. */
const M1_COMPONENTS = [
  'actor-chip.tsx',
  'agent-run-status.tsx',
  'avatar.tsx',
  'badge.tsx',
  'button.tsx',
  'dialog.tsx',
  'dropdown-menu.tsx',
  'icon-button.tsx',
  'icon.tsx',
  'mark.tsx',
  'popover.tsx',
  'toggle-group.tsx',
  'tooltip.tsx',
];

const PALETTE =
  /\b(?:bg|text|border|ring|fill|stroke|outline|decoration|from|to|via|shadow|divide|accent|caret)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-\d{2,3}\b|\b(?:bg|text|border)-(?:white|black)\b/;
const HEX = /#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b(?![0-9a-zA-Z])/;
const RAW_COLOUR = /\b(?:rgb|rgba|hsl|hsla)\(/;

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    // Build output is not product code: a local `build-storybook` leaves storybook-static/ behind.
    if (name === 'node_modules' || name === '.next' || name === 'dist' || name === 'storybook-static' || name.startsWith('.')) continue;
    const p = path.join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(tsx?|css|mjs|js)$/.test(name)) out.push(p);
  }
  return out;
}

describe('no font weight 700 (decision 3.6)', () => {
  const files = [
    ...walk(path.join(ROOT, 'web', 'app')),
    ...walk(path.join(ROOT, 'web', 'components')),
    ...walk(path.join(ROOT, 'packages')),
    ...walk(path.join(ROOT, 'render-service', 'src')),
  ].filter((f) => !f.includes(`${path.sep}generated${path.sep}`));

  it('scans the product code', () => {
    expect(files.length).toBeGreaterThan(200);
  });

  it.each(['font-bold', 'font-extrabold', 'font-black'])('uses no %s class', (cls) => {
    const hits = files.filter((f) => new RegExp(`\\b${cls}\\b`).test(readFileSync(f, 'utf8')));
    expect(hits.map((f) => path.relative(ROOT, f))).toEqual([]);
  });

  it('declares no CSS font-weight of 700 or more', () => {
    const hits = files.filter((f) => /font-weight:\s*(?:bold|bolder|[7-9]00)\b|fontWeight:\s*['"]?(?:[7-9]00)/.test(readFileSync(f, 'utf8')));
    expect(hits.map((f) => path.relative(ROOT, f))).toEqual([]);
  });
});

describe('M1 components use tokens only', () => {
  it('finds every M1 component', () => {
    for (const name of M1_COMPONENTS) expect(statSync(path.join(UI, name)).isFile()).toBe(true);
  });

  it.each(M1_COMPONENTS)('%s has no palette classes or raw colours', (name) => {
    const lines = readFileSync(path.join(UI, name), 'utf8').split('\n');
    const hits = lines
      .map((line, i) => ({ line: i + 1, text: line.trim() }))
      .filter(({ text }) => PALETTE.test(text) || HEX.test(text) || RAW_COLOUR.test(text));
    expect(hits).toEqual([]);
  });

  it('the scanner catches what it is meant to catch', () => {
    expect(PALETTE.test('bg-blue-500')).toBe(true);
    expect(PALETTE.test('text-emerald-600/40')).toBe(true);
    expect(HEX.test('color: #0961bb')).toBe(true);
    expect(PALETTE.test('bg-primary-tint text-success-text')).toBe(false);
  });
});

describe('curated icons', () => {
  it('icon-map.ts matches handoff/icons/icons.json', () => {
    const { icons } = JSON.parse(
      readFileSync(path.join(ROOT, 'docs', 'design', 'handoff', 'icons', 'icons.json'), 'utf8')
    ) as { icons: { name: string; tabler: string; mirrorsInRtl: boolean }[] };
    const map = readFileSync(path.join(UI, 'icon-map.ts'), 'utf8');
    expect(icons).toHaveLength(116);
    for (const icon of icons) {
      expect(map).toContain(`${JSON.stringify(icon.name)}: {`);
      expect(map).toMatch(new RegExp(`${JSON.stringify(icon.name)}: \\{ icon: \\w+, mirror: ${icon.mirrorsInRtl} \\}`));
    }
  });
});
