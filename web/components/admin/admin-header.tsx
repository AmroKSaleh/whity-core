import { ReactNode } from 'react';

interface AdminHeaderProps {
  title: string;
  description?: string;
  action?: ReactNode;
  breadcrumb?: ReactNode;
}

export function AdminHeader({ title, description, action, breadcrumb }: AdminHeaderProps) {
  return (
    <div className="mb-8 border-b border-border pb-6">
      {breadcrumb && (
        <div className="mb-2 text-sm text-muted-foreground">{breadcrumb}</div>
      )}
      <div className="flex items-center justify-between">
        <div className="flex-1">
          <h1 className="text-3xl font-bold text-foreground">
            {title}
          </h1>
          {description && (
            <p className="mt-2 text-sm text-muted-foreground">
              {description}
            </p>
          )}
        </div>
        {action && <div className="ml-6">{action}</div>}
      </div>
    </div>
  );
}
