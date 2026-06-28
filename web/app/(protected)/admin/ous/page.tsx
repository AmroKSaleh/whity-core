'use client';

import { useEffect, useMemo, useState } from 'react';
import dynamic from 'next/dynamic';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { OUS_WRITE, OUS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@whity/ui/button';
import { Skeleton } from '@whity/ui/skeleton';
import { IconBinaryTree2, IconList, IconPlus } from '@tabler/icons-react';
import { CreateOuModal } from './create-modal';
import { EditOuModal } from './edit-modal';
import { DeleteOuModal } from './delete-modal';
import { OuTree } from './ou-tree';
import { buildOuTree, findNode } from './ou-tree-util';
import type { OuAction } from './ou-view';
import type { OU } from './types';

// react-flow is heavy and touches browser-only APIs, so the graph view is
// loaded on demand and never server-rendered (ssr:false). Keeping it behind a
// dynamic import keeps it out of the main bundle; only users who switch to the
// graph pay for it.
const OuGraph = dynamic(() => import('./ou-graph'), {
  ssr: false,
  loading: () => <Skeleton className="h-[32rem] w-full rounded-lg" />,
});

type ViewMode = 'tree' | 'graph';
const VIEW_STORAGE_KEY = 'wc:ous:view';

export default function OUsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const canCreate = hasPermission(OUS_WRITE);
  const canEdit = hasPermission(OUS_WRITE);
  const canDelete = hasPermission(OUS_DELETE);

  // Lazily restore the persisted view choice. The initializer runs on the
  // client (this is a 'use client' route); the typeof guard keeps it safe under
  // SSR pre-render, where window is undefined.
  const [view, setView] = useState<ViewMode>(() => {
    if (typeof window === 'undefined') {
      return 'tree';
    }
    const stored = window.localStorage.getItem(VIEW_STORAGE_KEY);
    return stored === 'tree' || stored === 'graph' ? stored : 'tree';
  });
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [createParentId, setCreateParentId] = useState<number | null>(null);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [actionOu, setActionOu] = useState<OU | null>(null);

  const selectView = (next: ViewMode) => {
    setView(next);
    window.localStorage.setItem(VIEW_STORAGE_KEY, next);
  };

  const { data, loading: isLoading, error, refetch: fetchOUs } = useFetch(async () => {
    const response = await apiClient('/api/v1/ous');
    if (!response.ok) {
      throw new Error('Failed to fetch organizational units');
    }
    const data = await response.json();
    return (data.data ?? []) as OU[];
  }, [apiClient]);

  const ous = useMemo(() => data ?? [], [data]);

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const tree = useMemo(() => buildOuTree(ous), [ous]);

  // The drawer needs the live OU record for the selected id (kept in sync with
  // refetches), looked up from the built tree.
  const selectedOu = useMemo(
    () => (selectedId !== null ? findNode(tree, selectedId) ?? null : null),
    [tree, selectedId]
  );

  const handleAction = (action: OuAction, ou: OU) => {
    setActionOu(ou);
    switch (action) {
      case 'create-child':
        setCreateParentId(ou.id);
        setIsCreateModalOpen(true);
        break;
      case 'edit':
      case 'move':
        // Both open the edit modal; "move" lands on the same dialog, whose
        // "Move to parent" picker excludes the OU and its descendants.
        setIsEditModalOpen(true);
        break;
      case 'delete':
        setIsDeleteModalOpen(true);
        break;
    }
  };

  const openCreateRoot = () => {
    setCreateParentId(null);
    setIsCreateModalOpen(true);
  };

  const ViewToggle = (
    <div role="group" aria-label="View mode" className="inline-flex rounded-md border border-border p-0.5">
      <Button
        variant={view === 'tree' ? 'secondary' : 'ghost'}
        size="sm"
        aria-pressed={view === 'tree'}
        onClick={() => selectView('tree')}
        className="gap-1.5"
      >
        <IconList />
        Tree
      </Button>
      <Button
        variant={view === 'graph' ? 'secondary' : 'ghost'}
        size="sm"
        aria-pressed={view === 'graph'}
        onClick={() => selectView('graph')}
        className="gap-1.5"
      >
        <IconBinaryTree2 />
        Graph
      </Button>
    </div>
  );

  const isEmpty = !isLoading && ous.length === 0;

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Organizational Units"
        description="Visualize and manage your organizational hierarchy, role assignments, and members."
        action={
          canCreate ? (
            <Button onClick={openCreateRoot} className="gap-2">
              <IconPlus />
              Create OU
            </Button>
          ) : undefined
        }
      />

      <div className="flex items-center justify-between">
        {ViewToggle}
      </div>

      {isLoading ? (
        <Skeleton className="h-64 w-full rounded-lg" />
      ) : isEmpty ? (
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <h2 className="font-heading text-sm font-medium">No organizational units yet</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            Create an organizational unit to structure your organization.
          </p>
          {canCreate && (
            <Button onClick={openCreateRoot} variant="outline" className="mt-4 gap-2">
              <IconPlus />
              Create the first OU
            </Button>
          )}
        </div>
      ) : view === 'tree' ? (
        <OuTree
          tree={tree}
          selectedId={selectedId}
          onSelect={setSelectedId}
          onAction={(action, node) => handleAction(action, node)}
          canCreate={canCreate}
          canEdit={canEdit}
          canDelete={canDelete}
        />
      ) : (
        <OuGraph
          tree={tree}
          selectedId={selectedId}
          onSelect={setSelectedId}
          onAction={(action, node) => handleAction(action, node)}
          canCreate={canCreate}
          canEdit={canEdit}
          canDelete={canDelete}
        />
      )}

      <OuDetailDrawerLazy
        ou={selectedOu}
        onClose={() => setSelectedId(null)}
        onAction={handleAction}
        onChanged={fetchOUs}
      />

      <CreateOuModal
        key={`create-${createParentId ?? 'root'}`}
        isOpen={isCreateModalOpen}
        onClose={() => {
          setIsCreateModalOpen(false);
          setCreateParentId(null);
        }}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          setCreateParentId(null);
          void fetchOUs();
        }}
        ous={ous}
        defaultParentId={createParentId}
      />

      {actionOu && (
        <EditOuModal
          key={actionOu.id}
          isOpen={isEditModalOpen}
          onClose={() => {
            setIsEditModalOpen(false);
            setActionOu(null);
          }}
          onSuccess={() => {
            setIsEditModalOpen(false);
            setActionOu(null);
            void fetchOUs();
          }}
          ou={actionOu}
          ous={ous}
        />
      )}

      {actionOu && (
        <DeleteOuModal
          isOpen={isDeleteModalOpen}
          onClose={() => {
            setIsDeleteModalOpen(false);
            setActionOu(null);
          }}
          onSuccess={() => {
            setIsDeleteModalOpen(false);
            setActionOu(null);
            setSelectedId((current) => (current === actionOu.id ? null : current));
            void fetchOUs();
          }}
          ou={actionOu}
        />
      )}
    </div>
  );
}

// The drawer fetches per-OU detail; it is a client component already, but
// importing it lazily keeps the initial admin route lean.
const OuDetailDrawerLazy = dynamic(
  () => import('./ou-detail-drawer').then((m) => m.OuDetailDrawer),
  { ssr: false }
);
