'use client';

import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { IconPlus, IconStar } from '@tabler/icons-react';
import type { DocumentCollection, DocumentSubstrate, DocumentView } from './types';

/**
 * The organizer's left rail (#978).
 *
 * THREE KINDS OF ENTRY, THREE DIFFERENT MEANINGS
 * ----------------------------------------------
 *  1. An enabled folder. Computable, and this user can anchor it.
 *  2. A DISABLED folder carrying its reason. Computable, but this user cannot
 *     anchor it — they belong to no unit. #951: a control the viewer cannot use
 *     is disabled with the cause on it, never hidden, because a hidden control
 *     makes "I have no unit", "the feature was removed" and "it is broken"
 *     pixel-identical.
 *  3. A folder that is NOT HERE. The server did not send it, because this
 *     installation does not record the facts it reads. This component has
 *     nothing to render for it and deliberately renders nothing — an empty
 *     "Awaiting me" would state "nothing awaits you", which is false and which
 *     the reader cannot check from outside.
 *
 * The footnote is the only place case 3 is spoken about, and it is prose rather
 * than a folder: it says what this installation does not record and what would
 * supply it, so an operator asking "why is there no inbox here" has an answer.
 * Nothing in it is clickable, so it cannot be mistaken for case 2.
 */
export interface ViewRailProps {
  views: DocumentView[];
  collections: DocumentCollection[];
  unavailableSubstrates: DocumentSubstrate[];
  /** The selected folder: a view key, plus a collection id when it is one. */
  selectedViewKey: string;
  selectedCollectionId: number | null;
  onSelectView: (key: string) => void;
  onSelectCollection: (collectionId: number) => void;
  onCreateCollection: () => void;
  /**
   * Why a collection cannot be created right now, or null.
   *
   * Case 2 above, applied to a WRITE. When the collection list could not be read
   * the create control is disabled carrying that sentence, not hidden: a name
   * checked against a list we do not have would collide on the unique index and
   * come back as a bare 409 the person has no way to interpret. Hiding the
   * button instead would make "your collections failed to load" look exactly
   * like "this installation has no collections", which is the conflation this
   * whole rail exists to refuse.
   */
  createDisabledReason?: string | null;
}

/**
 * Translate a folder's label where this client knows the key, and fall back to
 * the server's English otherwise.
 *
 * A switch rather than a lookup map built from the response: the i18n catalogue
 * is extracted from literal `t()` calls, so a runtime key would be invisible to
 * the extractor and would ship untranslated in every language (see
 * TranslationKeyExtractor). The `default` branch is what keeps the rail correct
 * when a later feature or a plugin registers a folder this build has never
 * heard of — it renders the server's label instead of a blank chip.
 */
export function viewLabel(t: ReturnType<typeof useTranslation>, view: DocumentView): string {
  switch (view.key) {
    case 'all':
      return t('organizer.view.all', 'All documents');
    case 'created-by-me':
      return t('organizer.view.createdByMe', 'Created by me');
    case 'raised-by-my-unit':
      return t('organizer.view.raisedByMyUnit', 'Raised by my unit');
    case 'below-my-unit':
      return t('organizer.view.belowMyUnit', 'Everything below my unit');
    case 'starred':
      return t('organizer.view.starred', 'Starred');
    case 'collection':
      return t('organizer.view.collection', 'Collection');
    default:
      return view.label;
  }
}

