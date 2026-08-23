'use client';

/**
 * The role RECORD page — `/admin/roles/[id]` (#882).
 *
 * The FIRST record route in the web app. Every single-record surface here was
 * an overlay until now, so a record could not be linked to, bookmarked, or
 * returned to with the back button: "send me the link to that role" had no
 * answer. This route is that answer.
 *
 * It began as a hand-built prototype and is now a CONSUMER of the record-page
 * shell (@amroksaleh/features/record, #882) — the same shell `/admin/users/[id]`
 * mounts. The block-DESCRIBED version, which lets a plugin's records get the
 * same treatment without host edits, is #883.
 *
 * Thin, like every other page in this app: it owns only web's provider seams —
 * the dynamic segment, the cookie-authenticated `webRolesAdapter`, the
 * capability check, the composed translator, the toast notifier, and the router
 * — and hands them to the data-source-agnostic `RoleRecordScreen`
 * (@amroksaleh/features/roles). A desktop shell mounts the same component with
 * its own adapter and its own navigation.
 */

import { useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { RoleRecordScreen } from '@amroksaleh/features/roles';
import { webRolesAdapter } from '@/lib/roles-adapter';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useToast } from '@/lib/toast-context';

/**
 * Sentinel used to detect a "this domain has no translation for the key" miss.
 * `getTranslation` resolves as `value || fallback || key`, so passing this as
 * the fallback returns it verbatim when the `admin` domain lacks the key. It
 * carries no `{placeholder}` tokens, so interpolation leaves it byte-for-byte
 * intact and the equality check below stays reliable.
 */
const I18N_MISS = '__ROLE_RECORD_I18N_MISS__';

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const router = useRouter();
  const { addToast } = useToast();

  // Client pages read dynamic segments via useParams (Next 16 app router). The
  // single [id] segment is always a string, but the hook's honest type allows
  // string[] for catch-alls, so narrow defensively — same guard as
  // /admin/x/[featureId].
  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const roleId = Number(rawId);

  // Same composed translator the roles LIST page uses: the feature's own copy
  // lives in the `admin` domain, the shared UI chrome it renders in `common`.
  // One screen takes one translator, so resolve `admin` first and fall back to
  // `common` — restoring Arabic/RTL parity for the chrome instead of shipping an
  // English label onto an RTL admin page.
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

  const handleBack = useCallback(() => {
    // push, not back(): a record reached from a pasted link has no history entry
    // to go back TO, and `back()` there leaves the user wherever they came from
    // — which may be another site.
    router.push('/admin/roles');
  }, [router]);

  // A non-numeric segment never reaches a fetch. The route pattern admits it
  // (Next does not constrain dynamic segments), and `Number('abc')` is NaN,
  // which would become a request for `/api/v1/roles/NaN`.
  if (!Number.isInteger(roleId) || roleId <= 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {t('roles.record.error.title', 'This role could not be loaded')}
      </p>
    );
  }

  // No capability gate here any more (#910).
  //
  // This route used to hold the screen behind `useCapabilities().loading`,
  // because `hasPermission` is FAIL-CLOSED while that fetch is in flight and
  // mounting early would have rendered "You don't have permission to edit roles"
  // to an administrator who does. The screen no longer takes a `can` prop at
  // all: `GET /roles/{id}` carries a verdict per REGION, resolved server-side,
  // so the record's own response is the whole answer and there is nothing left
  // to wait on. The page now paints as soon as the record arrives rather than
  // after two round trips.
  return (
    <RoleRecordScreen
      adapter={webRolesAdapter}
      roleId={roleId}
      t={t}
      onNotify={addToast}
      onBack={handleBack}
    />
  );
}
