'use client';

import { useMemo } from 'react';
import type { PluginFeature } from '@/lib/plugin-features';
import { CrudScreen } from '@/components/plugin/crud-screen';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TAGS_READ, TAGS_MANAGE } from '@/lib/capabilities';

/**
 * Tag groups admin (WC-621). A native page that reuses the generic
 * schema-driven {@see CrudScreen} by describing the core `/api/v1/tag-groups`
 * resource as a synthetic {@link PluginFeature}. The screen derives its table,
 * create/edit forms, and — because the runtime OpenAPI marks `display_name`
 * with `x-whity-localized-text` — a bilingual {ar,en} editor, all from the
 * live spec. Write controls are gated on `tags:manage`; the list fails closed
 * (403 → access-denied) when the caller lacks `tags:read`.
 */
export default function TagGroupsPage() {
  const { hasPermission } = useCapabilities();
  const canManage = hasPermission(TAGS_MANAGE);

  const feature = useMemo<PluginFeature>(
    () => ({
      id: 'tag-groups',
      plugin: 'Core',
      label: 'Tag Groups',
      icon: 'tags',
      group: 'admin',
      order: 8,
      screen: 'crud',
      resource: { basePath: '/api/v1/tag-groups', titleField: 'key' },
      action: null,
      embed: null,
      requiredPermission: TAGS_READ,
      capabilities: { canCreate: canManage, canEdit: canManage, canDelete: canManage },
    }),
    [canManage]
  );

  return <CrudScreen feature={feature} />;
}
