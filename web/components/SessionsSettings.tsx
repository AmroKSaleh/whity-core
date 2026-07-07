'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@amroksaleh/ui/alert-dialog';
import { IconDeviceDesktopOff } from '@tabler/icons-react';

/**
 * WC-b-logout-others: "Sign out of all other sessions & devices".
 *
 * Calls POST /api/v1/me/logout-others, which bumps the profile's token epoch —
 * invalidating every OTHER access/refresh token AND every registered device
 * credential — and re-mints THIS session so the current tab stays signed in.
 * Because sessions are stateless (no per-session list), this is all-or-nothing
 * for other sessions; a specific native device can instead be removed from the
 * devices list. After success we refreshAuth() to re-sync from the re-issued
 * cookies.
 */
export function SessionsSettings() {
  const { apiClient, refreshAuth } = useAuth();
  const { addToast } = useToast();
  const [submitting, setSubmitting] = useState(false);

  const handleLogoutOthers = async () => {
    setSubmitting(true);
    try {
      const response = await apiClient('/api/v1/me/logout-others', { method: 'POST' });
      if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || 'Failed to sign out other sessions');
      }
      // The response re-issued this session's cookies at the new epoch; re-sync.
      await refreshAuth();
      addToast('Signed out of all other sessions and devices.', 'success');
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Something went wrong', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className="text-sm font-medium text-foreground">Active sessions</p>
        <p className="text-sm text-muted-foreground">
          Sign out everywhere except this device — other browsers, apps, and registered
          devices will need to sign in again.
        </p>
      </div>
      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button
            type="button"
            variant="outline"
            disabled={submitting}
            className="gap-2 shrink-0"
            data-testid="logout-others-button"
          >
            <IconDeviceDesktopOff className="w-4 h-4" />
            Sign out other sessions
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Sign out of all other sessions &amp; devices?</AlertDialogTitle>
            <AlertDialogDescription>
              This keeps you signed in here but signs you out of every other browser, app, and
              registered device. They&rsquo;ll each need to sign in again. This can&rsquo;t be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => void handleLogoutOthers()}
              data-testid="logout-others-confirm"
            >
              Sign out other sessions
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
