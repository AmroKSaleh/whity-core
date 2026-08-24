'use client';

/**
 * The route-template RECORD page — `/admin/document-route-templates/[id]` (#1027).
 *
 * Thin, like every other route here: the dynamic segment, the capability check
 * and the screen. `RouteFlowEditorScreen` owns the rest.
 */

import { useParams } from 'next/navigation';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@amroksaleh/features/i18n';
import { ROUTE_TEMPLATES_WRITE } from '@/lib/capabilities';
import { RouteFlowEditorScreen } from './flow-editor-screen';

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');

  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const templateId = Number(rawId);

  if (!Number.isInteger(templateId) || templateId <= 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {t('routeTemplates.record.error', 'This route template could not be loaded')}
      </p>
    );
  }

  // Fail-closed capabilities: mounting early would render a read-only canvas to
  // somebody who may in fact edit it, and they would conclude the feature is
  // broken rather than that the check had not finished.
  if (capabilitiesLoading) {
    return <p className="text-sm text-muted-foreground">{t('routeTemplates.loading', 'Loading…')}</p>;
  }

  return (
    <RouteFlowEditorScreen templateId={templateId} canWrite={hasPermission(ROUTE_TEMPLATES_WRITE)} />
  );
}
