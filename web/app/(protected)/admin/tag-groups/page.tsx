'use client';

import { useCallback, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TAGS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import { BilingualInput, type BilingualValue } from '@amroksaleh/ui/bilingual-input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';

/** A tag group as returned by GET /api/v1/tag-groups. */
interface TagGroup {
  id: number;
  key: string;
  display_name: BilingualValue;
}

const KEY_PATTERN = /^[A-Za-z0-9_.:-]{1,64}$/;

type ApiClient = ReturnType<typeof useAuth>['apiClient'];
type AddToast = ReturnType<typeof useToast>['addToast'];

/**
 * Tag groups admin (WC-621) — bespoke page. Core resources use hand-written
 * admin pages (like roles/users), not the plugin-only schema-driven CrudScreen:
 * that renders from the DYNAMIC /api/openapi.json, which describes only plugin
 * routes, so it cannot derive fields for a core resource. Here we fetch + render
 * directly via apiClient. `display_name` edits through the shared BilingualInput;
 * writes gate on tags:manage (server stays authoritative).
 */
export default function TagGroupsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canManage = hasPermission(TAGS_MANAGE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/tag-groups');
    if (!response.ok) {
      throw new Error(t('tagGroups.error.load', 'Failed to fetch tag groups'));
    }
    const body = await response.json();
    return (body.data ?? []) as TagGroup[];
  }, [apiClient]);

  const router = useRouter();

  // Only 'new' or closed now: editing an existing group happens on its record
  // page (#882/#884), which is the only place the KEY has ever been editable.
  // Creation stays a dialog — a group that does not exist yet has no id, so
  // there is no address to send anybody.
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<TagGroup | null>(null);

  /** #882: open the group's RECORD PAGE. */
  const openRecord = useCallback(
    (group: { id: number }) => {
      router.push(`/admin/tag-groups/${group.id}`);
    },
    [router]
  );

  const rows = (data ?? []).map((g) => ({
    ...g,
    displayLabel: g.display_name?.en || g.display_name?.ar || '—',
  }));
  type Row = (typeof rows)[number];

  const columns: DataTableColumn<Row>[] = [
    {
      accessorKey: 'key',
      header: t('tagGroups.table.key', 'Key'),
      enableSorting: true,
      enableColumnFilter: true,
      // #882: the key opens the record. The key rather than the display label,
      // because a group with neither language filled in has no label to click.
      cell: (group) => (
        <button
          type="button"
          onClick={() => openRecord(group)}
          className="text-start font-medium text-primary underline-offset-4 hover:underline"
        >
          {group.key}
        </button>
      ),
    },
    {
      accessorKey: 'displayLabel',
      header: t('tagGroups.table.displayName', 'Display name'),
      enableSorting: true,
    },
  ];

  const rowActions = (group: Row) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('tagGroups.rowActions.label', 'Actions for {key}', { key: group.key })}
        >
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => openRecord(group)}>
          {t('tagGroups.rowActions.edit', 'Edit')}
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => setDeleting(group)}
          className="text-destructive focus:text-destructive"
        >
          {t('tagGroups.rowActions.delete', 'Delete')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('tagGroups.title', 'Tag Groups')}
        description={t(
          'tagGroups.description',
          'Named buckets of tags (e.g. priority, department). Labels are bilingual.'
        )}
        action={
          canManage ? (
            <Button onClick={() => setCreating(true)} className="gap-2">
              <IconPlus size={18} />
              {t('tagGroups.createButton', 'Create Tag Group')}
            </Button>
          ) : undefined
        }
      />

      {error !== null ? (
        <p className="text-sm text-destructive">
          {t('tagGroups.loadError', 'Failed to load tag groups.')}
        </p>
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          getRowId={(g) => String(g.id)}
          rowActions={canManage ? rowActions : undefined}
          isLoading={loading}
          enableGlobalFilter
          globalFilterPlaceholder={t('tagGroups.searchPlaceholder', 'Search tag groups…')}
          pagination={{ pageSize: 10 }}
        />
      )}

      {creating && (
        <CreateTagGroupDialog
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            refetch();
          }}
        />
      )}

      {deleting !== null && (
        <DeleteTagGroupDialog
          group={deleting}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            refetch();
          }}
        />
      )}
    </div>
  );
}

