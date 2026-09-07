/**
 * An App Router `page.tsx` may export only what the framework understands.
 *
 * `web/app/login/page.tsx` exported a plain constant. That type-checks under
 * **Turbopack**, which is the path CI's build takes, and FAILS
 * `next build --webpack`. So it was green in CI and red for anybody building the
 * other way — the worst place for a build error to live, because it surfaces to
 * whoever next builds locally, most likely while they are chasing something
 * unrelated (#1053).
 *
 * WHY A TEST AND NOT JUST THE FIX. The reason that one instance survived is
 * precisely that nothing enumerated them. One found by accident is a lower
 * bound, not a count. This walks every page module so the next one fails here —
 * in the job that always runs — rather than on somebody's laptop.
 *
 * Deliberately a lexical scan and not a build. Running `next build --webpack` in
 * a unit test would take minutes and need the whole toolchain; the rule being
 * enforced is a property of the module's exports, which is readable from the
 * source.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * What the App Router accepts from a page module.
 *
 * From Next's route-segment config. `default` is the page itself; the rest are
 * the segment options and the metadata/params generators.
 */
const PERMITTED = new Set([
  'default',
  'metadata',
  'generateMetadata',
  'viewport',
  'generateViewport',
  'generateStaticParams',
  'dynamic',
  'dynamicParams',
  'revalidate',
  'fetchCache',
  'runtime',
  'preferredRegion',
  'maxDuration',
  'experimental_ppr',
]);

function pageFiles(dir: string, found: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      pageFiles(full, found);
    } else if (entry === 'page.tsx' || entry === 'page.ts') {
      found.push(full);
    }
  }
  return found;
}

/**
 * Value exports declared in a module, by name.
 *
 * TYPE-only exports (`export type`, `export interface`) are excluded on purpose:
 * they are erased before the bundler sees them, so they cannot break either
 * build. Re-exports (`export { x } from …`) are caught by the same pattern as a
 * plain `export { x }`.
 */
function namedValueExports(source: string): string[] {
  const names: string[] = [];

  // `export const X`, `export function X`, `export class X`, `export let X`
  for (const m of source.matchAll(/^export\s+(?:async\s+)?(?:const|let|var|function|class)\s+([A-Za-z_$][\w$]*)/gm)) {
    names.push(m[1]);
  }

  // `export { A, B as C }` — the exported NAME is what matters, so `as` wins.
  for (const m of source.matchAll(/^export\s*\{([^}]*)\}/gm)) {
    for (const part of m[1].split(',')) {
      const piece = part.trim();
      if (piece === '' || piece.startsWith('type ')) continue;
      const asMatch = piece.match(/\bas\s+([A-Za-z_$][\w$]*)$/);
      names.push(asMatch ? asMatch[1] : piece);
    }
  }

  return names;
}

describe('App Router page modules', () => {
  const pages = pageFiles(join(process.cwd(), 'app'));

  it('finds page modules to check', () => {
    // A scan that silently matched nothing would pass for ever while enforcing
    // nothing — the failure mode this whole file exists to remove.
    expect(pages.length).toBeGreaterThan(10);
  });

  it.each(pages)('%s exports only what the App Router accepts', (file) => {
    const source = readFileSync(file, 'utf8');
    const offending = namedValueExports(source).filter((name) => !PERMITTED.has(name));

    expect(offending).toEqual([]);
  });
});
