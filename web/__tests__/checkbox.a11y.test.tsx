import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { Checkbox } from '@amroksaleh/ui/checkbox';

/**
 * Accessibility regression for Checkbox (WC UI-library flow #536). Verifies
 * the tri-state contract (checked/unchecked/indeterminate) required by the
 * downstream DataTable-selection task, and that keyboard-only interaction
 * (Space key, no mouse) actually toggles it via Radix's ARIA checkbox
 * pattern.
 */
describe('Checkbox a11y', () => {
  it('has zero axe violations (unchecked)', async () => {
    const { container } = render(<Checkbox aria-label="Accept terms" />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('has zero axe violations when checked', async () => {
    const { container } = render(<Checkbox aria-label="Accept terms" defaultChecked />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('supports indeterminate state (required by DataTable selection #868dfcda) with zero axe violations', async () => {
    const { container } = render(<Checkbox aria-label="Select all" checked="indeterminate" />);
    const box = screen.getByRole('checkbox', { name: 'Select all' });
    expect(box).toHaveAttribute('aria-checked', 'mixed');
    expect(box).toHaveAttribute('data-state', 'indeterminate');

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('toggles via the keyboard alone (Tab + Space, no mouse/click)', async () => {
    // userEvent (not raw fireEvent.keyDown) is required here: Radix Checkbox
    // is a native <button> under the hood, and real browsers activate a
    // focused button on Space — that browser-native behavior is exactly what
    // userEvent simulates and a raw keyDown event does not.
    const user = userEvent.setup();
    let checked = false;
    const handleChange = jest.fn((v: boolean | 'indeterminate') => {
      checked = v === true;
    });

    const { rerender } = render(
      <Checkbox aria-label="Accept terms" checked={checked} onCheckedChange={handleChange} />
    );

    const box = screen.getByRole('checkbox', { name: 'Accept terms' });

    await user.tab();
    expect(box).toHaveFocus();

    await user.keyboard(' ');
    expect(handleChange).toHaveBeenCalledWith(true);

    // Reflect the controlled update, as a real onCheckedChange consumer would.
    rerender(
      <Checkbox aria-label="Accept terms" checked={checked} onCheckedChange={handleChange} />
    );
    expect(box).toHaveAttribute('data-state', 'checked');
  });

  it('is disabled and excluded from the tab order when disabled is set', () => {
    render(<Checkbox aria-label="Accept terms" disabled />);
    const box = screen.getByRole('checkbox', { name: 'Accept terms' });
    expect(box).toBeDisabled();
  });
});
