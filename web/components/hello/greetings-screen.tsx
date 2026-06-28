'use client';

import { useEffect, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import type { PluginFeature } from '@/lib/plugin-features';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@whity/ui/button';
import { Input } from '@whity/ui/input';
import { Skeleton } from '@whity/ui/skeleton';
import {
  IconAlertTriangle,
  IconMessageCircle,
  IconPlus,
  IconShieldLock,
} from '@tabler/icons-react';

/**
 * A single greeting row from the HelloWorld plugin
 * (`GET /api/v1/hello/greetings`).
 */
interface Greeting {
  id: number;
  tenantId: number;
  message: string;
  createdAt: string | null;
}

/** Narrow one raw list item to a Greeting, or null when it doesn't fit. */
function toGreeting(item: unknown): Greeting | null {
  if (typeof item !== 'object' || item === null) {
    return null;
  }
  const record = item as Record<string, unknown>;
  const id = record['id'];
  const tenantId = record['tenantId'];
  const message = record['message'];
  const createdAt = record['createdAt'];
  if (typeof id !== 'number' || typeof message !== 'string') {
    return null;
  }
  return {
    id,
    tenantId: typeof tenantId === 'number' ? tenantId : 0,
    message,
    createdAt: typeof createdAt === 'string' ? createdAt : null,
  };
}

/** Narrow a `{ data: unknown[] }` envelope to a typed list of greetings. */
function toGreetings(body: unknown): Greeting[] {
  const data =
    typeof body === 'object' && body !== null && 'data' in body
      ? (body as { data: unknown }).data
      : null;
  if (!Array.isArray(data)) {
    return [];
  }
  const greetings: Greeting[] = [];
  for (const item of data) {
    const greeting = toGreeting(item);
    if (greeting !== null) {
      greetings.push(greeting);
    }
  }
  return greetings;
}

/**
 * Extract the backend's `{ error: string }` message from a failed response,
 * falling back when the body is absent or not JSON.
 */
async function readErrorMessage(
  response: Response,
  fallback: string
): Promise<string> {
  try {
    const body: unknown = await response.json();
    if (typeof body === 'object' && body !== null && 'error' in body) {
      const message = (body as { error: unknown }).error;
      if (typeof message === 'string' && message.length > 0) {
        return message;
      }
    }
  } catch {
    // No JSON body — use the fallback.
  }
  return fallback;
}

/**
 * Bespoke override screen for the HelloWorld plugin's `hello-greetings`
 * feature — the canonical reference for the custom-screen pattern.
 *
 * The dynamic feature host resolves this component via the plugin UI registry
 * (see `lib/plugin-ui-registry.tsx`), so it ALWAYS wins over the generic
 * schema-driven renderer. It fetches the plugin's greetings on mount, lets the
 * caller post a new one, and degrades to an access-denied card on a 403.
 */
export function HelloGreetingsScreen({ feature }: { feature: PluginFeature }) {
  const { addToast } = useToast();

  const [greetings, setGreetings] = useState<Greeting[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const [message, setMessage] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    // The fetcher lives inside the effect so no setState runs synchronously in
    // the effect body (react-hooks/set-state-in-effect).
    const load = async (): Promise<void> => {
      setIsLoading(true);
      try {
        const response = await apiClient('/api/v1/hello/greetings');

        if (response.status === 403) {
          setIsForbidden(true);
          setGreetings([]);
          return;
        }
        setIsForbidden(false);

        if (!response.ok) {
          throw new Error(
            await readErrorMessage(response, 'Failed to load greetings')
          );
        }

        const body: unknown = await response.json();
        setGreetings(toGreetings(body));
      } catch (error) {
        const fallback = 'Failed to load greetings';
        addToast(error instanceof Error ? error.message : fallback, 'error');
      } finally {
        setIsLoading(false);
      }
    };

    void load();
  }, [reloadKey, addToast]);

  const refetch = () => setReloadKey((key) => key + 1);

  const handleSubmit = async () => {
    const trimmed = message.trim();
    if (trimmed === '') {
      return;
    }

    try {
      setIsSubmitting(true);
      const response = await apiClient('/api/v1/hello/greetings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: trimmed }),
      });
      if (!response.ok) {
        addToast(
          await readErrorMessage(response, 'Failed to create greeting'),
          'error'
        );
        return;
      }
      addToast('Greeting created successfully', 'success');
      setMessage('');
      refetch();
    } finally {
      setIsSubmitting(false);
    }
  };

  const description =
    `Greetings provided by the ${feature.plugin} plugin. ` +
    'This screen is a bespoke override demonstrating the custom-screen pattern.';

  if (isForbidden) {
    return (
      <div className="space-y-8">
        <AdminHeader title={feature.label} description={description} />
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconShieldLock
            size={32}
            className="mx-auto mb-3 text-muted-foreground"
          />
          <h2 className="font-heading text-sm font-medium">Access denied</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            You need the {feature.requiredPermission} permission to use this
            feature.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-8">
      <AdminHeader title={feature.label} description={description} />

      <form
        className="flex items-start gap-3"
        onSubmit={(event) => {
          event.preventDefault();
          void handleSubmit();
        }}
      >
        <Input
          value={message}
          maxLength={255}
          placeholder="Write a greeting..."
          aria-label="Greeting message"
          disabled={isSubmitting}
          onChange={(event) => setMessage(event.target.value)}
        />
        <Button
          type="submit"
          className="gap-2"
          disabled={isSubmitting || message.trim() === ''}
        >
          <IconPlus size={18} />
          {isSubmitting ? 'Adding...' : 'Add greeting'}
        </Button>
      </form>

      {isLoading ? (
        <div className="space-y-3">
          <Skeleton className="h-16 w-full rounded-lg" />
          <Skeleton className="h-16 w-full rounded-lg" />
          <Skeleton className="h-16 w-full rounded-lg" />
        </div>
      ) : greetings.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border bg-card p-10 text-center">
          <IconAlertTriangle
            size={32}
            className="mx-auto mb-3 text-muted-foreground"
          />
          <h2 className="font-heading text-sm font-medium">No greetings yet</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            Add the first greeting above to get started.
          </p>
        </div>
      ) : (
        <ul className="space-y-3">
          {greetings.map((greeting) => (
            <li
              key={greeting.id}
              className="rounded-lg border border-border bg-card p-4"
            >
              <div className="flex items-start gap-3">
                <IconMessageCircle
                  size={20}
                  className="mt-0.5 shrink-0 text-muted-foreground"
                />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-foreground">
                    {greeting.message}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    #{greeting.id}
                    {greeting.createdAt !== null && ` · ${greeting.createdAt}`}
                  </p>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
