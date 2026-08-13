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
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { Textarea } from '@amroksaleh/ui/textarea';
import { useTranslation } from '@amroksaleh/features/i18n';
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
  const t = useTranslation('admin');
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
      addToast(t('ous.create.validation.nameRequired', 'Name is required'), 'error');
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
        throw new Error(
          error.error || t('ous.create.error', 'Failed to create organizational unit')
        );
      }

      addToast(t('ous.create.success', 'Organizational unit created successfully'), 'success');
      setName('');
      setDescription('');
      setParentId('');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('ous.create.error', 'Failed to create organizational unit');
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
          <DialogTitle>{t('ous.create.title', 'Create Organizational Unit')}</DialogTitle>
          <DialogDescription>
            {t('ous.create.subtitle', 'Add a new organizational unit to your organization')}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <label className="text-sm font-medium">{t('ous.create.name.label', 'Name *')}</label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('ous.create.name.placeholder', 'e.g., Engineering')}
              disabled={isLoading}
            />
          </div>

          <div>
            <label className="text-sm font-medium">
              {t('ous.create.description.label', 'Description')}
            </label>
            <Textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder={t(
                'ous.create.description.placeholder',
                'Optional description for this OU'
              )}
              disabled={isLoading}
              rows={3}
            />
          </div>

          <div>
            <label className="text-sm font-medium">{t('ous.create.parent.label', 'Parent OU')}</label>
            <Select value={parentId} onValueChange={setParentId} disabled={isLoading}>
              <SelectTrigger>
                <SelectValue
                  placeholder={t('ous.create.parent.placeholder', 'Select a parent OU (optional)')}
                />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="null">{t('ous.create.parent.none', 'None (Root OU)')}</SelectItem>
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
              {t('ous.create.cancel', 'Cancel')}
            </Button>
            <Button
              onClick={handleCreate}
              disabled={isLoading}
            >
              {isLoading
                ? t('ous.create.submitting', 'Creating...')
                : t('ous.create.submit', 'Create')}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
