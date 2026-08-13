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
} from '@/components/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
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
import type { NewCredential } from './types';

// MCP scope identifiers, not copy: they are the protocol's own vocabulary and
// travel to the server verbatim, so they stay out of the catalogue.
const AVAILABLE_SCOPES = ['tools:call', 'resources:read', 'prompts:read'];

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildCreateSchema = (t: TranslateFn) =>
  z.object({
    name: z
      .string()
      .min(1, t('aiPrincipals.create.validation.nameRequired', 'Name is required'))
      .max(
        255,
        t('aiPrincipals.create.validation.nameMaxLength', 'Name must not exceed 255 characters')
      ),
    scope: z
      .array(z.string())
      .min(1, t('aiPrincipals.create.validation.scopeRequired', 'Select at least one scope')),
  });

type CreateFormData = z.infer<ReturnType<typeof buildCreateSchema>>;

interface CreateAiPrincipalModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: (credential: NewCredential) => void;
}

export function CreateAiPrincipalModal({
  isOpen,
  onOpenChange,
  onSuccess,
}: CreateAiPrincipalModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const schema = useMemo(() => buildCreateSchema(t), [t]);

  const form = useForm<CreateFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      scope: ['tools:call'],
    },
  });

  const selectedScope = form.watch('scope');

  const toggleScope = (scope: string) => {
    const current = form.getValues('scope');
    const updated = current.includes(scope)
      ? current.filter((s) => s !== scope)
      : [...current, scope];
    form.setValue('scope', updated, { shouldValidate: true });
  };

  const onSubmit = async (data: CreateFormData) => {
    try {
      setIsSubmitting(true);
      const response = await apiClient('/api/v1/mcp/tokens', {
        method: 'POST',
        body: JSON.stringify({ name: data.name, scope: data.scope }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        const errorObj = errorData as { message?: string };
        throw new Error(
          errorObj.message ?? t('aiPrincipals.create.error', 'Failed to create AI principal')
        );
      }

      const credential = (await response.json()) as NewCredential;
      form.reset();
      onSuccess(credential);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('aiPrincipals.create.error', 'Failed to create AI principal');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('aiPrincipals.create.title', 'Create AI Principal')}</DialogTitle>
          <DialogDescription>
            {t(
              'aiPrincipals.create.description',
              'Issue a new long-lived MCP bearer credential. The token value is ' +
                'shown only once — copy it immediately after creation.'
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
                  <FormLabel>{t('aiPrincipals.create.name.label', 'Name')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t(
                        'aiPrincipals.create.name.placeholder',
                        'e.g. Automation Bot'
                      )}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="scope"
              render={() => (
                <FormItem>
                  <FormLabel>{t('aiPrincipals.create.scopes.label', 'Scopes')}</FormLabel>
                  <div className="flex flex-wrap gap-2 pt-1">
                    {AVAILABLE_SCOPES.map((scope) => (
                      <button
                        key={scope}
                        type="button"
                        onClick={() => toggleScope(scope)}
                        className={[
                          'rounded-md border px-3 py-1 text-xs font-medium transition-colors',
                          selectedScope.includes(scope)
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-background text-foreground hover:bg-muted',
                        ].join(' ')}
                      >
                        {scope}
                      </button>
                    ))}
                  </div>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={isSubmitting}
              >
                {t('aiPrincipals.create.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting
                  ? t('aiPrincipals.create.submitting', 'Creating...')
                  : t('aiPrincipals.create.submit', 'Create')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
