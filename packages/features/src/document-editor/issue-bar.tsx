'use client';

import * as React from 'react';

import { Button } from '@whity-org/ui/button';
import { Icon } from '@whity-org/ui/icon';
import { useTranslation } from '../i18n';

/** A field the issuing person fills in: one of the template's own fields. */
export interface IssueField {
  key: string;
  label: string;
  sample?: string;
}

/**
 * The issuing bar (editor M4c): what a document will be issued for, and the
 * one way to issue it. It sits in the screen's top slot, above the editor's
 * header — the slot the save-conflict bar uses — so the header keeps its
 * own controls (mode, Preview, PDF) and Issue is the only filled button in
 * view: Publish is not offered while issuing.
 *
 * Start side: the record (its name and Change, or Choose a record) and the
 * template's own fields as compact inputs. End side: Edit before issuing
 * (templates only, outline) and Issue (filled). A refusal shows under it in
 * one line, naming the fields that need a value in the template's own words.
 */
export function IssueBar({
  recordType,
  recordLabel,
  onChangeRecord,
  fields,
  values,
  onValue,
  onIssue,
  issuing,
  canIssue,
  onEditCopy,
  copying,
  notice,
  refusal,
}: {
  /** The record type's name ("Invoice"), or null when the template needs no record. */
  recordType: string | null;
  /** The chosen record's name, or null while none is chosen. */
  recordLabel: string | null;
  onChangeRecord?: () => void;
  fields: IssueField[];
  values: Record<string, string>;
  onValue: (key: string, value: string) => void;
  onIssue: () => void;
  issuing: boolean;
  canIssue: boolean;
  /** Templates only: copy the published version as a letter to edit, then issue that. */
  onEditCopy?: () => void;
  copying?: boolean;
  /** Why issuing can't happen yet (a template never published). */
  notice?: string | null;
  refusal: { message: string; missing: string[] } | null;
}) {
  const t = useTranslation('documents');

  return (
    <div data-slot="issue-bar" className="grid gap-1.5 border-b border-border bg-card px-4 py-2">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        {recordType !== null && (
          <div className="flex min-w-0 items-center gap-2 text-[0.8125rem]">
            <span className="text-muted-foreground">{t('blockEditor.issue.record', 'For')}</span>
            {recordLabel ? (
              <span className="max-w-64 truncate font-medium" dir="auto" data-testid="issue-record">
                {recordLabel}
              </span>
            ) : (
              <span className="text-muted-foreground">{t('blockEditor.issue.noRecord', 'No {type} chosen', { type: recordType })}</span>
            )}
            {onChangeRecord && (
              <Button variant="outline" size="xs" onClick={onChangeRecord}>
                {recordLabel ? t('blockEditor.issue.changeRecord', 'Change') : t('blockEditor.issue.chooseRecord', 'Choose a record')}
              </Button>
            )}
          </div>
        )}

        {fields.map((field) => (
          <label key={field.key} className="flex items-center gap-2 text-[0.8125rem]">
            <span className="text-muted-foreground" dir="auto">
              {field.label || field.key}
            </span>
            <input
              id={`issue-field-${field.key}`}
              dir="auto"
              value={values[field.key] ?? ''}
              placeholder={field.sample ?? ''}
              onChange={(e) => onValue(field.key, e.target.value)}
              className="h-7 w-44 min-w-0 rounded-md border border-input bg-card px-2.5 text-xs font-medium text-foreground outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
            />
          </label>
        ))}

        <span className="min-w-0 flex-1" />

        {onEditCopy && (
          <Button variant="outline" size="sm" icon="edit" loading={copying} disabled={copying || issuing} onClick={onEditCopy}>
            {t('blockEditor.issue.editCopy', 'Edit before issuing')}
          </Button>
        )}
        <Button size="sm" icon="check" loading={issuing} disabled={!canIssue || issuing} onClick={onIssue}>
          {t('blockEditor.issue.submit', 'Issue')}
        </Button>
      </div>

      {notice && <p className="m-0 text-[0.8125rem] text-muted-foreground">{notice}</p>}

      {refusal && (
        <p role="alert" className="m-0 flex items-start gap-1.5 text-[0.8125rem] text-error-text">
          <Icon name="alert" size={14} className="mt-0.5 shrink-0" />
          <span>
            {refusal.message}
            {refusal.missing.length > 0 && !/[.!?؟]$/.test(refusal.message) && '.'}
            {refusal.missing.length > 0 && (
              <>
                {' '}
                {t('blockEditor.issue.missing', 'Needs a value: {fields}.', { fields: refusal.missing.join(', ') })}
              </>
            )}
          </span>
        </p>
      )}
    </div>
  );
}
