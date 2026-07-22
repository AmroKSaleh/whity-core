import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { TagInput, type TagOption } from '@amroksaleh/ui/tag-input';

/**
 * Accessibility + behavior regression for TagInput (WC-532 #7). Verifies the
 * chip/remove contract, that the "add" select only ever offers NOT-yet-selected
 * options, and that removing a chip is keyboard-reachable.
 */
const OPTIONS: TagOption[] = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
  { value: 'c', label: 'Gamma' },
];

function ControlledTagInput({ initial = [] }: { initial?: string[] }) {
  const [value, setValue] = React.useState<string[]>(initial);
  return <TagInput id="tags" options={OPTIONS} value={value} onChange={setValue} />;
}

describe('TagInput a11y', () => {
  it('has zero axe violations when empty', async () => {
    const { container } = render(<ControlledTagInput />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('has zero axe violations with tags selected', async () => {
    const { container } = render(<ControlledTagInput initial={['a', 'b']} />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });
});

describe('TagInput behavior', () => {
  it('shows a placeholder message when nothing is selected', () => {
    render(<ControlledTagInput />);
    expect(screen.getByText('No tags selected')).toBeInTheDocument();
  });

  it('renders a chip per selected value, in selection order', () => {
    render(<ControlledTagInput initial={['b', 'a']} />);
    const chips = screen.getByTestId('tag-input-chips');
    expect(chips.textContent).toContain('Beta');
    expect(chips.textContent).toContain('Alpha');
    expect(screen.queryByText('Gamma')).not.toBeInTheDocument();
  });

  it('removes a chip when its remove button is activated', async () => {
    const user = userEvent.setup();
    render(<ControlledTagInput initial={['a', 'b']} />);

    await user.click(screen.getByRole('button', { name: 'Remove Alpha' }));

    expect(screen.queryByText('Alpha')).not.toBeInTheDocument();
    expect(screen.getByText('Beta')).toBeInTheDocument();
  });

  it('hides the add-select once every option is already selected', () => {
    render(<ControlledTagInput initial={['a', 'b', 'c']} />);
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('does not render remove buttons when disabled', () => {
    render(
      <TagInput id="tags" options={OPTIONS} value={['a']} onChange={() => {}} disabled />
    );
    expect(screen.queryByRole('button', { name: 'Remove Alpha' })).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });
});
