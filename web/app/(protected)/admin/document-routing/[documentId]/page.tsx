'use client';

/**
 * One document's circulation — its routes, where each has actually got to, and
 * what the viewer may do about it (#978, over #989's engine).
 *
 * WHY THIS PAGE EXISTS BESIDE THE DOCUMENT RECORD PAGE
 * ----------------------------------------------------
 * The document RECORD page (#986's viewer plus the trail) is being built in
 * parallel and is the natural long-term host for these controls. This page is
 * deliberately a THIN HOST: every piece of routing UI lives in
 * `web/components/documents/route-*.tsx` and takes plain props, so the record
 * page can mount `RouteComposer`, `RouteFanout` and `RouteActPanel` without this
 * file being involved and without either surface forking a second copy.
 *
 * It is not merely a placeholder, though. An inbox item needs somewhere to land
 * that is about ACTING — a person who opened "Documents awaiting you" wants the
 * three buttons, not a reader — and a route with four figures of recipients
 * needs room to show its branches that a record page's sidebar does not have.
 *
 * WHAT THIS PAGE DELIBERATELY DOES NOT RENDER
 * -------------------------------------------
 * The TRAIL. `GET /api/v1/documents/{id}/trail` is the record page's, and two
 * renderings of an append-only trail would be exactly the divergence #947 wrote
 * the single engine to avoid — with the added trap that the trail is paginated
 * oldest-first, so two implementations would disagree about what "the last
 * thing that happened" is. There is one link to it and no second copy.
 *
 * NAMES ARE BEST-EFFORT, AND SAY SO WHEN THEY ARE MISSING
 * ------------------------------------------------------
 * `GET .../recipients` publishes `profile_id` and no display name, and there is
 * no batch profile-name endpoint. So names come from `GET /api/v1/users`,
 * best-effort, and anything not covered renders as its id with a visible note —
 * the pattern the document organizer already uses for unit names ("shown by
 * id"). Never a blank, never a guess (#756).
 *
 * The rejected alternative was `GET /api/v1/users/{id}` per recipient. A step
 * whose rule is "everyone holding a role" can resolve to four figures of people,
 * so that is four figures of requests to render one page — and it would fail
 * exactly on the documents that most need this view.
 */