export function ViewRail({
  views,
  collections,
  unavailableSubstrates,
  selectedViewKey,
  selectedCollectionId,
  onSelectView,
  onSelectCollection,
  onCreateCollection,
  createDisabledReason = null,
}: ViewRailProps) {
  const t = useTranslation('documents');

  // The `collection` folder is a TEMPLATE: it takes a required `collection_id`,
  // so it is instantiated once per collection below rather than shown as an
  // entry of its own. Filtering on the declared parameter rather than on the
  // key means a later parameterised folder behaves the same without this file
  // learning its name.
  const isTemplate = (view: DocumentView) => view.parameters.some((p) => p.required);
  const derived = views.filter((v) => v.group === 'derived' && !isTemplate(v));
  const personal = views.filter((v) => v.group === 'personal' && !isTemplate(v));

  return (
    <nav aria-label={t('organizer.rail.label', 'Document folders')} className="w-64 shrink-0 space-y-6">
      <RailSection title={t('organizer.rail.folders', 'Folders')}>
        {derived.map((view) => (
          <RailButton
            key={view.key}
            label={viewLabel(t, view)}
            title={view.description}
            selected={selectedViewKey === view.key && selectedCollectionId === null}
            disabled={!view.available}
            reason={view.unavailable_reason}
            onClick={() => onSelectView(view.key)}
          />
        ))}
      </RailSection>

      {(personal.length > 0 || collections.length > 0) && (
        <RailSection title={t('organizer.rail.mine', 'My organization')}>
          {personal.map((view) => (
            <RailButton
              key={view.key}
              label={viewLabel(t, view)}
              title={view.description}
              icon={view.key === 'starred' ? <IconStar size={14} aria-hidden /> : undefined}
              selected={selectedViewKey === view.key && selectedCollectionId === null}
              disabled={!view.available}
              reason={view.unavailable_reason}
              onClick={() => onSelectView(view.key)}
            />
          ))}

          {/* The starred collection is reachable through its own folder above,
              so it is not repeated here as a pile — one affordance per thing. */}
          {collections
            .filter((collection) => collection.system_key === null)
            .map((collection) => (
              <RailButton
                key={collection.id}
                label={collection.name}
                badge={collection.item_count}
                selected={selectedCollectionId === collection.id}
                onClick={() => onSelectCollection(collection.id)}
              />
            ))}

          <Button
            variant="ghost"
            size="sm"
            className="w-full justify-start"
            disabled={createDisabledReason !== null}
            title={createDisabledReason ?? undefined}
            onClick={onCreateCollection}
          >
            <IconPlus size={14} className="me-2" aria-hidden />
            {t('organizer.collection.new', 'New collection')}
          </Button>
          {createDisabledReason !== null && (
            // Visible, not only a `title`: hover is touch-inaccessible, and the
            // reason is the whole reason the disabled control is still here.
            <p className="px-2 pb-1 text-xs text-muted-foreground">{createDisabledReason}</p>
          )}
        </RailSection>
      )}

      {unavailableSubstrates.length > 0 && (
        <div className="rounded-md border border-dashed border-border p-3 text-xs text-muted-foreground">
          <p className="font-medium text-foreground">
            {t('organizer.rail.notRecorded', 'Not recorded in this installation')}
          </p>
          <p className="mt-1">
            {t(
              'organizer.rail.notRecordedHelp',
              'Folders derived from the facts below are absent rather than empty, so an empty list is never mistaken for nothing to do.'
            )}
          </p>
          <ul className="mt-2 space-y-1">
            {unavailableSubstrates.map((substrate) => (
              <li key={substrate.key}>
                {substrate.description}
                {substrate.provenance && (
                  <span className="block opacity-75">{substrate.provenance}</span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </nav>
  );
}

function RailSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <h2 className="mb-2 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {title}
      </h2>
      <div className="space-y-0.5">{children}</div>
    </div>
  );
}

/**
 * One rail entry.
 *
 * A disabled entry keeps its label and carries the reason both as a `title`
 * (hover) and as visible help text underneath. Hover alone would be
 * touch-inaccessible and would hide the very explanation the control exists to
 * give.
 */
function RailButton({
  label,
  title,
  icon,
  badge,
  selected,
  disabled,
  reason,
  onClick,
}: {
  label: string;
  title?: string;
  icon?: React.ReactNode;
  badge?: number;
  selected?: boolean;
  disabled?: boolean;
  reason?: string | null;
  onClick: () => void;
}) {
  return (
    <div>
      <button
        type="button"
        onClick={onClick}
        disabled={disabled}
        title={disabled ? (reason ?? undefined) : title}
        aria-current={selected ? 'page' : undefined}
        className={[
          'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm transition-colors',
          selected ? 'bg-accent font-medium text-accent-foreground' : 'text-foreground',
          disabled ? 'cursor-not-allowed opacity-50' : 'hover:bg-accent/60',
        ].join(' ')}
      >
        {icon}
        <span className="flex-1 truncate">{label}</span>
        {badge !== undefined && (
          <span className="text-xs text-muted-foreground">{badge}</span>
        )}
      </button>
      {disabled && reason && (
        <p className="px-2 pb-1 text-xs text-muted-foreground">{reason}</p>
      )}
    </div>
  );
}
