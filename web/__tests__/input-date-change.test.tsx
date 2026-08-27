/**
 * A date input must be able to report the date somebody picked.
 *
 * `Input type="date"` renders a DatePicker and hands the choice back by writing
 * to an internal ref and dispatching a native event. Every other branch of that
 * component renders the native input further down the file; the picker branches
 * returned EARLY and never reached it, so the ref was null, the dispatch body
 * was skipped entirely, and the chosen date never left the picker. The person
 * filling the form saw an empty value and a "required" error on a field they
 * had just completed.
 *
 * The regression is invisible from the outside — the picker still opens, still
 * highlights the date, still closes — so this asserts the mechanism rather than
 * the appearance: a native element React can attach `onChange` to, of a type
 * React actually tracks.
 */

import { render, screen } from '@testing-library/react';
import { Input } from '@amroksaleh/ui/input';

describe('Input type="date"', () => {
  it('renders a native input the change event can come from', () => {
    const { container } = render(
      <Input type="date" aria-label="Date of publication" onChange={() => undefined} />
    );

    const native = container.querySelector('input[type="date"]');

    expect(native).not.toBeNull();
  });

  it('uses a type React tracks changes for, not type="hidden"', () => {
    // React only tracks value changes on text-like inputs. Dispatching onto a
    // `type="hidden"` element would be the same silent nothing for a different
    // reason, so the backing element must NOT be hidden by type.
    const { container } = render(
      <Input type="date" aria-label="Date of publication" onChange={() => undefined} />
    );

    const backing = container.querySelector('input[hidden]');

    expect(backing).not.toBeNull();
    expect(backing?.getAttribute('type')).toBe('date');
  });

  it('still renders a normal text input unchanged', () => {
    render(<Input type="text" aria-label="Paper title" onChange={() => undefined} />);

    expect(screen.getByLabelText('Paper title')).toBeInTheDocument();
  });
});
