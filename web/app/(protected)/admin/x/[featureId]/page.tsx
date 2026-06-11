'use client';

import { useParams } from 'next/navigation';
import type { PluginFeature } from '@/lib/plugin-features';
import { usePluginFeatures } from '@/lib/plugin-features-context';
import {
  resolvePluginScreen,
  type PluginScreenComponent,
} from '@/lib/plugin-ui-registry';
import { CrudScreen } from '@/components/plugin/crud-screen';
import { AdminHeader } from '@/components/admin/admin-header';
import { Skeleton } from '@/components/ui/skeleton';
import { IconPuzzle, IconShieldLock } from '@tabler/icons-react';

/**
 * Renders a registry override. Declared at module level and fed the component
 * via props so the screen reference (a stable module-level registration, not
 * a render-time creation) satisfies react-hooks/static-components.
 */
function OverrideScreen({
  screen: Screen,
  feature,
}: {
  screen: PluginScreenComponent;
  feature: PluginFeature;
}) {
  return <Screen feature={feature} />;
}

/**
 * Dynamic plugin feature host (WC-169): /admin/x/[featureId].
 *
 * Resolves the feature id from the URL against the permission-filtered
 * feature list and renders, in order of precedence:
 *   1. a registry override registered for the feature id,
 *   2. the generic schema-driven CRUD screen (crud features with a resource),
 *   3. a placeholder asking the app to register a custom screen.
 */
export default function PluginFeaturePage() {
  // Client pages read dynamic segments via useParams (Next 16 app router).
  // The single [featureId] segment is always a string, but the hook's honest
  // type allows string[] for catch-alls, so narrow defensively.
  const params = useParams<{ featureId: string | string[] }>();
  const featureId = Array.isArray(params.featureId)
    ? params.featureId[0]
    : params.featureId;

  const { features, isLoading } = usePluginFeatures();

  if (isLoading) {
    return (
      <div className="space-y-8">
        <div className="space-y-2 border-b border-border pb-6">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-4 w-96" />
        </div>
        <Skeleton className="h-64 w-full rounded-lg" />
      </div>
    );
  }

  const feature = features.find((candidate) => candidate.id === featureId);

  // The server already filtered the list by permission, so "not in the list"
  // covers both unknown ids and features the user may not use.
  if (feature === undefined) {
    return (
      <div className="space-y-8">
        <AdminHeader
          title="Feature unavailable"
          description="This plugin feature could not be resolved."
        />
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconShieldLock
            size={32}
            className="mx-auto mb-3 text-muted-foreground"
          />
          <h2 className="font-heading text-sm font-medium">
            Not available
          </h2>
          <p className="mt-1 text-xs text-muted-foreground">
            The feature &apos;{featureId}&apos; does not exist or you do not
            have permission to use it.
          </p>
        </div>
      </div>
    );
  }

  // A registered bespoke screen always wins — and is the only way to render
  // screen='custom' features.
  const override = resolvePluginScreen(feature.id);
  if (override !== undefined) {
    return <OverrideScreen screen={override} feature={feature} />;
  }

  if (feature.screen === 'crud' && feature.resource !== null) {
    return <CrudScreen feature={feature} />;
  }

  return (
    <div className="space-y-8">
      <AdminHeader
        title={feature.label}
        description={`Provided by the ${feature.plugin} plugin.`}
      />
      <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
        <IconPuzzle size={32} className="mx-auto mb-3 text-muted-foreground" />
        <h2 className="font-heading text-sm font-medium">
          No screen registered
        </h2>
        <p className="mt-1 text-xs text-muted-foreground">
          This feature provides its own screen — register a component for
          &apos;{feature.id}&apos; in the plugin UI registry.
        </p>
      </div>
    </div>
  );
}
