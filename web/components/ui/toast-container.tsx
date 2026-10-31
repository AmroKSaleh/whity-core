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
 * The status shows in the icon's colour only (the Toast card): the toast
 * itself is a floating layer, the popover surface with the soft float shadow
 * (register 4.4), so a row of toasts never turns into a row of coloured bars.
 */
const iconStyles: Record<ToastType, string> = {
  success: 'text-success-text',
  error: 'text-error-text',
  warning: 'text-warning-text',
  info: 'text-info-text',
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
 * project where `@whity-org/features` need not exist. A caller that passes
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
      className="fixed bottom-0 inset-e-0 z-[9999] flex w-[min(400px,100vw)] flex-col gap-2 p-4 pointer-events-none"
    >
      {toasts.map((toast) => (
        <div
          key={toast.id}
          role="status"
          aria-live={toastPoliteness[toast.type]}
          aria-atomic="true"
          className="pointer-events-auto flex items-start gap-2.5 rounded-control-lg border border-border bg-popover py-3 ps-3.5 pe-2.5 text-sm text-popover-foreground shadow-float animate-in duration-200 ltr:slide-in-from-right-full rtl:slide-in-from-left-full"
        >
          <span className={cn('mt-px shrink-0', iconStyles[toast.type])}>
            <ToastIcon type={toast.type} />
          </span>
          <span className="min-w-0 flex-1 font-medium leading-5">{toast.message}</span>
          {toast.action && (
            <button
              type="button"
              onClick={() => {
                toast.action?.onClick();
                removeToast(toast.id);
              }}
              className="shrink-0 rounded-sm text-sm font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
            >
              {toast.action.label}
            </button>
          )}
          <button
            type="button"
            onClick={() => removeToast(toast.id)}
            aria-label={dismissLabel}
            className="shrink-0 rounded-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <IconX className="size-4" aria-hidden="true" />
          </button>
        </div>
      ))}
    </div>
  );
}
