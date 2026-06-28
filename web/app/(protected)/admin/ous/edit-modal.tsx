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
import { Input } from '@whity/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';
import { Textarea } from '@whity/ui/textarea';
import type { OU } from './types';
import { buildOuTree, getDescendantIds } from './ou-tree-util';

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
  // Form state seeds from the `ou` prop. The parent remounts this component via
  // `key={ou.id}` whenever a different OU is edited, so these initializers re-run
  // on the new record instead of synchronising state inside an effect (which the
  // React lint rules disallow).
  const [name, setName] = useState(ou.name);
  const [description, setDescription] = useState(ou.description || '');
  const [parentId, setParentId] = useState<string>(ou.parent_id ? ou.parent_id.toString() : 'null');

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

      // Move-to-parent: send the chosen parent (or null for "root"). Only
      // include parent_id when it actually changed so an unrelated rename does
      // not also re-assert the parent.
      const nextParentId = parentId === 'null' ? null : parseInt(parentId, 10);
      const currentParentId = ou.parent_id ?? null;
      if (nextParentId !== currentParentId) {
        payload.parent_id = nextParentId;
      }

      const response = await apiClient(`/api/v1/ous/${ou.id}`, {
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

  // Eligible move targets exclude the OU itself and all of its descendants, so
  // the picker can never form a cycle (the backend rejects cycles too — defense
  // in depth). getDescendantIds(tree, ou.id) returns ou.id + every descendant.
  const excludedIds = new Set(getDescendantIds(buildOuTree(ous), ou.id));
  const availableParents = ous.filter((o) => !excludedIds.has(o.id));

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
            <label className="text-sm font-medium">Move to parent</label>
            <Select value={parentId} onValueChange={setParentId} disabled={isLoading}>
              <SelectTrigger aria-label="Move to parent">
                <SelectValue placeholder="Select a parent OU (optional)" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="null">None (Root OU)</SelectItem>
                {availableParents.map((parent) => (
                  <SelectItem key={parent.id} value={parent.id.toString()}>
                    {parent.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="mt-1 text-xs text-muted-foreground">
              The OU itself and its descendants are excluded to prevent cycles.
            </p>
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
