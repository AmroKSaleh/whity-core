'use client';

/**
 * The schema-driven RECORD page for a plugin crud feature (#948).
 *
 * `/admin/x/[featureId]/[recordId]` is the address a crud row's Edit action now
 * goes to, and this is what renders there. It is the same derivation
 * `CrudScreen` performs — read the public OpenAPI document, derive the model
 * from the plugin's own published schema — pointed at ONE record instead of the
 * collection, so a plugin gets record pages for a resource it has already
 * described, with no declaration to add and no JavaScript to ship.
 *
 * IT MOUNTS THE RECORD-PAGE SHELL (#882), like `/admin/roles/[id]` and
 * `/admin/users/[id]`, rather than inventing a third record layout. Two things
 * come with it that a dialog could not have:
 *
 *   - READ-ONLY IS A STATE. A caller without the write capability gets the
 *     record as a description list plus a sentence saying why it is not
 *     editable — where the list screen's answer is to hide the Edit control
 *     entirely, which is #951's complaint: "you may not" and "this is broken"
 *     render identically.
 *   - THE RECORD HAS AN ADDRESS. It survives a reload, a paste into chat, and
 *     the back button, because the id comes from the route rather than from a
 *     click.
 *
 * WHERE THE RECORD COMES FROM, and why it is not `GET {basePath}/{id}`. Nothing
 * in a crud descriptor promises an item GET: the host validates that the plugin
 * registered the COLLECTION route (and that its permission equals the
 * descriptor's), and derives editability from PATCH — HelloWorld, the reference
 * plugin, registers PATCH and DELETE at the item path and no GET at all. So the
 * record is read from the one route the host has actually verified. The first
 * page usually holds it; when it does not, the walk is exhausted before
 * concluding anything, because "not on page 1" and "does not exist" are
 * different answers and only one of them may be shown to a person (#867).
 */

