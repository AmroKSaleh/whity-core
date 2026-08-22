'use client';

/**
 * The user RECORD page — `/admin/users/[id]` (#882).
 *
 * The SECOND record route in the web app, and the one that made the record-page
 * shell general: a shell extracted against a single screen is a shell shaped like
 * that screen, so `@amroksaleh/features/record` was pulled out of the roles
 * prototype and immediately proven here.
 *
 * Thin, like every other page in this app: it owns only web's provider seams —
 * the dynamic segment, the cookie-authenticated `webUsersAdapter`, the capability
 * check, the composed translator, the toast notifier, and the router — and hands
 * them to the data-source-agnostic `UserRecordScreen`
 * (@amroksaleh/features/users). A desktop shell mounts the same component with
 * its own adapter and its own navigation.
 *
 * DEEP-LINKABILITY is the point of a record page, and it is a property of THIS
 * file: the id comes from the route rather than from a click, so a pasted URL, a
 * refresh and the back button all work. The users list still opens its create,
 * delete and memberships MODALS; this route is additive.
 */

import { useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { UserRecordScreen } from '@amroksaleh/features/users';
import { RecordPageSkeleton } from '@amroksaleh/features/record';
import { webUsersAdapter } from '@/lib/users-adapter';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useToast } from '@/lib/toast-context';

/**
 * Sentinel used to detect a "this domain has no translation for the key" miss.
 * `getTranslation` resolves as `value || fallback || key`, so passing this as the
 * fallback returns it verbatim when the `admin` domain lacks the key. It carries
 * no `{placeholder}` tokens, so interpolation leaves it byte-for-byte intact and
 * the equality check below stays reliable.
 */
const I18N_MISS = '__USER_RECORD_I18N_MISS__';

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const router = useRouter();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const { addToast } = useToast();

  // Client pages read dynamic segments via useParams (Next 16 app router). The
  // single [id] segment is always a string, but the hook's honest type allows
  // string[] for catch-alls, so narrow defensively — same guard as
  // /admin/roles/[id].
  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const userId = Number(rawId);

  // The feature's own copy lives in the `admin` domain, the shared UI chrome in
  // `common`. One screen takes one translator, so resolve `admin` first and fall
  // back to `common` — restoring Arabic/RTL parity for the chrome instead of
  // shipping an English label onto an RTL admin page.
  //
  // @i18n-dynamic-ignore: this composite forwards a runtime key to the admin/common domains; the literal keys are declared in the @i18n-keys blocks of the @amroksaleh/features/users component files that call t().
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
    router.push('/admin/users');
  }, [router]);

  // A non-numeric segment never reaches a fetch. The route pattern admits it
  // (Next does not constrain dynamic segments), and `Number('abc')` is NaN,
  // which would become a request for `/api/v1/users/NaN`.
  if (!Number.isInteger(userId) || userId <= 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {t('users.record.error.title', 'This person could not be loaded')}
      </p>
    );
  }

  // `hasPermission` is FAIL-CLOSED while the capability fetch is in flight, so
  // mounting the screen early would render "You don't have permission to edit
  // users" to an administrator who does — a sentence, unlike a button that shows
  // up a moment later, that is simply false while it is on screen. The
  // capabilities fetch is a single shared request the root layout already
  // started, so this waits on something already in flight rather than adding a
  // round trip; the skeleton is the SCREEN'S OWN, so nothing jumps when the real
  // one takes over.
  if (capabilitiesLoading) {
    return <RecordPageSkeleton />;
  }

  return (
    <UserRecordScreen
      adapter={webUsersAdapter}
      userId={userId}
      can={hasPermission}
      t={t}
      onNotify={addToast}
      onBack={handleBack}
    />
  );
}
