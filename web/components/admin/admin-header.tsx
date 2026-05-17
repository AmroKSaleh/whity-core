import { ReactNode } from 'react';

interface AdminHeaderProps {
  title: string;
  description?: string;
  action?: ReactNode;
}

export function AdminHeader({ title, description, action }: AdminHeaderProps) {
  return (
    <div className="mb-8 flex items-center justify-between border-b border-slate-200 pb-6 dark:border-slate-800">
      <div className="flex-1">
        <h1 className="text-3xl font-bold text-slate-900 dark:text-slate-50">
          {title}
        </h1>
        {description && (
          <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
            {description}
          </p>
        )}
      </div>
      {action && <div className="ml-6">{action}</div>}
    </div>
  );
}
