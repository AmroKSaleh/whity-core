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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { OU } from './types';

interface CreateOuModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  ous: OU[];
  /** Pre-select a parent OU (used by the tree/graph "Create child OU" action). */
  defaultParentId?: number | null;
}

export function CreateOuModal({
  isOpen,
  onClose,
  onSuccess,
  ous,
  defaultParentId = null,
}: CreateOuModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isLoading, setIsLoading] = useState(false);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  // Initialised from the (possibly pre-selected) parent. The page remounts this
  // modal per open via a `key` tied to defaultParentId, so the initial value is
  // always correct without a sync effect.
  const [parentId, setParentId] = useState<string>(
    defaultParentId !== null ? String(defaultParentId) : 'null'
  );

  const handleCreate = async () => {
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

      const response = await apiClient('/api/v1/ous', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.error || 'Failed to create organizational unit');
      }

      addToast('Organizational unit created successfully', 'success');
      setName('');
      setDescription('');
      setParentId('');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to create organizational unit';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      setName('');
      setDescription('');
      setParentId('');
      onClose();
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Create Organizational Unit</DialogTitle>
          <DialogDescription>
            Add a new organizational unit to your organization
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
                {ous.map((ou) => (
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
              onClick={handleCreate}
              disabled={isLoading}
            >
              {isLoading ? 'Creating...' : 'Create'}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