import { useMemo, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { EmptyState, ErrorState } from '@amroksaleh/ui/empty-state';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { IconRoute } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { DOCUMENTS_ROUTE } from '@/lib/capabilities';
import { RouteComposer, type RoleOption } from '@/components/documents/route-composer';
import { RouteFanout } from '@/components/documents/route-fanout';
import { RouteActPanel } from '@/components/documents/route-act-panel';
import type {
  RecipientsResponse,
  RouteRecipient,
  RoutesResponse,
  RoutingRulesResponse,
} from '@/components/documents/routing-wire';

interface DocumentSummary {
  id: number;
  title: string;
}

export default function DocumentRoutingPage() {
  const t = useTranslation('documents');
  const { apiClient, user } = useAuth();
  const { has, loading: capsLoading } = useCapabilities();
  const router = useRouter();
  const params = useParams<{ documentId: string }>();

  const documentId = Number(params.documentId);
  const [composing, setComposing] = useState(false);
  /** Bumped after any successful act so both routes and recipients refetch. */
  const [version, setVersion] = useState(0);

  const viewerProfileId = user?.id ?? null;

  const document = useFetch<DocumentSummary>(async () => {
    const response = await apiClient(`/api/v1/documents/${documentId}`);
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(body?.error ?? t('routing.error.document', 'This document could not be loaded.'));
    }
    const body = (await response.json()) as { data: DocumentSummary };
    return body.data;
  }, [apiClient, documentId]);

  const routes = useFetch<RoutesResponse>(async () => {
    const response = await apiClient(`/api/v1/documents/${documentId}/routes`);
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(body?.error ?? t('routing.error.routes', 'This document’s routes could not be loaded.'));
    }
    return (await response.json()) as RoutesResponse;
  }, [apiClient, documentId, version]);

  const recipients = useFetch<RecipientsResponse>(async () => {
    const response = await apiClient(`/api/v1/documents/${documentId}/recipients`);
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(
        body?.error ?? t('routing.error.recipients', 'The recipients of this document could not be loaded.')
      );
    }
    return (await response.json()) as RecipientsResponse;
  }, [apiClient, documentId, version]);

  const rules = useFetch<RoutingRulesResponse>(async () => {
    const response = await apiClient('/api/v1/routing-rules');
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(body?.error ?? t('routing.error.rules', 'The routing rules could not be loaded.'));
    }
    return (await response.json()) as RoutingRulesResponse;
  }, [apiClient]);

  /**
   * Roles, for the core rules' `role_id`.
   *
   * A 403 here is EXPECTED, not exceptional: `documents:route` is granted by
   * migration 113 to every role holding `documents:render`, which says nothing
   * about `roles:read`. So the failure is captured as a REASON the composer can
   * render rather than thrown — throwing would take down the whole page over a
   * picker.
   */
  const rolesResult = useFetch<{ roles: RoleOption[]; reason: string | null }>(async () => {
    const probe = await apiClient('/api/v1/roles?page=1');
    if (!probe.ok) {
      const body = (await probe.json().catch(() => null)) as
        | { error?: string; required?: string }
        | null;
      const required = body?.required;
      return {
        roles: [],
        reason:
          probe.status === 403
            ? // The server's own `required` slug when it sent one, resolved by
              // NAME. Never a hardcoded permission id: #992 removed eight slugs
              // and the low id range has holes, so an id is not even stable
              // across installs.
              required !== undefined && required !== ''
                ? t(
                    'routing.compose.roles.forbiddenNamed',
                    'You cannot list roles here, so a rule cannot name one. An administrator would need to grant you {slug}.',
                    { slug: required }
                  )
                : t(
                    'routing.compose.roles.forbidden',
                    'You cannot list roles here, so a rule cannot name one. An administrator would need to grant you permission to read roles.'
                  )
            : (body?.error ?? t('routing.compose.roles.error', 'Roles could not be loaded.')),
      };
    }

    const all = await fetchAllPages<RoleOption>(apiClient, '/api/v1/roles');
    return {
      roles: all.items.map((role) => ({ id: role.id, name: role.name })),
      // MUST branch on `complete`: a truncated picker that looks whole would let
      // an author conclude a role does not exist and choose the wrong one.
      reason: all.complete
        ? null
        : t(
            'routing.compose.roles.partial',
            'Only some roles could be loaded, so this list may be incomplete.'
          ),
    };
  }, [apiClient]);

  /** Display names, best-effort. See the file docblock. */
  const profileNames = useFetch<{ names: Map<number, string>; complete: boolean }>(async () => {
    const probe = await apiClient('/api/v1/users?page=1');
    if (!probe.ok) return { names: new Map<number, string>(), complete: false };

    const all = await fetchAllPages<{ id: number; name: string }>(apiClient, '/api/v1/users');
    const names = new Map<number, string>();
    for (const person of all.items) {
      if (typeof person.name === 'string' && person.name !== '') names.set(person.id, person.name);
    }
    return { names, complete: all.complete };
  }, [apiClient]);

  const roleNames = useMemo(() => {
    const map = new Map<number, string>();
    for (const role of rolesResult.data?.roles ?? []) map.set(role.id, role.name);
    return map;
  }, [rolesResult.data]);

  const recipientRows = useMemo<RouteRecipient[]>(
    () => recipients.data?.data ?? [],
    [recipients.data]
  );

  const routeList = routes.data?.data ?? [];

  const onIssued = (): void => {
    setComposing(false);
    setVersion((v) => v + 1);
  };

  if (!Number.isInteger(documentId) || documentId <= 0) {
    return (
      <ErrorState
        title={t('routing.badId.title', 'Not a document')}
        description={t('routing.badId.description', 'The address does not name a document.')}
      />
    );
  }

  if (document.error !== null) {
    return (
      <div>
        <AdminHeader title={t('routing.title', 'Circulation')} />
        <ErrorState title={t('routing.error.document', 'This document could not be loaded.')} description={document.error} />
      </div>
    );
  }

  // Fail-closed while capabilities are in flight, matching the house policy:
  // an unresolved permission renders no write control rather than one that 403s.
  const canRoute = !capsLoading && has(DOCUMENTS_ROUTE);
  const routeDeniedReason = capsLoading
    ? t('routing.gate.loading', 'Checking what you may do…')
    : t('routing.gate.denied', 'Requires {slug}', { slug: DOCUMENTS_ROUTE });

  return (
    <div>
      <AdminHeader
        title={document.data?.title ?? t('routing.title', 'Circulation')}
        description={t('routing.description', 'Where this document has been sent, and what you can do about it.')}
        action={
          <div className="flex flex-col items-end">
            <span className="inline-flex" title={canRoute ? undefined : routeDeniedReason}>
              <Button
                disabled={!canRoute || composing}
                aria-disabled={!canRoute || composing}
                onClick={canRoute && !composing ? () => setComposing(true) : undefined}
                data-slot="routing-start"
              >
                <IconRoute className="size-4 me-1" />
                {t('routing.start', 'Send this document')}
              </Button>
              {!canRoute && (
                <span className="sr-only" role="note">
                  {routeDeniedReason}
                </span>
              )}
            </span>
            {/* Disabled with its reason, never hidden (#951). */}
            {!canRoute && (
              <p className="mt-1 max-w-xs text-xs text-muted-foreground">{routeDeniedReason}</p>
            )}
          </div>
        }
      />

      {profileNames.data !== null && !profileNames.data.complete && (
        <Alert className="mb-4">
          <AlertDescription>
            {t(
              'routing.names.partial',
              'People’s names could not all be loaded, so some recipients are shown by id.'
            )}
          </AlertDescription>
        </Alert>
      )}

      {composing && (
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>{t('routing.compose.heading', 'Choose the steps this document follows')}</CardTitle>
          </CardHeader>
          <CardContent>
            <RouteComposer
              documentId={documentId}
              documentTitle={document.data?.title ?? ''}
              rules={rules.data?.data ?? []}
              roles={rolesResult.data?.roles ?? []}
              rolesUnavailableReason={rolesResult.data?.reason ?? null}
              onIssued={onIssued}
              onCancel={() => setComposing(false)}
            />
          </CardContent>
        </Card>
      )}

      {routes.error !== null ? (
        <p className="rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
          {routes.error}
        </p>
      ) : routes.loading ? (
        <p className="text-sm text-muted-foreground">{t('routing.loading', 'Loading…')}</p>
      ) : routeList.length === 0 ? (
        /*
          A document with no route SAYS SO. #756: never an empty trail that reads
          as "nothing happened" — there is nothing to have happened, because this
          document has never been circulated, and those are different facts.
        */
        <EmptyState
          title={t('routing.empty.title', 'This document has not been circulated')}
          description={t(
            'routing.empty.description',
            'It has no route, so nobody has been asked to act on it and there is nothing in its trail. Sending it creates a route: an ordered set of steps, each naming a rule rather than a person.'
          )}
        />
      ) : (
        <div className="space-y-6">
          {routeList.map((route) => (
            <Card key={route.id} data-slot="routing-route">
              <CardHeader>
                <CardTitle className="text-base">{route.title}</CardTitle>
                <p className="text-xs text-muted-foreground">
                  {t('routing.route.raised', 'Raised {when}', {
                    when: new Date(route.created_at).toLocaleString(),
                  })}
                </p>
              </CardHeader>
              <CardContent className="space-y-6">
                {recipients.error !== null ? (
                  <p className="text-sm text-muted-foreground">{recipients.error}</p>
                ) : (
                  <RouteFanout
                    route={route}
                    recipients={recipientRows}
                    profileNames={profileNames.data?.names ?? new Map()}
                    roleNames={roleNames}
                    viewerProfileId={viewerProfileId}
                  />
                )}

                <div className="border-t border-border pt-4">
                  <RouteActPanel
                    documentId={documentId}
                    route={route}
                    recipients={recipientRows}
                    viewerProfileId={viewerProfileId}
                    onActed={() => setVersion((v) => v + 1)}
                  />
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <div className="mt-6">
        <Button variant="ghost" onClick={() => router.push('/admin/document-library')}>
          {t('routing.backToLibrary', 'Back to documents')}
        </Button>
      </div>
    </div>
  );
}
