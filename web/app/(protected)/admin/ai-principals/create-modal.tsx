'use client';

import { useState } from 'react';
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
import type { NewCredential } from './types';

const AVAILABLE_SCOPES = ['tools:call', 'resources:read', 'prompts:read'];

const createSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255, 'Name must not exceed 255 characters'),
  scope: z.array(z.string()).min(1, 'Select at least one scope'),
});

type CreateFormData = z.infer<typeof createSchema>;

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
  const [isSubmitting, setIsSubmitting] = useState(false);

  const form = useForm<CreateFormData>({
    resolver: zodResolver(createSchema),
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
        throw new Error(errorObj.message ?? 'Failed to create AI principal');
      }

      const credential = (await response.json()) as NewCredential;
      form.reset();
      onSuccess(credential);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to create AI principal';
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Create AI Principal</DialogTitle>
          <DialogDescription>
            Issue a new long-lived MCP bearer credential. The token value is
            shown only once — copy it immediately after creation.
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
                    <Input placeholder="e.g. Automation Bot" {...field} />
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
                  <FormLabel>Scopes</FormLabel>
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
                Cancel
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Creating...' : 'Create'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
