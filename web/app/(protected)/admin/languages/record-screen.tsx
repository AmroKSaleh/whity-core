'use client';

/**
 * The language RECORD page (#882/#884) — what `/admin/languages/[id]` renders.
 *
 * THIS ONE IS A GAP, NOT A REFACTOR. #884's audit found the languages screen was
 * the clearest hole in the set: it could create a language and never rename one.
 * `PATCH /api/v1/languages/{id}` has accepted `{name, enabled, direction}` since
 * migration 090; the UI reached only two of the three, through a `<select>` and
 * a `<Switch>` wedged into table cells, because there was nowhere else to put
 * them. A language's NAME — the word every person on the instance picks it by in
 * the language switcher — was simply not editable anywhere in the product, and a
 * typo in it was permanent.
 *
 * So this page is where the third field finally lives, and where the other two
 * move to for the same reason. A record page is the "somewhere else" the inline
 * cell controls were a workaround for.
 *
 * WHAT IT STILL CANNOT DO, and says so rather than implying otherwise:
 *   - the CODE is not editable. The handler takes no `code`, deliberately: the
 *     code is the language's identity — profiles store `language_code`, every
 *     translation row keys off the language, and renaming it would silently
 *     orphan both. Stated with the reason instead of rendered as a greyed box,
 *     which would invite the reader to hunt for the permission that ungreys it.
 *   - there is no DELETE. No route exists, and none should: a language with
 *     translations behind it and profiles pointing at it is switched OFF, not
 *     removed. The details card says that in place of an absent button.
 *
 * WHY COVERAGE IS ON THIS PAGE. "Is Arabic ready to turn on?" is the only real
 * question an operator has about a language, and the answer already existed at
 * `GET /api/v1/translations/coverage` — reachable until now only from the
 * translations screen, where it is one row of a table about something else. It
 * is behind a DIFFERENT permission (`translations:manage`, tenant-scoped) than
 * the record itself (`languages:manage`, system-tenant), so it is a side panel
 * that is ABSENT for a caller who lacks it rather than an error about a
 * capability nobody granted.
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
  type RecordBadge,
  type RecordFactsFn,
  type RecordResource,
} from '@amroksaleh/features/record';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@amroksaleh/ui/switch';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { IconLanguage } from '@tabler/icons-react';
import { errorMessage } from './shared';
import type { Language } from './types';

type LanguageDirection = Language['direction'];
type DomainCoverage = components['schemas']['TranslationDomainCoverage'];

/** The editable half of the record, stamped with the language it belongs to. */
interface LanguageForm {
  id: number;
  name: string;
  direction: LanguageDirection;
  enabled: boolean;
}

/** What this language's coverage looks like once the report has been read. */
interface LanguageCoverage {
  translated: number;
  total: number;
  domains: DomainCoverage[];
  /** Whether the report names this language as the SOURCE everything else is measured against. */
  isSource: boolean;
}

export interface LanguageRecordScreenProps {
  languageId: number;
  /** Whether the caller holds `languages:manage`. */
  canManage: boolean;
  /** Whether the caller is acting in the system tenant — the platform-write rule. */
  isSystemTenant: boolean;
  onNotify: (message: string, tone: 'success' | 'error' | 'info' | 'warning') => void;
  onBack: () => void;
}

/**
 * What the SERVER says this language IS.
 *
 * `translated`/`total` come from the coverage report rather than the language
 * row, and they are null until it lands — the honest "not answered yet" the
 * shell renders as an em dash, rather than a zero that would read as "nothing is
 * translated".
 */
interface LanguageRecordFields {
  code: string;
  name: string;
  direction: LanguageDirection;
  enabled: boolean;
  createdAt: string | null;
  translated: number | null;
  total: number | null;
  isSourceLanguage: boolean;
}

/** A pure projection of the record and the dictionary, at module scope (#895). */
const languageFacts: RecordFactsFn<LanguageRecordFields> = (language, t, dates) => {
  const badges: RecordBadge[] = [];
  if (!language.enabled) {
    badges.push({
      key: 'disabled',
      label: t('languages.record.badge.disabled', 'Switched off'),
      tone: 'warning',
      title: t(
        'languages.record.badge.disabled.hint',
        'Nobody can select this language yet. Everything set up here is kept and takes effect the moment it is switched on.'
      ),
    });
  }
  if (language.isSourceLanguage) {
    badges.push({
      key: 'source',
      label: t('languages.record.badge.source', 'Source language'),
      tone: 'info',
      title: t(
        'languages.record.badge.source.hint',
        'Every other language is measured against this one, so it is complete by definition.'
      ),
    });
  }

  return {
    title: language.name,
    // The code, verbatim: it is a BCP-47 tag, not prose, and it is the one thing
    // about a language that never translates.
    subtitle: language.code,
    badges,
    stats: [
      {
        key: 'direction',
        label: t('languages.record.stat.direction', 'Writing direction'),
        value:
          language.direction === 'rtl'
            ? t('languages.direction.rtl', 'Right to left')
            : t('languages.direction.ltr', 'Left to right'),
      },
      {
        key: 'coverage',
        label: t('languages.record.stat.coverage', 'Translated'),
        value:
          language.translated === null || language.total === null
            ? null
            : t('languages.record.stat.coverageValue', '{translated} of {total}', {
                translated: language.translated,
                total: language.total,
              }),
      },
      // #1068: the stat GOES when this tenant hides dates, rather than
      // surviving as "Added —" — a label refusing to answer its own question.
      ...(dates.hidden
        ? []
        : [
            {
              key: 'created',
              label: t('languages.record.stat.created', 'Added'),
              value: dates.date(language.createdAt),
            },
          ]),
    ],
  };
};

