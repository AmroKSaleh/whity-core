'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TAGS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
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

interface Tag {
  id: number;
  group_id: number;
  name: string;
}

interface TagGroupOption {
  id: number;
  key: string;
  display_name?: { ar?: string; en?: string };
}

type ApiClient = ReturnType<typeof useAuth>['apiClient'];
type AddToast = ReturnType<typeof useToast>['addToast'];

const groupLabel = (g: TagGroupOption): string => g.display_name?.en || g.display_name?.ar || g.key;

/**
 * Tags admin (WC-621) — bespoke page (see tag-groups/page.tsx for why core
 * resources don't use the plugin-only CrudScreen). Creating a tag picks its
 * group from a real dropdown of the tenant's tag groups; the list resolves each
 * tag's group_id to a readable label. Writes gate on tags:manage.
 */
export default function TagsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canManage = hasPermission(TAGS_MANAGE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const [tagsRes, groupsRes] = await Promise.all([
      apiClient('/api/v1/tags'),
      apiClient('/api/v1/tag-groups'),
    ]);
    if (!tagsRes.ok || !groupsRes.ok) {
      throw new Error(t('tags.error.load', 'Failed to fetch tags'));
    }
    const tagsBody = await tagsRes.json();
    const groupsBody = await groupsRes.json();
    return {
      tags: (tagsBody.data ?? []) as Tag[],
      groups: (groupsBody.data ?? []) as TagGroupOption[],
    };
  }, [apiClient]);

  const groups = data?.groups ?? [];
  const groupById = new Map(groups.map((g) => [g.id, g]));

  const [editing, setEditing] = useState<Tag | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Tag | null>(null);

  const rows = (data?.tags ?? []).map((tag) => ({
    ...tag,
    groupLabel: groupById.has(tag.group_id)
      ? groupLabel(groupById.get(tag.group_id)!)
      : `#${tag.group_id}`,
  }));
  type Row = (typeof rows)[number];

  const columns: DataTableColumn<Row>[] = [
    {
      accessorKey: 'name',
      header: t('tags.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'groupLabel',
      header: t('tags.table.group', 'Group'),
      enableSorting: true,
      enableColumnFilter: true,
    },
  ];

  const rowActions = (tag: Row) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('tags.rowActions.label', 'Actions for {name}', { name: tag.name })}
        >
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => setEditing(tag)}>
          {t('tags.rowActions.rename', 'Rename')}
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => setDeleting(tag)}
          className="text-destructive focus:text-destructive"
        >
          {t('tags.rowActions.delete', 'Delete')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('tags.title', 'Tags')}
        description={t(
          'tags.description',
          'Individual tags inside a group. Attach them to entities with the tag picker.'
        )}
        action={
          canManage ? (
            <Button
              onClick={() => setEditing('new')}
              className="gap-2"
              disabled={groups.length === 0}
              title={
                groups.length === 0
                  ? t('tags.createDisabledHint', 'Create a tag group first')
                  : undefined
              }
            >
              <IconPlus size={18} />
              {t('tags.createButton', 'Create Tag')}
            </Button>
          ) : undefined
        }
      />

      {error !== null ? (
        <p className="text-sm text-destructive">{t('tags.loadError', 'Failed to load tags.')}</p>
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          getRowId={(tag) => String(tag.id)}
          rowActions={canManage ? rowActions : undefined}
          isLoading={loading}
          enableGlobalFilter
          globalFilterPlaceholder={t('tags.searchPlaceholder', 'Search tags…')}
          pagination={{ pageSize: 10 }}
        />
      )}

      {editing !== null && (
        <TagDialog
          tag={editing === 'new' ? null : editing}
          groups={groups}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            refetch();
          }}
        />
      )}

      {deleting !== null && (
        <DeleteTagDialog
          tag={deleting}
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

/** Create (tag=null) or rename a tag. The backend supports renaming only, so an
 *  existing tag's group is shown read-only; a new tag picks its group. */
function TagDialog({
  tag,
  groups,
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  tag: Tag | null;
  groups: TagGroupOption[];
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const isEdit = tag !== null;
  const [name, setName] = useState(tag?.name ?? '');
  const [groupId, setGroupId] = useState<string>(tag ? String(tag.group_id) : '');
  const [nameError, setNameError] = useState<string | null>(null);
  const [groupError, setGroupError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    const trimmed = name.trim();
    if (trimmed === '' || trimmed.length > 128) {
      setNameError(t('tags.form.name.invalid', 'Name is required (max 128 characters).'));
      return;
    }
    setNameError(null);
    if (!isEdit && groupId === '') {
      setGroupError(t('tags.form.group.required', 'Choose a group.'));
      return;
    }
    setGroupError(null);
    setSubmitting(true);
    try {
      const response = isEdit
        ? await apiClient(`/api/v1/tags/${tag.id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: trimmed }),
          })
        : await apiClient('/api/v1/tags', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ group_id: Number(groupId), name: trimmed }),
          });
      if (response.status === 409) {
        setNameError(
          t('tags.form.conflict', 'A tag with this name already exists in this group.')
        );
        return;
      }
      if (!response.ok) {
        throw new Error('Save failed');
      }
      addToast(
        isEdit ? t('tags.form.renamed', 'Tag renamed') : t('tags.form.created', 'Tag created'),
        'success'
      );
      onSaved();
    } catch {
      addToast(t('tags.form.error', 'Failed to save tag'), 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {isEdit
              ? t('tags.form.editTitle', 'Rename Tag')
              : t('tags.form.createTitle', 'Create Tag')}
          </DialogTitle>
          <DialogDescription>
            {isEdit
              ? t('tags.form.editDescription', 'Rename this tag.')
              : t('tags.form.createDescription', 'Add a tag to a group.')}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="tag-name" className="text-sm font-medium">
              {t('tags.form.name.label', 'Name')}
            </label>
            <Input
              id="tag-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('tags.form.name.placeholder', 'e.g. high')}
              autoComplete="off"
            />
            {nameError !== null && <p className="text-xs text-destructive">{nameError}</p>}
          </div>

          {!isEdit && (
            <div className="space-y-1.5">
              <label htmlFor="tag-group" className="text-sm font-medium">
                {t('tags.form.group.label', 'Group')}
              </label>
              <Select value={groupId} onValueChange={setGroupId}>
                <SelectTrigger id="tag-group" aria-label={t('tags.form.group.label', 'Group')}>
                  <SelectValue placeholder={t('tags.form.group.placeholder', 'Select a group')} />
                </SelectTrigger>
                <SelectContent>
                  {groups.map((g) => (
                    <SelectItem key={g.id} value={String(g.id)}>
                      {groupLabel(g)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {groupError !== null && <p className="text-xs text-destructive">{groupError}</p>}
            </div>
          )}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('tags.form.cancel', 'Cancel')}
          </Button>
          <Button type="button" onClick={() => void submit()} disabled={submitting}>
            {submitting
              ? t('tags.form.saving', 'Saving…')
              : isEdit
                ? t('tags.form.save', 'Save')
                : t('tags.form.create', 'Create')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function DeleteTagDialog({
  tag,
  apiClient,
  addToast,
  onClose,
  onDeleted,
}: {
  tag: Tag;
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
      const response = await apiClient(`/api/v1/tags/${tag.id}`, { method: 'DELETE' });
      if (!response.ok) {
        throw new Error('Delete failed');
      }
      addToast(t('tags.delete.success', 'Tag deleted'), 'success');
      onDeleted();
    } catch {
      addToast(t('tags.delete.error', 'Failed to delete tag'), 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t('tags.delete.title', 'Delete tag “{name}”?', { name: tag.name })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'tags.delete.description',
              'This permanently deletes the tag and removes it from every entity it is attached to.'
            )}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('tags.delete.cancel', 'Cancel')}
          </Button>
          <Button type="button" variant="destructive" onClick={() => void confirm()} disabled={submitting}>
            {submitting ? t('tags.delete.pending', 'Deleting…') : t('tags.delete.confirm', 'Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
