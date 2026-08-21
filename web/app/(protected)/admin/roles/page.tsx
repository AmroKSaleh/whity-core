'use client';

/**
 * Roles admin page — a thin client wrapper around the extracted, data-source-
 * agnostic `RolesScreen` (@amroksaleh/features/roles, Path B pilot). This file
 * owns only web's provider seams: the cookie-authenticated `webRolesAdapter`,
 * the capability check, the translator, and the toast notifier. The desktop
 * client mounts the same `RolesScreen` with its own adapter/can/t/onNotify.
 */

import { useCallback } from 'react';
import { useRouter } from 'next/navigation';
import { RolesScreen } from '@amroksaleh/features/roles';
import type { Role } from '@amroksaleh/features/roles';
import { webRolesAdapter } from '@/lib/roles-adapter';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useToast } from '@/lib/toast-context';

/**
 * Sentinel used to detect a "this domain has no translation for the key" miss.
 * `getTranslation` resolves as `value || fallback || key`, so passing this as
 * the fallback returns it verbatim when the `admin` domain lacks the key. It
 * carries no `{placeholder}` tokens, so interpolation leaves it byte-for-byte
 * intact and the equality check below stays reliable.
 */
const I18N_MISS = '__ROLES_I18N_MISS__';

export default function Page() {
  const { hasPermission } = useCapabilities();
  const { addToast } = useToast();
  const router = useRouter();

  // The Roles feature's own copy lives in the `admin` domain, but the shared UI
  // chrome it renders (DataTable/Dialog `ui.*` keys) lives in `common` — exactly
  // where the old `@/components/ui/*` wrappers sourced their Arabic strings.
  // `RolesScreen` takes a SINGLE translator, so compose one that resolves `admin`
  // first and falls back to `common` for the keys `admin` lacks — restoring
  // Arabic/RTL parity for the chrome without ever shipping an English label on an
  // RTL admin page.
  //
  // @i18n-dynamic-ignore: this composite forwards a runtime key to the admin/common domains; the literal keys are declared in the @i18n-keys blocks of the @amroksaleh/features/roles component files that call t().
  const tAdmin = useTranslation('admin');
  const tCommon = useTranslation('common');
  const t = useCallback(
    (key: string, fallback?: string, vars?: Record<string, string | number>): string => {
      const fromAdmin = tAdmin(key, I18N_MISS, vars);
      return fromAdmin === I18N_MISS ? tCommon(key, fallback, vars) : fromAdmin;
    },
    [tAdmin, tCommon]
  );

  // #882: web routes Edit (and the row's own name) to the RECORD PAGE. Supplying
  // this prop is the entire opt-in — a host that omits it keeps the edit modal,
  // which is still in the package and still wired, so this is revertible by
  // deleting these three lines.
  const openRecord = useCallback(
    (role: Role) => {
      router.push(`/admin/roles/${role.id}`);
    },
    [router]
  );

  return (
    <RolesScreen
      adapter={webRolesAdapter}
      can={hasPermission}
      t={t}
      onNotify={addToast}
      onOpenRecord={openRecord}
    />
  );
}
