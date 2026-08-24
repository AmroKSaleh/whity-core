'use client';

/**
 * The tenant RECORD page — `/admin/tenants/[id]` (#882, #884).
 *
 * Thin, like every other route in this app: it owns only web's provider seams —
 * the dynamic segment, the capability check, the acting tenant, the toast
 * notifier and the router — and hands them to `TenantRecordScreen`.
 *
 * DEEP-LINKABILITY is a property of THIS file, and the reason the page exists at
 * all: the id comes from the route rather than from a click, so a pasted URL, a
 * refresh and the back button all work. `EditTenantModal` is gone — this
 * supersedes it rather than sitting beside it (see the screen's docblock).
 */

import { useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@amroksaleh/features/i18n';
import { RecordPageSkeleton } from '@amroksaleh/features/record';
import { TENANTS_WRITE } from '@/lib/capabilities';
import { TenantRecordScreen } from '../record-screen';

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const router = useRouter();
  const { user } = useAuth();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  // Client pages read dynamic segments via useParams (Next 16 app router). The
  // single [id] segment is always a string, but the hook's honest type allows
  // string[] for catch-alls, so narrow defensively — same guard as
  // /admin/users/[id].
  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const tenantId = Number(rawId);

  const handleBack = useCallback(() => {
    // push, not back(): a record reached from a pasted link has no history entry
    // to go back TO, and back() there leaves the operator wherever they came
    // from — which may be another site.
    router.push('/admin/tenants');
  }, [router]);

  // A non-numeric segment never reaches a fetch. The route pattern admits it
  // (Next does not constrain dynamic segments), and Number('abc') is NaN, which
  // would become a search for a workspace whose id is NaN.
  //
  // Tenant 0 is the SYSTEM tenant and is deliberately excluded: the list never
  // shows it, and it is not a workspace anyone administers through this page.
  if (!Number.isInteger(tenantId) || tenantId <= 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {t('tenants.record.error.title', 'This workspace could not be loaded')}
      </p>
    );
  }

  // `hasPermission` is FAIL-CLOSED while the capability fetch is in flight, so
  // mounting the screen early would render "You don't have permission to edit
  // workspaces" to an operator who does — a sentence that, unlike a button
  // appearing a moment later, is simply false while it is on screen.
  if (capabilitiesLoading) {
    return <RecordPageSkeleton stats={3} />;
  }

  return (
    <TenantRecordScreen
      tenantId={tenantId}
      canWrite={hasPermission(TENANTS_WRITE)}
      callerTenantId={user?.tenant_id ?? null}
      onNotify={addToast}
      onBack={handleBack}
    />
  );
}
