'use client';

import { useState } from 'react';
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
import { IconAlertTriangle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { Person } from './types';

interface DeletePersonModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  person: Person;
}

/**
 * Delete a non-user relative. Deleting cascades the person's relation edges. A
 * person linked to a user account cannot be deleted here (the backend guards
 * with 409); the page only opens this for non-user persons.
 */
export function DeletePersonModal({ isOpen, onClose, onSuccess, person }: DeletePersonModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isLoading, setIsLoading] = useState(false);

  const handleDelete = async () => {
    try {
      setIsLoading(true);
      const response = await apiClient(`/api/v1/persons/${person.id}`, { method: 'DELETE' });

      if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(
          error.error || t('relations.deletePerson.error', 'Failed to delete person')
        );
      }

      addToast(t('relations.deletePerson.success', 'Person deleted'), 'success');
      onSuccess();
    } catch (error) {
      addToast(
        error instanceof Error
          ? error.message
          : t('relations.deletePerson.error', 'Failed to delete person'),
        'error'
      );
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <IconAlertTriangle className="text-destructive" size={24} />
            {t('relations.deletePerson.title', 'Delete relative')}
          </DialogTitle>
          <DialogDescription>
            {t('relations.deletePerson.subtitle', 'Are you sure you want to delete “{name}”?', {
              name: person.displayName,
            })}
          </DialogDescription>
        </DialogHeader>

        <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
          <p className="font-medium">{t('relations.deletePerson.warning', 'Warning')}</p>
          <ul className="mt-2 list-inside list-disc space-y-1">
            <li>
              {t('relations.deletePerson.consequence.irreversible', 'This action cannot be undone.')}
            </li>
            <li>
              {/* Singular and plural are separate keys rather than a suffix
                  spliced onto a count: a language whose plural rules differ from
                  English cannot be served by appending an "s". */}
              {person.relationCount <= 0
                ? t('relations.deletePerson.consequence.noRelations', 'This person has no relations.')
                : person.relationCount === 1
                  ? t(
                      'relations.deletePerson.consequence.oneRelation',
                      'Its 1 relation will be removed.'
                    )
                  : t(
                      'relations.deletePerson.consequence.manyRelations',
                      'Its {count} relations will be removed.',
                      { count: person.relationCount }
                    )}
            </li>
          </ul>
        </div>

        <div className="flex justify-end gap-3">
          <Button variant="outline" onClick={onClose} disabled={isLoading}>
            {t('relations.deletePerson.cancel', 'Cancel')}
          </Button>
          <Button variant="destructive" onClick={handleDelete} disabled={isLoading}>
            {isLoading
              ? t('relations.deletePerson.submitting', 'Deleting…')
              : t('relations.deletePerson.submit', 'Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
