'use client';

/** The design system's Sheet with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@amroksaleh/features/i18n';
import { SheetContent as BaseSheetContent } from '@amroksaleh/ui/sheet';

export {
  Sheet,
  SheetTrigger,
  SheetClose,
  SheetHeader,
  SheetFooter,
  SheetTitle,
  SheetDescription,
} from '@amroksaleh/ui/sheet';

export function SheetContent(props: React.ComponentProps<typeof BaseSheetContent>) {
  const t = useTranslation('common');
  return <BaseSheetContent closeLabel={t('ui.dialog.close', 'Close')} {...props} />;
}
