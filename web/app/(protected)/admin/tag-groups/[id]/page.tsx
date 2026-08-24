'use client';

/**
 * The tag-group RECORD page — `/admin/tag-groups/[id]` (#882, #884).
 *
 * Thin, like every other route here: the dynamic segment, the capability check,
 * the toast notifier and the router, handed to `TagGroupRecordScreen`.
 */

import { useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@amroksaleh/features/i18n';
import { RecordPageSkeleton } from '@amroksaleh/features/record';
import { TAGS_MANAGE } from '@/lib/capabilities';
import { TagGroupRecordScreen } from '../record-screen';

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const router = useRouter();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const groupId = Number(rawId);

  const handleBack = useCallback(() => {
    // push, not back(): a record reached from a pasted link has no history entry
    // to go back TO.
    router.push('/admin/tag-groups');
  }, [router]);

  if (!Number.isInteger(groupId) || groupId <= 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {t('tagGroups.record.error.title', 'This tag group could not be loaded')}
      </p>
    );
  }

  // Fail-closed capabilities: mounting early would render "you don't have
  // permission to manage tags" to somebody who does.
  if (capabilitiesLoading) {
    return <RecordPageSkeleton stats={2} />;
  }

  return (
    <TagGroupRecordScreen
      groupId={groupId}
      canManage={hasPermission(TAGS_MANAGE)}
      onNotify={addToast}
      onBack={handleBack}
    />
  );
}
