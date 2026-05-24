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
});
