'use client';

import { useToast } from '@/lib/toast-context';
import { IconCheck, IconAlertCircle, IconInfoCircle, IconX } from '@tabler/icons-react';
import { cn } from '@/lib/utils';

export function ToastContainer() {
  const { toasts, removeToast } = useToast();

  return (
    <div className="fixed bottom-0 right-0 z-[9999] flex flex-col gap-3 p-4 pointer-events-none">
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className={cn(
            'pointer-events-auto flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-medium shadow-lg animate-in slide-in-from-right-full duration-200',
            toast.type === 'success' && 'bg-green-500 text-white',
            toast.type === 'error' && 'bg-red-500 text-white',
            toast.type === 'info' && 'bg-blue-500 text-white'
          )}
        >
          <div className="flex items-center gap-3 flex-1">
            {toast.type === 'success' && <IconCheck size={20} />}
            {toast.type === 'error' && <IconAlertCircle size={20} />}
            {toast.type === 'info' && <IconInfoCircle size={20} />}
            <span>{toast.message}</span>
          </div>
          <button
            onClick={() => removeToast(toast.id)}
            className="opacity-70 hover:opacity-100 transition-opacity"
          >
            <IconX size={16} />
          </button>
        </div>
      ))}
    </div>
  );
}
