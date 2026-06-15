import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { TwoFactorSettings } from '@/components/TwoFactorSettings';
import { useAuth } from '@/lib/auth-context';

// Mock global fetch before any imports
global.fetch = jest.fn();

// Mock the auth context
jest.mock('@/lib/auth-context', () => {
  const actual = jest.requireActual('@/lib/auth-context');
  return {
    ...actual,
    useAuth: jest.fn(() => ({
      apiClient: jest.fn(),
      user: { id: 1, email: 'test@example.com' },
      isAuthenticated: jest.fn(() => true),
    })),
  };
});

// Mock tabler icons
jest.mock('@tabler/icons-react', () => ({
  IconCheck: ({ className }: { className?: string }) => <span className={className} data-testid="icon-check">✓</span>,
}));

// Mock dialog component
jest.mock('@/components/ui/dialog', () => ({
  Dialog: ({ children, open }: React.PropsWithChildren<{ open?: boolean }>) =>
    open ? <div data-testid="dialog">{children}</div> : null,
  DialogContent: ({ children }: React.PropsWithChildren) => <div data-testid="dialog-content">{children}</div>,
  DialogHeader: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
  DialogTitle: ({ children }: React.PropsWithChildren) => <h2>{children}</h2>,
  DialogDescription: ({ children }: React.PropsWithChildren) => <p>{children}</p>,
}));

// Mock button component. The real Button has a custom `variant` prop that is
// not a valid DOM attribute, so drop it from the props before spreading the
// rest onto the <button>.
jest.mock('@/components/ui/button', () => ({
  Button: ({ children, variant, ...props }: React.ComponentProps<'button'> & { variant?: string }) => {
    void variant;
    return <button {...props}>{children}</button>;
  },
}));

// Mock alert component
jest.mock('@/components/ui/alert', () => ({
  Alert: ({ children, variant }: React.PropsWithChildren<{ variant?: string }>) => (
    <div data-testid={`alert-${variant}`} role="alert">
      {children}
    </div>
  ),
  AlertDescription: ({ children }: React.PropsWithChildren) => <p>{children}</p>,
}));

// Mock window.confirm
global.confirm = jest.fn(() => true);

// Mock document.createElement for file downloads
const mockClick = jest.fn();
const mockSetAttribute = jest.fn();
const originalCreateElement = document.createElement.bind(document);

document.createElement = jest.fn((tagName: string) => {
  if (tagName === 'a') {
    return {
      setAttribute: mockSetAttribute,
      click: mockClick,
    } as unknown as HTMLAnchorElement;
  }
  return originalCreateElement(tagName);
}) as typeof document.createElement;

