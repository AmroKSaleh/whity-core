'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import dynamic from 'next/dynamic';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import {
  IconBinaryTree2,
  IconList,
  IconPlus,
  IconShieldLock,
  IconUsersGroup,
} from '@tabler/icons-react';
import { CreatePersonModal } from './create-person-modal';
import { EditPersonModal } from './edit-person-modal';
import { DeletePersonModal } from './delete-person-modal';
import { AddRelationModal } from './add-relation-modal';
import type { PersonAction } from './relations-view';
import type { Person, RelationEdge, RelationshipType } from './types';
import { useCapabilities } from '@/hooks/useCapabilities';
import { RELATIONS_MANAGE } from '@/lib/capabilities';

// react-flow is heavy and touches browser-only APIs, so the graph view is loaded
// on demand and never server-rendered (ssr:false) — mirrors the OU hub.
const RelationsGraph = dynamic(() => import('./relations-graph'), {
  ssr: false,
  loading: () => <Skeleton className="h-[32rem] w-full rounded-lg" />,
});

const PersonDetailDrawerLazy = dynamic(
  () => import('./person-detail-drawer').then((m) => m.PersonDetailDrawer),
  { ssr: false }
);

type ViewMode = 'list' | 'graph';
const VIEW_STORAGE_KEY = 'wc:relations:view';

/** Row view-model for the persons table (display strings precomputed). */
interface PersonRow {
  id: number;
  name: string;
  account: string;
  relations: number;
  source: Person;
}

