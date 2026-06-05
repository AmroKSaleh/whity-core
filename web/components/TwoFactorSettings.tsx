'use client';

import React, { useState, useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { IconCheck } from '@tabler/icons-react';
import QRCode from 'react-qr-code';

interface TwoFactorStatus {
  enabled: boolean;
  backup_codes_available: number;
}

interface TwoFactorSetupWizardProps {
  onComplete: (codes: string[]) => void;
  onCancel: () => void;
}

/**
 * Placeholder for TwoFactorSetupWizard component
 * This will be implemented in Task 6
 */
const TwoFactorSetupWizard: React.FC<TwoFactorSetupWizardProps> = ({ onComplete, onCancel }) => {
  const { apiClient } = useAuth();
  const [step, setStep] = useState<'setup' | 'verify'>('setup');
  const [secret, setSecret] = useState<string>('');
  const [qrCodeUrl, setQrCodeUrl] = useState<string>('');
  const [code, setCode] = useState<string>('');
  const [error, setError] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(false);

  useEffect(() => {
    const fetchSetup = async () => {
      try {
        const response = await apiClient('/api/auth/2fa/setup', {
          method: 'POST',
        });

        if (!response.ok) {
          const errorData = await response.json().catch(() => ({}));
          setError(errorData.message || 'Failed to setup 2FA');
          return;
        }

        const data = await response.json();
        setSecret(data.secret);
        setQrCodeUrl(data.qrCodeUrl);
      } catch (err) {
        setError('Failed to fetch setup data');
      }
    };

    fetchSetup();
  }, [apiClient]);

  const handleVerify = async () => {
    if (!code.trim()) {
      setError('Please enter the verification code');
      return;
    }

    setLoading(true);
    setError('');

    try {
      const response = await apiClient('/api/auth/2fa/confirm', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ code, secret }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(errorData.message || 'Failed to verify code');
        setLoading(false);
        return;
      }

      const data = await response.json();
      onComplete(data.backup_codes);
    } catch (err) {
      setError('Failed to verify code');
      setLoading(false);
    }
  };

  return (
    <Dialog open={true} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="w-[90vw] max-w-2xl">
        <DialogHeader>
          <DialogTitle>Enable Two-Factor Authentication</DialogTitle>
          <DialogDescription>
            Secure your account with two-factor authentication
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="space-y-4">
            <div>
              <p className="text-sm font-medium mb-2">Scan with your authenticator app:</p>
              {qrCodeUrl && (
                <div className="flex justify-center">
                  <QRCode
                    value={qrCodeUrl}
                    size={200}
                    level="H"
                    className="border border-gray-200 rounded p-2"
                  />
                </div>
              )}
            </div>
            <div>
              <p className="text-sm text-gray-600 mb-2">
                Can't scan? Enter this code manually:
              </p>
              <div className="flex items-center gap-2">
                <code
                  className="flex-1 bg-gray-100 p-2 rounded text-sm font-mono break-all cursor-pointer hover:bg-gray-200 select-all"
                  title={secret}>
                  {secret}
                </code>
                <button
                  type="button"
                  onClick={() => {
                    navigator.clipboard.writeText(secret);
                  }}
                  className="px-3 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded text-sm whitespace-nowrap flex-shrink-0"
                >
                  Copy
                </button>
              </div>
            </div>
            <Button
              onClick={() => setStep('verify')}
              className="w-full bg-blue-500 hover:bg-blue-600"
            >
              Next
            </Button>
            <Button
              variant="outline"
              onClick={onCancel}
              className="w-full"
            >
              Cancel
            </Button>
          </div>
        )}

        {step === 'verify' && (
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-2">
                Enter the 6-digit code from your authenticator app:
              </label>
              <input
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                placeholder="000000"
                maxLength={6}
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-center text-2xl tracking-widest"
              />
            </div>
            {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
            <Button
              onClick={handleVerify}
              disabled={loading || code.length !== 6}
              className="w-full bg-blue-500 hover:bg-blue-600"
            >
              {loading ? 'Verifying...' : 'Verify'}
            </Button>
            <Button
              variant="outline"
              onClick={() => setStep('setup')}
              className="w-full"
            >
              Back
            </Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
};

/**
 * TwoFactorSettings Component
 *
 * Displays the user's 2FA status and allows them to:
 * - Enable 2FA (opens setup wizard)
 * - Regenerate backup codes
 * - Disable 2FA
 */
export const TwoFactorSettings: React.FC = () => {
  const { apiClient } = useAuth();
  const [enabled, setEnabled] = useState<boolean>(false);
  const [backupCodesAvailable, setBackupCodesAvailable] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(true);
  const [showWizard, setShowWizard] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [actionLoading, setActionLoading] = useState<boolean>(false);

  /**
   * Fetch the current 2FA status
   */
  const fetchStatus = async () => {
    setLoading(true);
    setError('');

    try {
      const response = await apiClient('/api/auth/2fa/status', {
        method: 'GET',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(errorData.message || 'Failed to fetch 2FA status');
        return;
      }

      const data: TwoFactorStatus = await response.json();
      setEnabled(data.enabled);
      setBackupCodesAvailable(data.backup_codes_available);
    } catch (err) {
      setError('Failed to fetch 2FA status');
    } finally {
      setLoading(false);
    }
  };

  /**
   * Disable 2FA after confirmation
   */
  const handleDisable = async () => {
    if (!window.confirm(
      'Are you sure? You will need to enable 2FA again to restore this protection. This cannot be undone immediately.'
    )) {
      return;
    }

    setActionLoading(true);
    setError('');

    try {
      const response = await apiClient('/api/auth/2fa/disable', {
        method: 'POST',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(errorData.message || 'Failed to disable 2FA');
        setActionLoading(false);
        return;
      }

      setEnabled(false);
      setBackupCodesAvailable(0);
    } catch (err) {
      setError('Failed to disable 2FA');
    } finally {
      setActionLoading(false);
    }
  };

  /**
   * Regenerate backup codes after confirmation
   */
  const handleRegenerateCodes = async () => {
    if (!window.confirm(
      'This will invalidate your current backup codes. Make sure to download the new ones. Continue?'
    )) {
      return;
    }

    setActionLoading(true);
    setError('');

    try {
      const response = await apiClient('/api/auth/2fa/regenerate-codes', {
        method: 'POST',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(errorData.message || 'Failed to regenerate backup codes');
        setActionLoading(false);
        return;
      }

      const data = await response.json();

      // Auto-download backup codes
      const text = data.backup_codes.join('\n');
      const element = document.createElement('a');
      element.setAttribute(
        'href',
        'data:text/plain;charset=utf-8,' + encodeURIComponent(text)
      );
      element.setAttribute('download', 'whity-backup-codes.txt');
      element.click();

      // Update state - 15 new codes generated
      setBackupCodesAvailable(15);
    } catch (err) {
      setError('Failed to regenerate backup codes');
    } finally {
      setActionLoading(false);
    }
  };

  /**
   * Handle wizard completion
   */
  const handleWizardComplete = async (codes: string[]) => {
    setShowWizard(false);

    // Auto-download backup codes
    const text = codes.join('\n');
    const element = document.createElement('a');
    element.setAttribute(
      'href',
      'data:text/plain;charset=utf-8,' + encodeURIComponent(text)
    );
    element.setAttribute('download', 'whity-backup-codes.txt');
    element.click();

    // Refetch status
    await fetchStatus();
  };

  // Fetch status on mount
  useEffect(() => {
    fetchStatus();
  }, []);

  if (loading) {
    return (
      <div className="max-w-md mx-auto p-6">
        <div className="flex justify-center">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-md mx-auto p-6">
      {error && (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="bg-gray-50 rounded-lg p-4 mb-4 border border-gray-200">
        <div className="flex items-center gap-2 mb-2">
          {enabled ? (
            <>
              <IconCheck className="w-5 h-5 text-green-600" />
              <span className="font-semibold text-green-700">Enabled</span>
            </>
          ) : (
            <span className="font-semibold text-gray-700">Not Enabled</span>
          )}
        </div>
        <p className="text-sm text-gray-600">
          Two-Factor Authentication {enabled ? 'is' : 'is not'} currently enabled
        </p>
      </div>

      {enabled && (
        <div className="bg-blue-50 rounded-lg p-4 mb-4 border border-blue-200">
          <p className="text-sm text-gray-700">
            You have <strong>{backupCodesAvailable}</strong> backup codes available
          </p>
          <p className="text-xs text-gray-600 mt-1">
            Use these codes if you lose access to your authenticator app
          </p>
        </div>
      )}

      <div className="space-y-3">
        {!enabled && (
          <Button
            onClick={() => setShowWizard(true)}
            className="w-full bg-blue-500 hover:bg-blue-600 text-white"
          >
            Enable 2FA
          </Button>
        )}

        {enabled && (
          <>
            <Button
              onClick={handleRegenerateCodes}
              disabled={actionLoading}
              className="w-full bg-gray-500 hover:bg-gray-600 text-white"
            >
              {actionLoading ? 'Regenerating...' : 'Regenerate Backup Codes'}
            </Button>
            <Button
              onClick={handleDisable}
              disabled={actionLoading}
              className="w-full bg-red-500 hover:bg-red-600 text-white"
            >
              {actionLoading ? 'Disabling...' : 'Disable 2FA'}
            </Button>
          </>
        )}
      </div>

      {showWizard && (
        <TwoFactorSetupWizard
          onComplete={handleWizardComplete}
          onCancel={() => setShowWizard(false)}
        />
      )}
    </div>
  );
};
