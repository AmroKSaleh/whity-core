import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import { RadioGroup, RadioGroupItem } from '@amroksaleh/ui/radio-group';

function Harness({ onChange }: { onChange: (v: string) => void }) {
  const [value, setValue] = useState('mine');
  return (
    <RadioGroup
      value={value}
      onValueChange={(v) => {
        setValue(v);
        onChange(v);
      }}
      aria-label="resolution"
    >
      <RadioGroupItem value="mine" aria-label="mine" />
      <RadioGroupItem value="theirs" aria-label="theirs" />
    </RadioGroup>
  );
}

describe('RadioGroup', () => {
  it('renders the radios and selects one on click', async () => {
    const onChange = jest.fn();
    render(<Harness onChange={onChange} />);

    const mine = screen.getByRole('radio', { name: 'mine' });
    const theirs = screen.getByRole('radio', { name: 'theirs' });
    expect(mine).toBeChecked();
    expect(theirs).not.toBeChecked();

    await userEvent.click(theirs);
    expect(onChange).toHaveBeenCalledWith('theirs');
    expect(theirs).toBeChecked();
  });
});