export default function RelationsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  // Caller's relations:manage capability (WC-177, WC-204). Fail-closed via
  // useCapabilities: write controls stay hidden until proven otherwise, so a
  // delegate holding only relations:read never sees affordances that would 403.
  const { hasPermission } = useCapabilities();
  const canManage = hasPermission(RELATIONS_MANAGE);

  const [persons, setPersons] = useState<Person[]>([]);
  const [edges, setEdges] = useState<RelationEdge[]>([]);
  const [types, setTypes] = useState<RelationshipType[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);

  // Lazily restore the persisted view (List default). The typeof guard keeps the
  // initializer safe under SSR pre-render where window is undefined.
  const [view, setView] = useState<ViewMode>(() => {
    if (typeof window === 'undefined') {
      return 'list';
    }
    const stored = window.localStorage.getItem(VIEW_STORAGE_KEY);
    return stored === 'list' || stored === 'graph' ? stored : 'list';
  });

  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isEditOpen, setIsEditOpen] = useState(false);
  const [isDeleteOpen, setIsDeleteOpen] = useState(false);
  const [isAddRelationOpen, setIsAddRelationOpen] = useState(false);
  const [actionPerson, setActionPerson] = useState<Person | null>(null);

  const selectView = (next: ViewMode) => {
    setView(next);
    window.localStorage.setItem(VIEW_STORAGE_KEY, next);
  };

  const fetchAll = useCallback(async () => {
    try {
      setIsLoading(true);
      // The backend supports page/per_page but not sort/filter query params, so
      // sort/filter/pagination all run CLIENT-side over a single fetch — fetching
      // the backend's own page-size ceiling (100) rather than its default fixes
      // the previous silent page-1-only truncation for the common case. Tenants
      // with >100 people are still capped until the backend grows real
      // search/sort support; that's a pre-existing limit, just moved further out.
      const [personsRes, edgesRes, typesRes] = await Promise.all([
        apiClient('/api/v1/persons?per_page=100'),
        apiClient('/api/v1/relations'),
        apiClient('/api/v1/relationship-types'),
      ]);

      if (personsRes.status === 403) {
        setIsForbidden(true);
        setPersons([]);
        setEdges([]);
        return;
      }
      setIsForbidden(false);

      if (!personsRes.ok) {
        throw new Error('Failed to fetch persons');
      }
      setPersons(((await personsRes.json()).data ?? []) as Person[]);
      if (edgesRes.ok) {
        setEdges(((await edgesRes.json()).data ?? []) as RelationEdge[]);
      }
      if (typesRes.ok) {
        setTypes(((await typesRes.json()).data ?? []) as RelationshipType[]);
      }
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Failed to fetch relations', 'error');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, addToast]);

  useEffect(() => {
    void (async () => {
      await fetchAll();
    })();
  }, [fetchAll]);

  const selectedPerson = useMemo(
    () => (selectedId !== null ? persons.find((p) => p.id === selectedId) ?? null : null),
    [persons, selectedId]
  );

  const rows: PersonRow[] = useMemo(
    () =>
      persons.map((p) => ({
        id: p.id,
        name: p.displayName,
        account: p.hasAccount ? 'Account' : '—',
        relations: p.relationCount,
        source: p,
      })),
    [persons]
  );

  const columns: DataTableColumn<PersonRow>[] = [
    { accessorKey: 'name', header: 'Name', enableSorting: true, enableColumnFilter: true },
    { accessorKey: 'account', header: 'Has account', enableSorting: true },
    { accessorKey: 'relations', header: 'Relations', enableSorting: true },
  ];

  const handleAction = (action: PersonAction, person: Person) => {
    setActionPerson(person);
    switch (action) {
      case 'edit':
        setIsEditOpen(true);
        break;
      case 'delete':
        setIsDeleteOpen(true);
        break;
      case 'add-relation':
        setIsAddRelationOpen(true);
        break;
    }
  };

  const rowActions = (row: PersonRow) => (
    <Button variant="ghost" size="sm" onClick={() => setSelectedId(row.source.id)}>
      View
    </Button>
  );

  const ViewToggle = (
    <div role="group" aria-label="View mode" className="inline-flex rounded-md border border-border p-0.5">
      <Button
        variant={view === 'list' ? 'secondary' : 'ghost'}
        size="sm"
        aria-pressed={view === 'list'}
        onClick={() => selectView('list')}
        className="gap-1.5"
      >
        <IconList />
        List
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

  // Converted from an early-return that skipped rendering DataTable entirely
  // into `overrideContent` (list mode only — see below); graph mode is
  // untouched by this bypass either way since it never reaches DataTable.
  const accessDeniedContent = (
    <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
      <IconShieldLock size={32} className="mx-auto mb-3 text-muted-foreground" />
      <h2 className="font-heading text-sm font-medium">Access denied</h2>
      <p className="mt-1 text-xs text-muted-foreground">
        You need the relations:read permission to view family relations.
      </p>
    </div>
  );

  const isEmpty = !isLoading && !isForbidden && persons.length === 0;

  return (
    <div className="space-y-8">
      <AdminHeader
        title="Family Relations"
        description="Record and manage familial relationships, including relatives without a platform account."
        action={
          // Manage-gated (WC-177): hidden for callers without relations:manage.
          canManage ? (
            <Button onClick={() => setIsCreateOpen(true)} className="gap-2">
              <IconPlus />
              Add relative
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center justify-between gap-3">
        {ViewToggle}
      </div>

      {isLoading ? (
        <Skeleton className="h-64 w-full rounded-lg" />
      ) : isEmpty ? (
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconUsersGroup size={32} className="mx-auto mb-3 text-muted-foreground" />
          <h2 className="font-heading text-sm font-medium">No people yet</h2>
          {canManage ? (
            <>
              <p className="mt-1 text-xs text-muted-foreground">
                Add a relative, or relate existing platform accounts to build the family graph.
              </p>
              <Button onClick={() => setIsCreateOpen(true)} variant="outline" className="mt-4 gap-2">
                <IconPlus />
                Add the first relative
              </Button>
            </>
          ) : (
            // Read-only callers (relations:read without relations:manage, WC-177)
            // get the empty state without the create CTA.
            <p className="mt-1 text-xs text-muted-foreground">
              No people have been added to the family graph yet.
            </p>
          )}
        </div>
      ) : view === 'list' ? (
        <DataTable
          columns={columns}
          data={rows}
          getRowId={(row) => String(row.id)}
          rowActions={rowActions}
          isLoading={isLoading}
          enableGlobalFilter
          globalFilterPlaceholder="Search by name…"
          pagination={{ pageSize: 10 }}
          overrideContent={isForbidden ? accessDeniedContent : undefined}
        />
      ) : (
        <RelationsGraph
          persons={persons}
          edges={edges}
          selectedId={selectedId}
          onSelect={setSelectedId}
          onAction={handleAction}
          canManage={canManage}
        />
      )}

      <PersonDetailDrawerLazy
        person={selectedPerson}
        onClose={() => setSelectedId(null)}
        onAction={handleAction}
        onChanged={fetchAll}
        canManage={canManage}
      />

      <CreatePersonModal
        key={isCreateOpen ? 'create-open' : 'create-closed'}
        isOpen={isCreateOpen}
        onClose={() => setIsCreateOpen(false)}
        onSuccess={() => {
          setIsCreateOpen(false);
          void fetchAll();
        }}
      />

      {actionPerson && (
        <EditPersonModal
          // Remount per person so the form initialises from the latest record
          // without a setState-in-effect sync.
          key={`edit-${actionPerson.id}`}
          isOpen={isEditOpen}
          onClose={() => {
            setIsEditOpen(false);
            setActionPerson(null);
          }}
          onSuccess={() => {
            setIsEditOpen(false);
            setActionPerson(null);
            void fetchAll();
          }}
          person={actionPerson}
        />
      )}

      {actionPerson && (
        <DeletePersonModal
          isOpen={isDeleteOpen}
          onClose={() => {
            setIsDeleteOpen(false);
            setActionPerson(null);
          }}
          onSuccess={() => {
            setIsDeleteOpen(false);
            setSelectedId((current) => (current === actionPerson.id ? null : current));
            setActionPerson(null);
            void fetchAll();
          }}
          person={actionPerson}
        />
      )}

      {actionPerson && (
        <AddRelationModal
          key={`add-relation-${actionPerson.id}-${isAddRelationOpen ? 'open' : 'closed'}`}
          isOpen={isAddRelationOpen}
          onClose={() => {
            setIsAddRelationOpen(false);
            setActionPerson(null);
          }}
          onSuccess={() => {
            setIsAddRelationOpen(false);
            setActionPerson(null);
            void fetchAll();
          }}
          fromPerson={actionPerson}
          persons={persons}
          types={types}
        />
      )}
    </div>
  );
}
