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
} from '@/components/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { useRichTranslation, useTranslation } from '@amroksaleh/features/i18n';
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
  const t = useTranslation('admin');
  const rt = useRichTranslation('admin');
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
          // `id` IS the profile id on this endpoint — UsersApiHandler
          // documents it as "the canonical profile_id (ADR 0005 hard
          // cutover)" and returns no separate `profileId`. This read
          // `u.profileId`, which was always undefined, and the guard below it
          // (`!== null`) did not catch that because `undefined !== null` is
          // true — so every option was built with `profileId: undefined` and
          // the picker looked populated while selecting one sent no id at all.
          // There is no longer a profile-less row to filter out: the identity
          // cutover made every membership row carry one.
          const options = (data.data ?? [] as Array<{ id: number; email: string }>)
            .map((u: { id: number; email: string }) => ({ profileId: u.id, email: u.email }));
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
      addToast(
        t('relations.addRelation.validation.required', 'Pick a target and a relationship type'),
        'error'
      );
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
        throw new Error(
          error.error || t('relations.addRelation.error', 'Failed to add relation')
        );
      }

      addToast(t('relations.addRelation.success', 'Relation added'), 'success');
      onSuccess();
    } catch (error) {
      addToast(
        error instanceof Error
          ? error.message
          : t('relations.addRelation.error', 'Failed to add relation'),
        'error'
      );
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
          <DialogTitle>{t('relations.addRelation.title', 'Add a relation')}</DialogTitle>
          <DialogDescription>
            {rt(
              'relations.addRelation.subtitle',
              'Define how <0>{name}</0> is related to another person or account.',
              { name: fromPerson.displayName },
              [<span key="name" className="font-medium" />]
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <label className="text-sm font-medium">
              {t('relations.addRelation.type.label', '{name} is the…', {
                name: fromPerson.displayName,
              })}
            </label>
            <Select value={typeId} onValueChange={setTypeId} disabled={isLoading}>
              <SelectTrigger aria-label={t('relations.addRelation.type.select', 'Relationship type')}>
                <SelectValue
                  placeholder={t(
                    'relations.addRelation.type.placeholder',
                    'Select a relationship type'
                  )}
                />
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
                {t(
                  'relations.addRelation.type.hint',
                  'The reciprocal is shown automatically from the other person’s side.'
                )}
              </AlertDescription>
            </Alert>
          </div>

          <div>
            <label className="text-sm font-medium">
              {t('relations.addRelation.target.label', 'Related to')}
            </label>
            <div className="flex gap-2">
              <Select value={targetKind} onValueChange={onTargetKindChange} disabled={isLoading}>
                <SelectTrigger
                  aria-label={t('relations.addRelation.targetKind.select', 'Target kind')}
                  className="w-32"
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="person">
                    {t('relations.addRelation.targetKind.person', 'Relative')}
                  </SelectItem>
                  <SelectItem value="profile">
                    {t('relations.addRelation.targetKind.profile', 'Account')}
                  </SelectItem>
                </SelectContent>
              </Select>

              <Select value={targetId} onValueChange={setTargetId} disabled={isLoading}>
                <SelectTrigger
                  aria-label={t('relations.addRelation.target.select', 'Target')}
                  className="flex-1"
                >
                  <SelectValue
                    placeholder={
                      targetKind === 'profile'
                        ? t('relations.addRelation.target.placeholderProfile', 'Select an account')
                        : t('relations.addRelation.target.placeholderPerson', 'Select a relative')
                    }
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
              {t('relations.addRelation.cancel', 'Cancel')}
            </Button>
            <Button onClick={handleSubmit} disabled={isLoading || !targetId || !typeId}>
              {isLoading
                ? t('relations.addRelation.submitting', 'Adding…')
                : t('relations.addRelation.submit', 'Add relation')}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
