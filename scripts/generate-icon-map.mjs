#!/usr/bin/env node
// Generates packages/ui/src/icon-map.ts from docs/design/handoff/icons/icons.json:
// the 116 curated Tabler outline icons by their Whity name (decision 5.1).
// Run: node scripts/generate-icon-map.mjs [--check]
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.join(root, 'docs/design/handoff/icons/icons.json');
const target = path.join(root, 'packages/ui/src/icon-map.ts');

const { icons } = JSON.parse(fs.readFileSync(source, 'utf8'));
const component = (tabler) =>
  'Icon' + tabler.split('-').map((part) => part[0].toUpperCase() + part.slice(1)).join('');

const imports = [...new Set(icons.map((i) => component(i.tabler)))].sort();
const lines = [
  '// Generated from docs/design/handoff/icons/icons.json by scripts/generate-icon-map.mjs.',
  '// Do not edit by hand: change the curated list in the design system first.',
  '',
  'import {',
  ...imports.map((name) => `  ${name},`),
  '} from "@tabler/icons-react"',
  'import type { Icon as TablerIcon } from "@tabler/icons-react"',
  '',
  '/** The curated Whity icon set: each name, its Tabler component, and whether it mirrors in RTL. */',
  'export const ICONS = {',
  ...icons.map((i) => `  ${JSON.stringify(i.name)}: { icon: ${component(i.tabler)}, mirror: ${i.mirrorsInRtl} },`),
  '} as const satisfies Record<string, { icon: TablerIcon; mirror: boolean }>',
  '',
  'export type IconName = keyof typeof ICONS',
  '',
  'export const iconNames = Object.keys(ICONS) as IconName[]',
  '',
];
const out = lines.join('\n');

if (process.argv.includes('--check')) {
  const current = fs.existsSync(target) ? fs.readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : '';
  if (current !== out) {
    console.error('icon-map.ts is stale: run node scripts/generate-icon-map.mjs');
    process.exit(1);
  }
  console.log(`icon-map.ts is up to date (${icons.length} icons).`);
} else {
  fs.writeFileSync(target, out, 'utf8');
  console.log(`wrote ${path.relative(root, target)} (${icons.length} icons)`);
}
