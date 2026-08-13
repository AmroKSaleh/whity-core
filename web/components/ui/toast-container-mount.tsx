'use client';

/**
 * Mounts the ToastContainer with this app's translations.
 *
 * The container itself is a PUBLISHED registry item, so it cannot call
 * useTranslation — a downstream consumer installs that file verbatim into a
 * project where `@amroksaleh/features` need not exist. It takes its accessible
 * names as props instead, and this thin client component supplies them.
 *
 * It exists at all because `app/layout.tsx` mounts the container, and reading
 * a translation there means being a client component; wrapping just this one
 * mount keeps the layout as it is.
 */

import { useTranslation } from '@amroksaleh/features/i18n';
import { ToastContainer } from './toast-container';

export function ToastContainerMount() {
  const t = useTranslation('common');

  return (
    <ToastContainer
      regionLabel={t('ui.toast.region', 'Notifications')}
      dismissLabel={t('ui.toast.dismiss', 'Dismiss notification')}
    />
  );
}
