#!/usr/bin/env node
'use strict';

/**
 * Bundles the render harness (ADR 0012 / WC-docdesigner Track 2) into a
 * standalone static bundle Puppeteer navigates to. This is the ONLY place the
 * render service reaches outside its own directory: it bundles
 * `web/components/documents/print-document.tsx` and its transitive
 * dependencies (the portable `packages/ui/src/documents/*` renderers +
 * `packages/ui/src/math-text.tsx`) IN-PLACE from source — the exact same
 * source the Next.js app itself compiles (`transpilePackages:
 * ["@amroksaleh/ui"]` in web/next.config), so a change to the on-screen
 * renderer is picked up here automatically on the next build, with no manual
 * sync step.
 *
 * esbuild resolves two path-alias families to mirror `web/tsconfig.json`'s
 * `paths` map (so `print-document.tsx`'s own imports resolve unchanged):
 *   "@/*"            -> <repo>/web/*
 *   "@amroksaleh/ui*" -> <repo>/packages/ui/src*
 *
 * Emits dist/harness/{bundle.js,bundle.css} (esbuild auto-emits the CSS
 * sibling for the `katex/dist/katex.min.css` import pulled in transitively by
 * `math-text.tsx`) and copies the static harness/{index.html,styles.css}
 * alongside them — the whole directory is what src/server.js serves.
 */

const path = require('node:path');
const fs = require('node:fs');
const esbuild = require('esbuild');

const RENDER_SERVICE_ROOT = path.resolve(__dirname, '..');
const REPO_ROOT = path.resolve(RENDER_SERVICE_ROOT, '..');
const WEB_ROOT = path.join(REPO_ROOT, 'web');
const UI_SRC_ROOT = path.join(REPO_ROOT, 'packages', 'ui', 'src');
const FEATURES_SRC_ROOT = path.join(REPO_ROOT, 'packages', 'features', 'src');
const OUT_DIR = path.join(RENDER_SERVICE_ROOT, 'dist', 'harness');

/**
 * Packages BOTH the harness entry (render-service's own dependency) AND the
 * bundled `web`/`packages/ui` source (a DIFFERENT part of the monorepo, whose
 * own node_modules — hoisted by the root npm workspace — is not necessarily
 * on this Docker image's disk at all) need at runtime: react, react-dom (+
 * its /client subpath), the automatic JSX runtime, katex, and bwip-js's
 * browser build. Resolving these via ordinary node_modules upward-search
 * would require web/packages/ui's copy and render-service's copy to be the
 * SAME physical install (true in local dev, where everything hangs off one
 * repo-root node_modules — but NOT guaranteed inside the Docker image, which
 * only ever runs `npm install` for render-service's OWN package.json). A
 * duplicated `react` copy across the bundle risks "invalid hook call" — two
 * copies of React's internal dispatcher never agree. So every one of these
 * specifiers is pinned, via Node's OWN resolver (`require.resolve`, which
 * correctly follows package.json "exports" maps), to render-service's single
 * installed copy — regardless of which file (harness/, web/, or
 * packages/ui/src/) does the importing.
 */
const PINNED_SHARED_MODULES = {
  react: require.resolve('react'),
  'react-dom': require.resolve('react-dom'),
  'react-dom/client': require.resolve('react-dom/client'),
  'react/jsx-runtime': require.resolve('react/jsx-runtime'),
  katex: require.resolve('katex'),
  // math-text.tsx also imports katex's stylesheet directly by subpath.
  'katex/dist/katex.min.css': require.resolve('katex/dist/katex.min.css'),
  'bwip-js/browser': require.resolve('bwip-js/browser'),
};

const RESOLVE_EXTENSIONS = ['.tsx', '.ts', '.jsx', '.js'];

/**
 * Resolve an extension-less alias TARGET (e.g. ".../documents/sheet") to an
 * actual file on disk by probing each candidate extension in turn — when an
 * esbuild `onResolve` callback returns an explicit `path`, esbuild treats it
 * as ALREADY fully resolved and skips its own extension-probing, so aliasing
 * to a bare module id (no extension) requires doing that probing ourselves.
 */
function resolveWithExtensions(basePath) {
  for (const ext of RESOLVE_EXTENSIONS) {
    const candidate = basePath + ext;
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }
  // A DIRECTORY target (e.g. `@amroksaleh/features/document-designer`, a slice
  // whose entry point is its own index) resolves to that index. Without this
  // the alias hands esbuild a directory path and the build dies on an
  // unreadable "file" — on Windows, with the memorably unhelpful "Incorrect
  // function". packages/ui never hit it because all its subpaths are files.
  if (fs.existsSync(basePath) && fs.statSync(basePath).isDirectory()) {
    for (const ext of RESOLVE_EXTENSIONS) {
      const candidate = path.join(basePath, 'index' + ext);
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    }
  }
  // Fall back to the unresolved path — esbuild's own error message is clearer
  // than a silent wrong-path resolution for a genuinely missing module.
  return basePath;
}