export function LanguageRecordScreen({
  languageId,
  canManage,
  isSystemTenant,
  onNotify,
  onBack,
}: LanguageRecordScreenProps) {
  const t = useTranslation('admin');

  // There is no `GET /api/v1/admin/languages/{id}`, so the record is picked out
  // of the admin listing — the one endpoint that returns DISABLED languages too,
  // which is most of what this page is for. The listing is unpaginated (the
  // catalogue is a handful of rows), so no walk is needed.
  const loaded = useRecordResource<Language | 'not-found'>(
    async () => {
      const { data, error } = await api.GET('/api/v1/admin/languages');
      if (data === undefined) {
        throw new Error(
          errorMessage(error, t('languages.record.error.load', 'Failed to load this language'))
        );
      }
      return data.data.find((language) => language.id === languageId) ?? 'not-found';
    },
    [languageId],
    t('languages.record.error.load', 'Failed to load this language')
  );

  /** The record as last SAVED, so a successful write costs no refetch. */
  const [saved, setSaved] = useState<Language | null>(null);
  /** What the operator has TYPED, or null while the form is untouched. */
  const [draft, setDraft] = useState<LanguageForm | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const record = loaded.status === 'ready' && loaded.value !== 'not-found' ? loaded.value : null;
  // Both pieces of state carry the id they belong to and are ignored when it
  // does not match — see the tenant record screen for why an id stamp beats a
  // clearing effect on an addressable page.
  const current = saved !== null && record !== null && saved.id === record.id ? saved : record;

  // The coverage report is keyed by CODE, not id, so it waits for the record.
  // Re-reading it when the language's own save changes `enabled` is deliberate:
  // the report only covers enabled languages, so switching one on is exactly
  // when its coverage appears.
  const code = current?.code ?? null;
  const enabled = current?.enabled ?? false;
  const coverage = useRecordResource<LanguageCoverage | null>(
    async () => {
      if (code === null) return null;
      const { data, response, error } = await api.GET('/api/v1/translations/coverage');
      if (response.status === 403) return 'forbidden';
      if (data === undefined) {
        throw new Error(
          errorMessage(
            error,
            t('languages.record.coverage.error', 'Failed to load translation coverage')
          )
        );
      }
      const entry = data.data.languages.find((language) => language.language_code === code);
      if (entry === undefined) return null;
      return {
        translated: entry.translated,
        total: entry.total,
        domains: entry.domains,
        isSource: data.data.source_language_code === code,
      };
    },
    [code, enabled],
    t('languages.record.coverage.error', 'Failed to load translation coverage')
  );

  // The form as the RECORD stands — what an untouched page shows, and what
  // "discard" returns to. Derived rather than seeded by an effect.
  const pristine: LanguageForm | null =
    current === null
      ? null
      : {
          id: current.id,
          name: current.name,
          direction: current.direction,
          enabled: current.enabled,
        };
  const form = draft !== null && pristine !== null && draft.id === pristine.id ? draft : pristine;

  const discard = useCallback(() => setDraft(null), []);

  // Two gates. The capability first, because a caller who has neither should be
  // told the one they could conceivably be granted, not the one that depends on
  // which workspace they are standing in.
  const access = resolveAccess([
    {
      allowed: canManage,
      reason: t(
        'languages.record.readOnly.noPermission',
        "You don't have permission to manage languages, so this record is read-only."
      ),
    },
    {
      allowed: isSystemTenant,
      reason: t(
        'languages.record.readOnly.systemTenant',
        'A language belongs to the whole platform, so only the system workspace can change one. This record is read-only.'
      ),
    },
  ]);

  const isDirty =
    form !== null &&
    pristine !== null &&
    (form.name !== pristine.name ||
      form.direction !== pristine.direction ||
      form.enabled !== pristine.enabled);

  const back = { label: t('languages.record.back', 'Back to languages'), onBack };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!current || !form || !access.editable) return;

    const name = form.name.trim();
    if (name === '') {
      onNotify(t('languages.record.name.required', 'A name is required.'), 'error');
      return;
    }

    try {
      setIsSaving(true);
      const { data, error } = await api.PATCH('/api/v1/languages/{id}', {
        params: { path: { id: current.id } },
        body: { name, direction: form.direction, enabled: form.enabled },
      });
      if (data === undefined) {
        throw new Error(
          errorMessage(error, t('languages.record.error.save', 'Failed to save this language'))
        );
      }
      onNotify(t('languages.record.save.success', 'Language updated'), 'success');
      // Re-seat on what the SERVER returned rather than on the draft: this write
      // invalidates the language-registry cache, and the response is the row as
      // it now stands.
      setSaved(data.data);
      setDraft(null);
    } catch (err) {
      onNotify(
        err instanceof Error && err.message
          ? err.message
          : t('languages.record.error.save', 'Failed to save this language'),
        'error'
      );
    } finally {
      setIsSaving(false);
    }
  };

  if (loaded.status === 'error') {
    return (
      <RecordPageError
        testId="language-record-error"
        title={t('languages.record.error.title', 'This language could not be loaded')}
        description={
          loaded.detail ?? t('languages.record.error.load', 'Failed to load this language')
        }
        back={back}
      />
    );
  }

  // An unknown id keeps its URL and names the cause rather than redirecting: a
  // bounce renders "removed", "never existed" and "not visible to you" as one
  // silent event (#951).
  if (loaded.status === 'ready' && loaded.value === 'not-found') {
    return (
      <RecordPageError
        testId="language-record-missing"
        title={t('languages.record.error.title', 'This language could not be loaded')}
        description={t(
          'languages.record.error.notFound',
          'No language with this id is in the catalogue.'
        )}
        back={back}
      />
    );
  }

  if (loaded.status !== 'ready' || current === null || form === null) {
    return (
      <RecordPageSkeleton
        testId="language-record-loading"
        back={back}
        label={t('languages.record.loading', 'Loading language…')}
        stats={3}
      />
    );
  }

  const report = coverage.status === 'ready' ? coverage.value : null;

  const fields: LanguageRecordFields = {
    code: current.code,
    name: current.name,
    direction: current.direction,
    enabled: current.enabled,
    createdAt: current.created_at,
    translated: report?.translated ?? null,
    total: report?.total ?? null,
    isSourceLanguage: report?.isSource ?? false,
  };

  const detailsCard = (children: ReactNode) => (
    <Card>
      <CardHeader>
        <CardTitle>{t('languages.record.details.title', 'Details')}</CardTitle>
        <CardDescription>
          {t(
            'languages.record.details.subtitle',
            'What this language is called, how it reads, and whether anyone can choose it.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">{children}</CardContent>
    </Card>
  );

  /** The code, stated with the reason it is not a field. Never a disabled input. */
  const codeField = (
    <div className="space-y-1.5">
      <span className="block text-sm font-medium text-foreground">
        {t('languages.record.code.label', 'Code')}
      </span>
      <p className="text-sm text-foreground" data-testid="language-record-code">
        {current.code}
      </p>
      <p className="text-xs text-muted-foreground">
        {t(
          'languages.record.code.hint',
          "The code is this language's identity — every profile preference and translation row points at it — so it cannot be changed after the language is added."
        )}
      </p>
    </div>
  );

  const editor = (
    <form id="language-record-form" onSubmit={handleSubmit}>
      {detailsCard(
        <>
          {codeField}

          <div className="space-y-1.5">
            <label
              htmlFor="language-record-name"
              className="block text-sm font-medium text-foreground"
            >
              {t('languages.record.name.label', 'Name')}
            </label>
            <Input
              id="language-record-name"
              data-testid="language-record-name"
              value={form.name}
              autoComplete="off"
              onChange={(event) => setDraft({ ...form, name: event.target.value })}
            />
            <p className="text-xs text-muted-foreground">
              {t(
                'languages.record.name.hint',
                'How this language names itself in the language switcher. Write it in the language itself — an Arabic speaker looks for “العربية”, not “Arabic”.'
              )}
            </p>
          </div>

          <div className="space-y-1.5">
            <label
              htmlFor="language-record-direction"
              className="block text-sm font-medium text-foreground"
            >
              {t('languages.record.direction.label', 'Writing direction')}
            </label>
            <Select
              value={form.direction}
              onValueChange={(value) =>
                setDraft({ ...form, direction: value as LanguageDirection })
              }
            >
              <SelectTrigger id="language-record-direction" data-testid="language-record-direction">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="ltr">{t('languages.direction.ltr', 'Left to right')}</SelectItem>
                <SelectItem value="rtl">{t('languages.direction.rtl', 'Right to left')}</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {t(
                'languages.record.direction.hint',
                'Direction travels with the language, never with the person: changing it re-mirrors the whole interface for everyone who has chosen this language.'
              )}
            </p>
          </div>

          <div className="flex items-start justify-between gap-4 rounded-lg border border-border bg-muted/20 p-4">
            <div className="space-y-0.5">
              <label
                htmlFor="language-record-enabled"
                className="text-sm font-medium text-foreground"
              >
                {t('languages.record.enabled.label', 'Available to choose')}
              </label>
              <p className="text-xs text-muted-foreground">
                {t(
                  'languages.record.enabled.hint',
                  'A language is switched off rather than deleted — its translations and everyone who has selected it are kept, and switching it back on restores them.'
                )}
              </p>
            </div>
            <Switch
              id="language-record-enabled"
              data-testid="language-record-enabled"
              checked={form.enabled}
              onCheckedChange={(checked) => setDraft({ ...form, enabled: checked })}
            />
          </div>
        </>
      )}
    </form>
  );

  const readOnly = detailsCard(
    <dl className="space-y-4">
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('languages.record.code.label', 'Code')}
        </dt>
        <dd className="text-sm text-foreground">{current.code}</dd>
      </div>
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('languages.record.name.label', 'Name')}
        </dt>
        <dd className="text-sm text-foreground">{current.name}</dd>
      </div>
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('languages.record.direction.label', 'Writing direction')}
        </dt>
        <dd className="text-sm text-foreground">
          {current.direction === 'rtl'
            ? t('languages.direction.rtl', 'Right to left')
            : t('languages.direction.ltr', 'Left to right')}
        </dd>
      </div>
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('languages.record.enabled.label', 'Available to choose')}
        </dt>
        <dd className="text-sm text-foreground">
          {current.enabled
            ? t('languages.record.enabled.yes', 'Yes')
            : t('languages.record.enabled.no', 'No')}
        </dd>
      </div>
    </dl>
  );

  return (
    <RecordPageShell
      testId="language-record"
      fields={fields}
      facts={languageFacts}
      t={t}
      access={access}
      back={back}
      icon={<IconLanguage />}
      actions={
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={!isDirty || isSaving}
            onClick={discard}
          >
            {t('languages.record.cancel', 'Discard changes')}
          </Button>
          <Button
            type="submit"
            form="language-record-form"
            data-testid="language-record-save"
            disabled={isSaving || !isDirty}
          >
            {isSaving
              ? t('languages.record.saving', 'Saving…')
              : t('languages.record.save', 'Save changes')}
          </Button>
        </div>
      }
      main={{ editor, readOnly }}
      side={<CoveragePanel coverage={coverage} enabled={current.enabled} />}
    />
  );
}

