'use client';

import { useToast } from '@/lib/toast-context';
import type { ToastType } from '@/lib/toast-context';
import {
  IconCheck,
  IconAlertCircle,
  IconAlertTriangle,
  IconInfoCircle,
  IconX,
} from '@tabler/icons-react';
import { cn } from '@/lib/utils';

/**
 * Per-type styling driven by the semantic color tokens from base.json
 * (success / warning / error / info). Errors map to the `error` token,
 * keeping them visually aligned with the `destructive` family.
 */
const toastStyles: Record<ToastType, string> = {
  success: 'bg-success text-success-foreground',
  error: 'bg-error text-error-foreground',
  warning: 'bg-warning text-warning-foreground',
  info: 'bg-info text-info-foreground',
};

/** Assertive announcements for failures/warnings, polite for the rest. */
const toastPoliteness: Record<ToastType, 'polite' | 'assertive'> = {
  success: 'polite',
  error: 'assertive',
  warning: 'assertive',
  info: 'polite',
};

function ToastIcon({ type }: { type: ToastType }) {
  switch (type) {
    case 'success':
      return <IconCheck className="size-5" aria-hidden="true" />;
    case 'error':
      return <IconAlertCircle className="size-5" aria-hidden="true" />;
    case 'warning':
      return <IconAlertTriangle className="size-5" aria-hidden="true" />;
    case 'info':
      return <IconInfoCircle className="size-5" aria-hidden="true" />;
  }
}

export function ToastContainer() {
  const { toasts, removeToast } = useToast();

  return (
    <div
      role="region"
      aria-label="Notifications"
      className="fixed bottom-0 inset-e-0 z-[9999] flex flex-col gap-3 p-4 pointer-events-none"
    >
      {toasts.map((toast) => (
        <div
          key={toast.id}
          role="status"
          aria-live={toastPoliteness[toast.type]}
          aria-atomic="true"
          className={cn(
            'pointer-events-auto flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium shadow-lg animate-in duration-200 ltr:slide-in-from-right-full rtl:slide-in-from-left-full',
            toastStyles[toast.type]
          )}
        >
          <div className="flex items-center gap-3 flex-1">
            <ToastIcon type={toast.type} />
            <span>{toast.message}</span>
          </div>
          <button
            type="button"
            onClick={() => removeToast(toast.id)}
            aria-label="Dismiss notification"
            className="opacity-70 hover:opacity-100 transition-opacity focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 rounded-sm"
          >
            <IconX className="size-4" aria-hidden="true" />
          </button>
        </div>
      ))}
    </div>
  );
}
