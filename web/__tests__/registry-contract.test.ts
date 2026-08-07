/**
 * CI gate: the shadcn registry must declare exactly what it imports.
 *
 * `registry.json` is a PUBLISHED CONTRACT — consumers (the Elmak Tauri/Flutter
 * clients) install components from https://whity.jameedium.org/r, and
 * `shadcn build` neither derives dependencies nor rewrites imports. Whatever is
 * authored here is what a consumer gets, so:
 *
 *  - an npm package imported but not in `dependencies` is a BROKEN install
 *    (the copied file imports something the consumer never installed);
 *  - a sibling component imported but not in `registryDependencies` is the
 *    same failure one level down;
 *  - a declared-but-unimported entry is bloat every consumer carries forever;
 *  - two items sharing a name means one silently overwrites the other at build
 *    time and is never published at all.
 *
 * All four shipped at once before this gate existed (WC-dep-audit).
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';

type RegistryFile = { path: string };
type RegistryItem = {
  name: string;
  files?: RegistryFile[];
  dependencies?: string[];
  registryDependencies?: string[];
};

const WEB_DIR = path.resolve(__dirname, '..');
const registry = JSON.parse(
  readFileSync(path.join(WEB_DIR, 'registry.json'), 'utf8')
) as { items?: RegistryItem[] };

const items = registry.items ?? [];

/** Provided by the consumer's framework, never installed by a registry item. */
const AMBIENT = new Set(['react', 'react-dom', 'next']);
const EXTS = ['', '.ts', '.tsx', '.js', '.jsx', '/index.ts', '/index.tsx'];

/**
 * Absolute file path -> owning item name. Resolution is BY PATH, not basename:
 * item names and file names diverge (documents/types.ts is `documents-types`)
 * and imports cross directories (`../math-text`).
 */
const fileToItem = new Map<string, string>();
for (const item of items) {
  for (const f of item.files ?? []) {
    fileToItem.set(path.resolve(WEB_DIR, f.path), item.name);
  }
}

function packageOf(spec: string): string | null {
  if (/^[.~]|^@\/|^node:/.test(spec)) return null;
  const parts = spec.split('/');
  return spec.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0];
}

function resolveSibling(fromFile: string, spec: string): string | null {
  const base = path.resolve(path.dirname(fromFile), spec);
  for (const ext of EXTS) {
    const hit = fileToItem.get(base + ext);
    if (hit) return hit;
  }
  return null;
}

function analyse(item: RegistryItem) {
  const npm = new Set<string>();
  const siblings = new Set<string>();

  for (const f of item.files ?? []) {
    const abs = path.resolve(WEB_DIR, f.path);
    let src: string;
    try {
      src = readFileSync(abs, 'utf8');
    } catch {
      continue;
    }
    const re = /(?:from|import)\s*\(?\s*["']([^"']+)["']/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(src)) !== null) {
      const spec = m[1];
      if (spec.startsWith('.')) {
        const sib = resolveSibling(abs, spec);
        if (sib && sib !== item.name) siblings.add(`@whity/${sib}`);
        continue;
      }
      const pkg = packageOf(spec);
      if (pkg && !AMBIENT.has(pkg)) npm.add(pkg);
    }
  }

  return { npm, siblings };
}

describe('shadcn registry contract', () => {
  it('has no duplicate item names', () => {
    const seen = new Map<string, number>();
    for (const item of items) seen.set(item.name, (seen.get(item.name) ?? 0) + 1);
    const dupes = [...seen.entries()].filter(([, n]) => n > 1).map(([n]) => n);
    expect(dupes).toEqual([]);
  });

  it('references only files that exist', () => {
    const missing: string[] = [];
    for (const item of items) {
      for (const f of item.files ?? []) {
        try {
          readFileSync(path.resolve(WEB_DIR, f.path));
        } catch {
          missing.push(`${item.name}: ${f.path}`);
        }
      }
    }
    expect(missing).toEqual([]);
  });

  it.each(items.map((i) => [i.name, i] as const))(
    '%s declares exactly the npm packages it imports',
    (_name, item) => {
      const { npm } = analyse(item);
      expect([...(item.dependencies ?? [])].sort()).toEqual([...npm].sort());
    }
  );

  it.each(items.map((i) => [i.name, i] as const))(
    '%s declares exactly the registry items it imports',
    (_name, item) => {
      const { siblings } = analyse(item);
      expect([...(item.registryDependencies ?? [])].sort()).toEqual([...siblings].sort());
    }
  );
});
