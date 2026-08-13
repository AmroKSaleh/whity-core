'use client';

/** The design system's Breadcrumb with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@amroksaleh/features/i18n';
import { Breadcrumb as BaseBreadcrumb } from '@amroksaleh/ui/breadcrumb';

export type { BreadcrumbProps, BreadcrumbItem } from '@amroksaleh/ui/breadcrumb';

export function Breadcrumb(props: React.ComponentProps<typeof BaseBreadcrumb>) {
  const t = useTranslation('common');
  return <BaseBreadcrumb navLabel={t('ui.breadcrumb.nav', 'Breadcrumb')} {...props} />;
}
