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

/**
 * Copy for the container's accessible names.
 *
 * Props with English defaults rather than a translation hook: this file is a
 * PUBLISHED registry item, installed verbatim into a downstream consumer's
 * project where `@amroksaleh/features` need not exist. A caller that passes
 * nothing renders exactly as before.
 */
export interface ToastContainerProps {
  /** Accessible name for the live region holding the toasts. */
  regionLabel?: string;
  /** Accessible name for a toast's dismiss button. */
  dismissLabel?: string;
}

export function ToastContainer({
  regionLabel = 'Notifications',
  dismissLabel = 'Dismiss notification',
}: ToastContainerProps = {}) {
  const { toasts, removeToast } = useToast();

  return (
    <div
      role="region"
      aria-label={regionLabel}
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
            aria-label={dismissLabel}
            className="opacity-70 hover:opacity-100 transition-opacity focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 rounded-sm"
          >
            <IconX className="size-4" aria-hidden="true" />
          </button>
        </div>
      ))}
    </div>
  );
}
