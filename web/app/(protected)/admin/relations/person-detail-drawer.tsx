'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { IconEdit, IconPlus, IconTrash, IconUser, IconUserOff, IconX } from '@tabler/icons-react';
import type { Person, RelationView } from './types';
import type { PersonAction } from './relations-view';

interface PersonDetailDrawerProps {
  /** The selected person (drawer is open when non-null), or null when closed. */
  person: Person | null;
  /** Close the drawer (clears the selection on the page). */
  onClose: () => void;
  /** Bubble an action (edit / delete / add-relation) to the page. */
  onAction: (action: PersonAction, person: Person) => void;
  /** Notify the page that the person/relations changed (triggers a refetch). */
  onChanged: () => void;
  /**
   * Whether the caller holds `relations:manage` (WC-177). When false the write
   * affordances are hidden — the structural action row (Add relation / Edit /
   * Delete) and each relation's "Remove" control — while the relations LIST
   * itself (read) stays visible.
   */
  canManage: boolean;
}

/**
 * `PersonDetailDrawer` — the management hub for the selected person, reusing the
 * OU hub's shared detail drawer (`sheet.tsx`). It owns its own relations fetch
 * (re-fetched whenever the selected person changes) so the list reflects live
 * reciprocal-derived relations, shows the person's details + the structural
 * actions (bubbled via `onAction`), and lets the operator remove a relation in
 * place. Editing/deleting are offered only for non-user relatives (a user-linked
 * shadow is managed via user management).
 */
export function PersonDetailDrawer({
  person,
  onClose,
  onAction,
  onChanged,
  canManage,
}: PersonDetailDrawerProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const [relations, setRelations] = useState<RelationView[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isMutating, setIsMutating] = useState(false);

  const personId = person?.id ?? null;

  const loadRelations = useCallback(async () => {
    if (personId === null) {
      return;
    }
    setIsLoading(true);
    try {
      const res = await apiClient(`/api/persons/${personId}/relations`);
      if (res.ok) {
        setRelations(((await res.json()).data ?? []) as RelationView[]);
      }
    } catch {
      addToast('Failed to load relations', 'error');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, personId, addToast]);

  useEffect(() => {
    if (personId !== null) {
      void loadRelations();
    }
  }, [personId, loadRelations]);

  const handleRemoveRelation = async (relationId: number) => {
    setIsMutating(true);
    try {
      const res = await apiClient(`/api/relations/${relationId}`, { method: 'DELETE' });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error || 'Failed to remove relation');
      }
      addToast('Relation removed', 'success');
      await loadRelations();
      onChanged();
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Failed to remove relation', 'error');
    } finally {
      setIsMutating(false);
    }
  };

  const Icon = person?.hasAccount ? IconUser : IconUserOff;

  return (
    <Sheet open={person !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent aria-describedby={undefined}>
        {person && (
          <>
            <SheetHeader>
              <SheetTitle className="flex items-center gap-2">
                <Icon className="size-4 text-muted-foreground" aria-hidden />
                {person.displayName}
              </SheetTitle>
              <SheetDescription>
                {person.hasAccount
                  ? 'Linked to a platform account.'
                  : 'A relative without a platform account.'}
              </SheetDescription>
              <div className="mt-1 flex flex-wrap gap-2 text-[0.625rem] text-muted-foreground">
                {person.birthDate && <span>Born {person.birthDate}</span>}
                {person.deceased && <Badge variant="outline">Deceased</Badge>}
              </div>
              {person.notes && (
                <p className="mt-2 text-xs text-muted-foreground">{person.notes}</p>
              )}
            </SheetHeader>

            {/* The structural action row is entirely writes; hide it unless the
                caller holds relations:manage (WC-177). */}
            {canManage && (
              <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" onClick={() => onAction('add-relation', person)}>
                  <IconPlus />
                  Add relation
                </Button>
                {!person.hasAccount && (
                  <Button size="sm" variant="outline" onClick={() => onAction('edit', person)}>
                    <IconEdit />
                    Edit
                  </Button>
                )}
                {!person.hasAccount && (
                  <Button size="sm" variant="destructive" onClick={() => onAction('delete', person)}>
                    <IconTrash />
                    Delete
                  </Button>
                )}
              </div>
            )}

            <section aria-labelledby="person-relations-heading" className="space-y-2">
              <h3
                id="person-relations-heading"
                className="font-heading text-xs font-semibold uppercase tracking-wide text-muted-foreground"
              >
                Relations
              </h3>

              {isLoading ? (
                <Skeleton className="h-8 w-full" />
              ) : relations.length === 0 ? (
                <p className="text-xs text-muted-foreground">No relations yet.</p>
              ) : (
                <ul className="space-y-1">
                  {relations.map((relation) => (
                    <li
                      key={relation.relationId}
                      className="flex items-center justify-between gap-2 rounded-md border border-border bg-card px-2 py-1"
                    >
                      <div className="min-w-0">
                        <Badge variant="secondary">{relation.typeName}</Badge>
                        <span className="ms-2 truncate text-xs text-foreground">
                          {relation.otherPersonName}
                        </span>
                        {!relation.otherPersonHasAccount && (
                          <span className="ms-1 text-[0.625rem] text-muted-foreground">
                            (relative)
                          </span>
                        )}
                      </div>
                      {/* Remove is a DELETE write; hide it unless the caller
                          holds relations:manage (WC-177). The relation row
                          (read) stays visible. */}
                      {canManage && (
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={`Remove relation to ${relation.otherPersonName}`}
                          disabled={isMutating}
                          onClick={() => handleRemoveRelation(relation.relationId)}
                        >
                          <IconX />
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
              <p className="text-[0.625rem] text-muted-foreground">
                Relations are shown from {person.displayName}&rsquo;s perspective; the reciprocal is
                derived automatically.
              </p>
            </section>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
