'use client';

import { useMemo, useState } from 'react';
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
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { errorMessage } from './shared';

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildCreateLanguageSchema = (t: TranslateFn) =>
  z.object({
    code: z
      .string()
      .min(1, t('languages.create.validation.codeRequired', 'Code is required'))
      .regex(
        /^[a-z]{2,10}(-[A-Za-z]{2,10})?$/,
        t(
          'languages.create.validation.codeFormat',
          'Use a language code like "en", "ar", or "en-US"'
        )
      ),
    name: z.string().min(1, t('languages.create.validation.nameRequired', 'Name is required')),
    // Direction is a property of the LANGUAGE (languages.direction), which is
    // exactly why it is asked for HERE: adding Hebrew, Farsi or Urdu is this
    // form, not a release. Nothing in the codebase infers direction from a code.
    direction: z.enum(['ltr', 'rtl']),
  });

type CreateLanguageFormData = z.infer<ReturnType<typeof buildCreateLanguageSchema>>;

interface CreateLanguageModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

export function CreateLanguageModal({ isOpen, onOpenChange, onSuccess }: CreateLanguageModalProps) {
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const schema = useMemo(() => buildCreateLanguageSchema(t), [t]);
  const form = useForm<CreateLanguageFormData>({
    resolver: zodResolver(schema),
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
        throw new Error(errorMessage(error, t('languages.create.error', 'Failed to create language')));
      }

      addToast(t('languages.create.success', 'Language created successfully'), 'success');
      form.reset();
      onSuccess();
    } catch (err) {
      addToast(
        err instanceof Error ? err.message : t('languages.create.error', 'Failed to create language'),
        'error'
      );
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
          <DialogTitle>{t('languages.create.title', 'Add Language')}</DialogTitle>
          <DialogDescription>
            {t(
              'languages.create.description',
              'New languages are added enabled by default and become selectable across the ' +
                'platform immediately.'
            )}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="code"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('languages.create.code.label', 'Language Code')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('languages.create.code.placeholder', 'e.g., fr')}
                      {...field}
                    />
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
                  <FormLabel>{t('languages.create.name.label', 'Display Name')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('languages.create.name.placeholder', 'e.g., Français')}
                      {...field}
                    />
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
                  <FormLabel>{t('languages.create.direction.label', 'Writing Direction')}</FormLabel>
                  <FormControl>
                    <select
                      {...field}
                      className="h-9 w-full rounded-md border border-input bg-input/20 px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    >
                      {/* The same two keys the Languages table's per-row picker
                          uses — one key, one English string, translated once. */}
                      <option value="ltr">{t('languages.direction.ltr', 'Left to right')}</option>
                      <option value="rtl">{t('languages.direction.rtl', 'Right to left')}</option>
                    </select>
                  </FormControl>
                  <FormDescription>
                    {t(
                      'languages.create.direction.hint',
                      'The interface mirrors automatically for anyone who selects this language — ' +
                        'there is no separate setting.'
                    )}
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                {t('languages.create.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting
                  ? t('languages.create.submitting', 'Adding...')
                  : t('languages.create.submit', 'Add Language')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
