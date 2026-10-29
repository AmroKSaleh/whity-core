'use client';

/** The design system's Sheet with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@whity-org/features/i18n';
import { SheetContent as BaseSheetContent } from '@whity-org/ui/sheet';

export {
  Sheet,
  SheetTrigger,
  SheetClose,
  SheetHeader,
  SheetFooter,
  SheetTitle,
  SheetDescription,
} from '@whity-org/ui/sheet';

export function SheetContent(props: React.ComponentProps<typeof BaseSheetContent>) {
  const t = useTranslation('common');
  return <BaseSheetContent closeLabel={t('ui.dialog.close', 'Close')} {...props} />;
}
