import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { Pagination } from '@amroksaleh/ui/pagination';

/**
 * Accessibility regression for Pagination (WC UI-library flow #539). Models
 * the existing ad-hoc audit-logs prev/next pattern (total/page/totalPages,
 * disabled at the boundaries).
 */
describe('Pagination a11y', () => {
  it('has zero axe violations on a middle page', async () => {
    const { container } = render(
      <Pagination page={2} perPage={25} total={214} onPageChange={jest.fn()} />
    );
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('renders a nav landmark labelled "Pagination" with the entry count and page text', () => {
    render(<Pagination page={2} perPage={25} total={214} onPageChange={jest.fn()} />);
    expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeInTheDocument();
    expect(screen.getByText(/214 entries/)).toBeInTheDocument();
    expect(screen.getByText(/page 2 of 9/)).toBeInTheDocument();
  });

  it('disables Previous on the first page and Next on the last page', () => {
    const { rerender } = render(
      <Pagination page={1} perPage={25} total={214} onPageChange={jest.fn()} />
    );
    expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Next page' })).toBeEnabled();

    rerender(<Pagination page={9} perPage={25} total={214} onPageChange={jest.fn()} />);
    expect(screen.getByRole('button', { name: 'Previous page' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
  });

  it('calls onPageChange with the next/previous page on click', async () => {
    const user = userEvent.setup();
    const onPageChange = jest.fn();
    render(<Pagination page={2} perPage={25} total={214} onPageChange={onPageChange} />);

    await user.click(screen.getByRole('button', { name: 'Next page' }));
    expect(onPageChange).toHaveBeenCalledWith(3);

    await user.click(screen.getByRole('button', { name: 'Previous page' }));
    expect(onPageChange).toHaveBeenCalledWith(1);
  });

  it('treats a single page of results as page 1 of 1, both buttons disabled, zero axe violations', async () => {
    const { container } = render(
      <Pagination page={1} perPage={25} total={12} onPageChange={jest.fn()} />
    );
    expect(screen.getByText(/page 1 of 1/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('singular "1 entry" is used for exactly one total row', () => {
    render(<Pagination page={1} perPage={25} total={1} onPageChange={jest.fn()} />);
    expect(screen.getByText(/1 entry\b/)).toBeInTheDocument();
  });
});
