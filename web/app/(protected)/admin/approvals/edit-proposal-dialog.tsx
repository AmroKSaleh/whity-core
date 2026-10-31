'use client';

/**
 * Edit, then approve (register 7.5a's Edit): change what the agent will send
 * before it runs. Plain values get a field each; anything nested stays as
 * JSON, which is honest about its shape rather than guessing a form for it.
 * Approving here sends the edited arguments; the server checks them all
 * again, as it does for an unedited approval.
 */

import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@whity-org/ui/dialog';
import { Button } from '@whity-org/ui/button';
import { Input } from '@whity-org/ui/input';
import { Textarea } from '@whity-org/ui/textarea';
import { Icon } from '@whity-org/ui/icon';
import { useTranslation } from '@whity-org/features/i18n';
import { humanizeKey, type AgentProposal } from '@/components/agents/proposals';

type Draft = Record<string, string>;

function isPlain(value: unknown): value is string | number | boolean | null {
  return value === null || ['string', 'number', 'boolean'].includes(typeof value);
}

function toDraft(args: Record<string, unknown>): Draft {
  return Object.fromEntries(
    Object.entries(args).map(([key, value]) => [
      key,
      isPlain(value) ? (value === null ? '' : String(value)) : JSON.stringify(value, null, 2),
    ]),
  );
}

/** Back to the types the agent sent: a number stays a number, nested stays parsed. */
function fromDraft(original: Record<string, unknown>, draft: Draft): Record<string, unknown> | string {
  const out: Record<string, unknown> = {};
  for (const [key, text] of Object.entries(draft)) {
    const was = original[key];
    if (typeof was === 'number') {
      const n = Number(text);
      if (text.trim() === '' || Number.isNaN(n)) return key;
      out[key] = n;
    } else if (typeof was === 'boolean') {
      out[key] = text === 'true';
    } else if (isPlain(was)) {
      out[key] = was === null && text === '' ? null : text;
    } else {
      try {
        out[key] = JSON.parse(text);
      } catch {
        return key;
      }
    }
  }
  return out;
}

export function EditProposalDialog({
  proposal,
  busy,
  onCancel,
  onApprove,
}: {
  proposal: AgentProposal;
  busy: boolean;
  onCancel: () => void;
  onApprove: (args: Record<string, unknown>) => void;
}) {
  const t = useTranslation('admin');
  const original = (proposal.arguments ?? {}) as Record<string, unknown>;
  const [draft, setDraft] = useState<Draft>(() => toDraft(original));
  const [invalid, setInvalid] = useState<string | null>(null);

  const submit = () => {
    const parsed = fromDraft(original, draft);
    if (typeof parsed === 'string') {
      setInvalid(parsed);
      return;
    }
    onApprove(parsed);
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onCancel()}>
      <DialogContent closeLabel={t('approvals.editDialog.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('approvals.editDialog.title', 'Edit before approving')}</DialogTitle>
          <DialogDescription>
            {t('approvals.editDialog.description', 'Change what it will send. It runs once you approve.')}
          </DialogDescription>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault();
            submit();
          }}
        >
          <p className="text-sm font-medium">{proposal.summary}</p>
          {Object.keys(draft).length === 0 && (
            <p className="text-sm text-muted-foreground">
              {t('approvals.editDialog.nothing', 'This request has nothing to change.')}
            </p>
          )}
          {Object.entries(draft).map(([key, value]) => {
            const id = `proposal-arg-${key}`;
            const nested = !isPlain(original[key]);
            return (
              <div key={key} className="grid gap-1.5">
                <label htmlFor={id} className="text-sm font-medium">
                  {humanizeKey(key)}
                </label>
                {nested ? (
                  <Textarea
                    id={id}
                    value={value}
                    rows={4}
                    dir="ltr"
                    className="font-mono text-xs"
                    aria-invalid={invalid === key || undefined}
                    onChange={(event) => {
                      setInvalid(null);
                      setDraft({ ...draft, [key]: event.target.value });
                    }}
                  />
                ) : (
                  <Input
                    id={id}
                    value={value}
                    inputMode={typeof original[key] === 'number' ? 'decimal' : undefined}
                    aria-invalid={invalid === key || undefined}
                    onChange={(event) => {
                      setInvalid(null);
                      setDraft({ ...draft, [key]: event.target.value });
                    }}
                  />
                )}
                {invalid === key && (
                  <p className="text-sm text-error-text">
                    {nested
                      ? t('approvals.editDialog.invalidJson', 'This needs to stay valid JSON.')
                      : t('approvals.editDialog.invalidNumber', 'This needs to be a number.')}
                  </p>
                )}
              </div>
            );
          })}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onCancel} disabled={busy}>
              {t('approvals.editDialog.cancel', 'Cancel')}
            </Button>
            <Button type="submit" disabled={busy}>
              <Icon name="check" size={16} />
              {t('approvals.editDialog.approve', 'Approve with changes')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