import { useEffect, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import {
  deriveCrudModel,
  effectiveCapabilities,
  fetchSpec,
  type CrudColumn,
  type CrudModel,
} from '@/lib/plugin-crud-schema';
import type { PluginFeature } from '@/lib/plugin-features';
import {
  CrudFields,
  preferredLocalizedText,
  toLocalizedTextValue,
  useCrudForm,
  type CrudRow,
} from '@/components/plugin/crud-form';
import { fetchAllPages, isPaginationEnvelope } from '@/lib/api/fetch-all-pages';
import { useToast } from '@/lib/toast-context';
import { useDirection } from '@/lib/direction-context';
import {
  RecordPageError,
  RecordPageShell,
  RecordPageSkeleton,
  resolveAccess,
  type RecordBack,
  type RecordFactsFn,
} from '@amroksaleh/features/record';
import { Button } from '@amroksaleh/ui/button';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * i18n (domain `plugin`): only the CHROME this file authors is keyed. The
 * record's own values, its column labels and the feature's label are plugin
 * DATA and are rendered verbatim.
 */

/** The em dash a column shows when the record has no value for it. */
const EMPTY_VALUE = '—';

/**
 * What the SERVER says this record is: the row itself, plus the two pieces of
 * the feature descriptor the projection needs to name it.
 *
 * `titleField` is descriptor metadata rather than a column of the row, and it
 * has to be here because the projection receives NOTHING else — that is the
 * #895 shape, and it is why a caller-permission flag cannot reach a fact. None
 * of these names is a permission, so the fields type compiles.
 */
interface CrudRecordFields {
  row: CrudRow;
  /** The plugin's own display column, or null to fall back to the id. */
  titleField: string | null;
  /** The plugin that provides the feature — used for the subtitle. */
  plugin: string;
}

/** The record's display name: its title column's value, else `#id`. */
function rowTitle(row: CrudRow, titleField: string | null): string {
  if (titleField !== null) {
    const value = row[titleField];
    if (typeof value === 'string' && value.length > 0) {
      return value;
    }
    if (typeof value === 'number') {
      return String(value);
    }
  }
  return `#${String(row.id)}`;
}

/**
 * A pure projection of the record and the dictionary, at module scope — no
 * capability check is in reach here, which is the point (#895).
 */
const crudRecordFacts: RecordFactsFn<CrudRecordFields> = (fields, t) => ({
  title: rowTitle(fields.row, fields.titleField),
  // The same sentence the feature's own header carries, so a record page and
  // the screen it came from attribute the data to the same plugin in the same
  // words.
  subtitle: t('feature.providedBy', 'Provided by the {plugin} plugin.', {
    plugin: fields.plugin,
  }),
});

/** How the record fetch ended. */
type RecordState =
  | { status: 'loading' }
  | { status: 'forbidden' }
  | { status: 'error'; message: string }
  | { status: 'missing' }
  | { status: 'ready'; row: CrudRow };

/**
 * A settled fetch, and HOW MANY have settled.
 *
 * The counter is what the record page keys its form on, and it exists because
 * a refetch and its result do not arrive together. Keying on "a refetch was
 * asked for" remounts the form against the record that is still in hand — the
 * one from BEFORE the save — and the values the caller just wrote appear to
 * revert. Keying on "a result arrived" remounts it against what the server
 * actually holds, and leaves the caller's own text on screen until then.
 */
interface SettledRecord {
  state: RecordState;
  version: number;
}

/** Narrow one raw list item to a row; anything without a usable id is dropped. */
function toRow(item: unknown): CrudRow | null {
  if (typeof item !== 'object' || item === null) {
    return null;
  }
  const record = item as Record<string, unknown>;
  const id = record['id'];
  if (typeof id !== 'string' && typeof id !== 'number') {
    return null;
  }
  return { ...record, id };
}

/** Find the record by id, comparing as text — a plugin key may be either. */
function findRow(items: unknown[], recordId: string): CrudRow | null {
  for (const item of items) {
    const row = toRow(item);
    if (row !== null && String(row.id) === recordId) {
      return row;
    }
  }
  return null;
}

/**
 * Read one record out of the feature's collection endpoint.
 *
 * Returns `missing` only when the whole set was seen and the record was not in
 * it. A walk that could not finish resolves to `error`, never to `missing`: a
 * page the walk never fetched is not evidence that the record is gone, and
 * "deleted" is not a thing to tell somebody on a guess.
 */
function useCrudRecord(
  basePath: string | null,
  recordId: string,
  reloadKey: number,
  errorText: string
): SettledRecord {
  const [settled, setSettled] = useState<SettledRecord>({
    state: { status: 'loading' },
    version: 0,
  });

  useEffect(() => {
    if (basePath === null) {
      return;
    }

    let cancelled = false;

    // The fetcher lives inside the effect so no setState runs synchronously in
    // the effect body (react-hooks/set-state-in-effect).
    const load = async (): Promise<void> => {
      const settle = (next: RecordState): void => {
        if (!cancelled) {
          setSettled((previous) => ({ state: next, version: previous.version + 1 }));
        }
      };

      try {
        const response = await apiClient(basePath);
        if (response.status === 403) {
          settle({ status: 'forbidden' });
          return;
        }
        if (!response.ok) {
          settle({ status: 'error', message: errorText });
          return;
        }

        const body: unknown = await response.json();
        const envelope =
          typeof body === 'object' && body !== null
            ? (body as { data?: unknown; pagination?: unknown })
            : {};
        if (!Array.isArray(envelope.data)) {
          settle({ status: 'error', message: errorText });
          return;
        }

        const onFirstPage = findRow(envelope.data, recordId);
        if (onFirstPage !== null) {
          settle({ status: 'ready', row: onFirstPage });
          return;
        }

        // Row 26 of a paginated resource is not a missing row. Only walk when
        // the envelope itself says rows were withheld — an unpaginated plugin
        // route (the common case) answers here and issues no second request.
        const withheld =
          isPaginationEnvelope(envelope.pagination) &&
          envelope.data.length < envelope.pagination.total;
        if (!withheld) {
          settle({ status: 'missing' });
          return;
        }

        const walk = await fetchAllPages<unknown>(apiClient, basePath);
        const found = findRow(walk.items, recordId);
        if (found !== null) {
          settle({ status: 'ready', row: found });
          return;
        }
        settle(walk.complete ? { status: 'missing' } : { status: 'error', message: errorText });
      } catch {
        settle({ status: 'error', message: errorText });
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [basePath, recordId, reloadKey, errorText]);

  return settled;
}

/** The record's own values, as a description list — the read-only rendering. */
function CrudRecordSummary({
  columns,
  row,
  dir,
  untranslated,
  emptyLabel,
}: {
  columns: CrudColumn[];
  row: CrudRow;
  dir: 'ltr' | 'rtl';
  untranslated: string;
  emptyLabel: string;
}) {
  if (columns.length === 0) {
    return (
      <p className="text-sm text-muted-foreground" data-testid="crud-record-fields-empty">
        {emptyLabel}
      </p>
    );
  }

  return (
    <dl
      className="grid grid-cols-1 gap-4 rounded-lg border border-border bg-card p-6 sm:grid-cols-2"
      data-testid="crud-record-fields"
    >
      {columns.map((column) => {
        const raw = row[column.key];
        let value: string;
        if (column.isLocalizedText) {
          value = preferredLocalizedText(toLocalizedTextValue(raw), dir) ?? untranslated;
        } else if (raw === null || raw === undefined || raw === '') {
          value = EMPTY_VALUE;
        } else {
          value = String(raw);
        }
        return (
          <div key={column.key} className="space-y-1">
            <dt className="text-xs text-muted-foreground">{column.label}</dt>
            <dd className="text-sm text-foreground" data-testid={`crud-record-field-${column.key}`}>
              {value}
            </dd>
          </div>
        );
      })}
    </dl>
  );
}

/**
 * The record page proper, mounted once the model and the record have both
 * arrived.
 *
 * Split out so the form's `useState` seeds from a record that EXISTS: a form
 * whose defaults are computed before the fetch resolves has to be reset from an
 * effect afterwards, which is the synchronous-setState-in-an-effect this
 * codebase refuses everywhere else.
 */
function CrudRecordBody({
  feature,
  model,
  row,
  back,
  onSaved,
}: {
  feature: PluginFeature;
  model: CrudModel;
  row: CrudRow;
  back: RecordBack;
  onSaved: () => void;
}) {
  const t = useTranslation('plugin');
  const { dir } = useDirection();
  const { addToast } = useToast();
  const form = useCrudForm(model.editFields, row);

  const capabilities = effectiveCapabilities(model.capabilities, feature.capabilities);

  // The gates in the order they should be explained: the spec first (the
  // resource publishes no PATCH at all, so nobody can edit it), then this
  // caller's own permission. `resolveAccess` renders only the FIRST refusal.
  const access = resolveAccess([
    {
      allowed: model.capabilities.canEdit,
      reason: t(
        'crud.record.readOnly.noOperation',
        'This resource publishes no update operation, so its records cannot be edited here.'
      ),
    },
    {
      allowed: capabilities.canEdit,
      reason: t(
        'crud.record.readOnly.noPermission',
        'You do not hold the permission to edit this record, so it is shown read-only.'
      ),
    },
  ]);

  const save = async (payload: Record<string, unknown>): Promise<boolean> => {
    const response = await apiClient(
      `${feature.resource?.basePath ?? ''}/${encodeURIComponent(String(row.id))}`,
      {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    if (!response.ok) {
      let message = t('crud.error.update', 'Failed to update record');
      try {
        const body: unknown = await response.json();
        if (typeof body === 'object' && body !== null && 'error' in body) {
          const backendMessage = (body as { error: unknown }).error;
          if (typeof backendMessage === 'string' && backendMessage.length > 0) {
            message = backendMessage;
          }
        }
      } catch {
        // No JSON body — the keyed fallback stands.
      }
      addToast(message, 'error');
      return false;
    }
    addToast(t('crud.toast.updated', 'Record updated successfully'), 'success');
    // Stay on the record. A save is not a reason to leave the page the user
    // deliberately navigated to — refetch instead, so what is on screen is what
    // the server now holds (including anything it computed).
    onSaved();
    return true;
  };

  return (
    <RecordPageShell
      testId="crud-record"
      fields={{ row, titleField: feature.resource?.titleField ?? null, plugin: feature.plugin }}
      facts={crudRecordFacts}
      t={t}
      access={access}
      back={back}
      actions={
        <Button
          type="submit"
          form="crud-record-form"
          disabled={form.isSubmitting}
          data-testid="crud-record-save"
        >
          {form.isSubmitting
            ? t('crud.edit.pending', 'Saving...')
            : t('crud.edit.submit', 'Save changes')}
        </Button>
      }
      main={{
        editor: (
          // The submit control lives in the shell's header, so the two are
          // associated by `form=` rather than by nesting. Same element, same
          // Enter-to-submit behaviour, without a second save button inside the
          // panel.
          <form
            id="crud-record-form"
            className="rounded-lg border border-border bg-card p-6"
            onSubmit={(event) => {
              event.preventDefault();
              void form.submit(save);
            }}
          >
            <CrudFields
              fields={model.editFields}
              values={form.values}
              errors={form.errors}
              onChange={form.setValue}
            />
          </form>
        ),
        readOnly: (
          <CrudRecordSummary
            columns={model.columns}
            row={row}
            dir={dir}
            untranslated={t('crud.localizedText.untranslated', 'Untranslated')}
            emptyLabel={t('crud.record.fields.empty', 'This record has no fields to show.')}
          />
        ),
      }}
    />
  );
}

/**
 * The record page for one crud feature record.
 *
 * Owns the two fetches (the OpenAPI document and the record) and the states
 * they can end in; `CrudRecordBody` owns the page once both have arrived.
 */
export function CrudRecordScreen({
  feature,
  recordId,
  onBack,
}: {
  feature: PluginFeature;
  recordId: string;
  onBack: () => void;
}) {
  const t = useTranslation('plugin');
  const basePath = feature.resource?.basePath ?? null;

  const [model, setModel] = useState<CrudModel | null>(null);
  const [isSpecLoading, setIsSpecLoading] = useState(true);
  const [reloadKey, setReloadKey] = useState(0);

  // Resolved at render rather than inside the fetch, so a language switch does
  // not re-issue the request: a STRING dependency is compared by value.
  const listErrorText = t('crud.error.list', 'Failed to load records');

  useEffect(() => {
    if (basePath === null) {
      return;
    }

    let cancelled = false;

    const load = async (): Promise<void> => {
      const spec = await fetchSpec();
      if (cancelled) {
        return;
      }
      setModel(spec === null ? null : deriveCrudModel(spec, basePath));
      setIsSpecLoading(false);
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [basePath]);

  const { state: record, version } = useCrudRecord(basePath, recordId, reloadKey, listErrorText);

  const back: RecordBack = {
    label: t('crud.record.back', 'Back to {resource}', { resource: feature.label }),
    onBack,
  };

  // The feature reached this route without a resource — the same defensive
  // branch `CrudScreen` carries, since callers only mount either for a crud
  // feature that has one.
  if (basePath === null) {
    return (
      <RecordPageError
        testId="crud-record-error"
        title={t('crud.noResource.title', 'No resource')}
        description={t(
          'crud.noResource.description',
          "The '{id}' feature does not declare a REST resource to render.",
          { id: feature.id }
        )}
        back={back}
      />
    );
  }

  if (isSpecLoading || record.status === 'loading') {
    // The SHELL's own skeleton, not a lookalike: a hand-rolled copy is a copy
    // that drifts, and the page then visibly jumps when the real one arrives.
    return <RecordPageSkeleton back={back} stats={0} testId="crud-record-loading" />;
  }

  if (model === null) {
    return (
      <RecordPageError
        testId="crud-record-error"
        title={t('crud.schemaUnavailable.title', 'Schema unavailable')}
        description={t(
          'crud.schemaUnavailable.description',
          'The API schema for this feature could not be loaded, so the screen cannot be rendered.'
        )}
        back={back}
      />
    );
  }

  // A 403 on the collection is an ANSWER, not a failure: say which permission
  // the caller is missing, exactly as the list screen does, rather than
  // redirecting somewhere and leaving them to guess (#951).
  if (record.status === 'forbidden') {
    return (
      <RecordPageError
        testId="crud-record-error"
        title={t('crud.forbidden.title', 'Access denied')}
        description={t(
          'crud.forbidden.description',
          'You need the {permission} permission to use this feature.',
          { permission: feature.requiredPermission }
        )}
        back={back}
      />
    );
  }

  // Unknown id: the page STAYS at this URL and says so. Not a 404 page, which
  // would lose the address a reload could retry, and not a redirect to the
  // list, which turns "you may not see this" and "this is gone" into the same
  // silent bounce.
  if (record.status === 'missing') {
    return (
      <RecordPageError
        testId="crud-record-missing"
        title={t('crud.record.missing.title', 'Record not found')}
        description={t(
          'crud.record.missing.description',
          'No record with the id {id} is visible to you in {resource}. It may have been deleted, or it may be outside what you are allowed to see.',
          { id: recordId, resource: feature.label }
        )}
        back={back}
      />
    );
  }

  if (record.status === 'error') {
    return (
      <RecordPageError
        testId="crud-record-error"
        title={t('crud.record.error.title', 'This record could not be loaded')}
        description={record.message}
        back={back}
      />
    );
  }

  return (
    <CrudRecordBody
      // Remount when a fetch DELIVERS a record — not when one is requested — so
      // the form seeds from what the server holds, without resetting state from
      // an effect. See SettledRecord for why the distinction is load-bearing.
      key={`${recordId}-${version}`}
      feature={feature}
      model={model}
      row={record.row}
      back={back}
      onSaved={() => setReloadKey((key) => key + 1)}
    />
  );
}
