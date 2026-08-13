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
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { IconCheck } from '@tabler/icons-react';
import QRCode from 'react-qr-code';

interface TwoFactorStatus {
  enabled: boolean;
  backup_codes_available: number;
}

/**
 * A wizard failure is either text the BACKEND supplied — already a sentence,
 * in whatever language it speaks — or one of OURS, recorded as a variant and
 * translated at render time.
 *
 * Recording ours rather than resolving it immediately is what keeps the setup
 * effect off `t`: `t`'s identity changes the moment the translation bundle
 * arrives, so listing it as a dependency would re-run the effect and mint a
 * SECOND TOTP secret under a QR code the user may already have scanned.
 */
type WizardError =
  | { kind: 'none' }
  | { kind: 'backend'; text: string }
  | { kind: 'setup' }
  | { kind: 'fetch' }
  | { kind: 'codeRequired' }
  | { kind: 'verify' };

/** Resolve a {@link WizardError} to the sentence to show. */
function wizardErrorText(t: TranslateFn, error: WizardError): string {
  switch (error.kind) {
    case 'none':
      return '';
    case 'backend':
      return error.text;
    case 'setup':
      return t('twoFactorSetup.error.setup', 'Failed to setup 2FA');
    case 'fetch':
      return t('twoFactorSetup.error.fetch', 'Failed to fetch setup data');
    case 'codeRequired':
      return t('twoFactorSetup.error.codeRequired', 'Please enter the verification code');
    case 'verify':
      return t('twoFactorSetup.error.verify', 'Failed to verify code');
  }
}

/**
 * The same treatment for the settings panel, and for the same reason: its
 * status fetch is a `useCallback` a mount effect depends on, so pulling `t`
 * into it would re-run the fetch — and flash the loading spinner over an
 * already-rendered panel — as soon as the translation bundle arrived.
 */
type SettingsError =
  | { kind: 'none' }
  | { kind: 'backend'; text: string }
  | { kind: 'status' }
  | { kind: 'disable' }
  | { kind: 'regenerate' };

/** Resolve a {@link SettingsError} to the sentence to show. */
function settingsErrorText(t: TranslateFn, error: SettingsError): string {
  switch (error.kind) {
    case 'none':
      return '';
    case 'backend':
      return error.text;
    case 'status':
      return t('twoFactorSettings.error.status', 'Failed to fetch 2FA status');
    case 'disable':
      return t('twoFactorSettings.error.disable', 'Failed to disable 2FA');
    case 'regenerate':
      return t('twoFactorSettings.error.regenerate', 'Failed to regenerate backup codes');
  }
}

interface TwoFactorSetupWizardProps {
  onComplete: (codes: string[]) => void;
  onCancel: () => void;
  /**
   * Bearer token to authenticate setup()/confirm() with INSTEAD of the normal
   * session (via apiClient). Used by the login-time mandatory-enrollment flow
   * (WC-525), where the caller holds a narrowly-scoped `two_factor_enrollment`
   * token (see TokenValidator::validateTwoFactorEnrollmentToken) rather than a
   * full session — there is no session to attach cookies/headers from yet.
   */
  bearerToken?: string;
}

