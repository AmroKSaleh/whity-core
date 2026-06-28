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
    // packages/ui/src/* lives outside web/ so Jest resolves its imports
    // starting from packages/ui/ — outside web/node_modules. Pin peer-deps
    // to web/node_modules so they're found regardless of which file imports them.
    '^react$': '<rootDir>/node_modules/react',
    '^react/(.*)$': '<rootDir>/node_modules/react/$1',
    '^react-dom$': '<rootDir>/node_modules/react-dom',
    '^react-dom/(.*)$': '<rootDir>/node_modules/react-dom/$1',
    '^radix-ui$': '<rootDir>/node_modules/radix-ui',
    '^radix-ui/(.*)$': '<rootDir>/node_modules/radix-ui/$1',
    '^@radix-ui/(.*)$': '<rootDir>/node_modules/@radix-ui/$1',
    '^@tabler/icons-react$': '<rootDir>/node_modules/@tabler/icons-react',
    '^class-variance-authority$': '<rootDir>/node_modules/class-variance-authority',
    '^clsx$': '<rootDir>/node_modules/clsx',
    '^tailwind-merge$': '<rootDir>/node_modules/tailwind-merge',
    '^react-hook-form$': '<rootDir>/node_modules/react-hook-form',
    '^katex$': '<rootDir>/node_modules/katex',
  },
  testMatch: ['**/__tests__/**/*.test.ts', '**/__tests__/**/*.test.tsx'],
};

// createJestConfig is exported this way to ensure that next/jest can load the Next.js config which is async
export default createJestConfig(customJestConfig);
