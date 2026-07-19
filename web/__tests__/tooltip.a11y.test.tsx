import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import axe from 'axe-core';
import { Tooltip, TooltipTrigger, TooltipContent } from '@amroksaleh/ui/tooltip';
import { Button } from '@amroksaleh/ui/button';

/**
 * Accessibility regression for Tooltip (WC UI-library flow #535). Radix's
 * Tooltip.Trigger shows content on BOTH pointer hover and keyboard focus by
 * default and wires aria-describedby automatically once open — this test
 * proves that behavior actually holds in this codebase's setup, not just
 * assumes it from Radix's docs.
 *
 * Content renders TWICE in the DOM while open (a visible span plus a
 * visually-hidden role="tooltip" span carrying the accessible name) — query
 * by role="tooltip", not by text, to avoid an ambiguous multi-match.
 */
describe('Tooltip a11y', () => {
  it('has zero axe violations when closed', async () => {
    const { container } = render(
      <Tooltip>
        <TooltipTrigger asChild>
          <Button>Hover me</Button>
        </TooltipTrigger>
        <TooltipContent>Helpful content</TooltipContent>
      </Tooltip>
    );

    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('shows content and links it via aria-describedby on keyboard focus (not just mouse hover)', async () => {
    render(
      <Tooltip>
        <TooltipTrigger asChild>
          <Button>Hover me</Button>
        </TooltipTrigger>
        <TooltipContent>Helpful content</TooltipContent>
      </Tooltip>
    );

    const trigger = screen.getByRole('button', { name: 'Hover me' });

    // Keyboard focus, not a mouse event.
    fireEvent.focus(trigger);

    const tooltip = await screen.findByRole('tooltip');
    expect(tooltip).toHaveTextContent('Helpful content');

    await waitFor(() => {
      const describedBy = trigger.getAttribute('aria-describedby');
      expect(describedBy).toBeTruthy();
      expect(tooltip.id).toBe(describedBy);
    });
  });

  it('has zero axe violations while open', async () => {
    render(
      <Tooltip open>
        <TooltipTrigger asChild>
          <Button>Hover me</Button>
        </TooltipTrigger>
        <TooltipContent>Helpful content</TooltipContent>
      </Tooltip>
    );

    // Radix renders TooltipContent into a portal attached to document.body,
    // outside the render() container — scan document.body so the portal
    // content (the actual thing under test) is included. The "region" rule
    // (page content must be contained by landmarks) is disabled: it is a
    // PAGE-level check that always fires against a bare test harness with no
    // real <main>/<header>/nav, and is unrelated to this component.
    await screen.findByRole('tooltip');
    const results = await axe.run(document.body, { rules: { region: { enabled: false } } });
    expect(results.violations).toEqual([]);
  });
});
