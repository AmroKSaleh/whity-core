'use client';

import React, { useCallback, useState, useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { Input } from '@amroksaleh/ui/input';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@amroksaleh/ui/alert-dialog';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@amroksaleh/ui/dialog';
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
        const response = await apiClient('/api/v1/auth/2fa/setup', {
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
      } catch {
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
      const response = await apiClient('/api/v1/auth/2fa/confirm', {
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
    } catch {
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
                    className="border border-border rounded p-2"
                  />
                </div>
              )}
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-2">
                Can&apos;t scan? Enter this code manually:
              </p>
              <div className="flex items-center gap-2">
                <code
                  className="flex-1 bg-muted p-2 rounded text-sm font-mono break-all cursor-pointer hover:bg-muted/80 select-all"
                  title={secret}>
                  {secret}
                </code>
                <button
                  type="button"
                  onClick={() => {
                    navigator.clipboard.writeText(secret);
                  }}
                  className="px-3 py-2 bg-primary hover:bg-primary/80 text-primary-foreground rounded text-sm whitespace-nowrap shrink-0"
                >
                  Copy
                </button>
              </div>
            </div>
            <Button
              onClick={() => setStep('verify')}
              className="w-full"
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
              <Input
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                placeholder="000000"
                maxLength={6}
                className="text-center text-2xl tracking-widest h-12"
              />
            </div>
            {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
            <Button
              onClick={handleVerify}
              disabled={loading || code.length !== 6}
              className="w-full"
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

export const TwoFactorSettings: React.FC = () => {
  const { apiClient } = useAuth();
  const [enabled, setEnabled] = useState<boolean>(false);
  const [backupCodesAvailable, setBackupCodesAvailable] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(true);
  const [showWizard, setShowWizard] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [statusMessage, setStatusMessage] = useState<string>('');
  const [actionLoading, setActionLoading] = useState<boolean>(false);
  const [disableConfirmOpen, setDisableConfirmOpen] = useState<boolean>(false);
  const [regenerateConfirmOpen, setRegenerateConfirmOpen] = useState<boolean>(false);

  const fetchStatus = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      const response = await apiClient('/api/v1/auth/2fa/status', {
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
    } catch {
      setError('Failed to fetch 2FA status');
    } finally {
      setLoading(false);
    }
  }, [apiClient]);

  const handleDisable = async () => {
    setDisableConfirmOpen(false);
    setActionLoading(true);
    setError('');
    setStatusMessage('');

    try {
      const response = await apiClient('/api/v1/auth/2fa/disable', {
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
      setStatusMessage('Two-factor authentication has been disabled.');
    } catch {
      setError('Failed to disable 2FA');
    } finally {
      setActionLoading(false);
    }
  };

  const handleRegenerateCodes = async () => {
    setRegenerateConfirmOpen(false);
    setActionLoading(true);
    setError('');
    setStatusMessage('');

    try {
      const response = await apiClient('/api/v1/auth/2fa/regenerate-codes', {
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

      setBackupCodesAvailable(data.backup_codes.length);
      setStatusMessage('Backup codes regenerated and downloaded.');
    } catch {
      setError('Failed to regenerate backup codes');
    } finally {
      setActionLoading(false);
    }
  };

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
    void (async () => {
      await fetchStatus();
    })();
  }, [fetchStatus]);

  if (loading) {
    return (
      <div className="max-w-md mx-auto p-6">
        <div className="flex justify-center">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-md mx-auto p-6">
      {/* aria-live region for status announcements */}
      <div aria-live="polite" aria-atomic="true" className="sr-only">
        {statusMessage}
      </div>

      {error && (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="bg-muted rounded-lg p-4 mb-4 border border-border">
        <div className="flex items-center gap-2 mb-2">
          {enabled ? (
            <>
              <IconCheck className="w-5 h-5 text-success" />
              <span className="font-semibold text-success">Enabled</span>
            </>
          ) : (
            <span className="font-semibold text-foreground">Not Enabled</span>
          )}
        </div>
        <p className="text-sm text-muted-foreground">
          Two-Factor Authentication {enabled ? 'is' : 'is not'} currently enabled
        </p>
      </div>

      {enabled && (
        <div className="bg-card rounded-lg p-4 mb-4 border border-border">
          <p className="text-sm text-card-foreground">
            You have <strong>{backupCodesAvailable}</strong> backup codes available
          </p>
          <p className="text-xs text-muted-foreground mt-1">
            Use these codes if you lose access to your authenticator app
          </p>
        </div>
      )}

      <div className="space-y-3">
        {!enabled && (
          <Button
            onClick={() => setShowWizard(true)}
            className="w-full"
          >
            Enable 2FA
          </Button>
        )}

        {enabled && (
          <>
            <Button
              variant="secondary"
              onClick={() => setRegenerateConfirmOpen(true)}
              disabled={actionLoading}
              className="w-full"
            >
              {actionLoading ? 'Regenerating...' : 'Regenerate Backup Codes'}
            </Button>
            <Button
              variant="destructive"
              onClick={() => setDisableConfirmOpen(true)}
              disabled={actionLoading}
              className="w-full"
            >
              {actionLoading ? 'Disabling...' : 'Disable 2FA'}
            </Button>
          </>
        )}
      </div>

      {/* Disable 2FA confirmation dialog */}
      <AlertDialog open={disableConfirmOpen} onOpenChange={setDisableConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Disable Two-Factor Authentication?</AlertDialogTitle>
            <AlertDialogDescription>
              You will need to enable 2FA again to restore this protection. This cannot be undone immediately.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/80"
              onClick={handleDisable}
            >
              Disable 2FA
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Regenerate backup codes confirmation dialog */}
      <AlertDialog open={regenerateConfirmOpen} onOpenChange={setRegenerateConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Regenerate Backup Codes?</AlertDialogTitle>
            <AlertDialogDescription>
              This will invalidate your current backup codes. Make sure to download the new ones.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleRegenerateCodes}>
              Regenerate Codes
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {showWizard && (
        <TwoFactorSetupWizard
          onComplete={handleWizardComplete}
          onCancel={() => setShowWizard(false)}
        />
      )}
    </div>
  );
};
