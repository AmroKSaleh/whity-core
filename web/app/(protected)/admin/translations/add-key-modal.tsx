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
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { errorMessage } from '../languages/shared';

/**
 * Built from `t` rather than declared at module scope: a validation message is
 * user-facing text like any other, and a schema frozen at import time would
 * always speak English. The translate function is passed in — the shape the
 * sign-in screen's `ssoErrorMessage(t, reason)` established.
 */
const buildAddKeySchema = (t: TranslateFn) =>
  z.object({
    key: z.string().min(1, t('translations.add.keyRequired', 'Key is required')),
    translation: z
      .string()
      .min(1, t('translations.add.translationRequired', 'Translation is required')),
  });

type AddKeyFormData = z.infer<ReturnType<typeof buildAddKeySchema>>;

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
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const schema = useMemo(() => buildAddKeySchema(t), [t]);
  const form = useForm<AddKeyFormData>({
    resolver: zodResolver(schema),
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
        throw new Error(
          errorMessage(error, t('translations.add.error', 'Failed to create translation'))
        );
      }

      addToast(t('translations.add.success', 'Translation key added.'), 'success');
      form.reset();
      onSuccess();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('translations.add.error', 'Failed to create translation'),
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
          <DialogTitle>{t('translations.add.title', 'Add Translation Key')}</DialogTitle>
          {/* One sentence with two holes, not four concatenated fragments around
              two <strong> tags: the order of "key", language and domain differs
              between languages, and a sentence assembled from pieces cannot be
              reordered by a translator. */}
          <DialogDescription>
            {t(
              'translations.add.description',
              'Adds a new key for {language} / {domain} in your current scope.',
              { language: languageCode, domain }
            )}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="key"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('translations.add.key', 'Key')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('translations.add.keyPlaceholder', 'e.g., greeting')}
                      {...field}
                    />
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
                  <FormLabel>{t('translations.add.translation', 'Translation')}</FormLabel>
                  <FormControl>
                    <Textarea
                      rows={3}
                      placeholder={t('translations.add.translationPlaceholder', 'e.g., Hello')}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                {t('translations.add.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting
                  ? t('translations.add.submitting', 'Adding...')
                  : t('translations.add.submit', 'Add Key')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
