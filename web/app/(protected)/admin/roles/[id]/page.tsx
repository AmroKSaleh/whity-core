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
import { RecordPageSkeleton } from '@amroksaleh/features/record';
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
const I18N_MISS = '__ROLE_RECORD_I18N_MISS__';

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const router = useRouter();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
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

  // `hasPermission` is FAIL-CLOSED while the capability fetch is in flight, so
  // mounting the screen early would render "You don't have permission to edit
  // roles" to an administrator who does — a sentence, unlike a button that shows
  // up a moment later, that is simply false while it is on screen. The
  // capabilities fetch is a single shared request the root layout already
  // started, so this waits on something already in flight rather than adding a
  // round trip.
  //
  // #882: the screen's OWN skeleton, not a hand-copied lookalike. This route
  // used to reproduce it — four `Skeleton` boxes in a four-column grid — and a
  // copy of a skeleton is a copy that drifts the first time the record page
  // grows a fifth stat, at which point the page visibly jumps at the moment the
  // capabilities land.
  if (capabilitiesLoading) {
    return <RecordPageSkeleton />;
  }

  return (
    <RoleRecordScreen
      adapter={webRolesAdapter}
      roleId={roleId}
      can={hasPermission}
      t={t}
      onNotify={addToast}
      onBack={handleBack}
    />
  );
}