/**
 * Create a tag group.
 *
 * CREATE ONLY since #882/#884: editing an existing group is `/admin/tag-groups/
 * [id]`, which shows the tags inside it while you rename it — context a dialog
 * over the list has nowhere to put. A record that does not exist yet has no id
 * and therefore no address, which is why creation stays here.
 */
function CreateTagGroupDialog({
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const [key, setKey] = useState('');
  const [displayName, setDisplayName] = useState<BilingualValue>({});
  const [keyError, setKeyError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    const trimmed = key.trim();
    if (!KEY_PATTERN.test(trimmed)) {
      setKeyError(
        t(
          'tagGroups.form.key.invalid',
          'Key must be a token of up to 64 chars (letters, digits, _.:-).'
        )
      );
      return;
    }
    setKeyError(null);
    setSubmitting(true);
    try {
      const payload = {
        key: trimmed,
        display_name: { ar: displayName.ar ?? '', en: displayName.en ?? '' },
      };
      const response = await apiClient('/api/v1/tag-groups', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (response.status === 409) {
        setKeyError(t('tagGroups.form.key.conflict', 'A tag group with this key already exists.'));
        return;
      }
      if (!response.ok) {
        throw new Error('Save failed');
      }
      addToast(t('tagGroups.form.created', 'Tag group created'), 'success');
      onSaved();
    } catch {
      addToast(t('tagGroups.form.error', 'Failed to save tag group'), 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('tagGroups.form.createTitle', 'Create Tag Group')}</DialogTitle>
          <DialogDescription>
            {t(
              'tagGroups.form.description',
              'A key identifies the group; the display name is shown to users.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="tag-group-key" className="text-sm font-medium">
              {t('tagGroups.form.key.label', 'Key')}
            </label>
            <Input
              id="tag-group-key"
              value={key}
              onChange={(e) => setKey(e.target.value)}
              placeholder={t('tagGroups.form.key.placeholder', 'e.g. priority')}
              autoComplete="off"
            />
            {keyError !== null && <p className="text-xs text-destructive">{keyError}</p>}
          </div>

          <div className="space-y-1.5">
            <label htmlFor="tag-group-display-name" className="text-sm font-medium">
              {t('tagGroups.form.displayName.label', 'Display name')}
            </label>
            <BilingualInput id="tag-group-display-name" value={displayName} onChange={setDisplayName} />
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('tagGroups.form.cancel', 'Cancel')}
          </Button>
          <Button type="button" onClick={() => void submit()} disabled={submitting}>
            {submitting
              ? t('tagGroups.form.saving', 'Saving…')
              : t('tagGroups.form.create', 'Create')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function DeleteTagGroupDialog({
  group,
  apiClient,
  addToast,
  onClose,
  onDeleted,
}: {
  group: TagGroup;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const t = useTranslation('admin');
  const [submitting, setSubmitting] = useState(false);

  const confirm = async () => {
    setSubmitting(true);
    try {
      const response = await apiClient(`/api/v1/tag-groups/${group.id}`, { method: 'DELETE' });
      if (!response.ok) {
        throw new Error('Delete failed');
      }
      addToast(t('tagGroups.delete.success', 'Tag group deleted'), 'success');
      onDeleted();
    } catch {
      addToast(t('tagGroups.delete.error', 'Failed to delete tag group'), 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t('tagGroups.delete.title', 'Delete tag group “{key}”?', { key: group.key })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'tagGroups.delete.description',
              'This permanently deletes the group and all of its tags, and removes those tags from every entity they are attached to.'
            )}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('tagGroups.delete.cancel', 'Cancel')}
          </Button>
          <Button type="button" variant="destructive" onClick={() => void confirm()} disabled={submitting}>
            {submitting
              ? t('tagGroups.delete.pending', 'Deleting…')
              : t('tagGroups.delete.confirm', 'Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
