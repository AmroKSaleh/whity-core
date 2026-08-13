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
} from '@/components/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
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
  // Direction is a property of the LANGUAGE (languages.direction), which is
  // exactly why it is asked for HERE: adding Hebrew, Farsi or Urdu is this
  // form, not a release. Nothing in the codebase infers direction from a code.
  direction: z.enum(['ltr', 'rtl']),
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
    defaultValues: { code: '', name: '', direction: 'ltr' },
  });

  const onSubmit = async (values: CreateLanguageFormData) => {
    setIsSubmitting(true);
    try {
      const { error } = await api.POST('/api/v1/languages', {
        body: {
          code: values.code,
          name: values.name,
          direction: values.direction,
          enabled: true,
        },
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

            <FormField
              control={form.control}
              name="direction"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Writing Direction</FormLabel>
                  <FormControl>
                    <select
                      {...field}
                      className="h-9 w-full rounded-md border border-input bg-input/20 px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    >
                      <option value="ltr">Left to right</option>
                      <option value="rtl">Right to left</option>
                    </select>
                  </FormControl>
                  <FormDescription>
                    The interface mirrors automatically for anyone who selects this language —
                    there is no separate setting.
                  </FormDescription>
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
