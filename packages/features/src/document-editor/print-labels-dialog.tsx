'use client';

import * as React from 'react';

import { Button } from '@whity-org/ui/button';
import { Checkbox } from '@whity-org/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity-org/ui/dialog';
import type { LabelPrintSource } from '@whity-org/ui/editor/contract';
import type { FieldGroup } from '@whity-org/ui/editor/fields';
import { Icon } from '@whity-org/ui/icon';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity-org/ui/select';
import { ToggleGroup } from '@whity-org/ui/toggle-group';
import { useTranslation } from '../i18n';
import type { LabelPrintResult, RecordChoice } from './types';

const NOT_PRINTED = '__none';

/**
 * The first row of a CSV: its column names. Commas, semicolons and tabs are
 * recognised as the server does (LabelCsv), and quoted names may hold them.
 */
export function csvHeader(text: string): string[] {
  const clean = text.startsWith('﻿') ? text.slice(1) : text;
  const line = clean.split(/\r?\n/, 1)[0] ?? '';
  const counts = [',', ';', '\t'].map((d) => [d, line.split(d).length - 1] as const);
  const delimiter = counts.reduce((best, c) => (c[1] > best[1] ? c : best))[0];
  const out: string[] = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (quoted) {
      if (ch === '"' && line[i + 1] === '"') {
        cell += '"';
        i++;
      } else if (ch === '"') quoted = false;
      else cell += ch;
    } else if (ch === '"') quoted = true;
    else if (ch === delimiter) {
      out.push(cell.trim());
      cell = '';
    } else cell += ch;
  }
  out.push(cell.trim());
  return out.filter((name) => name !== '');
}

/** A column's field, guessed from its name: the field whose label or path's last part matches. */
export function guessField(column: string, groups: FieldGroup[]): string | null {
  const norm = (s: string) => s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
  const want = norm(column);
  if (!want) return null;
  for (const group of groups) {
    for (const field of group.items) {
      if (norm(field.label) === want || norm(field.path.split('.').pop() ?? '') === want) return field.path;
    }
  }
  return null;
}

/**
 * Print a label sheet (E.43): one label per record the reader picks, or per
 * row of a CSV with its columns matched to the template's fields. The CSV is
 * sent with the print and never stored.
 */