export const TwoFactorSetupWizard: React.FC<TwoFactorSetupWizardProps> = ({ onComplete, onCancel, bearerToken }) => {
  const { apiClient } = useAuth();
  const t = useTranslation('auth');
  const [step, setStep] = useState<'setup' | 'verify'>('setup');
  const [secret, setSecret] = useState<string>('');
  const [qrCodeUrl, setQrCodeUrl] = useState<string>('');
  const [code, setCode] = useState<string>('');
  const [error, setError] = useState<WizardError>({ kind: 'none' });
  const [loading, setLoading] = useState<boolean>(false);

  const doFetch = useCallback((path: string, init?: RequestInit) => {
    if (bearerToken) {
      return fetch(path, {
        ...init,
        headers: {
          ...(init?.headers ?? {}),
          'Content-Type': 'application/json',
          Authorization: `Bearer ${bearerToken}`,
        },
      });
    }
    return apiClient(path, init);
  }, [apiClient, bearerToken]);

  useEffect(() => {
    const fetchSetup = async () => {
      try {
        const response = await doFetch('/api/v1/auth/2fa/setup', {
          method: 'POST',
        });

        if (!response.ok) {
          const errorData = await response.json().catch(() => ({}));
          setError(
            errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'setup' }
          );
          return;
        }

        const data = await response.json();
        setSecret(data.secret);
        setQrCodeUrl(data.qrCodeUrl);
      } catch {
        setError({ kind: 'fetch' });
      }
    };

    fetchSetup();
  }, [doFetch]);

  const handleVerify = async () => {
    if (!code.trim()) {
      setError({ kind: 'codeRequired' });
      return;
    }

    setLoading(true);
    setError({ kind: 'none' });

    try {
      const response = await doFetch('/api/v1/auth/2fa/confirm', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ code, secret }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(
          errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'verify' }
        );
        setLoading(false);
        return;
      }

      const data = await response.json();
      onComplete(data.backup_codes);
    } catch {
      setError({ kind: 'verify' });
      setLoading(false);
    }
  };

  const errorText = wizardErrorText(t, error);

  return (
    <Dialog open={true} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="w-[90vw] max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {t('twoFactorSetup.title', 'Enable Two-Factor Authentication')}
          </DialogTitle>
          <DialogDescription>
            {t('twoFactorSetup.subtitle', 'Secure your account with two-factor authentication')}
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="space-y-4">
            <div>
              <p className="text-sm font-medium mb-2">
                {t('twoFactorSetup.scan.label', 'Scan with your authenticator app:')}
              </p>
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
                {t('twoFactorSetup.manual.label', "Can't scan? Enter this code manually:")}
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
                  {t('twoFactorSetup.copy', 'Copy')}
                </button>
              </div>
            </div>
            <Button
              onClick={() => setStep('verify')}
              className="w-full"
            >
              {t('twoFactorSetup.next', 'Next')}
            </Button>
            <Button
              variant="outline"
              onClick={onCancel}
              className="w-full"
            >
              {t('twoFactorSetup.cancel', 'Cancel')}
            </Button>
          </div>
        )}

        {step === 'verify' && (
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-2">
                {t(
                  'twoFactorSetup.code.label',
                  'Enter the 6-digit code from your authenticator app:'
                )}
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
            {errorText && <Alert variant="destructive"><AlertDescription>{errorText}</AlertDescription></Alert>}
            <Button
              onClick={handleVerify}
              disabled={loading || code.length !== 6}
              className="w-full"
            >
              {loading
                ? t('twoFactorSetup.submit.pending', 'Verifying...')
                : t('twoFactorSetup.submit', 'Verify')}
            </Button>
            <Button
              variant="outline"
              onClick={() => setStep('setup')}
              className="w-full"
            >
              {t('twoFactorSetup.back', 'Back')}
            </Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
};

export const TwoFactorSettings: React.FC = () => {
  const { apiClient } = useAuth();
  const t = useTranslation('auth');
  const [enabled, setEnabled] = useState<boolean>(false);
  const [backupCodesAvailable, setBackupCodesAvailable] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(true);
  const [showWizard, setShowWizard] = useState<boolean>(false);
  const [error, setError] = useState<SettingsError>({ kind: 'none' });
  const [statusMessage, setStatusMessage] = useState<string>('');
  const [actionLoading, setActionLoading] = useState<boolean>(false);
  const [disableConfirmOpen, setDisableConfirmOpen] = useState<boolean>(false);
  const [regenerateConfirmOpen, setRegenerateConfirmOpen] = useState<boolean>(false);

  const fetchStatus = useCallback(async () => {
    setLoading(true);
    setError({ kind: 'none' });

    try {
      const response = await apiClient('/api/v1/auth/2fa/status', {
        method: 'GET',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(
          errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'status' }
        );
        return;
      }

      const data: TwoFactorStatus = await response.json();
      setEnabled(data.enabled);
      setBackupCodesAvailable(data.backup_codes_available);
    } catch {
      setError({ kind: 'status' });
    } finally {
      setLoading(false);
    }
  }, [apiClient]);

  const handleDisable = async () => {
    setDisableConfirmOpen(false);
    setActionLoading(true);
    setError({ kind: 'none' });
    setStatusMessage('');

    try {
      const response = await apiClient('/api/v1/auth/2fa/disable', {
        method: 'POST',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(
          errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'disable' }
        );
        setActionLoading(false);
        return;
      }

      setEnabled(false);
      setBackupCodesAvailable(0);
      setStatusMessage(
        t('twoFactorSettings.disable.success', 'Two-factor authentication has been disabled.')
      );
    } catch {
      setError({ kind: 'disable' });
    } finally {
      setActionLoading(false);
    }
  };

  const handleRegenerateCodes = async () => {
    setRegenerateConfirmOpen(false);
    setActionLoading(true);
    setError({ kind: 'none' });
    setStatusMessage('');

    try {
      const response = await apiClient('/api/v1/auth/2fa/regenerate-codes', {
        method: 'POST',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(
          errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'regenerate' }
        );
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
      setStatusMessage(
        t('twoFactorSettings.regenerate.success', 'Backup codes regenerated and downloaded.')
      );
    } catch {
      setError({ kind: 'regenerate' });
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

  const errorText = settingsErrorText(t, error);

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

      {errorText && (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>{errorText}</AlertDescription>
        </Alert>
      )}

      <div className="bg-muted rounded-lg p-4 mb-4 border border-border">
        <div className="flex items-center gap-2 mb-2">
          {enabled ? (
            <>
              <IconCheck className="w-5 h-5 text-success" />
              <span className="font-semibold text-success">
                {t('twoFactorSettings.status.enabled', 'Enabled')}
              </span>
            </>
          ) : (
            <span className="font-semibold text-foreground">
              {t('twoFactorSettings.status.notEnabled', 'Not Enabled')}
            </span>
          )}
        </div>
        <p className="text-sm text-muted-foreground">
          {enabled
            ? t(
                'twoFactorSettings.status.description.enabled',
                'Two-Factor Authentication is currently enabled'
              )
            : t(
                'twoFactorSettings.status.description.disabled',
                'Two-Factor Authentication is not currently enabled'
              )}
        </p>
      </div>

      {enabled && (
        <div className="bg-card rounded-lg p-4 mb-4 border border-border">
          {/*
            NOT translated, deliberately: the count is wrapped in <strong> mid-
            sentence, and `t()` returns a string — so keeping the emphasis would
            mean handing a translator three fragments in English word order,
            which is worse than leaving the sentence in English. It also has no
            plural form ("You have 1 backup codes available"). Both need the
            markup pulled out of the sentence first; tracked separately.
          */}
          <p className="text-sm text-card-foreground">
            You have <strong>{backupCodesAvailable}</strong> backup codes available
          </p>
          <p className="text-xs text-muted-foreground mt-1">
            {t(
              'twoFactorSettings.backupCodes.hint',
              'Use these codes if you lose access to your authenticator app'
            )}
          </p>
        </div>
      )}

      <div className="space-y-3">
        {!enabled && (
          <Button
            onClick={() => setShowWizard(true)}
            className="w-full"
          >
            {t('twoFactorSettings.enable', 'Enable 2FA')}
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
              {actionLoading
                ? t('twoFactorSettings.regenerate.pending', 'Regenerating...')
                : t('twoFactorSettings.regenerate', 'Regenerate Backup Codes')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => setDisableConfirmOpen(true)}
              disabled={actionLoading}
              className="w-full"
            >
              {actionLoading
                ? t('twoFactorSettings.disable.pending', 'Disabling...')
                : t('twoFactorSettings.disable', 'Disable 2FA')}
            </Button>
          </>
        )}
      </div>

      {/* Disable 2FA confirmation dialog */}
      <AlertDialog open={disableConfirmOpen} onOpenChange={setDisableConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('twoFactorSettings.disable.confirm.title', 'Disable Two-Factor Authentication?')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t(
                'twoFactorSettings.disable.confirm.body',
                'You will need to enable 2FA again to restore this protection. This cannot be undone immediately.'
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('twoFactorSettings.confirm.cancel', 'Cancel')}</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/80"
              onClick={handleDisable}
            >
              {t('twoFactorSettings.disable', 'Disable 2FA')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Regenerate backup codes confirmation dialog */}
      <AlertDialog open={regenerateConfirmOpen} onOpenChange={setRegenerateConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('twoFactorSettings.regenerate.confirm.title', 'Regenerate Backup Codes?')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t(
                'twoFactorSettings.regenerate.confirm.body',
                'This will invalidate your current backup codes. Make sure to download the new ones.'
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('twoFactorSettings.confirm.cancel', 'Cancel')}</AlertDialogCancel>
            <AlertDialogAction onClick={handleRegenerateCodes}>
              {t('twoFactorSettings.regenerate.confirm.submit', 'Regenerate Codes')}
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
