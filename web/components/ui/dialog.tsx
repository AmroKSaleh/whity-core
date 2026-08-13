'use client';

/**
 * The design system's Dialog, with this app's translations applied.
 *
 * WHY A WRAPPER RATHER THAN PROPS AT EVERY CALL SITE
 * --------------------------------------------------
 * `@amroksaleh/ui` takes its copy as props with English defaults, because it is
 * published standalone and must not depend on our i18n feature (see #758). That
 * leaves someone to supply the translations, and doing it at each call site
 * would mean writing `closeLabel={t('ui.dialog.close', 'Close')}` in thirty-two
 * files — thirty-two chances to forget, and thirty-two copies of one string.
 *
 * So the app declares each component's copy ONCE here and re-exports it. A
 * screen imports `@/components/ui/dialog` instead of `@amroksaleh/ui/dialog`
 * and needs no other change.
 *
 * Caller props are spread AFTER the defaults, so a screen that wants its own
 * wording still wins.
 *
 * NOT FOR PUBLISHED REGISTRY ITEMS. A file listed in registry.json is installed
 * verbatim into a downstream consumer's project, where `@/components/ui` does
 * not exist — those keep importing `@amroksaleh/ui/*` directly and take their
 * copy through props. `__tests__/registry-contract.test.ts` enforces this.
 */

import { useTranslation } from '@amroksaleh/features/i18n';
import {
  DialogContent as BaseDialogContent,
  DialogFooter as BaseDialogFooter,
} from '@amroksaleh/ui/dialog';

export {
  Dialog,
  DialogTrigger,
  DialogPortal,
  DialogClose,
  DialogOverlay,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@amroksaleh/ui/dialog';

export function DialogContent(props: React.ComponentProps<typeof BaseDialogContent>) {
  const t = useTranslation('common');
  return <BaseDialogContent closeLabel={t('ui.dialog.close', 'Close')} {...props} />;
}

export function DialogFooter(props: React.ComponentProps<typeof BaseDialogFooter>) {
  const t = useTranslation('common');
  return <BaseDialogFooter closeLabel={t('ui.dialog.close', 'Close')} {...props} />;
}
