'use client';

/**
 * Roles admin page — a thin client wrapper around the extracted, data-source-
 * agnostic `RolesScreen` (@amroksaleh/features/roles, Path B pilot). This file
 * owns only web's provider seams: the cookie-authenticated `webRolesAdapter`,
 * the capability check, the translator, and the toast notifier. The desktop
 * client mounts the same `RolesScreen` with its own adapter/can/t/onNotify.
 */

import { RolesScreen } from '@amroksaleh/features/roles';
import { webRolesAdapter } from '@/lib/roles-adapter';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useToast } from '@/lib/toast-context';

export default function Page() {
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const { addToast } = useToast();

  return (
    <RolesScreen
      adapter={webRolesAdapter}
      can={hasPermission}
      t={t}
      onNotify={addToast}
    />
  );
}
