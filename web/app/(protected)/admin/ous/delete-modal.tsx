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
import { IconAlertTriangle } from '@tabler/icons-react';
import type { OU } from './types';

interface DeleteOuModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  ou: OU;
}

export function DeleteOuModal({ isOpen, onClose, onSuccess, ou }: DeleteOuModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isLoading, setIsLoading] = useState(false);

  const handleDelete = async () => {
    try {
      setIsLoading(true);
      const response = await apiClient(`/api/v1/ous/${ou.id}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.error || 'Failed to delete organizational unit');
      }

      addToast('Organizational unit deleted successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to delete organizational unit';
      addToast(message, 'error');
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
            Delete Organizational Unit
          </DialogTitle>
          <DialogDescription>
            Are you sure you want to delete &quot;{ou.name}&quot;?
          </DialogDescription>
        </DialogHeader>

        <div className="bg-destructive/10 rounded-lg p-4 text-sm text-destructive">
          <p className="font-medium">Warning:</p>
          <ul className="mt-2 list-inside list-disc space-y-1">
            <li>This action cannot be undone</li>
            <li>Users assigned to this OU will no longer inherit its roles</li>
            <li>Child OUs cannot have this OU as parent</li>
          </ul>
        </div>

        <div className="flex justify-end gap-3">
          <Button
            variant="outline"
            onClick={onClose}
            disabled={isLoading}
          >
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={handleDelete}
            disabled={isLoading}
          >
            {isLoading ? 'Deleting...' : 'Delete'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
