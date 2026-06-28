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
} from '@whity/ui/dialog';
import { Button } from '@whity/ui/button';
import { IconAlertTriangle } from '@tabler/icons-react';
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
  const [isLoading, setIsLoading] = useState(false);

  const handleDelete = async () => {
    try {
      setIsLoading(true);
      const response = await apiClient(`/api/v1/persons/${person.id}`, { method: 'DELETE' });

      if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(error.error || 'Failed to delete person');
      }

      addToast('Person deleted', 'success');
      onSuccess();
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Failed to delete person', 'error');
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
            Delete relative
          </DialogTitle>
          <DialogDescription>
            Are you sure you want to delete &ldquo;{person.displayName}&rdquo;?
          </DialogDescription>
        </DialogHeader>

        <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
          <p className="font-medium">Warning</p>
          <ul className="mt-2 list-inside list-disc space-y-1">
            <li>This action cannot be undone.</li>
            <li>
              {person.relationCount > 0
                ? `Its ${person.relationCount} relation${person.relationCount === 1 ? '' : 's'} will be removed.`
                : 'This person has no relations.'}
            </li>
          </ul>
        </div>

        <div className="flex justify-end gap-3">
          <Button variant="outline" onClick={onClose} disabled={isLoading}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={handleDelete} disabled={isLoading}>
            {isLoading ? 'Deleting…' : 'Delete'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
