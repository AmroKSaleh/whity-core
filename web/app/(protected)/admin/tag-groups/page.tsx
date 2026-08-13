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
  const canManage = hasPermission(TAGS_MANAGE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/tag-groups');
    if (!response.ok) {
      throw new Error('Failed to fetch tag groups');
    }
    const body = await response.json();
    return (body.data ?? []) as TagGroup[];
  }, [apiClient]);

  const [editing, setEditing] = useState<TagGroup | 'new' | null>(null);
  const [deleting, setDeleting] = useState<TagGroup | null>(null);

  const rows = (data ?? []).map((g) => ({
    ...g,
    displayLabel: g.display_name?.en || g.display_name?.ar || '—',
  }));
  type Row = (typeof rows)[number];

  const columns: DataTableColumn<Row>[] = [
    { accessorKey: 'key', header: 'Key', enableSorting: true, enableColumnFilter: true },
    { accessorKey: 'displayLabel', header: 'Display name', enableSorting: true },
  ];

  const rowActions = (group: Row) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${group.key}`}>
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => setEditing(group)}>Edit</DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => setDeleting(group)}
          className="text-destructive focus:text-destructive"
        >
          Delete
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Tag Groups"
        description="Named buckets of tags (e.g. priority, department). Labels are bilingual."
        action={
          canManage ? (
            <Button onClick={() => setEditing('new')} className="gap-2">
              <IconPlus size={18} />
              Create Tag Group
            </Button>
          ) : undefined
        }
      />

      {error !== null ? (
        <p className="text-sm text-destructive">Failed to load tag groups.</p>
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          getRowId={(g) => String(g.id)}
          rowActions={canManage ? rowActions : undefined}
          isLoading={loading}
          enableGlobalFilter
          globalFilterPlaceholder="Search tag groups…"
          pagination={{ pageSize: 10 }}
        />
      )}

      {editing !== null && (
        <TagGroupDialog
          group={editing === 'new' ? null : editing}
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

/** Create (group=null) or edit a tag group. Conditionally mounted, so form
 *  state seeds from props via useState initializers — no seeding effect. */
function TagGroupDialog({
  group,
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  group: TagGroup | null;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = group !== null;
  const [key, setKey] = useState(group?.key ?? '');
  const [displayName, setDisplayName] = useState<BilingualValue>(group?.display_name ?? {});
  const [keyError, setKeyError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    const trimmed = key.trim();
    if (!KEY_PATTERN.test(trimmed)) {
      setKeyError('Key must be a token of up to 64 chars (letters, digits, _.:-).');
      return;
    }
    setKeyError(null);
    setSubmitting(true);
    try {
      const payload = {
        key: trimmed,
        display_name: { ar: displayName.ar ?? '', en: displayName.en ?? '' },
      };
      const response = isEdit
        ? await apiClient(`/api/v1/tag-groups/${group.id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          })
        : await apiClient('/api/v1/tag-groups', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });
      if (response.status === 409) {
        setKeyError('A tag group with this key already exists.');
        return;
      }
      if (!response.ok) {
        throw new Error('Save failed');
      }
      addToast(isEdit ? 'Tag group updated' : 'Tag group created', 'success');
      onSaved();
    } catch {
      addToast('Failed to save tag group', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit Tag Group' : 'Create Tag Group'}</DialogTitle>
          <DialogDescription>
            A key identifies the group; the display name is shown to users.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="tag-group-key" className="text-sm font-medium">Key</label>
            <Input
              id="tag-group-key"
              value={key}
              onChange={(e) => setKey(e.target.value)}
              placeholder="e.g. priority"
              autoComplete="off"
            />
            {keyError !== null && <p className="text-xs text-destructive">{keyError}</p>}
          </div>

          <div className="space-y-1.5">
            <label htmlFor="tag-group-display-name" className="text-sm font-medium">Display name</label>
            <BilingualInput id="tag-group-display-name" value={displayName} onChange={setDisplayName} />
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="button" onClick={() => void submit()} disabled={submitting}>
            {submitting ? 'Saving…' : isEdit ? 'Save' : 'Create'}
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
  const [submitting, setSubmitting] = useState(false);

  const confirm = async () => {
    setSubmitting(true);
    try {
      const response = await apiClient(`/api/v1/tag-groups/${group.id}`, { method: 'DELETE' });
      if (!response.ok) {
        throw new Error('Delete failed');
      }
      addToast('Tag group deleted', 'success');
      onDeleted();
    } catch {
      addToast('Failed to delete tag group', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete tag group “{group.key}”?</DialogTitle>
          <DialogDescription>
            This permanently deletes the group and all of its tags, and removes those tags from every entity they are attached to.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="button" variant="destructive" onClick={() => void confirm()} disabled={submitting}>
            {submitting ? 'Deleting…' : 'Delete'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
