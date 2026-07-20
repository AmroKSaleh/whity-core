'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import type { Person, RelationshipType } from './types';

/**
 * An account-holder option for relating to a profile (resolved to its shadow person).
 * The id is the profile id (not the legacy user id) as required by the backend
 * `kind:'profile'` reference after WC-idcut-D.
 */
interface ProfileOption {
  profileId: number;
  email: string;
}

interface AddRelationModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  /** The person the new relation starts FROM (the drawer/menu subject). */
  fromPerson: Person;
  /** All persons in the tenant (possible person targets). */
  persons: Person[];
  /** The relationship-type vocabulary. */
  types: RelationshipType[];
}

type TargetKind = 'person' | 'profile';

/**
 * Add a relation from `fromPerson` to a chosen target. The target may be another
 * PERSON or a PROFILE (account-holder); the backend resolves a profile to its
 * shadow person. The relationship type is read from `fromPerson`'s perspective and
 * stored as a single edge; the reciprocal is derived at read time.
 */
export function AddRelationModal({
  isOpen,
  onClose,
  onSuccess,
  fromPerson,
  persons,
  types,
}: AddRelationModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isLoading, setIsLoading] = useState(false);
  const [targetKind, setTargetKind] = useState<TargetKind>('person');
  const [targetId, setTargetId] = useState<string>('');
  const [typeId, setTypeId] = useState<string>('');
  const [profiles, setProfiles] = useState<ProfileOption[]>([]);

  // Person targets exclude the subject itself (no self-relation).
  const personTargets = useMemo(
    () => persons.filter((p) => p.id !== fromPerson.id),
    [persons, fromPerson.id]
  );

  // Lazily load the profiles list the first time the "profile" target kind is
  // picked, so the dialog stays cheap when relating two persons. The users API
  // now returns profileId (the identity anchor) alongside the email so we can
  // send kind:'profile' to the backend.
  useEffect(() => {
    if (targetKind !== 'profile' || profiles.length > 0) {
      return;
    }
    let cancelled = false;
    void (async () => {
      try {
        const res = await apiClient('/api/v1/users');
        if (!res.ok) {
          return;
        }
        const data = await res.json();
        if (!cancelled) {
          // Only include users that have a resolved profile_id; users without one
          // (not yet migrated to the identity model) cannot be auto-provisioned.
          const options = (data.data ?? [] as Array<{ profileId: number | null; email: string }>)
            .filter((u: { profileId: number | null; email: string }) => u.profileId !== null)
            .map((u: { profileId: number; email: string }) => ({ profileId: u.profileId, email: u.email }));
          setProfiles(options);
        }
      } catch {
        // Non-fatal: the profile picker simply stays empty.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [targetKind, profiles.length, apiClient]);

  const handleSubmit = async () => {
    if (!targetId || !typeId) {
      addToast('Pick a target and a relationship type', 'error');
      return;
    }

    try {
      setIsLoading(true);
      const response = await apiClient('/api/v1/relations', {
        method: 'POST',
        body: JSON.stringify({
          from: { kind: 'person' as const, id: fromPerson.id },
          to: { kind: targetKind, id: parseInt(targetId, 10) },
          relationshipTypeId: parseInt(typeId, 10),
        }),
      });

      if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(error.error || 'Failed to add relation');
      }

      addToast('Relation added', 'success');
      onSuccess();
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Failed to add relation', 'error');
    } finally {
      setIsLoading(false);
    }
  };

  const onTargetKindChange = (value: string) => {
    setTargetKind(value === 'profile' ? 'profile' : 'person');
    setTargetId('');
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add a relation</DialogTitle>
          <DialogDescription>
            Define how <span className="font-medium">{fromPerson.displayName}</span> is related to
            another person or account.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <label className="text-sm font-medium">{fromPerson.displayName} is the…</label>
            <Select value={typeId} onValueChange={setTypeId} disabled={isLoading}>
              <SelectTrigger aria-label="Relationship type">
                <SelectValue placeholder="Select a relationship type" />
              </SelectTrigger>
              <SelectContent>
                {types.map((type) => (
                  <SelectItem key={type.id} value={type.id.toString()}>
                    {type.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Alert variant="info" className="mt-1">
              <AlertDescription>
                The reciprocal is shown automatically from the other person&rsquo;s side.
              </AlertDescription>
            </Alert>
          </div>

          <div>
            <label className="text-sm font-medium">Related to</label>
            <div className="flex gap-2">
              <Select value={targetKind} onValueChange={onTargetKindChange} disabled={isLoading}>
                <SelectTrigger aria-label="Target kind" className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="person">Relative</SelectItem>
                  <SelectItem value="profile">Account</SelectItem>
                </SelectContent>
              </Select>

              <Select value={targetId} onValueChange={setTargetId} disabled={isLoading}>
                <SelectTrigger aria-label="Target" className="flex-1">
                  <SelectValue
                    placeholder={targetKind === 'profile' ? 'Select an account' : 'Select a relative'}
                  />
                </SelectTrigger>
                <SelectContent>
                  {targetKind === 'person'
                    ? personTargets.map((p) => (
                        <SelectItem key={p.id} value={p.id.toString()}>
                          {p.displayName}
                        </SelectItem>
                      ))
                    : profiles.map((u) => (
                        <SelectItem key={u.profileId} value={u.profileId.toString()}>
                          {u.email}
                        </SelectItem>
                      ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={onClose} disabled={isLoading}>
              Cancel
            </Button>
            <Button onClick={handleSubmit} disabled={isLoading || !targetId || !typeId}>
              {isLoading ? 'Adding…' : 'Add relation'}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
