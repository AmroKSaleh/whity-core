'use client';

import { useMemo } from 'react';
import type { PluginFeature } from '@/lib/plugin-features';
import { CrudScreen } from '@/components/plugin/crud-screen';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TAGS_READ, TAGS_MANAGE } from '@/lib/capabilities';

/**
 * Tags admin (WC-621). Reuses the generic schema-driven {@see CrudScreen} over
 * the core `/api/v1/tags` resource. Write controls are gated on `tags:manage`;
 * the list fails closed (403 → access-denied) without `tags:read`.
 *
 * NOTE: `group_id` currently renders as a numeric input because the schema-
 * driven renderer has no foreign-key affordance yet. Turning FK columns into a
 * reference dropdown is a generic CrudScreen enhancement (an `x-whity-reference`
 * hint, analogous to `x-whity-localized-text`) that would benefit every
 * resource — tracked as a follow-up, not hand-rolled here.
 */
export default function TagsPage() {
  const { hasPermission } = useCapabilities();
  const canManage = hasPermission(TAGS_MANAGE);

  const feature = useMemo<PluginFeature>(
    () => ({
      id: 'tags',
      plugin: 'Core',
      label: 'Tags',
      icon: 'tag',
      group: 'admin',
      order: 9,
      screen: 'crud',
      resource: { basePath: '/api/v1/tags', titleField: 'name' },
      action: null,
      embed: null,
      requiredPermission: TAGS_READ,
      capabilities: { canCreate: canManage, canEdit: canManage, canDelete: canManage },
    }),
    [canManage]
  );

  return <CrudScreen feature={feature} />;
}
