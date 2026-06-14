'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import dynamic from 'next/dynamic';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type Column } from '@/components/admin/data-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
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
import { RELATIONS_MANAGE, parsePermissions } from '@/lib/capabilities';

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

  const [persons, setPersons] = useState<Person[]>([]);
  const [edges, setEdges] = useState<RelationEdge[]>([]);
  const [types, setTypes] = useState<RelationshipType[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);
  const [search, setSearch] = useState('');
  // Caller's relations:manage capability (WC-177, #205). Fail-closed: write
  // controls stay hidden until proven otherwise, so a delegate holding only
  // relations:read never sees affordances that would 403 server-side.
  const [canManage, setCanManage] = useState(false);

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
      const [personsRes, edgesRes, typesRes, capsRes] = await Promise.all([
        apiClient('/api/persons'),
        apiClient('/api/relations'),
        apiClient('/api/relationship-types'),
        apiClient('/api/me/capabilities'),
      ]);

      // Derive the caller's manage capability before the read gate so the UI
      // hides write controls (WC-177). FAIL CLOSED: any non-ok response or
      // parse failure leaves canManage false.
      if (capsRes.ok) {
        const permissions = parsePermissions(await capsRes.json());
        setCanManage(permissions.includes(RELATIONS_MANAGE));
      } else {
        setCanManage(false);
      }

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
      // Fail closed: an aborted fetch must not leave stale write affordances.
      setCanManage(false);
      addToast(error instanceof Error ? error.message : 'Failed to fetch relations', 'error');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, addToast]);

  useEffect(() => {
    void fetchAll();
  }, [fetchAll]);

  const selectedPerson = useMemo(
    () => (selectedId !== null ? persons.find((p) => p.id === selectedId) ?? null : null),
    [persons, selectedId]
  );

  // Client-side name search keeps filtering instant and robust regardless of the
  // server query path.
  const filteredPersons = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (term === '') {
      return persons;
    }
    return persons.filter((p) => p.displayName.toLowerCase().includes(term));
  }, [persons, search]);

  const rows: PersonRow[] = useMemo(
    () =>
      filteredPersons.map((p) => ({
        id: p.id,
        name: p.displayName,
        account: p.hasAccount ? 'Account' : '—',
        relations: p.relationCount,
        source: p,
      })),
    [filteredPersons]
  );

  const columns: Column<PersonRow>[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'account', label: 'Has account', sortable: true },
    { key: 'relations', label: 'Relations', sortable: true },
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

  if (isForbidden) {
    return (
      <div className="space-y-8">
        <AdminHeader
          title="Family Relations"
          description="Record and manage familial relationships between people in your tenant."
        />
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconShieldLock size={32} className="mx-auto mb-3 text-muted-foreground" />
          <h2 className="font-heading text-sm font-medium">Access denied</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            You need the relations:read permission to view family relations.
          </p>
        </div>
      </div>
    );
  }

  const isEmpty = !isLoading && persons.length === 0;

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
        {view === 'list' && !isEmpty && (
          <Input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name…"
            aria-label="Search persons by name"
            className="w-full max-w-xs"
          />
        )}
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
        <DataTable columns={columns} data={rows} rowActions={rowActions} isLoading={isLoading} />
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
