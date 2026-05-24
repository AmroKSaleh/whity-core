import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { useRouter } from 'next/navigation';
import LoginPage from '@/app/login/page';
import { AuthProvider, useAuth } from '@/lib/auth-context';

// Mock next/navigation
jest.mock('next/navigation', () => ({
  useRouter: jest.fn(),
}));

// Mock global fetch
global.fetch = jest.fn();

const mockRouter = {
  push: jest.fn(),
};

describe('LoginPage - 2FA Flow', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (useRouter as jest.Mock).mockReturnValue(mockRouter);
    (global.fetch as jest.Mock).mockClear();
  });

  // Test 1: 202 response triggers 2FA input
  test('test202ResponseTriggers2FAInput', async () => {
    // Mock initial /api/me call from AuthProvider
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    // Mock login endpoint returning 202 (2FA required)
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
      json: async () => ({ requires_2fa: true }),
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    // Wait for component to mount and /api/me call to complete
    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Fill in login form
    const emailInput = screen.getByPlaceholderText('Enter your email') as HTMLInputElement;
    const passwordInput = screen.getByPlaceholderText('Enter your password') as HTMLInputElement;
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    // Wait for 2FA form to appear
    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Verify email/password fields are hidden
    expect(screen.queryByPlaceholderText('Enter your email')).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText('Enter your password')).not.toBeInTheDocument();

    // Verify 2FA input is visible
    expect(screen.getByPlaceholderText('000000')).toBeInTheDocument();
  });

  // Test 2: 2FA code input accepts only digits
  test('test2FACodeInputAcceptsOnlyDigits', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Type letters and special characters into 2FA code input
    const codeInput = screen.getByPlaceholderText('000000') as HTMLInputElement;
    fireEvent.change(codeInput, { target: { value: 'abc123def456' } });

    // Should only contain digits, max 6 chars
    expect(codeInput).toHaveValue('123456');
  });

  // Test 3: 2FA submission calls /api/login/2fa
  test('test2FASubmissionCallsApi', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ success: true }),
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Enter 2FA code
    const codeInput = screen.getByPlaceholderText('000000');
    fireEvent.change(codeInput, { target: { value: '123456' } });

    // Submit 2FA form
    const verifyButton = screen.getByRole('button', { name: /verify/i });
    fireEvent.click(verifyButton);

    // Wait for API call
    await waitFor(() => {
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/login/2fa'),
        expect.objectContaining({
          method: 'POST',
          body: expect.stringContaining('123456'),
        })
      );
    });
  });

  // Test 4: Invalid 2FA code shows error
  test('testInvalid2FACodeShowsError', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
      json: async () => ({ message: 'Invalid code' }),
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Enter invalid 2FA code
    const codeInput = screen.getByPlaceholderText('000000');
    fireEvent.change(codeInput, { target: { value: '000000' } });

    // Submit 2FA form
    const verifyButton = screen.getByRole('button', { name: /verify/i });
    fireEvent.click(verifyButton);

    // Wait for error message
    await waitFor(() => {
      expect(screen.getByText(/Invalid authenticator code/i)).toBeInTheDocument();
    });

    // Code field should be cleared
    expect(codeInput).toHaveValue('');
  });

  // Test 5: Back button returns to login form
  test('testBackButtonReturnsToLoginForm', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Click back button
    const backButton = screen.getByRole('button', { name: /back to login/i });
    fireEvent.click(backButton);

    // Wait for login form to reappear
    await waitFor(() => {
      expect(screen.getByPlaceholderText('Enter your email')).toBeInTheDocument();
      expect(screen.getByPlaceholderText('Enter your password')).toBeInTheDocument();
    });

    // Verify 2FA form is hidden
    expect(screen.queryByText(/Enter the 6-digit code/i)).not.toBeInTheDocument();
  });

  // Test 6: Successful 2FA redirects to dashboard
  test('testSuccessful2FARedirectsToDashboard', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: true,
      json: async () => ({ success: true }),
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Enter valid 2FA code
    const codeInput = screen.getByPlaceholderText('000000');
    fireEvent.change(codeInput, { target: { value: '123456' } });

    // Submit 2FA form
    const verifyButton = screen.getByRole('button', { name: /verify/i });
    fireEvent.click(verifyButton);

    // Wait for redirect
    await waitFor(() => {
      expect(mockRouter.push).toHaveBeenCalledWith('/dashboard');
    });
  });

  // Test 7: 2FA submit button disabled until 6 digits entered
  test('test2FASubmitButtonDisabledUntil6Digits', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    const verifyButton = screen.getByRole('button', { name: /verify/i });

    // Initially disabled
    expect(verifyButton).toBeDisabled();

    // Type 5 digits - still disabled
    const codeInput = screen.getByPlaceholderText('000000');
    fireEvent.change(codeInput, { target: { value: '12345' } });
    expect(verifyButton).toBeDisabled();

    // Type 6 digits - now enabled
    fireEvent.change(codeInput, { target: { value: '123456' } });
    expect(verifyButton).not.toBeDisabled();
  });

  // Test 8: Email/password cleared after successful password validation
  test('testEmailPasswordClearedAfterPasswordValidation', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    const emailInput = screen.getByPlaceholderText('Enter your email') as HTMLInputElement;
    const passwordInput = screen.getByPlaceholderText('Enter your password') as HTMLInputElement;
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });

    expect(emailInput.value).toBe('user@example.com');
    expect(passwordInput.value).toBe('password123');

    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Verify fields are hidden (2FA form is shown)
    expect(screen.queryByPlaceholderText('Enter your email')).not.toBeInTheDocument();
  });

  // Test 9: Recovery code link toggles recovery form
  test('testRecoveryCodeLinkTogglesRecoveryForm', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Click recovery code link
    const recoveryLink = screen.getByRole('button', { name: /Can't access your authenticator/i });
    fireEvent.click(recoveryLink);

    // Recovery form should appear (check for recovery code input)
    await waitFor(() => {
      expect(screen.getByPlaceholderText('XXXXXXXX')).toBeInTheDocument();
    });

    // Authenticator form should be hidden
    expect(screen.queryByText(/Enter the 6-digit code/i)).not.toBeInTheDocument();
  });

  // Test 10: Recovery code input accepts only alphanumeric, auto-uppercase, max 8 chars
  test('testRecoveryCodeInputValidation', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Click recovery code link
    const recoveryLink = screen.getByRole('button', { name: /Can't access your authenticator/i });
    fireEvent.click(recoveryLink);

    // Type lowercase with special chars: abc-1234 should become ABC1234
    const recoveryInput = await waitFor(() => {
      return screen.getByPlaceholderText('XXXXXXXX') as HTMLInputElement;
    });

    fireEvent.change(recoveryInput, { target: { value: 'abc-1234' } });

    // Should be uppercase and special chars removed
    expect(recoveryInput.value).toBe('ABC1234');
  });

  // Test 11: Recovery code submit button disabled until 8 chars entered
  test('testRecoveryCodeSubmitButtonDisabledUntil8Chars', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Click recovery code link
    const recoveryLink = screen.getByRole('button', { name: /Can't access your authenticator/i });
    fireEvent.click(recoveryLink);

    const verifyRecoveryButton = await waitFor(() => {
      return screen.getByRole('button', { name: /Verify Recovery Code/i });
    });

    // Initially disabled
    expect(verifyRecoveryButton).toBeDisabled();

    // Type 7 chars - still disabled
    const recoveryInput = screen.getByPlaceholderText('XXXXXXXX');
    fireEvent.change(recoveryInput, { target: { value: 'ABC1234' } });
    expect(verifyRecoveryButton).toBeDisabled();

    // Type 8 chars - now enabled
    fireEvent.change(recoveryInput, { target: { value: 'ABC12345' } });
    expect(verifyRecoveryButton).not.toBeDisabled();
  });

  // Test 12: Back to Authenticator button returns to authenticator form
  test('testBackToAuthenticatorButtonReturnsToAuthForm', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Click recovery code link to switch to recovery form
    const recoveryLink = screen.getByRole('button', { name: /Can't access your authenticator/i });
    fireEvent.click(recoveryLink);

    // Wait for recovery input to appear
    await waitFor(() => {
      expect(screen.getByPlaceholderText('XXXXXXXX')).toBeInTheDocument();
    });

    // Click back to authenticator button
    const backButton = screen.getByRole('button', { name: /Back to Authenticator/i });
    fireEvent.click(backButton);

    // Authenticator form should reappear
    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Recovery form should be hidden (input should not exist)
    expect(screen.queryByPlaceholderText('XXXXXXXX')).not.toBeInTheDocument();
  });

  // Test 13: Invalid recovery code shows error
  test('testInvalidRecoveryCodeShowsError', async () => {
    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      status: 202,
      ok: false,
    });

    (global.fetch as jest.Mock).mockResolvedValueOnce({
      ok: false,
      status: 401,
      json: async () => ({ message: 'Invalid recovery code' }),
    });

    render(
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByText(/Sign in to your account/i)).toBeInTheDocument();
    });

    // Trigger 2FA form
    const emailInput = screen.getByPlaceholderText('Enter your email');
    const passwordInput = screen.getByPlaceholderText('Enter your password');
    const submitButton = screen.getByRole('button', { name: /sign in/i });

    fireEvent.change(emailInput, { target: { value: 'user@example.com' } });
    fireEvent.change(passwordInput, { target: { value: 'password123' } });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(screen.getByText(/Enter the 6-digit code/i)).toBeInTheDocument();
    });

    // Click recovery code link
    const recoveryLink = screen.getByRole('button', { name: /Can't access your authenticator/i });
    fireEvent.click(recoveryLink);

    // Wait for recovery input to appear
    const recoveryInput = await waitFor(() => {
      return screen.getByPlaceholderText('XXXXXXXX') as HTMLInputElement;
    });

    // Enter invalid recovery code
    fireEvent.change(recoveryInput, { target: { value: 'INVALID1' } });

    // Submit recovery form
    const verifyRecoveryButton = screen.getByRole('button', { name: /Verify Recovery Code/i });
    fireEvent.click(verifyRecoveryButton);

    // Wait for error message
    await waitFor(() => {
      expect(screen.getByText(/Invalid recovery code/i)).toBeInTheDocument();
    });

    // Recovery form should still be visible (not switched back) - check input is still there
    expect(screen.getByPlaceholderText('XXXXXXXX')).toBeInTheDocument();
  });
});
