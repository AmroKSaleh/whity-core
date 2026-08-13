'use client';

import { useMemo, useState, useEffect } from 'react';
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
import type { Tenant } from './page';

// Slug validation: lowercase, hyphens, no spaces or special chars
const slugRegex = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildEditTenantSchema = (t: TranslateFn) =>
  z.object({
    name: z.string().min(1, t('tenants.edit.validation.nameRequired', 'Name is required')),
    slug: z.string()
      .min(1, t('tenants.edit.validation.slugRequired', 'Slug is required'))
      .regex(
        slugRegex,
        t(
          'tenants.edit.validation.slugFormat',
          'Slug must contain only lowercase letters, numbers, and hyphens'
        )
      ),
  });

type EditTenantFormData = z.infer<ReturnType<typeof buildEditTenantSchema>>;

interface EditTenantModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  tenant: Tenant;
  onSuccess: () => void;
}

export function EditTenantModal({
  isOpen,
  onOpenChange,
  tenant,
  onSuccess,
}: EditTenantModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const schema = useMemo(() => buildEditTenantSchema(t), [t]);
  const form = useForm<EditTenantFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: tenant.name || '',
      slug: tenant.slug || '',
    },
  });

  useEffect(() => {
    if (isOpen && tenant) {
      form.reset({
        name: tenant.name,
        slug: tenant.slug,
      });
    }
  }, [isOpen, tenant, form]);

  const onSubmit = async (data: EditTenantFormData) => {
    try {
      setIsSubmitting(true);

      const response = await apiClient(`/api/v1/tenants/${tenant.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name: data.name,
          slug: data.slug,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || 'Failed to update tenant'
        );
      }

      addToast('Tenant updated successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to update tenant';
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit Tenant</DialogTitle>
          <DialogDescription>
            Update tenant information.
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Name</FormLabel>
                  <FormControl>
                    <Input placeholder="My Company" {...field} />
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
                  <FormLabel>Slug</FormLabel>
                  <FormControl>
                    <Input placeholder="my-company" {...field} />
                  </FormControl>
                  <p className="text-xs text-muted-foreground">
                    URL-friendly identifier for this tenant
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
                Cancel
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Saving...' : 'Save Changes'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
