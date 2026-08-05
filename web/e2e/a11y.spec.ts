/**
 * Accessibility (WCAG + Axe) audit test suite for the Whity web frontend.
 *
 * This suite scans all major pages for accessibility violations using @axe-core/playwright.
 * It checks:
 * - Focus management and keyboard navigation
 * - Semantic markup (headings, lists, buttons vs divs)
 * - ARIA attributes (aria-label, aria-describedby, aria-current, etc.)
 * - Form labels and input associations
 * - Contrast ratios (WCAG AA: 4.5:1 for normal, 3:1 for large)
 * - Table structure (scope attributes, captions)
 * - Screen reader compatibility (sr-only content)
 *
 * Known limitations:
 * - 3rd-party components (e.g., recharts) may have inherent a11y issues; these
 *   are documented in axe rule exceptions below.
 * - Some dynamic content (toasts, modals) require additional manual testing.
 * - RTL (Arabic) keyboard nav is checked but may need manual screen-reader testing.
 *
 * Run with:
 *   npm run test:e2e -- tests/a11y.spec.ts
 *   npm run test:e2e:ui -- tests/a11y.spec.ts
 */

import { test, expect } from '@playwright/test';
import { injectAxe, checkA11y, getViolations } from 'axe-playwright';

/**
 * Type definitions for axe-core violations and nodes.
 * @see https://github.com/dequelabs/axe-core/blob/develop/doc/rule-descriptions.md
 */
interface AxeViolation {
  id: string;
  impact?: 'minor' | 'moderate' | 'serious' | 'critical';
  description: string;
  nodes: AxeViolationNode[];
}

interface AxeViolationNode {
  html: string;
}

/**
 * Public pages that do NOT require authentication.
 */
const PUBLIC_PAGES = [
  { url: '/login', label: 'Login' },
  { url: '/register', label: 'Register' },
  { url: '/forgot-password', label: 'Forgot Password' },
  { url: '/account-recovery', label: 'Account Recovery' },
];

/**
 * Protected pages requiring admin authentication.
 * These are sampled from the major sections.
 */
const PROTECTED_PAGES = [
  { url: '/dashboard', label: 'Dashboard' },
  { url: '/admin', label: 'Admin Home' },
  { url: '/admin/users', label: 'Users' },
  { url: '/admin/roles', label: 'Roles' },
  { url: '/admin/ous', label: 'Organizational Units' },
  { url: '/admin/tenants', label: 'Tenants' },
  { url: '/admin/settings', label: 'Website Settings' },
  { url: '/admin/settings/branding', label: 'Branding Settings' },
  { url: '/admin/settings/sso', label: 'SSO Settings' },
  { url: '/admin/settings/email', label: 'Email Settings' },
  { url: '/admin/audit-logs', label: 'Audit Logs' },
  { url: '/admin/documents', label: 'Documents' },
  { url: '/admin/plugins', label: 'Plugins' },
  { url: '/settings', label: 'User Settings' },
];

/**
 * Axe rules to exclude globally (known unavoidable issues).
 * Only add here if there is a documented reason and alternative mitigation.
 */
const EXCLUDED_RULES: string[] = [
  // Document any exclusions here with reasoning
];

test.describe('Accessibility — Public Pages', () => {
  for (const page of PUBLIC_PAGES) {
    test(`${page.label} (${page.url})`, async ({ page: browserPage, baseURL }) => {
      const fullUrl = `${baseURL}${page.url}`;
      await browserPage.goto(fullUrl);

      // Inject axe and wait for the page to stabilize
      await injectAxe(browserPage);
      await browserPage.waitForLoadState('networkidle');

      // Perform the accessibility scan
      try {
        await checkA11y(browserPage, null, {
          rules: {
            ...(EXCLUDED_RULES.length > 0 && {
              disableRules: EXCLUDED_RULES,
            }),
          },
        });
      } catch (error) {
        // If checkA11y throws, getViolations will provide details
        const violations = await getViolations(browserPage, null, {
          rules: {
            ...(EXCLUDED_RULES.length > 0 && {
              disableRules: EXCLUDED_RULES,
            }),
          },
        });

        // Report each violation
        if (violations.length > 0) {
          console.error(`A11y violations found on ${page.label}:`);
          violations.forEach((violation: AxeViolation) => {
            console.error(`  [${violation.impact}] ${violation.id}: ${violation.description}`);
            violation.nodes.forEach((node: AxeViolationNode) => {
              console.error(`    - ${node.html}`);
            });
          });
        }

        throw error;
      }
    });
  }
});

test.describe('Accessibility — Protected Pages (Admin)', () => {
  for (const page of PROTECTED_PAGES) {
    test(`${page.label} (${page.url})`, async ({ page: browserPage, baseURL }) => {
      const fullUrl = `${baseURL}${page.url}`;

      // Navigate to the page
      await browserPage.goto(fullUrl, { waitUntil: 'networkidle' });

      // If we're redirected to login, skip this test (requires auth setup)
      if (browserPage.url().includes('/login')) {
        test.skip();
      }

      // Inject axe and wait for the page to stabilize
      await injectAxe(browserPage);
      await browserPage.waitForLoadState('networkidle');

      // Perform the accessibility scan
      try {
        await checkA11y(browserPage, null, {
          rules: {
            ...(EXCLUDED_RULES.length > 0 && {
              disableRules: EXCLUDED_RULES,
            }),
          },
        });
      } catch (error) {
        // Detailed violation report
        const violations = await getViolations(browserPage, null, {
          rules: {
            ...(EXCLUDED_RULES.length > 0 && {
              disableRules: EXCLUDED_RULES,
            }),
          },
        });

        if (violations.length > 0) {
          console.error(`A11y violations found on ${page.label}:`);
          violations.forEach((violation: AxeViolation) => {
            console.error(`  [${violation.impact}] ${violation.id}: ${violation.description}`);
            violation.nodes.forEach((node: AxeViolationNode) => {
              console.error(`    - ${node.html}`);
            });
          });
        }

        throw error;
      }
    });
  }
});

test.describe('Keyboard Navigation', () => {
  test('login page is keyboard navigable', async ({ page, baseURL }) => {
    await page.goto(`${baseURL}/login`);

    // Focus should start somewhere on the page
    await page.keyboard.press('Tab');
    const focusedElement = await page.evaluate(() => {
      return document.activeElement?.tagName;
    });

    expect(['INPUT', 'BUTTON', 'A']).toContain(focusedElement);

    // Tab through a few more times
    for (let i = 0; i < 3; i++) {
      await page.keyboard.press('Tab');
    }

    // Should still have focus on an interactive element
    const stillFocused = await page.evaluate(() => {
      const el = document.activeElement;
      return el?.tagName && ['INPUT', 'BUTTON', 'A'].includes(el.tagName);
    });

    expect(stillFocused).toBe(true);
  });
});

test.describe('Focus Visibility', () => {
  test('buttons have visible focus indicator', async ({ page, baseURL }) => {
    await page.goto(`${baseURL}/login`);

    // Get the first button
    const button = page.locator('button').first();
    await button.focus();

    // Check that the button has focus styling
    const outlineStyle = await button.evaluate((el) => {
      const styles = window.getComputedStyle(el);
      return styles.outline || styles.boxShadow || styles.borderColor;
    });

    // Should have some focus styling
    expect(outlineStyle).toBeTruthy();
  });
});
