'use client';

import { useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
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
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';

// Slug validation: lowercase, hyphens, no spaces or special chars
const slugRegex = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildCreateTenantSchema = (t: TranslateFn) =>
  z.object({
    name: z.string().min(1, t('tenants.create.validation.nameRequired', 'Name is required')),
    slug: z.string()
      .min(1, t('tenants.create.validation.slugRequired', 'Slug is required'))
      .regex(
        slugRegex,
        t(
          'tenants.create.validation.slugFormat',
          'Slug must contain only lowercase letters, numbers, and hyphens'
        )
      ),
  });

type CreateTenantFormData = z.infer<ReturnType<typeof buildCreateTenantSchema>>;

interface CreateTenantModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

// Generate slug from name: lowercase, replace spaces and special chars with hyphens
function generateSlug(name: string): string {
  return name
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '') // Remove special characters
    .replace(/[\s_-]+/g, '-') // Replace spaces and underscores with hyphens
    .replace(/^-+|-+$/g, ''); // Remove leading/trailing hyphens
}

export function CreateTenantModal({
  isOpen,
  onOpenChange,
  onSuccess,
}: CreateTenantModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const schema = useMemo(() => buildCreateTenantSchema(t), [t]);
  const form = useForm<CreateTenantFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      slug: '',
    },
  });

  const handleNameChange = (value: string) => {
    form.setValue('name', value);
    // Auto-generate slug only if slug field is empty (initial state)
    if (!form.getValues('slug') || form.getValues('slug') === generateSlug(form.getValues('name'))) {
      form.setValue('slug', generateSlug(value));
    }
  };

  const onSubmit = async (data: CreateTenantFormData) => {
    try {
      setIsSubmitting(true);

      const response = await apiClient('/api/v1/tenants', {
        method: 'POST',
        body: JSON.stringify({
          name: data.name,
          slug: data.slug,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || t('tenants.create.error', 'Failed to create tenant')
        );
      }

      addToast(t('tenants.create.success', 'Tenant created successfully'), 'success');
      form.reset();
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('tenants.create.error', 'Failed to create tenant');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('tenants.create.title', 'Create New Tenant')}</DialogTitle>
          <DialogDescription>
            {t(
              'tenants.create.description',
              'Add a new tenant to your system. The slug is auto-generated but can be customized.'
            )}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('tenants.create.name.label', 'Name')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('tenants.create.name.placeholder', 'My Company')}
                      {...field}
                      onChange={(e) => handleNameChange(e.target.value)}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="slug"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('tenants.create.slug.label', 'Slug')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('tenants.create.slug.placeholder', 'my-company')}
                      {...field}
                    />
                  </FormControl>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'tenants.create.slug.hint',
                      'Auto-generated from name, but you can customize it'
                    )}
                  </p>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                {t('tenants.create.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting
                  ? t('tenants.create.submitting', 'Creating...')
                  : t('tenants.create.submit', 'Create Tenant')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
