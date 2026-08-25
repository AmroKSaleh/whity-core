'use client';

/**
 * The tag-group RECORD page (#882/#884) — what `/admin/tag-groups/[id]` renders.
 *
 * WHY THIS ONE. A tag group is a CONTAINER: the only interesting question about
 * one is what is in it, and the edit dialog could not answer it. Tags and their
 * groups live on two separate admin screens joined by an id, so "which tags are
 * in Priority?" meant opening the tags list and filtering by a column — while
 * `GET /api/v1/tags?group_id=N` has always answered it directly and nothing
 * asked. The record page's side column is the first place that question is put
 * next to the group it is about.
 *
 * THE KEY IS EDITABLE HERE, and that is not a detail. `TagGroupsApiHandler` has
 * accepted `{key, display_name}` all along; the dialog offered the key only
 * while CREATING, so a group whose key was typed wrong was renamed by deleting
 * it — which, per the handler's own destructive-delete guard, takes every tag in
 * it and every `entity_tags` row pointing at those tags with it. Editing the key
 * is a rename; deleting the group is a data loss. Those were the same button.
 */

import { useCallback, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useTranslation } from '@amroksaleh/features/i18n';
import {
  RecordCollectionPanel,
  RecordList,
  RecordListItem,
  RecordPageError,
  RecordPageShell,
  RecordPageSkeleton,
  resolveAccess,
  useRecordResource,
  type RecordFactsFn,
  type RecordResource,
} from '@amroksaleh/features/record';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@/components/ui/input';
import { BilingualInput, type BilingualValue } from '@amroksaleh/ui/bilingual-input';
import { IconTags } from '@tabler/icons-react';

/**
 * The full group record, from the OpenAPI schema (WC-168) — `created_at` and
 * all. The list page keeps its own narrower row shape: it renders two columns
 * and has no use for the rest.
 */
type TagGroup = components['schemas']['TagGroup'];
type Tag = components['schemas']['Tag'];

/**
 * The key rule, kept identical to `TagGroupsApiHandler::KEY_PATTERN`. Duplicated
 * from the create dialog rather than shared with it, because the dialog is about
 * to stop being the only writer of this field and a client rule that drifts from
 * the server's produces a 422 the form cannot explain.
 */
const KEY_PATTERN = /^[A-Za-z0-9_.:-]{1,64}$/;

/** The editable half of the record, stamped with the group it belongs to. */
interface TagGroupForm {
  id: number;
  key: string;
  displayName: BilingualValue;
}

/** The em dash a stated value shows when the record carries none. */
const EMPTY_VALUE = '—';

/** The group's human label, in whichever language the record actually carries. */
function groupLabel(group: TagGroup): string {
  return group.display_name?.en || group.display_name?.ar || group.key;
}

/**
 * What the SERVER says this group IS. No permission flag, and nothing derived
 * from one (#895).
 */
interface TagGroupRecordFields {
  key: string;
  label: string;
  createdAt: string | null;
  /** How many tags the group holds; null while that request is in flight. */
  tagCount: number | null;
}

/** A pure projection of the record and the dictionary, at module scope (#895). */
const tagGroupFacts: RecordFactsFn<TagGroupRecordFields> = (group, t, dates) => ({
  title: group.label,
  // The key, verbatim: it is the token plugins and imports address the group by,
  // not prose, so it never translates.
  subtitle: group.key,
  stats: [
    {
      key: 'tags',
      label: t('tagGroups.record.stat.tags', 'Tags'),
      value: group.tagCount,
    },
    // #1068: the stat GOES when this tenant hides dates, rather than
    // surviving as "Created —".
    ...(dates.hidden
      ? []
      : [
          {
            key: 'created',
            label: t('tagGroups.record.stat.created', 'Created'),
            value: dates.date(group.createdAt),
          },
        ]),
  ],
});

export interface TagGroupRecordScreenProps {
  groupId: number;
  /** Whether the caller holds `tags:manage`. */
  canManage: boolean;
  onNotify: (message: string, tone: 'success' | 'error' | 'info' | 'warning') => void;
  onBack: () => void;
}

