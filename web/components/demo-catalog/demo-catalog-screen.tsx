'use client';

import { useState } from 'react';
import type { PluginFeature } from '@/lib/plugin-features';
import { demoCatalogAdapter } from '@/lib/demo-catalog-adapter';
import { AdminHeader } from '@/components/admin/admin-header';
import { DemoCatalogList, DemoCatalogDetail } from '@amroksaleh/features/demo-catalog';

/**
 * web/'s bespoke screen for the DemoCatalog plugin's `screen: 'custom'`
 * feature (multi-client feature-extraction pilot) — registered in
 * `lib/plugin-screens.tsx` under the `demo-catalog` feature id.
 *
 * This file is the ONLY web-specific piece: it owns local view state
 * (list vs. detail-for-id vs. detail-for-new) and wires web's
 * `demoCatalogAdapter` into the extracted, data-source-agnostic
 * `DemoCatalogList`/`DemoCatalogDetail` components
 * (@amroksaleh/features/demo-catalog). Those components themselves have zero
 * Next.js dependency and zero opinion about where the data comes from — the
 * exact same components render, unmodified, in the Vite SPA harness
 * (packages/spa-harness) against an in-memory adapter instead.
 *
 * View state is kept local rather than route-based (no `/admin/x/demo-catalog/[id]`
 * sub-route) since the plugin feature host only ever mounts this one
 * component for the `demo-catalog` feature id — a real product extracting
 * this pattern onto its own routes would instead drive `view` from the URL.
 */

type View = { kind: 'list' } | { kind: 'detail'; itemId: number | null };

export function DemoCatalogScreen({ feature }: { feature: PluginFeature }) {
  const [view, setView] = useState<View>({ kind: 'list' });

  return (
    <div className="space-y-8">
      <AdminHeader
        title={feature.label}
        description={`Provided by the ${feature.plugin} plugin.`}
      />

      {view.kind === 'list' ? (
        <DemoCatalogList
          adapter={demoCatalogAdapter}
          onSelect={(itemId) => setView({ kind: 'detail', itemId })}
          onCreate={() => setView({ kind: 'detail', itemId: null })}
        />
      ) : (
        <DemoCatalogDetail
          adapter={demoCatalogAdapter}
          itemId={view.itemId}
          onCancel={() => setView({ kind: 'list' })}
          onSaved={() => setView({ kind: 'list' })}
        />
      )}
    </div>
  );
}
