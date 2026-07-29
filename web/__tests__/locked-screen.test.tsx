import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { LockedScreen } from '@amroksaleh/ui/locked-screen';

describe('LockedScreen', () => {
  it('renders as an alert with the default title, description, and action', async () => {
    const onRelogin = jest.fn();
    render(
      <LockedScreen
        description="Your session expired offline."
        action={<button onClick={onRelogin}>Sign in again</button>}
      />,
    );

    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText('Session locked')).toBeInTheDocument();
    expect(screen.getByText('Your session expired offline.')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Sign in again' }));
    expect(onRelogin).toHaveBeenCalledTimes(1);
  });

  it('accepts a custom title', () => {
    render(<LockedScreen title="Locked out" description="x" />);
    expect(screen.getByText('Locked out')).toBeInTheDocument();
  });
});
