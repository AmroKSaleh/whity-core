'use client';

import { useMemo, useState } from 'react';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useTranslation } from '@whity-org/features/i18n';
import { Alert, AlertDescription, AlertTitle } from '@whity-org/ui/alert';
import { Button } from '@whity-org/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity-org/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { describeAudience, needsPublish, scopeLabel } from './audience';
import { patchTemplate, type AddToast, type ApiClient } from './api';
import { DOCUMENT_SCOPES, type DocumentScope, type OuOption, type PermissionSource, type TemplateRow } from './types';

/** The sentinel a `Select` uses for "no value" — Radix cannot hold an empty string. */
const NONE = '__none__';
/** The sentinel for "a permission that is not in the list I could offer". */
const CUSTOM = '__custom__';

/**
 * Change WHO CAN SEE a template or label sheet — the publishing decision.
 *
 * This is the screen's reason for existing, so three things are deliberate about
 * its shape.
 *
 * ONE DIALOG, THREE FIELDS, ONE GATE. Scope, placement and permission tag are
 * three independent columns, and every one of them is a publish action
 * server-side — including placement, and including placement on a PERSONAL row,
 * which is the non-obvious rule `DocumentAccessPolicy::needsPublish()` applies
 * (a placement an ordinary writer chose would silently acquire an audience the
 * moment somebody with publish rights promoted the scope). So they are edited
 * together behind one `documents:publish` gate rather than scattered into
 * separate actions with different gates, and the dialog says which combinations
 * still need publish as the fields change.
 *
 * THE CLIENT CHOOSES, THE SERVER DECIDES. The permission picker exists because
 * publishing tenant-wide is exactly where the RBAC decision gets made — a
 * manager's contract templates must not become visible to technicians — and that
 * choice cannot be inferred, only made. The server enforces it regardless
 * (`needsPublish` + the 403), so everything here is affordance and explanation.
 *
 * THE CONSEQUENCE IS SHOWN, NOT IMPLIED. The live audience sentence under the
 * fields is the same `describeAudience()` the table's tooltip uses, so the
 * preview and the resulting row can never describe themselves differently.
 *
 * Sharing changes who sees the PUBLISHED version; the draft stays its author's
 * work in progress (E.9).
 */
