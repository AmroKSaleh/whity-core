'use client';

/**
 * The language RECORD page — `/admin/languages/[id]` (#882, #884).
 *
 * Thin, like every other route here: it owns the dynamic segment, the capability
 * check, the acting tenant, the toast notifier and the router, and hands them to
 * `LanguageRecordScreen`.
 *
 * The page-level ACCESS GATES are deliberately NOT repeated here as refusals.
 * The list page renders `AccessDenied` for a caller who lacks `languages:manage`
 * or is outside the system tenant, because a list of platform-wide rows is
 * nothing but writes. A record is different: reading one is harmless, and the
 * record page's own read-only state says which rule holds while still showing
 * the language. The server refuses every write either way.
 */

import { useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@amroksaleh/features/i18n';
import { RecordPageSkeleton } from '@amroksaleh/features/record';
import { LANGUAGES_MANAGE } from '@/lib/capabilities';
import { LanguageRecordScreen } from '../record-screen';

/** Languages are a platform catalogue; only tenant 0 may write one. */
const SYSTEM_TENANT_ID = 0;

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const router = useRouter();
  const { user } = useAuth();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const languageId = Number(rawId);

  const handleBack = useCallback(() => {
    // push, not back(): a record reached from a pasted link has no history entry
    // to go back TO.
    router.push('/admin/languages');
  }, [router]);

  if (!Number.isInteger(languageId) || languageId <= 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {t('languages.record.error.title', 'This language could not be loaded')}
      </p>
    );
  }

  // Fail-closed capabilities: mounting early would tell an operator who holds
  // `languages:manage` that they do not, in a sentence that is simply false
  // while it is on screen.
  if (capabilitiesLoading) {
    return <RecordPageSkeleton stats={3} />;
  }

  return (
    <LanguageRecordScreen
      languageId={languageId}
      canManage={hasPermission(LANGUAGES_MANAGE)}
      isSystemTenant={user?.tenant_id === SYSTEM_TENANT_ID}
      onNotify={addToast}
      onBack={handleBack}
    />
  );
}
