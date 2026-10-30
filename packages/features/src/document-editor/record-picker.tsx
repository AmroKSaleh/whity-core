'use client';

import * as React from 'react';

import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@whity-org/ui/dialog';
import { Icon } from '@whity-org/ui/icon';
import { cn } from '@whity-org/ui/utils';
import { useTranslation } from '../i18n';
import type { RecordChoice } from './types';

/**
 * Picks the record a template is previewed or printed with (E.12, "Change
 * record"): the reader's own records of the template's type, searchable.
 */
export function RecordPicker({
  open,
  onOpenChange,
  title,
  find,
  onPick,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  find: (query: string) => Promise<RecordChoice[]>;
  onPick: (choice: RecordChoice) => void;
}) {
  const t = useTranslation('documents');
  const [query, setQuery] = React.useState('');
  const [choices, setChoices] = React.useState<RecordChoice[] | null>(null);
  const [failed, setFailed] = React.useState(false);
  const [active, setActive] = React.useState(0);

  React.useEffect(() => {
    if (!open) return;
    let live = true;
    setFailed(false);
    const timer = setTimeout(() => {
      find(query).then(
        (found) => {
          if (!live) return;
          setChoices(found);
          setActive(0);
        },
        () => live && setFailed(true)
      );
    }, 200);
    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [open, query, find]);

  const pick = (choice: RecordChoice) => {
    onOpenChange(false);
    setQuery('');
    onPick(choice);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[440px]" closeLabel={t('blockEditor.recordPicker.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{t('blockEditor.recordPicker.description', 'The pages show what would print for this record.')}</DialogDescription>
        </DialogHeader>
        <label className="flex items-center gap-2 rounded-control border border-input px-2.5 text-muted-foreground">
          <Icon name="search" size={16} />
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => {
              const n = choices?.length ?? 0;
              if (!n) return;
              if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : n - 1)) % n);
              } else if (e.key === 'Enter') {
                e.preventDefault();
                pick(choices![active]);
              }
            }}
            placeholder={t('blockEditor.recordPicker.search', 'Search records')}
            aria-label={t('blockEditor.recordPicker.search', 'Search records')}
            aria-controls="wy-recordpick"
            className="h-9 min-w-0 flex-1 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
          />
        </label>
        <div id="wy-recordpick" role="listbox" aria-label={title} className="grid max-h-[300px] gap-0.5 overflow-y-auto">
          {failed && (
            <p role="alert" className="m-0 px-2 py-3 text-sm text-error-text">
              {t('blockEditor.recordPicker.failed', 'Records didn’t load. Try again.')}
            </p>
          )}
          {!failed && choices?.length === 0 && (
            <p className="m-0 px-2 py-3 text-sm text-muted-foreground">{t('blockEditor.recordPicker.none', 'No records match')}</p>
          )}
          {choices?.map((choice, i) => (
            <div
              key={choice.ref}
              role="option"
              aria-selected={i === active}
              onMouseMove={() => setActive(i)}
              onClick={() => pick(choice)}
              className={cn(
                'cursor-pointer rounded-control px-2.5 py-2 text-sm',
                i === active && 'bg-primary-tint text-primary-tint-foreground'
              )}
            >
              <span dir="auto">{choice.label}</span>
            </div>
          ))}
        </div>
      </DialogContent>
    </Dialog>
  );
}
