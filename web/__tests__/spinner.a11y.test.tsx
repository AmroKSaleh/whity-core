import React from 'react';
import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { Spinner } from '@amroksaleh/ui/spinner';

/**
 * Accessibility regression for Spinner (WC UI-library flow #537). A spinner
 * is decorative on its own (an animated icon) — it must be announced via a
 * status role + accessible name, not left silent to screen readers.
 */
describe('Spinner a11y', () => {
  it('has zero axe violations with the default label', async () => {
    const { container } = render(<Spinner />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('announces via role=status with the default "Loading" accessible name', () => {
    render(<Spinner />);
    const status = screen.getByRole('status');
    expect(status).toHaveAccessibleName('Loading');
  });

  it('announces a custom label when provided, with zero axe violations', async () => {
    const { container } = render(<Spinner label="Fetching audit logs…" />);
    const status = screen.getByRole('status');
    expect(status).toHaveAccessibleName('Fetching audit logs…');

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('hides the decorative icon itself from assistive tech (aria-hidden)', () => {
    const { container } = render(<Spinner />);
    const icon = container.querySelector('svg');
    expect(icon).toHaveAttribute('aria-hidden', 'true');
  });
});
