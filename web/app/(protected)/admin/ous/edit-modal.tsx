'use client';

import { useEffect, useState } from 'react';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { OU } from './types';

interface EditOuModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  ou: OU;
  ous: OU[];
}

export function EditOuModal({ isOpen, onClose, onSuccess, ou, ous }: EditOuModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isLoading, setIsLoading] = useState(false);
  const [name, setName] = useState(ou.name);
  const [description, setDescription] = useState(ou.description || '');
  const [parentId, setParentId] = useState<string>(ou.parent_id ? ou.parent_id.toString() : 'null');

  useEffect(() => {
    setName(ou.name);
    setDescription(ou.description || '');
    setParentId(ou.parent_id ? ou.parent_id.toString() : 'null');
  }, [ou]);

  const handleUpdate = async () => {
    if (!name.trim()) {
      addToast('Name is required', 'error');
      return;
    }

    try {
      setIsLoading(true);
      const payload: Record<string, unknown> = {
        name: name.trim(),
        description: description.trim(),
      };

      if (parentId && parentId !== 'null') {
        payload.parent_id = parseInt(parentId, 10);
      }

      const response = await apiClient(`/api/ous/${ou.id}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.error || 'Failed to update organizational unit');
      }

      addToast('Organizational unit updated successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to update organizational unit';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      onClose();
    }
  };

  const availableParents = ous.filter((o) => o.id !== ou.id);

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit Organizational Unit</DialogTitle>
          <DialogDescription>
            Update the details of {ou.name}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <label className="text-sm font-medium">Name *</label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g., Engineering"
              disabled={isLoading}
            />
          </div>

          <div>
            <label className="text-sm font-medium">Description</label>
            <Textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Optional description for this OU"
              disabled={isLoading}
              rows={3}
            />
          </div>

          <div>
            <label className="text-sm font-medium">Parent OU</label>
            <Select value={parentId} onValueChange={setParentId} disabled={isLoading}>
              <SelectTrigger>
                <SelectValue placeholder="Select a parent OU (optional)" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="null">None (Root OU)</SelectItem>
                {availableParents.map((ou) => (
                  <SelectItem key={ou.id} value={ou.id.toString()}>
                    {ou.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
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
              onClick={handleUpdate}
              disabled={isLoading}
            >
              {isLoading ? 'Updating...' : 'Update'}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
