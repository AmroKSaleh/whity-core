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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

interface CreatePersonModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
}

/**
 * Create a non-user relative (a person with no login). User shadows are
 * auto-provisioned by the backend when a user is first related, so this form
 * never links to a user — it only captures the genealogy fields.
 */
export function CreatePersonModal({ isOpen, onClose, onSuccess }: CreatePersonModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isLoading, setIsLoading] = useState(false);
  const [displayName, setDisplayName] = useState('');
  const [birthDate, setBirthDate] = useState('');
  const [deceased, setDeceased] = useState(false);
  const [notes, setNotes] = useState('');

  const reset = () => {
    setDisplayName('');
    setBirthDate('');
    setDeceased(false);
    setNotes('');
  };

  const handleCreate = async () => {
    if (!displayName.trim()) {
      addToast('Name is required', 'error');
      return;
    }

    try {
      setIsLoading(true);
      const payload: Record<string, unknown> = { displayName: displayName.trim() };
      if (birthDate) {
        payload.birthDate = birthDate;
      }
      if (deceased) {
        payload.deceased = true;
      }
      if (notes.trim()) {
        payload.notes = notes.trim();
      }

      const response = await apiClient('/api/v1/persons', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(error.error || 'Failed to create person');
      }

      addToast('Person created', 'success');
      reset();
      onSuccess();
    } catch (error) {
      addToast(error instanceof Error ? error.message : 'Failed to create person', 'error');
    } finally {
      setIsLoading(false);
    }
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      reset();
      onClose();
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add a relative</DialogTitle>
          <DialogDescription>
            Add a person who does not have a platform account (e.g. a child or a
            deceased relative).
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <label className="text-sm font-medium" htmlFor="person-name">
              Name *
            </label>
            <Input
              id="person-name"
              value={displayName}
              onChange={(e) => setDisplayName(e.target.value)}
              placeholder="e.g., Jane Doe"
              disabled={isLoading}
            />
          </div>

          <div>
            <label className="text-sm font-medium" htmlFor="person-birth">
              Birth date
            </label>
            <Input
              id="person-birth"
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
            <label className="text-sm font-medium" htmlFor="person-notes">
              Notes
            </label>
            <Textarea
              id="person-notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Optional notes"
              disabled={isLoading}
              rows={3}
            />
          </div>

          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={isLoading}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={isLoading}>
              {isLoading ? 'Creating…' : 'Create'}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
