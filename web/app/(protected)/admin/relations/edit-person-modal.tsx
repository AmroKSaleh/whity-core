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
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Textarea } from '@amroksaleh/ui/textarea';
import type { Person } from './types';

interface EditPersonModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  person: Person;
}

/**
 * Edit a non-user relative. A person linked to a user account cannot be edited
 * here (the backend returns 409); the page only opens this for non-user persons.
 */
export function EditPersonModal({ isOpen, onClose, onSuccess, person }: EditPersonModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isLoading, setIsLoading] = useState(false);
  const [displayName, setDisplayName] = useState(person.displayName);
  const [birthDate, setBirthDate] = useState(person.birthDate ?? '');
  const [deceased, setDeceased] = useState(person.deceased);
  const [notes, setNotes] = useState(person.notes ?? '');

  // The page remounts this modal per person via a `key`, so the initial state
  // above is always correct without a setState-in-effect sync.

  const handleUpdate = async () => {
    if (!displayName.trim()) {
      addToast('Name is required', 'error');
      return;
    }

    try {
      setIsLoading(true);
      const payload: Record<string, unknown> = {
        displayName: displayName.trim(),
        birthDate: birthDate || null,
        deceased,
        notes: notes.trim() || null,
      };

      const response = await apiClient(`/api/v1/persons/${person.id}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(error.error || 'Failed to update person');
      }

      addToast('Person updated', 'success');
      onSuccess();
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Failed to update person', 'error');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit relative</DialogTitle>
          <DialogDescription>Update the details of {person.displayName}.</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <label className="text-sm font-medium" htmlFor="edit-person-name">
              Name *
            </label>
            <Input
              id="edit-person-name"
              value={displayName}
              onChange={(e) => setDisplayName(e.target.value)}
              disabled={isLoading}
            />
          </div>

          <div>
            <label className="text-sm font-medium" htmlFor="edit-person-birth">
              Birth date
            </label>
            <Input
              id="edit-person-birth"
              type="date"
              value={birthDate}
              onChange={(e) => setBirthDate(e.target.value)}
              disabled={isLoading}
            />
          </div>

          <label className="flex items-center gap-2 text-sm font-medium">
            <input
              type="checkbox"
              className="size-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-ring/40"
              checked={deceased}
              onChange={(e) => setDeceased(e.target.checked)}
              disabled={isLoading}
            />
            Deceased
          </label>

          <div>
            <label className="text-sm font-medium" htmlFor="edit-person-notes">
              Notes
            </label>
            <Textarea
              id="edit-person-notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              disabled={isLoading}
              rows={3}
            />
          </div>

          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={onClose} disabled={isLoading}>
              Cancel
            </Button>
            <Button onClick={handleUpdate} disabled={isLoading}>
              {isLoading ? 'Updating…' : 'Update'}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
