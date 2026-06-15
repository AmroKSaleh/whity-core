import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
  {
    rules: {
      "no-restricted-syntax": [
        "warn",
        {
          selector:
            "JSXAttribute[name.name='className'] Literal[value=/\\b(slate|gray|zinc|stone|neutral|red|green|blue|yellow|purple|pink|rose|indigo|violet|emerald|teal|cyan|sky|lime|amber|fuchsia|orange)-[0-9]/]",
          message:
            "Use semantic design tokens instead of raw Tailwind color classes. See web/app/globals.css for available tokens.",
        },
      ],
    },
  },
]);

export default eslintConfig;
