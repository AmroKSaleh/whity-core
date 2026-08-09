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
import { errorMessage } from './shared';

const createLanguageSchema = z.object({
  code: z
    .string()
    .min(1, 'Code is required')
    .regex(/^[a-z]{2,10}(-[A-Za-z]{2,10})?$/, 'Use a language code like "en", "ar", or "en-US"'),
  name: z.string().min(1, 'Name is required'),
});

type CreateLanguageFormData = z.infer<typeof createLanguageSchema>;

interface CreateLanguageModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

export function CreateLanguageModal({ isOpen, onOpenChange, onSuccess }: CreateLanguageModalProps) {
  const { addToast } = useToast();
  const [isSubmitting, setIsSubmitting] = useState(false);

  const form = useForm<CreateLanguageFormData>({
    resolver: zodResolver(createLanguageSchema),
    defaultValues: { code: '', name: '' },
  });

  const onSubmit = async (values: CreateLanguageFormData) => {
    setIsSubmitting(true);
    try {
      const { error } = await api.POST('/api/v1/languages', {
        body: { code: values.code, name: values.name, enabled: true },
      });
      if (error) {
        throw new Error(errorMessage(error, 'Failed to create language'));
      }

      addToast('Language created successfully', 'success');
      form.reset();
      onSuccess();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to create language', 'error');
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
          <DialogTitle>Add Language</DialogTitle>
          <DialogDescription>
            New languages are added enabled by default and become selectable across the
            platform immediately.
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="code"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Language Code</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., fr" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Display Name</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g., Français" {...field} />
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
                {isSubmitting ? 'Adding...' : 'Add Language'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