export function TagGroupRecordScreen({
  groupId,
  canManage,
  onNotify,
  onBack,
}: TagGroupRecordScreenProps) {
  const t = useTranslation('admin');

  // A real item GET, unlike the tenant and language records: `GET
  // /api/v1/tag-groups/{id}` exists and answers 404 for an id this tenant cannot
  // see, so the record is read directly and "not found" is the SERVER's answer
  // rather than one inferred from a list.
  const loaded = useRecordResource<TagGroup | 'not-found'>(
    async () => {
      const { data, response } = await api.GET('/api/v1/tag-groups/{id}', {
        params: { path: { id: groupId } },
      });
      if (response.status === 404) return 'not-found';
      if (data === undefined) {
        throw new Error(t('tagGroups.record.error.load', 'Failed to load this tag group'));
      }
      return data.data;
    },
    [groupId],
    t('tagGroups.record.error.load', 'Failed to load this tag group')
  );

  // Reading tags is `tags:read`, which is a WEAKER grant than the `tags:manage`
  // this page's form needs — so this panel is normally present even for a caller
  // who sees the record read-only, and absent for the narrower case of someone
  // who can manage groups but was never granted tag reads.
  const tags = useRecordResource<Tag[]>(
    async () => {
      const { data, response } = await api.GET('/api/v1/tags', {
        params: { query: { group_id: groupId } },
      });
      if (response.status === 403) return 'forbidden';
      if (data === undefined) {
        throw new Error(t('tagGroups.record.tags.error', "Failed to load this group's tags"));
      }
      return data.data;
    },
    [groupId],
    t('tagGroups.record.tags.error', "Failed to load this group's tags")
  );

  /** The record as last SAVED, so a successful write costs no refetch. */
  const [saved, setSaved] = useState<TagGroup | null>(null);
  /** What the operator has TYPED, or null while the form is untouched. */
  const [draft, setDraft] = useState<TagGroupForm | null>(null);
  const [keyError, setKeyError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const record = loaded.status === 'ready' && loaded.value !== 'not-found' ? loaded.value : null;
  // Both pieces of state carry the id they belong to and are ignored when it
  // does not match — see the tenant record screen for why an id stamp beats a
  // clearing effect on an addressable page.
  const current = saved !== null && record !== null && saved.id === record.id ? saved : record;

  // The form as the RECORD stands — what an untouched page shows, and what
  // "discard" returns to. Derived rather than seeded by an effect.
  const pristine: TagGroupForm | null =
    current === null
      ? null
      : {
          id: current.id,
          key: current.key,
          displayName: {
            ar: current.display_name?.ar ?? '',
            en: current.display_name?.en ?? '',
          },
        };
  const form = draft !== null && pristine !== null && draft.id === pristine.id ? draft : pristine;

  const discard = useCallback(() => {
    setKeyError(null);
    setDraft(null);
  }, []);

  const access = resolveAccess([
    {
      allowed: canManage,
      reason: t(
        'tagGroups.record.readOnly.noPermission',
        "You don't have permission to manage tags, so this record is read-only."
      ),
    },
  ]);

  const isDirty =
    form !== null &&
    pristine !== null &&
    (form.key !== pristine.key ||
      (form.displayName.ar ?? '') !== (pristine.displayName.ar ?? '') ||
      (form.displayName.en ?? '') !== (pristine.displayName.en ?? ''));

  const back = { label: t('tagGroups.record.back', 'Back to tag groups'), onBack };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!current || !form || !access.editable) return;

    const key = form.key.trim();
    if (!KEY_PATTERN.test(key)) {
      setKeyError(
        t(
          'tagGroups.record.key.invalid',
          'A key is up to 64 characters of letters, digits, and _ . : -'
        )
      );
      return;
    }
    setKeyError(null);

    try {
      setIsSaving(true);
      const { data, response } = await api.PATCH('/api/v1/tag-groups/{id}', {
        params: { path: { id: current.id } },
        body: {
          key,
          display_name: { ar: form.displayName.ar ?? '', en: form.displayName.en ?? '' },
        },
      });
      if (response.status === 409) {
        setKeyError(t('tagGroups.record.key.conflict', 'Another group already uses this key.'));
        return;
      }
      if (data === undefined) {
        throw new Error(t('tagGroups.record.error.save', 'Failed to save this tag group'));
      }
      onNotify(t('tagGroups.record.save.success', 'Tag group updated'), 'success');
      setSaved(data.data);
      setDraft(null);
    } catch (error) {
      onNotify(
        error instanceof Error && error.message
          ? error.message
          : t('tagGroups.record.error.save', 'Failed to save this tag group'),
        'error'
      );
    } finally {
      setIsSaving(false);
    }
  };

  if (loaded.status === 'error') {
    return (
      <RecordPageError
        testId="tag-group-record-error"
        title={t('tagGroups.record.error.title', 'This tag group could not be loaded')}
        description={
          loaded.detail ?? t('tagGroups.record.error.load', 'Failed to load this tag group')
        }
        back={back}
      />
    );
  }

  if (loaded.status === 'ready' && loaded.value === 'not-found') {
    return (
      <RecordPageError
        testId="tag-group-record-missing"
        title={t('tagGroups.record.error.title', 'This tag group could not be loaded')}
        description={t(
          'tagGroups.record.error.notFound',
          'No tag group with this id belongs to your workspace. It may have been deleted.'
        )}
        back={back}
      />
    );
  }

  if (loaded.status !== 'ready' || current === null || form === null) {
    return (
      <RecordPageSkeleton
        testId="tag-group-record-loading"
        back={back}
        label={t('tagGroups.record.loading', 'Loading tag group…')}
        stats={2}
      />
    );
  }

  const fields: TagGroupRecordFields = {
    key: current.key,
    label: groupLabel(current),
    createdAt: current.created_at,
    tagCount: tags.status === 'ready' ? tags.value.length : null,
  };

  const detailsCard = (children: ReactNode) => (
    <Card>
      <CardHeader>
        <CardTitle>{t('tagGroups.record.details.title', 'Details')}</CardTitle>
        <CardDescription>
          {t(
            'tagGroups.record.details.subtitle',
            'What this group is called, and the token everything else addresses it by.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">{children}</CardContent>
    </Card>
  );

  const editor = (
    <form id="tag-group-record-form" onSubmit={handleSubmit}>
      {detailsCard(
        <>
          <div className="space-y-1.5">
            <label
              htmlFor="tag-group-record-key"
              className="block text-sm font-medium text-foreground"
            >
              {t('tagGroups.record.key.label', 'Key')}
            </label>
            <Input
              id="tag-group-record-key"
              data-testid="tag-group-record-key"
              value={form.key}
              autoComplete="off"
              onChange={(event) => setDraft({ ...form, key: event.target.value })}
            />
            <p className="text-xs text-muted-foreground">
              {t(
                'tagGroups.record.key.hint',
                'The stable token imports and plugins use to find this group. Changing it renames the group everywhere; the tags inside it are untouched.'
              )}
            </p>
            {keyError !== null && (
              <p className="text-xs text-destructive" data-testid="tag-group-record-key-error">
                {keyError}
              </p>
            )}
          </div>

          <BilingualInput
            id="tag-group-record-display-name"
            label={t('tagGroups.record.displayName.label', 'Display name')}
            description={t(
              'tagGroups.record.displayName.hint',
              'What people see instead of the key. A group with neither language filled in falls back to showing its key.'
            )}
            value={form.displayName}
            onChange={(displayName) => setDraft({ ...form, displayName })}
          />
        </>
      )}
    </form>
  );

  const readOnly = detailsCard(
    <dl className="space-y-4">
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('tagGroups.record.key.label', 'Key')}
        </dt>
        <dd className="text-sm text-foreground">{current.key}</dd>
      </div>
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('tagGroups.record.displayName.en', 'Display name (English)')}
        </dt>
        <dd className="text-sm text-foreground">{current.display_name?.en || EMPTY_VALUE}</dd>
      </div>
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('tagGroups.record.displayName.ar', 'Display name (Arabic)')}
        </dt>
        <dd className="text-sm text-foreground">{current.display_name?.ar || EMPTY_VALUE}</dd>
      </div>
    </dl>
  );

  return (
    <RecordPageShell
      testId="tag-group-record"
      fields={fields}
      facts={tagGroupFacts}
      t={t}
      access={access}
      back={back}
      icon={<IconTags />}
      actions={
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={!isDirty || isSaving}
            onClick={discard}
          >
            {t('tagGroups.record.cancel', 'Discard changes')}
          </Button>
          <Button
            type="submit"
            form="tag-group-record-form"
            data-testid="tag-group-record-save"
            disabled={isSaving || !isDirty}
          >
            {isSaving
              ? t('tagGroups.record.saving', 'Saving…')
              : t('tagGroups.record.save', 'Save changes')}
          </Button>
        </div>
      }
      main={{ editor, readOnly }}
      side={<TagsPanel tags={tags} />}
    />
  );
}

/** What is actually IN this group — the question a group exists to answer. */
function TagsPanel({ tags }: { tags: RecordResource<Tag[]> }) {
  const t = useTranslation('admin');

  return (
    <RecordCollectionPanel
      testId="tag-group-record-tags"
      title={t('tagGroups.record.tags.title', 'Tags in this group')}
      subtitle={t(
        'tagGroups.record.tags.subtitle',
        'Every tag that belongs to this group. Tags themselves are created and renamed on the Tags screen.'
      )}
      resource={tags}
      emptyLabel={t('tagGroups.record.tags.empty', 'This group holds no tags yet.')}
      placeholderRows={3}
    >
      {(items) => (
        <RecordList>
          {items.map((tag) => (
            // A tag name is tenant DATA — rendered verbatim, never translated.
            <RecordListItem key={tag.id} primary={tag.name} />
          ))}
        </RecordList>
      )}
    </RecordCollectionPanel>
  );
}
