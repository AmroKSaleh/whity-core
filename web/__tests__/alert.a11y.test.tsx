import React from 'react';
import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { Alert, AlertTitle, AlertDescription, AlertAction } from '@amroksaleh/ui/alert';

/**
 * Accessibility regression for Alert's expanded semantic variant set
 * (default/info/success/warning/destructive). Tone backgrounds are pastel
 * tints (never saturated fills) and AlertDescription always stays
 * text-muted-foreground regardless of variant — several tone tokens fall
 * short of 4.5:1 against their own pastel tint at small text sizes, so color
 * carries the state via the icon/title + tinted surface/border, never body
 * copy. `warning` additionally falls back to `warning-foreground` (not
 * `warning`) for its title, since raw `warning` text reads at only ~2.6:1 on
 * its own tint — below even the ~3:1 floor for large/bold text.
 *
 * axe-core cannot evaluate color-contrast in jsdom (no canvas
 * getContext), so these tests assert structure/roles; the actual contrast
 * ratios were computed and verified against the real oklch token values
 * before choosing this design (see alert.tsx's variant doc comment).
 */
describe('Alert a11y', () => {
  const variants = ['default', 'info', 'success', 'warning', 'destructive'] as const;

  it.each(variants)('variant=%s has zero axe violations and role=alert', async (variant) => {
    const { container } = render(
      <Alert variant={variant}>
        <AlertTitle>Heads up</AlertTitle>
        <AlertDescription>Something worth noting.</AlertDescription>
      </Alert>
    );

    expect(screen.getByRole('alert')).toBeInTheDocument();

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('renders an action slot and still has zero axe violations', async () => {
    const { container } = render(
      <Alert variant="info">
        <AlertTitle>New version available</AlertTitle>
        <AlertDescription>Version 2.4 is ready to install.</AlertDescription>
        <AlertAction>
          <button type="button">Update</button>
        </AlertAction>
      </Alert>
    );

    expect(screen.getByRole('button', { name: 'Update' })).toBeInTheDocument();

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('AlertDescription stays text-muted-foreground regardless of variant', () => {
    for (const variant of variants) {
      const { container, unmount } = render(
        <Alert variant={variant}>
          <AlertDescription>Body copy</AlertDescription>
        </Alert>
      );
      const description = container.querySelector('[data-slot="alert-description"]');
      expect(description).toHaveClass('text-muted-foreground');
      unmount();
    }
  });
});
