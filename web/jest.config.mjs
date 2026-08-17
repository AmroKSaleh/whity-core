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