export function PrintLabelsDialog({
  open,
  onOpenChange,
  fields,
  findRecords,
  canUseRecords,
  onPrint,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  fields: FieldGroup[];
  findRecords: (query: string) => Promise<RecordChoice[]>;
  /** Whether the template has a record type to print records of. */
  canUseRecords: boolean;
  onPrint: (source: LabelPrintSource) => Promise<LabelPrintResult>;
}) {
  const t = useTranslation('documents');
  const [from, setFrom] = React.useState<'records' | 'csv'>(canUseRecords ? 'records' : 'csv');
  const [query, setQuery] = React.useState('');
  const [choices, setChoices] = React.useState<RecordChoice[] | null>(null);
  const [picked, setPicked] = React.useState<Map<string, RecordChoice>>(new Map());
  const [file, setFile] = React.useState<File | null>(null);
  const [columns, setColumns] = React.useState<string[]>([]);
  const [map, setMap] = React.useState<Record<string, string>>({});
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [done, setDone] = React.useState<LabelPrintResult | null>(null);
  const input = React.useRef<HTMLInputElement>(null);

  React.useEffect(() => {
    if (!open) return;
    setError(null);
    setDone(null);
  }, [open]);

  React.useEffect(() => {
    if (!open || from !== 'records') return;
    let live = true;
    const timer = setTimeout(() => {
      findRecords(query).then(
        (found) => live && setChoices(found),
        () => live && setChoices([])
      );
    }, 200);
    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [open, from, query, findRecords]);

  const allFields = fields.flatMap((g) => g.items);
  const mapped = Object.fromEntries(Object.entries(map).filter(([, path]) => path !== NOT_PRINTED));
  const ready = from === 'records' ? picked.size > 0 : !!file && Object.keys(mapped).length > 0;

  const chooseFile = async (next: File | null) => {
    setFile(next);
    setError(null);
    if (!next) return;
    const head = csvHeader(await next.slice(0, 64 * 1024).text());
    setColumns(head);
    setMap(Object.fromEntries(head.map((c) => [c, guessField(c, fields) ?? NOT_PRINTED])));
  };

  const print = async () => {
    setBusy(true);
    setError(null);
    setDone(null);
    try {
      const source: LabelPrintSource =
        from === 'records'
          ? {
              kind: 'records',
              records: [...picked.keys()].map((ref) => {
                const at = ref.indexOf(':');
                return { type: ref.slice(0, at), id: ref.slice(at + 1) };
              }),
            }
          : { kind: 'csv', file: file!, columns: mapped };
      setDone(await onPrint(source));
    } catch (e) {
      setError(e instanceof Error && e.message ? e.message : t('blockEditor.labelPrint.failed', 'The labels didn’t print. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  const toggle = (choice: RecordChoice, on: boolean) =>
    setPicked((prev) => {
      const next = new Map(prev);
      if (on) next.set(choice.ref, choice);
      else next.delete(choice.ref);
      return next;
    });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[520px]" closeLabel={t('blockEditor.labelPrint.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('blockEditor.labelPrint.title', 'Print labels')}</DialogTitle>
          <DialogDescription>{t('blockEditor.labelPrint.description', 'One label for each record or each row of a CSV file.')}</DialogDescription>
        </DialogHeader>

        {canUseRecords && (
          <ToggleGroup
            size="sm"
            label={t('blockEditor.labelPrint.from', 'Print from')}
            required
            value={from}
            onChange={(v) => v && setFrom(v as 'records' | 'csv')}
            options={[
              { value: 'records', label: t('blockEditor.labelPrint.records', 'Records') },
              { value: 'csv', label: t('blockEditor.labelPrint.csv', 'CSV file') },
            ]}
          />
        )}

        {from === 'records' ? (
          <div className="grid gap-2">
            <label className="flex items-center gap-2 rounded-control border border-input px-2.5 text-muted-foreground">
              <Icon name="search" size={16} />
              <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t('blockEditor.recordPicker.search', 'Search records')}
                aria-label={t('blockEditor.recordPicker.search', 'Search records')}
                className="h-9 min-w-0 flex-1 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
              />
            </label>
            <div className="grid max-h-[260px] gap-0.5 overflow-y-auto" role="group" aria-label={t('blockEditor.labelPrint.records', 'Records')}>
              {choices?.length === 0 && (
                <p className="m-0 px-2 py-3 text-sm text-muted-foreground">{t('blockEditor.recordPicker.none', 'No records match')}</p>
              )}
              {choices?.map((choice) => (
                <label key={choice.ref} className="flex cursor-pointer items-center gap-2.5 rounded-control px-2 py-1.5 text-sm hover:bg-accent">
                  <Checkbox checked={picked.has(choice.ref)} onCheckedChange={(on) => toggle(choice, on === true)} />
                  <span dir="auto">{choice.label}</span>
                </label>
              ))}
            </div>
            <span className="text-xs text-muted-foreground">
              {picked.size === 1
                ? t('blockEditor.labelPrint.oneRecord', '1 record selected')
                : t('blockEditor.labelPrint.nRecords', '{n} records selected').replace('{n}', String(picked.size))}
            </span>
          </div>
        ) : (
          <div className="grid gap-3">
            <div className="flex items-center gap-2">
              <input
                ref={input}
                type="file"
                accept=".csv,text/csv,text/plain"
                className="hidden"
                onChange={(e) => void chooseFile(e.target.files?.[0] ?? null)}
              />
              <Button variant="outline" size="sm" icon="upload" onClick={() => input.current?.click()}>
                {file ? t('blockEditor.labelPrint.changeFile', 'Choose another file') : t('blockEditor.labelPrint.chooseFile', 'Choose a CSV file')}
              </Button>
              {file && (
                <span className="min-w-0 truncate text-sm" dir="auto">
                  {file.name}
                </span>
              )}
            </div>
            {file && columns.length === 0 && (
              <p role="alert" className="m-0 text-sm text-error-text">
                {t('blockEditor.labelPrint.noHeader', 'The first row of the file should name its columns.')}
              </p>
            )}
            {columns.length > 0 && (
              <div className="grid gap-1.5">
                <span className="text-xs font-semibold text-foreground">{t('blockEditor.labelPrint.match', 'Match each column to a field')}</span>
                <div className="grid max-h-[240px] gap-1.5 overflow-y-auto">
                  {columns.map((column) => (
                    <div key={column} className="grid grid-cols-2 items-center gap-2">
                      <span className="truncate text-sm" dir="auto">
                        {column}
                      </span>
                      <Select value={map[column] ?? NOT_PRINTED} onValueChange={(v) => setMap((m) => ({ ...m, [column]: v }))}>
                        <SelectTrigger size="sm" aria-label={column}>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value={NOT_PRINTED}>{t('blockEditor.labelPrint.notPrinted', 'Not printed')}</SelectItem>
                          {allFields.map((field) => (
                            <SelectItem key={field.path} value={field.path}>
                              {field.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  ))}
                </div>
                <span className="text-xs text-muted-foreground">
                  {t('blockEditor.labelPrint.notStored', 'The file is used for this print only and isn’t kept.')}
                </span>
              </div>
            )}
          </div>
        )}

        {error && (
          <p role="alert" className="m-0 flex items-start gap-1.5 text-sm text-error-text">
            <Icon name="alert" size={16} className="mt-0.5 shrink-0" />
            {error}
          </p>
        )}
        {done && (
          <p role="status" className="m-0 text-sm text-muted-foreground">
            {(done.count === 1
              ? t('blockEditor.labelPrint.printedOne', '1 label printed.')
              : t('blockEditor.labelPrint.printed', '{n} labels printed.').replace('{n}', String(done.count))) +
              (done.clipped > 0
                ? ' ' + t('blockEditor.labelPrint.clipped', '{n} didn’t fit their label and were cut off.').replace('{n}', String(done.clipped))
                : '')}
          </p>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('blockEditor.labelPrint.cancel', 'Cancel')}
          </Button>
          <Button icon="print" disabled={!ready || busy} onClick={() => void print()}>
            {busy ? t('blockEditor.labelPrint.printing', 'Printing…') : t('blockEditor.labelPrint.print', 'Print')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
