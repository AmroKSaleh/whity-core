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
import { useFormattingLocale, useTranslation } from '@amroksaleh/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { DOCUMENTS_ROUTE } from '@/lib/capabilities';
import type { AudienceGroupOption } from '@amroksaleh/ui/audience-group-picker';
import type { AudiencePersonOption } from '@amroksaleh/ui/audience-people-picker';
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

/**
 * A picker's catalogue, plus the two DIFFERENT things that can be wrong with it.
 *
 * They were one field until #1015 and the conflation was a real defect: a
 * truncated pagination walk set the same `reason` a 403 set, the composer only
 * rendered that field when the list was EMPTY, and so a short list rendered as
 * though it were whole — an author could conclude a role did not exist and pick
 * the wrong one, which is precisely what `fetchAllPages`' `complete` flag exists
 * to prevent.
 *
 * `unavailable` means there is NO list and says why. `incomplete` means the list
 * is there but may be short. One is rendered instead of the control, the other
 * beside it.
 */
interface PickerCatalogue<T> {
  items: T[];
  unavailable: string | null;
  incomplete: string | null;
}

export default function DocumentRoutingPage() {
  const t = useTranslation('documents');
  const locale = useFormattingLocale();
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
  const rolesResult = useFetch<PickerCatalogue<RoleOption>>(async () => {
    const probe = await apiClient('/api/v1/roles?page=1');
    if (!probe.ok) {
      const body = (await probe.json().catch(() => null)) as
        | { error?: string; required?: string }
        | null;
      const required = body?.required;
      return {
        items: [],
        incomplete: null,
        unavailable:
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
      items: all.items.map((role) => ({ id: role.id, name: role.name })),
      unavailable: null,
      // MUST branch on `complete`: a truncated picker that looks whole would let
      // an author conclude a role does not exist and choose the wrong one.
      incomplete: all.complete
        ? null
        : t(
            'routing.compose.roles.partial',
            'Only some roles could be loaded, so this list may be incomplete.'
          ),
    };
  }, [apiClient]);

  /**
   * User groups, for the `group` kind's `group_id` (#1015).
   *
   * A 403 here is as EXPECTED as the one on roles, and for a related reason.
   * Migration 116 grants `groups:read` to whoever held `documents:route` AT THE
   * MOMENT IT RAN — which is a snapshot, not a standing implication: a role that
   * acquired `documents:route` afterwards, or holds it through inheritance, an OU
   * assignment or a delegation, was never seen by that grant. So somebody who may
   * route a document may perfectly well be unable to list groups, and that is a
   * reason to render, not an exception to throw.
   */
  const groupsResult = useFetch<PickerCatalogue<AudienceGroupOption>>(async () => {
    const probe = await apiClient('/api/v1/user-groups?page=1');
    if (!probe.ok) {
      const body = (await probe.json().catch(() => null)) as
        | { error?: string; required?: string }
        | null;
      const required = body?.required;
      return {
        items: [],
        incomplete: null,
        unavailable:
          probe.status === 403
            ? required !== undefined && required !== ''
              ? t(
                  'routing.compose.groups.forbiddenNamed',
                  'You cannot list user groups here, so a step cannot name one. An administrator would need to grant you {slug}.',
                  { slug: required }
                )
              : t(
                  'routing.compose.groups.forbidden',
                  'You cannot list user groups here, so a step cannot name one. An administrator would need to grant you permission to read user groups.'
                )
            : (body?.error ??
              t('routing.compose.groups.error', 'User groups could not be loaded.')),
      };
    }

    const all = await fetchAllPages<{ id: number; name: string; description: string | null }>(
      apiClient,
      '/api/v1/user-groups'
    );
    return {
      items: all.items.map((group) => ({
        id: group.id,
        name: group.name,
        description: group.description,
      })),
      unavailable: null,
      incomplete: all.complete
        ? null
        : t(
            'routing.compose.groups.partial',
            'Only some user groups could be loaded, so this list may be incomplete.'
          ),
    };
  }, [apiClient]);

  /**
   * People — two jobs, ONE request.
   *
   * The display names this page has always needed (see the file docblock), and
   * since #1015 the catalogue the `explicit` kind's picker searches. They are the
   * same rows behind the same `users:read` gate, and asking twice would mean two
   * walks of the same list that could disagree about how much of it arrived.
   */
  const peopleResult = useFetch<
    PickerCatalogue<AudiencePersonOption> & { names: Map<number, string> }
  >(async () => {
    const probe = await apiClient('/api/v1/users?page=1');
    if (!probe.ok) {
      const body = (await probe.json().catch(() => null)) as
        | { error?: string; required?: string }
        | null;
      const required = body?.required;
      return {
        items: [],
        names: new Map<number, string>(),
        incomplete: null,
        unavailable:
          probe.status === 403
            ? required !== undefined && required !== ''
              ? t(
                  'routing.compose.people.forbiddenNamed',
                  'You cannot list people here, so a step cannot name one by name. An administrator would need to grant you {slug}.',
                  { slug: required }
                )
              : t(
                  'routing.compose.people.forbidden',
                  'You cannot list people here, so a step cannot name one by name. An administrator would need to grant you permission to read people.'
                )
            : (body?.error ?? t('routing.compose.people.error', 'People could not be loaded.')),
      };
    }

    const all = await fetchAllPages<{ id: number; name: string; email?: string | null }>(
      apiClient,
      '/api/v1/users'
    );
    const names = new Map<number, string>();
    for (const person of all.items) {
      if (typeof person.name === 'string' && person.name !== '') names.set(person.id, person.name);
    }
    return {
      names,
      items: all.items.map((person) => ({
        id: person.id,
        name: typeof person.name === 'string' && person.name !== '' ? person.name : String(person.id),
        secondary: person.email ?? null,
      })),
      unavailable: null,
      incomplete: all.complete
        ? null
        : t(
            'routing.compose.people.partial',
            'Only some people could be loaded, so this list may be incomplete.'
          ),
    };
  }, [apiClient]);

  const roleNames = useMemo(() => {
    const map = new Map<number, string>();
    for (const role of rolesResult.data?.items ?? []) map.set(role.id, role.name);
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

      {peopleResult.data !== null &&
        (peopleResult.data.unavailable !== null || peopleResult.data.incomplete !== null) && (
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
              roles={rolesResult.data?.items ?? []}
              rolesUnavailableReason={rolesResult.data?.unavailable ?? null}
              rolesIncompleteReason={rolesResult.data?.incomplete ?? null}
              groups={groupsResult.data?.items ?? []}
              groupsUnavailableReason={groupsResult.data?.unavailable ?? null}
              groupsIncompleteReason={groupsResult.data?.incomplete ?? null}
              people={peopleResult.data?.items ?? []}
              peopleUnavailableReason={peopleResult.data?.unavailable ?? null}
              peopleIncompleteReason={peopleResult.data?.incomplete ?? null}
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
                    when: new Date(route.created_at).toLocaleString(locale),
                  })}
                </p>
              </CardHeader>
              <CardContent className="space-y-6">
                {/*
                  BOTH panels below read the recipient rows, and BOTH turn an
                  empty list into a definite statement: "this route reached
                  nobody", "nothing on this route is awaiting you". So neither
                  may be rendered until the rows have actually arrived.

                  Until this branch existed they were, and the result was a
                  route that had just reached two people announcing that it had
                  reached nobody — for as long as the second request took. The
                  claim was not merely premature, it was the OPPOSITE of the
                  truth, and it is the reading `RouteFanout`'s own comment says
                  the sentence exists to prevent: an empty list must never be
                  shown where "still loading" is what is true.

                  `routes` and `recipients` are two requests and the routes
                  resolve first, which is exactly why the gap is visible rather
                  than theoretical.

                  The gate is the FIRST load only — `loading` with nothing held
                  yet. `useFetch` raises `loading` again on every refetch, and an
                  act triggers one, so gating on `loading` alone UNMOUNTED the
                  act panel the instant somebody acted. That threw away the one
                  sentence the panel exists to show them ("your approval is
                  recorded; this step is still waiting on the others") before it
                  could be read (#1041). Holding the previous rows for the length
                  of a refetch does not reintroduce the #1039 defect: the claim
                  that was false was "this route reached nobody", and rows we
                  already have are not nobody.
                */}
                {recipients.error !== null ? (
                  <p className="text-sm text-muted-foreground">{recipients.error}</p>
                ) : recipients.loading && recipients.data === null ? (
                  <p className="text-sm text-muted-foreground" data-slot="routing-recipients-loading">
                    {t('routing.recipients.loading', 'Working out who this reached…')}
                  </p>
                ) : (
                  <>
                    <RouteFanout
                      route={route}
                      recipients={recipientRows}
                      profileNames={peopleResult.data?.names ?? new Map()}
                      roleNames={roleNames}
                      viewerProfileId={viewerProfileId}
                    />

                    <div className="border-t border-border pt-4">
                      <RouteActPanel
                        documentId={documentId}
                        route={route}
                        recipients={recipientRows}
                        viewerProfileId={viewerProfileId}
                        onActed={() => setVersion((v) => v + 1)}
                      />
                    </div>
                  </>
                )}
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