/** Escape every regex metacharacter (not just `/`) so a literal string is
 * always matched literally when spliced into a RegExp source — the fixed
 * PINNED_SHARED_MODULES keys never contain one today, but building the
 * pattern this way is correct regardless of what a key looks like, rather
 * than relying on that always staying true. */
function escapeRegExp(literal) {
  return literal.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** esbuild plugin: rewrite `@/...` and `@amroksaleh/ui...` specifiers to the
 * matching source paths, mirroring web/tsconfig.json's `paths` map — plus pin
 * the shared runtime packages above to a single physical copy. */
function pathAliasPlugin() {
  return {
    name: 'whity-path-alias',
    setup(build) {
      const pinnedFilter = new RegExp(
        '^(' + Object.keys(PINNED_SHARED_MODULES).map(escapeRegExp).join('|') + ')$'
      );
      build.onResolve({ filter: pinnedFilter }, (args) => ({
        path: PINNED_SHARED_MODULES[args.path],
      }));
      build.onResolve({ filter: /^@\// }, (args) => ({
        path: resolveWithExtensions(path.join(WEB_ROOT, args.path.slice(2))),
      }));
      build.onResolve({ filter: /^@amroksaleh\/ui/ }, (args) => {
        const rest = args.path.slice('@amroksaleh/ui'.length); // '' or '/documents/xyz'
        const target = rest === '' ? path.join(UI_SRC_ROOT, 'index') : path.join(UI_SRC_ROOT, rest);
        return { path: resolveWithExtensions(target) };
      });
      // The designer moved to `@amroksaleh/features/document-designer` so the
      // Tauri desktop client could render the same code, and
      // `web/components/documents/print-document.tsx` — which entry.tsx imports
      // — is now a re-export shim pointing there. Without this resolver the
      // harness build fails outright.
      //
      // That shim deliberately targets the `/print-document` SUBPATH rather
      // than the slice barrel: the barrel would drag the whole editor (radix,
      // tabler icons, the canvas) into this production PDF bundle and leave
      // tree-shaking to take it back out. If bundle.js ever jumps in size,
      // check that first.
      build.onResolve({ filter: /^@amroksaleh\/features/ }, (args) => {
        const rest = args.path.slice('@amroksaleh/features'.length);
        const target = rest === '' ? path.join(FEATURES_SRC_ROOT, 'index') : path.join(FEATURES_SRC_ROOT, rest);
        return { path: resolveWithExtensions(target) };
      });
    },
  };
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });

  await esbuild.build({
    entryPoints: [path.join(RENDER_SERVICE_ROOT, 'harness', 'entry.tsx')],
    // outdir (not outfile): katex's stylesheet pulls in its own webfont files
    // (.woff/.woff2/.ttf, via the 'file' loader below), which esbuild can only
    // emit as separate assets alongside a directory output.
    outdir: OUT_DIR,
    entryNames: 'bundle',
    assetNames: 'fonts/[name]',
    bundle: true,
    format: 'iife',
    platform: 'browser',
    target: ['chrome120'],
    jsx: 'automatic',
    minify: true,
    sourcemap: false,
    plugins: [pathAliasPlugin()],
    resolveExtensions: ['.tsx', '.ts', '.jsx', '.js', '.css', '.json'],
    // KaTeX's own webfonts, referenced via url(...) in katex.min.css — copied
    // as static assets (not inlined) so the CSS `url()` references stay small
    // relative links, exactly like the on-screen Next.js build.
    loader: {
      '.woff': 'file',
      '.woff2': 'file',
      '.ttf': 'file',
    },
    define: {
      'process.env.NODE_ENV': '"production"',
    },
    logLevel: 'info',
  });

  // esbuild only emits bundle.css when the entry graph actually imports CSS
  // (it does, transitively, via katex/dist/katex.min.css) — but guard anyway
  // so a future dependency change that drops the CSS import doesn't break the
  // static <link> in harness/index.html. Uses the exclusive-create flag ('wx')
  // instead of a separate existsSync()-then-writeFileSync() pair: a
  // check-then-act pair over two syscalls is a classic TOCTOU race, whereas a
  // single open(..., O_CREAT|O_EXCL) is atomic — it fails with EEXIST rather
  // than clobbering a file created between the check and the write.
  const cssOut = path.join(OUT_DIR, 'bundle.css');
  try {
    fs.writeFileSync(cssOut, '/* no CSS emitted by the harness bundle */\n', { flag: 'wx' });
  } catch (err) {
    if (!err || err.code !== 'EEXIST') {
      throw err;
    }
  }

  fs.copyFileSync(path.join(RENDER_SERVICE_ROOT, 'harness', 'index.html'), path.join(OUT_DIR, 'index.html'));
  fs.copyFileSync(path.join(RENDER_SERVICE_ROOT, 'harness', 'styles.css'), path.join(OUT_DIR, 'styles.css'));

  console.log(`[build-harness] wrote ${OUT_DIR}`);
}

main().catch((err) => {
  console.error('[build-harness] failed:', err);
  process.exit(1);
});
