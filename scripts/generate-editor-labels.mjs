#!/usr/bin/env node
// Generates packages/features/src/document-editor/editor-labels.ts from the
// editor's English defaults (packages/ui/src/editor/labels.ts): one literal
// t('blockEditor.<key>', '<English>') call per label, so the i18n extractor
// puts every editor string in the `documents` catalogue and Arabic can be
// added in database/i18n/ar/documents.json.
// Run: node scripts/generate-editor-labels.mjs [--check]
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.join(root, 'packages/ui/src/editor/labels.ts');
const target = path.join(root, 'packages/features/src/document-editor/editor-labels.ts');

const text = fs.readFileSync(source, 'utf8');
const body = /export const EDITOR_LABELS = \{([\s\S]*?)\n\}/.exec(text)?.[1];
if (!body) throw new Error('EDITOR_LABELS not found in labels.ts');
const entries = [...body.matchAll(/^\s+(\w+): ("(?:[^"\\]|\\.)*"),?$/gm)].map((m) => [m[1], JSON.parse(m[2])]);
if (entries.length < 10) throw new Error(`only ${entries.length} labels parsed; the parser no longer matches labels.ts`);

const quote = (s) => `'${s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
const lines = [
  '// Generated from packages/ui/src/editor/labels.ts by scripts/generate-editor-labels.mjs.',
  '// Do not edit by hand: change the English default in labels.ts and regenerate.',
  "'use client';",
  '',
  "import type { EditorLabels } from '@whity-org/ui/editor/labels';",
  "import { useTranslation } from '../i18n';",
  '',
  '/** The editor\'s strings in the reader\'s language (`documents` domain, `blockEditor.*`). */',
  'export function useEditorLabels(): EditorLabels {',
  "  const t = useTranslation('documents');",
  '  return {',
  ...entries.map(([key, english]) => `    ${key}: t(${quote(`blockEditor.${key}`)}, ${quote(english)}),`),
  '  };',
  '}',
  '',
];
const out = lines.join('\n');

if (process.argv.includes('--check')) {
  const current = fs.existsSync(target) ? fs.readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : '';
  if (current !== out) {
    console.error('editor-labels.ts is stale: run node scripts/generate-editor-labels.mjs');
    process.exit(1);
  }
  console.log(`editor-labels.ts is up to date (${entries.length} labels).`);
} else {
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, out, 'utf8');
  console.log(`wrote ${path.relative(root, target)} (${entries.length} labels)`);
}
