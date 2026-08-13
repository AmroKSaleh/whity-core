'use client';

/** The design system's ScreenTooSmall with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@amroksaleh/features/i18n';
import { ScreenTooSmall as BaseScreenTooSmall } from '@amroksaleh/ui/screen-too-small';

export { useViewportAtLeast } from '@amroksaleh/ui/screen-too-small';
export type { ScreenTooSmallProps } from '@amroksaleh/ui/screen-too-small';

export function ScreenTooSmall(props: React.ComponentProps<typeof BaseScreenTooSmall>) {
  const t = useTranslation('common');

  return (
    <BaseScreenTooSmall
      title={t('ui.screenTooSmall.title', 'This screen is too small')}
      minWidthHint={(px) =>
        t('ui.screenTooSmall.minWidth', 'Needs a window at least {width}px wide.', { width: px })
      }
      {...props}
    />
  );
}
