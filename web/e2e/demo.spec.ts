import { test, expect } from './support/fixtures';

/**
 * The /demo route is a standalone shadcn-ui component showcase (it renders its
 * own <html>/<body>) used for design verification, not part of the
 * authenticated admin product. It is publicly reachable, so this smoke test
 * confirms it loads and its one piece of interactivity — the light/dark theme
 * toggle and the component Tabs — works. The page touches no backend data, so
 * there is nothing to clean up.
 */
test.describe('Demo showcase (/demo)', () => {
  test('loads and the theme toggle flips between light and dark', async ({ page }) => {
    await page.goto('/demo');
    await expect(page.getByRole('heading', { name: 'Whity Dashboard' })).toBeVisible();

    // Default is light mode; clicking the toggle switches to dark and back.
    const toggle = page.getByRole('button', { name: /Light|Dark/ });
    await expect(toggle).toHaveText(/Dark/);
    await toggle.click();
    await expect(toggle).toHaveText(/Light/);
    await toggle.click();
    await expect(toggle).toHaveText(/Dark/);
  });

  test('the component showcase tabs switch panels', async ({ page }) => {
    await page.goto('/demo');
    const buttonsTab = page.getByRole('tab', { name: 'Buttons' });
    const formsTab = page.getByRole('tab', { name: 'Forms' });

    await expect(buttonsTab).toHaveAttribute('data-state', 'active');
    await formsTab.click();
    await expect(formsTab).toHaveAttribute('data-state', 'active');
    // The Forms panel reveals form-specific content.
    await expect(page.getByText('Form Elements')).toBeVisible();
  });

  test('renders without a hydration mismatch', async ({ page }) => {
    // Regression guard: the page previously rendered its own <html>/<body>
    // nested inside the root layout's document, which is illegal nesting and
    // produced a React hydration mismatch on every load. Collect any console
    // error / page error whose text signals a hydration problem and assert
    // none were emitted while loading and exercising the page.
    const hydrationErrors: string[] = [];
    const isHydrationMessage = (text: string) => {
      const t = text.toLowerCase();
      return t.includes('hydrat') || t.includes('did not match');
    };
    page.on('console', (msg) => {
      if (msg.type() === 'error' && isHydrationMessage(msg.text())) {
        hydrationErrors.push(msg.text());
      }
    });
    page.on('pageerror', (err) => {
      if (isHydrationMessage(err.message)) {
        hydrationErrors.push(err.message);
      }
    });

    await page.goto('/demo');
    // Wait for the page to be interactive before exercising it.
    await expect(page.getByRole('heading', { name: 'Whity Dashboard' })).toBeVisible();

    // Exercise the theme toggle once — toggling re-renders the wrapper, so any
    // hydration/attribute-mismatch warning would surface here too.
    const toggle = page.getByRole('button', { name: /Light|Dark/ });
    await expect(toggle).toHaveText(/Dark/);
    await toggle.click();
    await expect(toggle).toHaveText(/Light/);

    expect(hydrationErrors).toEqual([]);
  });
});
