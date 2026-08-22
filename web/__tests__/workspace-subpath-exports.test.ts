/**
 * CI gate: every `@amroksaleh/*` subpath we import must be one the package
 * actually publishes.
 *
 * jest.config.mjs maps `@amroksaleh/ui/*` straight at `../packages/ui/src/*`,
 * mirroring web/tsconfig.json's `paths` so that a `jest.mock()` specifier and
 * the component under test resolve to one file (#840). That mapping is a
 * deliberate trade: it resolves by PATH and therefore never consults the
 * package's `exports` map, so an import of a subpath the package does not
 * export still works under Jest.
 *
 * It does not work everywhere. templates/tauri-desktop — whose sources run
 * under this Jest project (see `roots`) — builds with Vite and no
 * tsconfig-paths plugin, so its real build resolves through `exports`. The same
 * goes for anyone consuming the published package. A subpath that exists on
 * disk but is missing from `exports` therefore passes here and fails there,
 * which is precisely the class of divergence #840 was about.
 *
 * So: the alias may bypass `exports`, but nothing may DEPEND on that bypass.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';

const WEB_DIR = path.resolve(__dirname, '..');
const CHECKOUT_ROOT = path.resolve(WEB_DIR, '..');

/** Mirrors `roots` in jest.config.mjs — the trees whose imports Jest resolves. */
const SCANNED_ROOTS = [
  WEB_DIR,
  path.join(CHECKOUT_ROOT, 'packages/features/src'),
  path.join(CHECKOUT_ROOT, 'templates/tauri-desktop/src'),
];

const ALIASED_PACKAGES = ['ui', 'features'] as const;
const SOURCE_EXTENSIONS = ['.ts', '.tsx', '.js', '.jsx', '.mjs'];
const SKIP_DIRECTORIES = new Set(['node_modules', '.next', 'dist', 'coverage', '.turbo']);

function sourceFiles(dir: string): string[] {
  let entries: string[];
  try {
    entries = readdirSync(dir);
  } catch {
    return [];
  }

  return entries.flatMap((entry) => {
    if (SKIP_DIRECTORIES.has(entry)) return [];
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) return sourceFiles(full);
    return SOURCE_EXTENSIONS.includes(path.extname(full)) ? [full] : [];
  });
}

function exportsOf(pkg: string): Set<string> {
  const manifest = JSON.parse(
    readFileSync(path.join(CHECKOUT_ROOT, 'packages', pkg, 'package.json'), 'utf8')
  ) as { exports?: Record<string, unknown> };
  return new Set(Object.keys(manifest.exports ?? {}));
}

const SPECIFIER = new RegExp(`['"]@amroksaleh/(${ALIASED_PACKAGES.join('|')})(/[^'"]*)?['"]`, 'g');

/** Every distinct subpath imported under the scanned roots, with one example importer. */
function importedSubpaths(): Map<string, string> {
  const seen = new Map<string, string>();

  for (const root of SCANNED_ROOTS) {
    for (const file of sourceFiles(root)) {
      const source = readFileSync(file, 'utf8');
      for (const [, pkg, subpath] of source.matchAll(SPECIFIER)) {
        const key = `${pkg}${subpath ?? ''}`;
        if (!seen.has(key)) seen.set(key, path.relative(CHECKOUT_ROOT, file));
      }
    }
  }

  return seen;
}

describe('@amroksaleh workspace subpaths', () => {
  const imported = importedSubpaths();

  it('imports at least one subpath from each aliased package', () => {
    // Guards the scanner itself: a regex that quietly matches nothing would
    // make every assertion below vacuously true.
    for (const pkg of ALIASED_PACKAGES) {
      expect([...imported.keys()].filter((key) => key.startsWith(pkg)).length).toBeGreaterThan(0);
    }
  });

  it('only imports subpaths the package declares in `exports`', () => {
    const declared = new Map(ALIASED_PACKAGES.map((pkg) => [pkg, exportsOf(pkg)] as const));

    const undeclared = [...imported].filter(([key]) => {
      const [pkg, ...rest] = key.split('/');
      const subpath = rest.length > 0 ? `./${rest.join('/')}` : '.';
      return !declared.get(pkg as (typeof ALIASED_PACKAGES)[number])!.has(subpath);
    });

    expect(
      undeclared.map(([key, importer]) => `@amroksaleh/${key} (imported by ${importer})`)
    ).toEqual([]);
  });
});
