'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Textarea } from '@amroksaleh/ui/textarea';
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from '@amroksaleh/ui/form';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { errorMessage } from '../languages/shared';

const addKeySchema = z.object({
  key: z.string().min(1, 'Key is required'),
  translation: z.string().min(1, 'Translation is required'),
});

type AddKeyFormData = z.infer<typeof addKeySchema>;

interface AddKeyModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  languageCode: string;
  domain: string;
  onSuccess: () => void;
}

/**
 * Creates a new translation row for the currently-loaded language + domain.
 * The row's SCOPE follows the caller, not a form field — the system tenant
 * creates a system default, a regular tenant creates its own override — so
 * this form only ever asks for the key and the text.
 */
export function AddKeyModal({
  isOpen,
  onOpenChange,
  languageCode,
  domain,
  onSuccess,
}: AddKeyModalProps) {
  const { addToast } = useToast();
  const [isSubmitting, setIsSubmitting] = useState(false);

  const form = useForm<AddKeyFormData>({
    resolver: zodResolver(addKeySchema),
    defaultValues: { key: '', translation: '' },
  });

  const onSubmit = async (values: AddKeyFormData) => {
    setIsSubmitting(true);
    try {
      const { error } = await api.POST('/api/v1/translations', {
        body: {
          language_code: languageCode,
          domain,
          key: values.key,
          translation: values.translation,
        },
      });
      if (error) {
        throw new Error(errorMessage(error, 'Failed to create translation'));
      }

      addToast('Translation key added.', 'success');
      form.reset();
      onSuccess();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to create translation', 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        if (!open) form.reset();
        onOpenChange(open);
      }}
    >
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Add Translation Key</DialogTitle>
          <DialogDescription>
            Adds a new key for <strong>{languageCode}</strong> / <strong>{domain}</strong> in
            your current scope.
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="key"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Key</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., greeting" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="translation"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Translation</FormLabel>
                  <FormControl>
                    <Textarea rows={3} placeholder="e.g., Hello" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Adding...' : 'Add Key'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