export function ScopeDialog({
  row,
  name,
  ous,
  ousUnavailable,
  permissionNames,
  permissionSource,
  viewerProfileId,
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  row: TemplateRow;
  /** The row's title as the page shows it ("Untitled" for an empty one). */
  name: string;
  ous: OuOption[];
  ousUnavailable: boolean;
  permissionNames: string[];
  permissionSource: PermissionSource;
  viewerProfileId: number | null;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');

  const [scope, setScope] = useState<DocumentScope>(row.scope);
  const [ouId, setOuId] = useState<number | null>(row.owner_ou_id);
  const [permission, setPermission] = useState<string | null>(row.required_permission);
  /**
   * Whether the tag is being typed rather than picked. Seeded true when the row
   * already carries a tag the offered list does not contain — otherwise reopening
   * the dialog on a row tagged with something outside the list would silently
   * show "None" and then clear the tag on save.
   */
  const [customTag, setCustomTag] = useState(
    row.required_permission !== null && !permissionNames.includes(row.required_permission)
  );
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const ouName = useMemo(
    () => (ouId === null ? null : (ous.find((o) => o.id === ouId)?.name ?? null)),
    [ouId, ous]
  );

  const preview = describeAudience(
    { scope, owner_ou_id: ouId, required_permission: permission, created_by: row.created_by },
    { ouName, viewerProfileId },
    t
  );

  const changed =
    scope !== row.scope || ouId !== row.owner_ou_id || permission !== row.required_permission;

  /**
   * Whether this row is about to become NARROWER than it is now: dropping out
   * of a shared scope, or gaining a permission tag where there was none. Not a
   * full re-derivation of reach, which depends on the whole unit tree and on
   * who holds what.
   */
  const narrowing =
    (row.scope !== 'personal' && scope === 'personal') ||
    (row.required_permission === null && permission !== null);

  const submit = async () => {
    if (!changed) {
      onClose();
      return;
    }
    if (customTag && permission !== null && permission.trim() === '') {
      setError(t('documentTemplates.scopeDialog.emptyTag', 'Enter a permission slug, or choose "None".'));
      return;
    }

    setError(null);
    setSubmitting(true);
    try {
      const failure = await patchTemplate(
        apiClient,
        row.id,
        {
          scope,
          owner_ou_id: ouId,
          required_permission: permission === null ? null : permission.trim(),
        },
        t('documentTemplates.scopeDialog.failed', 'Failed to save the visibility change')
      );
      if (failure !== null) {
        setError(failure);
        return;
      }
      addToast(
        t('documentTemplates.scopeDialog.saved', 'Visibility updated for {name}', { name }),
        'success'
      );
      onSaved();
    } catch {
      setError(t('documentTemplates.scopeDialog.failed', 'Failed to save the visibility change'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t('documentTemplates.scopeDialog.title', 'Who can see “{name}”', { name })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'documentTemplates.scopeDialog.description',
              'Two independent questions, and both must pass: where in the organisation this is filed, and what a person must hold to see it.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {/* ── scope ─────────────────────────────────────────────────────── */}
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="scope-select">
              {t('documentTemplates.scopeDialog.scope', 'Visibility tier')}
            </label>
            <Select value={scope} onValueChange={(value) => setScope(value as DocumentScope)}>
              <SelectTrigger id="scope-select" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {DOCUMENT_SCOPES.map((option) => (
                  <SelectItem key={option} value={option}>
                    {scopeLabel(option, t)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {scope === 'system' && (
              <p className="text-xs text-muted-foreground">
                {t(
                  'documentTemplates.scopeDialog.systemNote',
                  'System rows skip the permission gate entirely — everyone who reaches this sees it, tag or no tag.'
                )}
              </p>
            )}
          </div>

          {/* ── placement ─────────────────────────────────────────────────── */}
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="ou-select">
              {t('documentTemplates.scopeDialog.placement', 'Filed at')}
            </label>
            <Select
              value={ouId === null ? NONE : String(ouId)}
              onValueChange={(value) => setOuId(value === NONE ? null : Number(value))}
              disabled={ousUnavailable}
            >
              <SelectTrigger id="ou-select" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE}>
                  {t('documentTemplates.scopeDialog.noUnit', 'Not filed at a unit — the whole tenant')}
                </SelectItem>
                {ous.map((ou) => (
                  <SelectItem key={ou.id} value={String(ou.id)}>
                    {ou.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {ousUnavailable
                ? t(
                    'documentTemplates.scopeDialog.placementLocked',
                    'Placement cannot be changed here: listing units needs the ous:read permission, and offering an empty picker would let one click unfile this row.'
                  )
                : t(
                    'documentTemplates.scopeDialog.placementHint',
                    'Filing this at a unit is what makes a dean’s secretary and a department head’s secretary — who hold the same permissions — see different things. Reach runs downward, so a unit includes everything beneath it.'
                  )}
            </p>
          </div>

          {/* ── permission tag ────────────────────────────────────────────── */}
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="permission-select">
              {t('documentTemplates.scopeDialog.permission', 'Also requires')}
            </label>
            <Select
              value={customTag ? CUSTOM : (permission ?? NONE)}
              onValueChange={(value) => {
                if (value === CUSTOM) {
                  setCustomTag(true);
                  setPermission(permission ?? '');
                  return;
                }
                setCustomTag(false);
                setPermission(value === NONE ? null : value);
              }}
            >
              <SelectTrigger id="permission-select" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE}>
                  {t('documentTemplates.scopeDialog.noPermission', 'Nothing — anyone who reaches it')}
                </SelectItem>
                {permissionNames.map((name) => (
                  <SelectItem key={name} value={name}>
                    {name}
                  </SelectItem>
                ))}
                <SelectItem value={CUSTOM}>
                  {t('documentTemplates.scopeDialog.customPermission', 'Another permission…')}
                </SelectItem>
              </SelectContent>
            </Select>
            {customTag && (
              <Input
                aria-label={t('documentTemplates.scopeDialog.customLabel', 'Permission slug')}
                placeholder="documents:use:contracts"
                value={permission ?? ''}
                onChange={(event) => setPermission(event.target.value)}
              />
            )}
            <p className="text-xs text-muted-foreground">
              {permissionSource === 'catalogue'
                ? t(
                    'documentTemplates.scopeDialog.permissionCatalogue',
                    'From the permission catalogue. A tag is a NAME, never an id — ids are not stable across installs.'
                  )
                : t(
                    'documentTemplates.scopeDialog.permissionOwn',
                    'These are the permissions you hold; reading the full catalogue needs the admin role. Tagging with one you hold means you can still see what you published — an author’s own reach is waived for placement but never for the tag.'
                  )}
            </p>
          </div>
        </div>

        {/* ── the consequence ───────────────────────────────────────────────── */}
        <div className="rounded-md border border-border bg-muted/40 p-3">
          <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
            {t('documentTemplates.scopeDialog.previewLabel', 'After saving')}
          </p>
          <p className="mt-1 text-sm">{preview}</p>
          {changed && !needsPublish(scope, permission, ouId) && (
            <p className="mt-2 text-xs text-muted-foreground">
              {t(
                'documentTemplates.scopeDialog.noLongerShared',
                'These settings are an ordinary personal row — no publish capability is involved in this state.'
              )}
            </p>
          )}
        </div>

        {narrowing && (
          <Alert variant="warning">
            <IconAlertTriangle size={16} />
            <AlertTitle>{t('documentTemplates.scopeDialog.narrowTitle', 'This narrows access')}</AlertTitle>
            <AlertDescription>
              {t(
                'documentTemplates.scopeDialog.narrowBody',
                'People who can see this today may stop being able to. Nothing is deleted and nobody is notified.'
              )}
            </AlertDescription>
          </Alert>
        )}

        {error !== null && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
          <Button onClick={submit} loading={submitting} disabled={!changed}>
            {t('documentTemplates.scopeDialog.submit', 'Save visibility')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
