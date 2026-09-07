'use client';

import { useEffect, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import type { PluginFeature } from '@/lib/plugin-features';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { ErrorState } from '@amroksaleh/ui/empty-state';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
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
 * The SHAPE of a bespoke override screen for a plugin feature: it fetches the
 * plugin's greetings on mount, lets the caller post a new one, and degrades to
 * an access-denied card on a 403.
 *
 * NOT REGISTERED, DELIBERATELY (#964). This was registered against the
 * HelloWorld plugin's `hello-greetings` feature back when the registry was
 * write-only — nothing it registered ever rendered (the registrations ran in
 * the server's module graph, the lookup happened in the browser's), so the
 * feature has been served by the generic schema-driven CRUD screen since the
 * day this file was written. Fixing the registry meant deciding which of those
 * two screens `hello-greetings` should actually have, and the generic one has
 * since grown everything this screen lacks: capability-gated controls that are
 * disabled WITH A REASON rather than absent (#199/#951/#953), edit and delete,
 * and a record route with a real address (#948). This screen would have shown
 * a delegate an enabled "Add greeting" form that 403s on submit — so switching
 * to it would have been a regression across three shipped issues, on the only
 * `screen: 'crud'` feature in the repo.
 *
 * The live reference for the override mechanism is `DemoCatalogScreen`
 * (`components/demo-catalog/demo-catalog-screen.tsx`), registered in
 * `lib/plugin-screens.tsx` for the DemoCatalog plugin's `screen: 'custom'`
 * feature — which has no host-derived screen to displace, and so demonstrates
 * the mechanism without trading anything away for it. This file stays as the
 * pattern's worked example (it has a Storybook gallery entry alongside it) and
 * as what a plugin would write when the block DSL genuinely cannot express its
 * screen.
 */
export function HelloGreetingsScreen({ feature }: { feature: PluginFeature }) {
  const { addToast } = useToast();
  const t = useTranslation('plugin');
  const dates = useDateDisplay();

  const [greetings, setGreetings] = useState<Greeting[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const [message, setMessage] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Resolved here and passed into the effect as a STRING rather than depending
  // on `t` itself: `t`'s identity changes when the translation bundle arrives,
  // and an identity-compared dependency would re-run the fetch on every load.
  // A string dependency is compared by value, so the English case never re-runs.
  const loadErrorMessage = t('hello.load.error', 'Failed to load greetings');

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
          throw new Error(await readErrorMessage(response, loadErrorMessage));
        }

        const body: unknown = await response.json();
        setGreetings(toGreetings(body));
      } catch (error) {
        addToast(error instanceof Error ? error.message : loadErrorMessage, 'error');
      } finally {
        setIsLoading(false);
      }
    };

    void load();
  }, [reloadKey, addToast, loadErrorMessage]);

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
          await readErrorMessage(
            response,
            t('hello.create.error', 'Failed to create greeting')
          ),
          'error'
        );
        return;
      }
      addToast(t('hello.create.success', 'Greeting created successfully'), 'success');
      setMessage('');
      refetch();
    } finally {
      setIsSubmitting(false);
    }
  };

  const description = t(
    'hello.description',
    'Greetings provided by the {plugin} plugin. This screen is a bespoke override demonstrating the custom-screen pattern.',
    { plugin: feature.plugin }
  );

  if (isForbidden) {
    return (
      <div className="space-y-8">
        <AdminHeader title={feature.label} description={description} />
        <ErrorState
          icon={<IconShieldLock />}
          title={t('hello.accessDenied.title', 'Access denied')}
          description={t(
            'hello.accessDenied.description',
            'You need the {permission} permission to use this feature.',
            { permission: feature.requiredPermission }
          )}
        />
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
          placeholder={t('hello.message.placeholder', 'Write a greeting...')}
          aria-label={t('hello.message.label', 'Greeting message')}
          disabled={isSubmitting}
          onChange={(event) => setMessage(event.target.value)}
        />
        <Button
          type="submit"
          className="gap-2"
          disabled={isSubmitting || message.trim() === ''}
        >
          <IconPlus size={18} />
          {isSubmitting
            ? t('hello.submit.pending', 'Adding...')
            : t('hello.submit', 'Add greeting')}
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
          <h2 className="font-heading text-sm font-medium">
            {t('hello.empty.title', 'No greetings yet')}
          </h2>
          <p className="mt-1 text-xs text-muted-foreground">
            {t('hello.empty.description', 'Add the first greeting above to get started.')}
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
                  {/*
                    #1068. Two changes: the stamp is FORMATTED at all (it was
                    the raw wire string interpolated straight into the line),
                    and the whole ` · <when>` segment drops out when this tenant
                    hides dates, leaving the id on its own rather than a
                    dangling separator.
                  */}
                  <p className="mt-1 text-xs text-muted-foreground">
                    #{greeting.id}
                    {(() => {
                      const created = dates.dateTime(greeting.createdAt);
                      return created === null ? null : ` · ${created}`;
                    })()}
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
