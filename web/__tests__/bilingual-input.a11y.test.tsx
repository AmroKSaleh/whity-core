import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { BilingualInput, type BilingualValue } from '@amroksaleh/ui/bilingual-input';

/**
 * Accessibility + behavior regression for BilingualInput (WC-532 #1). Verifies
 * the {ar?, en?} value contract, per-field direction (AR always rtl, EN always
 * ltr regardless of host page direction), and the presence indicators.
 */
function ControlledBilingualInput({
  initial = {},
  ...rest
}: { initial?: BilingualValue } & Partial<
  Omit<React.ComponentProps<typeof BilingualInput>, 'value' | 'onChange'>
>) {
  const [value, setValue] = React.useState<BilingualValue>(initial);
  return <BilingualInput id="bi" value={value} onChange={setValue} {...rest} />;
}

describe('BilingualInput a11y', () => {
  it('has zero axe violations when empty', async () => {
    const { container } = render(<ControlledBilingualInput />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('has zero axe violations when both fields are set', async () => {
    const { container } = render(<ControlledBilingualInput initial={{ ar: 'مرحبا', en: 'Hello' }} />);
    const results = await axe.run(container);
    expect(results.violations).toEqual([]);
  });

  it('renders the AR field as dir="rtl" and the EN field as dir="ltr"', () => {
    render(<ControlledBilingualInput arLabel="Arabic" enLabel="English" />);
    expect(screen.getByLabelText('Arabic')).toHaveAttribute('dir', 'rtl');
    expect(screen.getByLabelText('English')).toHaveAttribute('dir', 'ltr');
  });
});

describe('BilingualInput behavior', () => {
  it('reports presence "Empty" for an unset field and "Set" once typed', async () => {
    const user = userEvent.setup();
    render(<ControlledBilingualInput arLabel="Arabic" enLabel="English" />);

    expect(screen.getByTestId('bilingual-presence-en')).toHaveTextContent('Empty');

    await user.type(screen.getByLabelText('English'), 'Hello');

    expect(screen.getByTestId('bilingual-presence-en')).toHaveTextContent('Set');
  });

  it('calls onChange with the merged {ar, en} value, never clobbering the other language', async () => {
    const user = userEvent.setup();
    const handleChange = jest.fn();

    function Harness() {
      const [value, setValue] = React.useState<BilingualValue>({ ar: 'مرحبا' });
      return (
        <BilingualInput
          id="bi"
          arLabel="Arabic"
          enLabel="English"
          value={value}
          onChange={(next) => {
            handleChange(next);
            setValue(next);
          }}
        />
      );
    }

    render(<Harness />);
    await user.type(screen.getByLabelText('English'), 'Hi');

    const lastCall = handleChange.mock.calls.at(-1)?.[0] as BilingualValue;
    expect(lastCall.ar).toBe('مرحبا');
    expect(lastCall.en).toBe('Hi');
  });

  it('disables both inputs when disabled is set', () => {
    render(
      <BilingualInput
        id="bi"
        arLabel="Arabic"
        enLabel="English"
        value={{ ar: 'a', en: 'b' }}
        onChange={() => {}}
        disabled
      />
    );
    expect(screen.getByLabelText('Arabic')).toBeDisabled();
    expect(screen.getByLabelText('English')).toBeDisabled();
  });
});
