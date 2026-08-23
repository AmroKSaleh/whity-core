'use client';

/**
 * The plugin RECORD page — `/admin/x/[featureId]/[recordId]` (#948).
 *
 * THE ADDRESS THAT WAS MISSING. Everything needed to render a plugin record
 * page shipped with SDK 1.33/1.34 — `dataRecord` fetches one resource,
 * `recordFields` renders it, `accessGate` decides whether it may be edited, and
 * `dataRecord.source` accepts `{record}`, the reserved binding the SDK
 * documents as "the record a record-page ROUTE is about". There was no such
 * route. `/admin/x/[featureId]` read the feature id and nothing else, nothing in
 * application code ever passed `record=`, and so `{record}` could not bind in a
 * real session: for every resource a plugin owns, "send me the link to that
 * record" had no answer. This file is that answer, and it is the plugin-facing
 * half of what `/admin/roles/[id]` (#882) did for first-party records.
 *
 * WHAT RENDERS HERE depends on how the feature describes itself, exactly as on
 * the feature page above it:
 *
 *   - `screen: 'blocks'` — the feature's own tree, rendered with the route's
 *     segment seeded as `{record}`. Nothing else changes: the same declaration
 *     that renders a master-detail pane on the feature page renders a record
 *     page here, because the only difference between the two has always been
 *     how the record gets named — a click into the master-detail context, or a
 *     URL. That is why no new block type and no new descriptor key was needed.
 *   - `screen: 'crud'` — the host-derived record page (`CrudRecordScreen`),
 *     built from the plugin's published OpenAPI schema. Every crud feature gets
 *     one without declaring anything.
 *
 * THE SHELL STAYS OURS TO MOUNT. `@amroksaleh/features/record` is not reachable
 * from a plugin and is not meant to be: a plugin ships no JavaScript. The crud
 * record page mounts the shell on the plugin's behalf; a blocks tree owns its
 * own content, states and gating, so this route gives it the page chrome and
 * gets out of the way.
 *
 * Thin, like every other page in this app: it owns web's provider seams — the
 * dynamic segments, the permission-filtered feature list, the translator and
 * the router — and hands them to components that know nothing about routing.
 */

import { useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { usePluginFeatures } from '@/lib/plugin-features-context';
import { resolvePluginScreen } from '@/lib/plugin-ui-registry';
import { featureHref } from '@/lib/plugin-record-route';
import { CrudRecordScreen } from '@/components/plugin/crud-record-screen';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { IconArrowLeft, IconPuzzle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';

export default function PluginRecordPage() {
  // Client pages read dynamic segments via useParams (Next 16 app router). A
  // single segment is always a string, but the hook's honest type allows
  // string[] for catch-alls, so narrow defensively — the same guard
  // /admin/x/[featureId] and /admin/roles/[id] carry.
  const params = useParams<{ featureId: string | string[]; recordId: string | string[] }>();
  const featureId = Array.isArray(params.featureId) ? params.featureId[0] : params.featureId;
  const rawRecordId = Array.isArray(params.recordId) ? params.recordId[0] : params.recordId;
  // Decoded, because the segment is percent-encoded on the way in (a plugin's
  // key may be a slug, a uuid, or anything else its resource uses) and every
  // consumer wants the value, not its transport form.
  const recordId = decodeURIComponent(rawRecordId ?? '');

  const router = useRouter();
  // The `plugin` domain, not `admin`: this is the host every plugin feature
  // renders inside, and it shares its chrome — `feature.providedBy`, the
  // unavailable-feature card — with the feature page and the crud screens.
  const t = useTranslation('plugin');
  const { features, isLoading } = usePluginFeatures();

  const goToFeature = useCallback(() => {
    // push, not back(): a record reached from a pasted link has no history
    // entry to go back TO, and `back()` there leaves the user wherever they
    // came from — which may be another site. Same reasoning as the role and
    // user record routes.
    router.push(featureHref(featureId ?? ''));
  }, [router, featureId]);

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
  // covers both unknown ids and features the user may not use — the same
  // sentence the feature page renders, for the same reason.
  if (feature === undefined) {
    return (
      <div className="space-y-8">
        <AdminHeader
          title={t('feature.unavailable.title', 'Feature unavailable')}
          description={t(
            'feature.unavailable.description',
            'This plugin feature could not be resolved.'
          )}
        />
        <AccessDenied
          title={t('feature.notAvailable.title', 'Not available')}
          description={t(
            'feature.notAvailable.description',
            "The feature '{id}' does not exist or you do not have permission to use it.",
            { id: featureId }
          )}
        />
      </div>
    );
  }

  // An empty segment cannot name a record. Next admits it (`/admin/x/f//`
  // normalises oddly, and a hand-typed URL can produce it), and passing it on
  // would leave `{record}` bound to the empty string — which reads to every
  // block downstream as "a record was named" and produces a request for
  // `/api/v1/things/`, the COLLECTION.
  const hasRecordId = recordId !== '';

  const noRecordPage = (title: string, description: string) => (
    <div className="space-y-8">
      <AdminHeader title={feature.label} description={description} />
      <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
        <IconPuzzle size={32} className="mx-auto mb-3 text-muted-foreground" />
        <h2 className="font-heading text-sm font-medium">{title}</h2>
        <p className="mt-1 text-xs text-muted-foreground">{description}</p>
        <Button variant="outline" size="sm" className="mt-4 gap-2" onClick={goToFeature}>
          <IconArrowLeft size={16} className="rtl:rotate-180" />
          {t('crud.record.back', 'Back to {resource}', { resource: feature.label })}
        </Button>
      </div>
    </div>
  );

  if (!hasRecordId) {
    return noRecordPage(
      t('record.noRecord.title', 'No record named'),
      t('record.noRecord.description', 'This address is missing the record it is about.')
    );
  }

  // A registered bespoke screen owns the whole feature, including whatever it
  // considers a record — the host cannot render half of somebody else's screen.
  const override = resolvePluginScreen(feature.id);
  if (override !== undefined) {
    return noRecordPage(
      t('record.customScreen.title', 'This feature provides its own screen'),
      t(
        'record.customScreen.description',
        "Records of '{id}' are handled by the screen registered for it, not by this page.",
        { id: feature.id }
      )
    );
  }

  if (feature.screen === 'crud' && feature.resource !== null) {
    return (
      <CrudRecordScreen feature={feature} recordId={recordId} onBack={goToFeature} />
    );
  }

  if (feature.screen === 'blocks') {
    return (
      <div className="space-y-8">
        <div>
          <Button variant="ghost" size="sm" className="mb-2 gap-2" onClick={goToFeature}>
            {/* Mirrored by direction: the arrow points AWAY from the content in
                LTR and RTL alike, which a fixed left-arrow would not. */}
            <IconArrowLeft size={16} className="rtl:rotate-180" />
            {t('crud.record.back', 'Back to {resource}', { resource: feature.label })}
          </Button>
          <AdminHeader
            title={feature.label}
            description={t('feature.providedBy', 'Provided by the {plugin} plugin.', {
              plugin: feature.plugin,
            })}
          />
        </div>
        {/* THE BINDING. `record` is seeded into the master-detail context under
            the SDK's reserved `{record}` name, so a `dataRecord` declaring
            `source: '/api/x/things/{record}'` resolves it — and every sibling
            block reading that record's fields follows, with no new vocabulary. */}
        <BlockRenderer blocks={feature.blocks ?? []} record={recordId} />
      </div>
    );
  }

  return noRecordPage(
    t('record.noRecordPage.title', 'No record page'),
    t(
      'record.noRecordPage.description',
      "The '{id}' feature does not describe individual records, so there is nothing at this address.",
      { id: feature.id }
    )
  );
}