/**
 * How much of the product exists in this language, per domain.
 *
 * The answer to "can I turn this on yet?", which is the only question anybody
 * brings to a language record.
 */
function CoveragePanel({
  coverage,
  enabled,
}: {
  coverage: RecordResource<LanguageCoverage | null>;
  enabled: boolean;
}) {
  const t = useTranslation('admin');

  const resource: RecordResource<readonly DomainCoverage[]> =
    coverage.status === 'ready'
      ? { status: 'ready', value: coverage.value?.domains ?? [] }
      : coverage;

  return (
    <RecordCollectionPanel
      testId="language-record-coverage"
      title={t('languages.record.coverage.title', 'Translation coverage')}
      subtitle={t(
        'languages.record.coverage.subtitle',
        'How much of each part of the product exists in this language.'
      )}
      resource={resource}
      // Two different silences, said differently. A switched-off language is
      // absent from the report by design, and reporting that as "nothing is
      // translated" would send someone to translate work that may already exist.
      emptyLabel={
        enabled
          ? t(
              'languages.record.coverage.empty',
              'Nothing is reported for this language yet — no source keys have reached it.'
            )
          : t(
              'languages.record.coverage.disabled',
              'Coverage is reported for languages that are switched on, so there is nothing to show while this one is off.'
            )
      }
      placeholderRows={3}
    >
      {(domains) => (
        <RecordList>
          {domains.map((domain) => (
            <RecordListItem
              key={domain.domain}
              // A domain name is a machine identifier the operator also sees in
              // the CLI (`common`, `admin`, `acme:catalog`) — rendered verbatim.
              primary={domain.domain}
              secondary={t(
                'languages.record.coverage.summary',
                '{translated} of {total} translated',
                { translated: domain.translated, total: domain.total }
              )}
              action={
                domain.missing > 0 ? (
                  <span className="text-xs text-muted-foreground">
                    {t('languages.record.coverage.missing', '{count} missing', {
                      count: domain.missing,
                    })}
                  </span>
                ) : undefined
              }
            />
          ))}
        </RecordList>
      )}
    </RecordCollectionPanel>
  );
}
