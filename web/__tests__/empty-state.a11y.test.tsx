import React from 'react';
import { render } from '@testing-library/react';
import axe from 'axe-core';
import { EmptyState, ErrorState } from '@amroksaleh/ui/empty-state';

/**
 * Accessibility regression for EmptyState/ErrorState (WC UI-library flow #534).
 * Runs a real axe-core scan against the rendered DOM — this is the pattern the
 * sibling primitive tasks in this flow (Tooltip/Checkbox/Spinner/Breadcrumb/
 * Pagination) should reuse, since no shared a11y test harness existed before
 * this task (the broader "systematic a11y pass via axe + Playwright" sweep is
 * separate, future work).
 */
describe('EmptyState / ErrorState a11y', () => {
  it('EmptyState has zero axe violations and announces via role=status', async () => {
    const { container } = render(
      <EmptyState title="No tenants yet" description="Create your first tenant to get started." />
    );

    const status = container.querySelector('[data-slot="empty-state"]');
    expect(status).not.toBeNull();
    expect(status).toHaveAttribute('role', 'status');

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('ErrorState has zero axe violations and announces via role=alert', async () => {
    const { container } = render(
      <ErrorState title="Couldn't load audit logs" description="The request failed." />
    );

    const alert = container.querySelector('[data-slot="empty-state"]');
    expect(alert).not.toBeNull();
    expect(alert).toHaveAttribute('role', 'alert');

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('renders an action slot and still has zero axe violations', async () => {
    const { container, getByRole } = render(
      <EmptyState
        title="No roles found"
        description="Roles you create will show up here."
        action={<button type="button">Create role</button>}
      />
    );

    expect(getByRole('button', { name: 'Create role' })).toBeInTheDocument();

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });
});
