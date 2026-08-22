'use client';

/**
 * Roles admin page — a thin client wrapper around the extracted, data-source-
 * agnostic `RolesScreen` (@amroksaleh/features/roles, Path B pilot). This file
 * owns only web's provider seams: the cookie-authenticated `webRolesAdapter`,
 * the capability check, the translator, and the toast notifier. The desktop
 * client mounts the same `RolesScreen` with its own adapter/can/t/onNotify.
 */

import { useCallback, useMemo } from 'react';
import { useRouter } from 'next/navigation';
import { RolesScreen } from '@amroksaleh/features/roles';
import type { Role, RoleScopeSeam, RoleTenantOption } from '@amroksaleh/features/roles';
import { webRolesAdapter } from '@/lib/roles-adapter';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useToast } from '@/lib/toast-context';

/**
 * The reserved tenant whose holders administer the platform. Only they may name
 * a target tenant, or ask for a global role, on `POST /api/roles` — the server
 * refuses anyone else with a 403, so the picker is hidden rather than offered
 * and then rejected. Same constant and the same reasoning as the memberships
 * modal, which is where this parameter shape comes from (#797 §2).
 */
const SYSTEM_TENANT_ID = 0;

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
  const { user, apiClient } = useAuth();
  const isSystemAdmin = user?.tenant_id === SYSTEM_TENANT_ID;

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

  // #888: the create modal may offer a target tenant only to a system-tenant
  // operator — everyone else has exactly one answer and the server would 403 the
  // field anyway. Supplying (or not supplying) this prop is the entire opt-in;
  // omitted, the create request carries no ownership fields and behaves exactly
  // as it did.
  //
  // The RAW client, not the typed one: `per_page` is a real query parameter the
  // tenants list honours but does not declare in the published schema, so the
  // generated types reject it. Same call, and the same reason, as the
  // memberships modal.
  const scope = useMemo<RoleScopeSeam | undefined>(() => {
    if (!isSystemAdmin) return undefined;
    return {
      loadTenants: async (): Promise<RoleTenantOption[]> => {
        const response = await apiClient('/api/v1/tenants?per_page=100');
        if (!response.ok) {
          throw new Error(t('roles.create.scope.error', 'Failed to load tenants'));
        }
        const body: { data: RoleTenantOption[] } = await response.json();
        return body.data.map((tenant) => ({ id: tenant.id, name: tenant.name }));
      },
    };
  }, [isSystemAdmin, apiClient, t]);

  return (
    <RolesScreen
      adapter={webRolesAdapter}
      can={hasPermission}
      t={t}
      onNotify={addToast}
      onOpenRecord={openRecord}
      scope={scope}
    />
  );
}