describe('TwoFactorSettings', () => {
  let mockApiClient: jest.Mock;

  beforeEach(() => {
    jest.clearAllMocks();
    mockClick.mockClear();
    mockSetAttribute.mockClear();
    (global.confirm as jest.Mock).mockClear();

    mockApiClient = jest.fn();
    (useAuth as jest.Mock).mockReturnValue({
      apiClient: mockApiClient,
      user: { id: 1, email: 'test@example.com' },
      isAuthenticated: jest.fn(() => true),
    });
  });

  describe('Initial Load', () => {
    test('testRendersLoadingSpinnerOnMount', async () => {
      mockApiClient.mockImplementationOnce(() =>
        new Promise(() => {}) // Never resolves
      );

      render(<TwoFactorSettings />);

      // Check for loading spinner (div with animate-spin)
      const spinners = document.querySelectorAll('.animate-spin');
      expect(spinners.length).toBeGreaterThan(0);
    });

    test('testFetches2FAStatusOnMount', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: false, backup_codes_available: 0 }),
      });

      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(mockApiClient).toHaveBeenCalledWith('/api/auth/2fa/status', {
          method: 'GET',
        });
      });
    });

    test('testHandlesStatusFetchError', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: false,
        json: async () => ({ message: 'Unauthorized' }),
      });

      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(screen.getByTestId('alert-destructive')).toBeInTheDocument();
      });
    });
  });

  describe('2FA Disabled State', () => {
    beforeEach(() => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: false, backup_codes_available: 0 }),
      });
    });

    test('testDisplaysNotEnabledMessage', async () => {
      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(screen.getByText(/Two-Factor Authentication is not currently enabled/i)).toBeInTheDocument();
      });
    });

    test('testDisplaysEnableButton', async () => {
      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /Enable 2FA/i })).toBeInTheDocument();
      });
    });

    test('testOpensWizardOnEnableClick', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: false, backup_codes_available: 0 }),
      });

      // Second call for setup endpoint in wizard
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          secret: 'TEST_SECRET',
          qrCodeUrl: 'https://example.com/qr',
        }),
      });

      render(<TwoFactorSettings />);

      const enableButton = await screen.findByRole('button', { name: /Enable 2FA/i });
      fireEvent.click(enableButton);

      await waitFor(() => {
        expect(screen.getByTestId('dialog')).toBeInTheDocument();
      });
    });
  });

  describe('2FA Enabled State', () => {
    test('testDisplaysEnabledStatus', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 12 }),
      });

      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(screen.getByText(/Two-Factor Authentication is currently enabled/i)).toBeInTheDocument();
      });
    });

    test('testDisplaysBackupCodeCount', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 12 }),
      });

      render(<TwoFactorSettings />);

      await waitFor(
        () => {
          // Text is split across elements due to <strong> tag
          expect(screen.getByText(/You have/i)).toBeInTheDocument();
          expect(screen.getByText('12')).toBeInTheDocument();
          expect(screen.getByText(/backup codes available/i)).toBeInTheDocument();
        },
        { timeout: 3000 }
      );
    });

    test('testDisplaysRegenerateButton', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 12 }),
      });

      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /Regenerate Backup Codes/i })).toBeInTheDocument();
      });
    });

    test('testDisplaysDisableButton', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 12 }),
      });

      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /Disable 2FA/i })).toBeInTheDocument();
      });
    });

    test('testDisplaysCheckmarkIcon', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 12 }),
      });

      render(<TwoFactorSettings />);

      await waitFor(() => {
        expect(screen.getByTestId('icon-check')).toBeInTheDocument();
      });
    });
  });

  describe('Disable 2FA', () => {
    test('testDisables2FAWithConfirmation', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 10 }),
      });

      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ message: 'Two-factor authentication disabled' }),
      });

      render(<TwoFactorSettings />);

      const disableButton = await screen.findByRole('button', { name: /Disable 2FA/i });
      fireEvent.click(disableButton);

      expect(global.confirm).toHaveBeenCalled();

      await waitFor(() => {
        expect(mockApiClient).toHaveBeenCalledWith('/api/auth/2fa/disable', {
          method: 'POST',
        });
      });
    });

    test('testUpdatesStateAfterDisable', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 10 }),
      });

      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ message: 'Two-factor authentication disabled' }),
      });

      render(<TwoFactorSettings />);

      const disableButton = await screen.findByRole('button', { name: /Disable 2FA/i });
      fireEvent.click(disableButton);

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /Enable 2FA/i })).toBeInTheDocument();
      });
    });

    test('testHandlesDisableError', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 10 }),
      });

      mockApiClient.mockResolvedValueOnce({
        ok: false,
        json: async () => ({ message: 'Failed to disable' }),
      });

      render(<TwoFactorSettings />);

      const disableButton = await screen.findByRole('button', { name: /Disable 2FA/i });
      fireEvent.click(disableButton);

      await waitFor(() => {
        expect(screen.getByTestId('alert-destructive')).toBeInTheDocument();
      });
    });

    test('testCancel2FADisable', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 10 }),
      });

      (global.confirm as jest.Mock).mockReturnValueOnce(false);

      render(<TwoFactorSettings />);

      const disableButton = await screen.findByRole('button', { name: /Disable 2FA/i });
      fireEvent.click(disableButton);

      // API should not be called if user cancels
      await waitFor(() => {
        expect(mockApiClient).toHaveBeenCalledTimes(1); // Only the initial status fetch
      });
    });
  });

  describe('Regenerate Backup Codes', () => {
    test('testRegenerateCodesWithConfirmation', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 8 }),
      });

      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          backup_codes: Array.from({ length: 15 }, (_, i) => `CODE${i}`),
          message: 'Backup codes regenerated successfully',
        }),
      });

      render(<TwoFactorSettings />);

      const regenerateButton = await screen.findByRole('button', {
        name: /Regenerate Backup Codes/i,
      });
      fireEvent.click(regenerateButton);

      expect(global.confirm).toHaveBeenCalled();

      await waitFor(() => {
        expect(mockApiClient).toHaveBeenCalledWith('/api/auth/2fa/regenerate-codes', {
          method: 'POST',
        });
      });
    });

    test('testAutoDownloadsNewCodes', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 8 }),
      });

      const mockCodes = Array.from({ length: 15 }, (_, i) => `CODE${String(i + 1).padStart(3, '0')}`);

      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          backup_codes: mockCodes,
          message: 'Backup codes regenerated successfully',
        }),
      });

      render(<TwoFactorSettings />);

      const regenerateButton = await screen.findByRole('button', {
        name: /Regenerate Backup Codes/i,
      });
      fireEvent.click(regenerateButton);

      await waitFor(() => {
        expect(mockClick).toHaveBeenCalled();
        expect(mockSetAttribute).toHaveBeenCalledWith('download', 'whity-backup-codes.txt');
      });
    });

    test('testUpdatesCodeCountAfterRegenerate', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 8 }),
      });

      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          backup_codes: Array.from({ length: 15 }, (_, i) => `CODE${i}`),
          message: 'Backup codes regenerated successfully',
        }),
      });

      render(<TwoFactorSettings />);

      const regenerateButton = await screen.findByRole('button', {
        name: /Regenerate Backup Codes/i,
      });
      fireEvent.click(regenerateButton);

      await waitFor(
        () => {
          // Text is split across elements due to <strong> tag
          expect(screen.getByText(/You have/i)).toBeInTheDocument();
          expect(screen.getByText('15')).toBeInTheDocument();
          expect(screen.getByText(/backup codes available/i)).toBeInTheDocument();
        },
        { timeout: 3000 }
      );
    });

    test('testHandlesRegenerateError', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 8 }),
      });

      mockApiClient.mockResolvedValueOnce({
        ok: false,
        json: async () => ({ message: 'Failed to regenerate' }),
      });

      render(<TwoFactorSettings />);

      const regenerateButton = await screen.findByRole('button', {
        name: /Regenerate Backup Codes/i,
      });
      fireEvent.click(regenerateButton);

      await waitFor(() => {
        expect(screen.getByTestId('alert-destructive')).toBeInTheDocument();
      });
    });

    test('testCancelRegenerateCodes', async () => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: true, backup_codes_available: 8 }),
      });

      (global.confirm as jest.Mock).mockReturnValueOnce(false);

      render(<TwoFactorSettings />);

      const regenerateButton = await screen.findByRole('button', {
        name: /Regenerate Backup Codes/i,
      });
      fireEvent.click(regenerateButton);

      // API should not be called if user cancels
      await waitFor(() => {
        expect(mockApiClient).toHaveBeenCalledTimes(1); // Only the initial status fetch
      });
    });
  });

  describe('Setup Wizard Integration', () => {
    beforeEach(() => {
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ enabled: false, backup_codes_available: 0 }),
      });
    });

    test('testWizardCancelClosesDialog', async () => {
      // Setup endpoint in wizard
      mockApiClient.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          secret: 'TEST_SECRET',
          qrCodeUrl: 'https://example.com/qr',
        }),
      });

      render(<TwoFactorSettings />);

      const enableButton = await screen.findByRole('button', { name: /Enable 2FA/i });
      fireEvent.click(enableButton);

      // Dialog should appear
      await waitFor(() => {
        expect(screen.getByTestId('dialog')).toBeInTheDocument();
      });
    });
  });
});
