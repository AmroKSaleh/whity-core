import nextJest from 'next/jest.js';

const createJestConfig = nextJest({
  // Provide the path to your Next.js app to load next.config.js and .env files in your test environment
  dir: './',
});

// Add any custom config to be passed to Jest
const customJestConfig = {
  setupFilesAfterEnv: ['<rootDir>/jest.setup.js'],
  testEnvironment: 'jest-environment-jsdom',
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/$1',
    // The `@amroksaleh/*` workspace packages must resolve to THIS checkout —
    // and, more precisely, to the same file the transform rewrites imports to.
    //
    // web/tsconfig.json maps `@amroksaleh/ui/*` to `../packages/ui/src/*`, and
    // next/jest hands those `paths` to SWC. So an ESM `import … from
    // '@amroksaleh/ui/dialog'` is emitted as a RELATIVE require, resolved
    // against this tsconfig — always inside the checkout being run. The string
    // in `jest.mock('@amroksaleh/ui/dialog')` is a call argument rather than an
    // import, so SWC leaves it alone and Jest resolves it as a bare specifier:
    // node_modules walked UP from web/. Worktrees live at .claude/worktrees/*
    // INSIDE the repo, so that walk escapes the worktree and lands on the MAIN
    // checkout's node_modules, whose `@amroksaleh/ui` is an npm-workspaces
    // symlink to the MAIN checkout's packages/ui.
    //
    // Two physical paths, two module instances: the mock is filed against one
    // and every component imports the other. The factory becomes dead code, the
    // real component renders, and four suites fail from a worktree while passing
    // from the main checkout (#840) — reading as a broken component rather than
    // a broken environment, which is what made it expensive.
    //
    // Mirroring the tsconfig `paths` here puts both halves on one file. Only
    // these two packages need it: they are the only `@amroksaleh/*` entries in
    // `paths`, so the only ones SWC rewrites. (`@amroksaleh/tokens` goes through
    // node_modules for imports and mocks alike — not local, but consistent.)
    //
    // These four entries are load-bearing, and silently so: delete them and the
    // suite goes back to failing as though the COMPONENTS were broken. That is
    // what jest.workspace-guard.js is for — it aborts every suite, naming the
    // cause, if a workspace package resolves outside this checkout.
    //
    // A DELIBERATE TRADE: this bypasses each package's `exports` map, so a test
    // could import a subpath the package does not publish — and it would pass
    // here while failing templates/tauri-desktop's Vite build, which has no
    // tsconfig-paths plugin and so resolves through `exports` for real.
    // __tests__/workspace-subpath-exports.test.ts holds that line.
    '^@amroksaleh/ui$': '<rootDir>/../packages/ui/src/index.ts',
    '^@amroksaleh/ui/(.*)$': '<rootDir>/../packages/ui/src/$1',
    '^@amroksaleh/features$': '<rootDir>/../packages/features/src/index.ts',
    '^@amroksaleh/features/(.*)$': '<rootDir>/../packages/features/src/$1',
    // Peer deps (react, radix, react-hook-form, …) are hoisted to the workspace
    // root node_modules by npm workspaces, so standard resolution walking up
    // from web/ (and from packages/ui/src) finds the single shared copy — no
    // per-package pins needed. (They previously pointed at web/node_modules,
    // which the hoist emptied.)
  },
  testMatch: ['**/__tests__/**/*.test.ts', '**/__tests__/**/*.test.tsx'],
  // packages/features ships browser-side logic (the i18n cache and hooks) but
  // has no runner of its own, so its __tests__ would sit inert next to the
  // source. Scan it from here — this is the only Jest project in the repo, and
  // an untested test file is worse than no test file.
  //
  // templates/tauri-desktop is the same situation and cost more: its block
  // renderer is a hand-written twin of web's, checked by nothing but a `tsc`
  // that only runs on a release tag, and it silently diverged on which values
  // a form submits (#847). Its `@tauri-apps/api` transport is a mockable module
  // like any other, so the renderer runs under this project unmodified.
  roots: [
    '<rootDir>',
    '<rootDir>/../packages/features/src',
    '<rootDir>/../templates/tauri-desktop/src',
  ],
};

// createJestConfig is exported this way to ensure that next/jest can load the Next.js config which is async
export default createJestConfig(customJestConfig);
