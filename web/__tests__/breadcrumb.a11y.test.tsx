import React from 'react';
import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { Breadcrumb } from '@amroksaleh/ui/breadcrumb';

/**
 * Accessibility regression for Breadcrumb (WC UI-library flow #538).
 * Presentational component only — the pathname-driven wrapper is a separate
 * task (#38802077).
 */
describe('Breadcrumb a11y', () => {
  const items = [
    { label: 'Admin', href: '/admin' },
    { label: 'Users', href: '/admin/users' },
    { label: 'Jane Doe' },
  ];

  it('has zero axe violations', async () => {
    const { container } = render(<Breadcrumb items={items} />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('renders a nav landmark labelled "Breadcrumb"', () => {
    render(<Breadcrumb items={items} />);
    expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toBeInTheDocument();
  });

  it('marks the current (last) item aria-current="page" and does NOT render it as a link', () => {
    render(<Breadcrumb items={items} />);
    const current = screen.getByText('Jane Doe');
    expect(current.tagName).not.toBe('A');
    expect(current).toHaveAttribute('aria-current', 'page');
  });

  it('renders every non-current item with an href as a real link, none marked aria-current', () => {
    render(<Breadcrumb items={items} />);
    const admin = screen.getByRole('link', { name: 'Admin' });
    const users = screen.getByRole('link', { name: 'Users' });
    expect(admin).toHaveAttribute('href', '/admin');
    expect(users).toHaveAttribute('href', '/admin/users');
    expect(admin).not.toHaveAttribute('aria-current');
    expect(users).not.toHaveAttribute('aria-current');
  });

  it('supports a single-level trail (last item with no href) with zero axe violations', async () => {
    const { container } = render(<Breadcrumb items={[{ label: 'Dashboard' }]} />);
    const current = screen.getByText('Dashboard');
    expect(current).toHaveAttribute('aria-current', 'page');

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });
});
