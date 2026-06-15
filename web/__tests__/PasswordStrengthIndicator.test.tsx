import React from 'react';
import { render, screen } from '@testing-library/react';
import { PasswordStrengthIndicator } from '@/components/PasswordStrengthIndicator';

describe('PasswordStrengthIndicator', () => {
  it('renders nothing when password is empty', () => {
    const { container } = render(<PasswordStrengthIndicator password="" />);
    expect(container.firstChild).toBeNull();
  });

  it('shows "Weak" for a very short password', () => {
    render(<PasswordStrengthIndicator password="abc" />);
    expect(screen.getByText('Weak')).toBeInTheDocument();
  });

  it('shows "Fair" for a password that meets length and one extra criterion', () => {
    // length ≥ 8 + uppercase = 2 criteria → Fair
    render(<PasswordStrengthIndicator password="Abcdefgh" />);
    expect(screen.getByText('Fair')).toBeInTheDocument();
  });

  it('shows "Good" for Password1 (length + uppercase + number)', () => {
    render(<PasswordStrengthIndicator password="Password1" />);
    expect(screen.getByText('Good')).toBeInTheDocument();
  });

  it('shows "Strong" for Password1! (all four criteria)', () => {
    render(<PasswordStrengthIndicator password="Password1!" />);
    expect(screen.getByText('Strong')).toBeInTheDocument();
  });

  it('has an accessible aria-label reflecting the strength level', () => {
    render(<PasswordStrengthIndicator password="Password1!" />);
    const region = screen.getByLabelText('Password strength: Strong');
    expect(region).toBeInTheDocument();
  });
});
