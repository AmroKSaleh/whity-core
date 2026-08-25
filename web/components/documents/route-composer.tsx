'use client';

/**
 * Starting a route — choosing the steps a document will follow
 * (#978, over #989's engine).
 *
 * A STEP NAMES A RULE, NEVER A PERSON
 * -----------------------------------
 * This is the one thing the composer must get right, and it is a UI decision as
 * much as a schema one. `document_route_steps` has `rule_kind` + `rule_config`
 * and NO profile column, because a stored list of people is wrong the moment the
 * organisation changes: a unit created last week is not in a list authored last
 * month, and the route still reports success while omitting them.
 *
 * So every row here is a rule. "Everyone holding Instructor" is ONE row, and it
 * stays one row whether it resolves to four people or four thousand. The picker
 * offers rule kinds from `GET /api/v1/routing-rules` — core's four, plus
 * whatever a plugin registered through `RoutingRuleRegistry`.
 *
 * `explicit` ("exactly these people, by name") is the one core kind that
 * enumerates, and it is a KIND rather than an exception to kinds precisely so
 * the enumeration cannot leak into the others. It MEANS those three: it will not
 * pick up the fourth person who joins the department, the screen says so where
 * it is authored, and it is stored as one opaque config value rather than as the
 * membership table #999 rejected. See `ExplicitRuleResolver`.
 *
 * THE RULE-PREVIEW CONTRACT: A COUNT WHERE ONE IS KNOWABLE, NEVER A ROSTER
 * -----------------------------------------------------------------------
 * There is no "who will this ROUTE reach?" button, and that is deliberate rather
 * than unfinished. Three reasons, in order of how much they'd hurt:
 *
 *  1. THERE IS NOTHING TO PREVIEW FOR MOST STEPS. `DocumentRouter::issue()`
 *     resolves ONLY step 1. Steps 2..N are resolved relative to whoever actually
 *     acts, at the moment they act — that is what `role_below_actor` means, and
 *     it is why the same route reaches different people for a dean than for a
 *     faculty officer. A preview of step 3 would have to pick an actor who has
 *     not been chosen yet and present the answer as fact.
 *
 *  2. BUILDING ONE CLIENT-SIDE WOULD FORK THE RESOLVER. The honest answer needs
 *     the resolver's exact semantics — active memberships only, the DIRECT
 *     membership role and not role inheritance, resource-scoped grants excluded,
 *     subtree root-inclusive. Re-deriving that from `/api/v1/users` would be a
 *     second implementation of the thing #989 argues hardest against, and it
 *     would drift in whichever direction was last edited. It would also need
 *     `users:read`, which somebody who may route a document need not hold.
 *
 *  3. A ROSTER IS THE FAILURE THE RULE EXISTS TO AVOID. A design surface that
 *     renders 1,043 rows has recreated the problem, and worse, it invites the
 *     author to read the list as the thing that was saved.
 *
 * What the author gets instead is the engine's own count, reported the moment it
 * becomes true: the 201 from `POST .../routes` carries `resolved` (what the rule
 * found) and `delivered` (how many inbox rows that became after de-duplication).
 * `RoleRuleResolver`'s docblock names this as the intended feedback path — "an
 * author who picked the wrong role finds out in the response rather than in a
 * complaint six weeks later".
 *
 * THE ONE PRE-FLIGHT ANSWER THERE IS, AND WHY IT COSTS NONE OF THAT (#1015)
 * ------------------------------------------------------------------------
 * A `group` step names a STORED definition, and #999 already built the endpoint
 * that answers "who does group 7 resolve to right now":
 * `GET /api/v1/user-groups/{id}/preview`, an exact count plus a sample bounded
 * by the tenant's own `groups.preview_sample_size` (per-tenant, then global, then
 * the registry default). So choosing a group shows it, and none of the three
 * objections above applies:
 *
 *  - it is not a preview of THE ROUTE. It is a preview of the GROUP, which is a
 *    thing that exists whether or not any step names it, and which resolves with
 *    no document, route or actor-in-the-middle in the question;
 *  - nothing is forked. This client sends one request and renders the resolver's
 *    own answer, `truncated` included — it re-derives nothing, which is the
 *    whole reason the server states that flag instead of leaving it to be
 *    inferred from `total > sample.length`;
 *  - it is not a roster. A count, a handful of faces, and no way to ask for more
 *    — there is deliberately no page parameter on that endpoint.
 *
 * The answer carries its own caveat wherever it appears, because a group is a
 * RULE and there is no `user_group_members` table behind it: what is on screen is
 * true at the moment it was asked, and the step will be resolved again, against
 * the organisation as it stands, when the document actually moves.
 *
 * For the role kinds a pre-flight count is still absent, and the seam if one is
 * ever wanted is unchanged: a server-side `POST /api/documents/{id}/routes/preview`
 * that runs step 1's resolver. That belongs behind the resolver, not in front of
 * it.
 *
 * LIMITS ARE NOT MIRRORED, THEY ARE SURFACED
 * ------------------------------------------
 * `documents.routing_max_steps` and `documents.routing_max_recipients_per_step`
 * are per-tenant settings, and reading them needs `settings:read` — which a
 * person who may route a document need not hold. So this component does not
 * pre-validate against them. It sends the route and renders the engine's refusal
 * VERBATIM; those messages already name the number and the setting to raise
 * ("Narrow the rule, or raise documents.routing_max_recipients_per_step"). A
 * mirrored constant would be a second copy of a tenant-configurable number,
 * wrong on the first install that changed it, and wrong in the direction that
 * blocks legitimate work.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import {
  AudienceGroupPicker,
  type AudienceGroupOption,
  type AudienceGroupPreview,
  type AudienceGroupPreviewStatus,
} from '@amroksaleh/ui/audience-group-picker';
import {
  AudiencePeoplePicker,
  type AudiencePersonOption,
} from '@amroksaleh/ui/audience-people-picker';
import { IconArrowDown, IconArrowUp, IconPlus, IconTrash } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import type { DraftStep, IssueRouteResponse, RoutingRule } from './routing-wire';
import {
  EXPLICIT_KIND,
  GROUP_KIND,
  configuredGroupId,
  configuredProfileIds,
  configuredRoleId,
  isCoreConfiguredKind,
  isRoleConfiguredKind,
  isStepConfigured,
} from './routing-wire';

export interface RoleOption {
  id: number;
  name: string;
}

export interface RouteComposerProps {
  documentId: number;
  documentTitle: string;
  /** Kinds from `GET /api/v1/routing-rules`. */
  rules: RoutingRule[];
  /** Roles for the core kinds' `role_id`. Empty when they could not be read. */
  roles: RoleOption[];
  /**
   * Why `roles` is empty, when it is empty for a reason the user can act on
   * (typically a 403 for lacking `roles:read`). Rendered as an explanation, never
   * as an empty dropdown — #756: an empty state, never invented content.
   */
  rolesUnavailableReason: string | null;
  /**
   * Why a POPULATED list may not be all of them — a pagination walk that did not
   * finish.
   *
   * A separate prop from `rolesUnavailableReason` rather than the same one reused,
   * and the separation fixes a real defect: the host set that single prop to a
   * "only some roles could be loaded" sentence while ALSO passing the roles it
   * did get, and this component only ever rendered the prop when the list was
   * EMPTY. So a truncated picker rendered as though it were whole, which is
   * exactly the conclusion the host's own comment says must be prevented — an
   * author deciding a role does not exist and choosing the wrong one. The two
   * facts are different ("there is no list" / "the list may be short"), they are
   * rendered in different places, and they are now different props.
   */
  rolesIncompleteReason?: string | null;
  /**
   * User groups for the `group` kind's `group_id` — the reference kind, and the
   * reason groups exist (#1015). Empty when they could not be read.
   */
  groups: AudienceGroupOption[];
  /** Why `groups` is empty. Same contract as `rolesUnavailableReason`. */
  groupsUnavailableReason: string | null;
  /** Why `groups`, though populated, may be short. */
  groupsIncompleteReason?: string | null;
  /** People for the `explicit` kind's `profile_ids`. Empty when unreadable. */
  people: AudiencePersonOption[];
  /** Why `people` is empty. Same contract as `rolesUnavailableReason`. */
  peopleUnavailableReason: string | null;
  /** Why `people`, though populated, may be short. */
  peopleIncompleteReason?: string | null;
  onIssued: () => void;
  onCancel: () => void;
}

/** One group's membership snapshot as this component holds it. */
interface PreviewEntry {
  status: AudienceGroupPreviewStatus;
  preview: AudienceGroupPreview | null;
  error: string | null;
}

let draftKeySeed = 0;
const nextDraftKey = (): string => `step-${++draftKeySeed}`;

function newStep(kind: string): DraftStep {
  return { key: nextDraftKey(), rule_kind: kind, rule_config: {}, label: '' };
}

export function RouteComposer({
  documentId,
  documentTitle,
  rules,
  roles,
  rolesUnavailableReason,
  rolesIncompleteReason = null,
  groups,
  groupsUnavailableReason,
  groupsIncompleteReason = null,
  people,
  peopleUnavailableReason,
  peopleIncompleteReason = null,
  onIssued,
  onCancel,
}: RouteComposerProps) {
  const t = useTranslation('documents');
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const firstKind = rules.length > 0 ? rules[0].kind : '';
  const [title, setTitle] = useState('');
  const [steps, setSteps] = useState<DraftStep[]>(() =>
    firstKind === '' ? [] : [newStep(firstKind)]
  );

  /**
   * Seed the first step ONCE, whenever the kinds arrive.
   *
   * The initialiser above runs on the first render only, and a host that mounts
   * this component while `GET /api/v1/routing-rules` is still in flight passes
   * `rules: []` on that render. The composer then opened permanently empty —
   * "A route needs at least one step", with an Add-a-step button that could only
   * add a step with no kind. Reproducible by opening the composer promptly after
   * a page load, which is exactly what somebody who came to send a document
   * does.
   *
   * A ref rather than `steps.length === 0`, because the two are different
   * states: an author who has REMOVED every step meant to, and must not have one
   * put back under them. This fires at most once, on the transition from "no
   * kinds known" to "kinds known".
   */
  const seededFirstStep = useRef(firstKind !== '');
  useEffect(() => {
    if (seededFirstStep.current || firstKind === '') return;
    seededFirstStep.current = true;
    setSteps((current) => (current.length === 0 ? [newStep(firstKind)] : current));
  }, [firstKind]);
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);
  /**
   * Membership snapshots, keyed by GROUP id rather than by step.
   *
   * Two steps naming the same group are asking the same question, so they get one
   * request and one answer that cannot disagree with itself on the same screen.
   */
  const [previews, setPreviews] = useState<Record<number, PreviewEntry>>({});

  /**
   * Ask the server who a group currently reaches.
   *
   * `GET /api/v1/user-groups/{id}/preview` — #999's preview, not a second
   * mechanism. It answers with an exact count and a bounded sample whose size is
   * the tenant's `groups.preview_sample_size` (per-tenant, then global, then the
   * registry default), and this client neither knows nor mirrors that number: it
   * renders what it is sent, including `truncated`, which the server states rather
   * than leaving to be inferred.
   *
   * Deliberately re-fetched every time a group is chosen, even one seen a moment
   * ago. The answer is a snapshot of a live organisation, and a cached one shown
   * as current would be the very staleness a group exists to avoid.
   */
  const loadPreview = useCallback(
    async (groupId: number): Promise<void> => {
      setPreviews((current) => ({
        ...current,
        [groupId]: { status: 'loading', preview: null, error: null },
      }));

      const fail = (message: string): void => {
        setPreviews((current) => ({
          ...current,
          [groupId]: { status: 'error', preview: null, error: message },
        }));
      };

      try {
        const response = await apiClient(`/api/v1/user-groups/${groupId}/preview`);
        const body = (await response.json().catch(() => null)) as
          | {
              error?: string;
              data?: {
                total: number;
                truncated: boolean;
                sample_size: number;
                sample: { profile_id: number; display_name: string | null }[];
              };
            }
          | null;

        const data = body?.data;
        // `== null` catches BOTH an absent `data` and an explicit null. A body
        // that answered 200 with no payload is not a preview, and reading
        // `.total` off it would throw inside a state updater — which takes the
        // whole composer down over a failed side panel.
        if (!response.ok || data == null) {
          // Verbatim: a deleted group's refusal names it by id, and a 403 names
          // the permission that is missing.
          fail(body?.error ?? t('routing.compose.group.previewError', 'Who this group reaches could not be worked out.'));
          return;
        }

        setPreviews((current) => ({
          ...current,
          [groupId]: {
            status: 'ready',
            preview: {
              total: data.total,
              truncated: data.truncated,
              sampleSize: data.sample_size,
              members: (data.sample ?? []).map((member) => ({
                profileId: member.profile_id,
                displayName: member.display_name,
              })),
            },
            error: null,
          },
        }));
      } catch {
        fail(t('routing.compose.group.previewNetworkError', 'Who this group reaches could not be worked out.'));
      }
    },
    [apiClient, t]
  );

  const rulesByKind = useMemo(() => {
    const map = new Map<string, RoutingRule>();
    for (const rule of rules) map.set(rule.kind, rule);
    return map;
  }, [rules]);

  const updateStep = (key: string, patch: Partial<DraftStep>): void => {
    setSteps((current) => current.map((s) => (s.key === key ? { ...s, ...patch } : s)));
  };

  const move = (index: number, delta: number): void => {
    setSteps((current) => {
      const target = index + delta;
      if (target < 0 || target >= current.length) return current;
      const next = [...current];
      // Swap, not splice-and-insert: the array's ORDER is what becomes
      // `position`, and the server refuses a JSON object in place of a list
      // precisely so ordering is never implicit.
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  /**
   * Whether every step is complete enough to be worth sending.
   *
   * Only the shape this client is responsible for, which #1015 widened from two
   * kinds to four: a kind must be chosen, and each of CORE's kinds must carry the
   * config its own resolver requires. Everything else — the tenant's step ceiling,
   * the per-step recipient ceiling, a PLUGIN rule's required config — is still the
   * engine's to judge, and guessing at it here would block routes the engine would
   * have accepted.
   *
   * The rule for which kinds this client may speak for is `isStepConfigured`, and
   * it lives in `routing-wire` beside the readers that mirror each resolver — so
   * the answer to "is this step configured" and the answer to "what did the author
   * configure it with" cannot drift apart.
   */
  const incompleteStep = useMemo(() => {
    for (const [index, step] of steps.entries()) {
      if (step.rule_kind === '') return index;
      if (!isStepConfigured(step.rule_kind, step.rule_config)) return index;
    }
    return null;
  }, [steps]);

  const submitBlockedReason = useMemo<string | null>(() => {
    if (steps.length === 0) {
      // The engine's own sentence for the empty case.
      return t(
        'routing.compose.blocked.noSteps',
        'A route needs at least one step. A route with none would issue a document to nobody and record it as sent.'
      );
    }
    if (incompleteStep !== null) {
      return t('routing.compose.blocked.incomplete', 'Step {position} still needs a rule and its setting.', {
        position: incompleteStep + 1,
      });
    }
    return null;
  }, [steps.length, incompleteStep, t]);

  const submit = async (): Promise<void> => {
    setBusy(true);
    setRefusal(null);
    try {
      const response = await apiClient(`/api/v1/documents/${documentId}/routes`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          // Omitted when blank so the server falls back to the document's own
          // title, which is what it does and what an author almost always wants:
          // a route is a circulation OF something.
          ...(title.trim() === '' ? {} : { title: title.trim() }),
          // A JSON ARRAY, never an object. The engine indexes `position` from
          // this order and refuses an object rather than silently re-indexing.
          steps: steps.map((step) => ({
            rule_kind: step.rule_kind,
            rule_config: step.rule_config,
            ...(step.label.trim() === '' ? {} : { label: step.label.trim() }),
          })),
        }),
      });

      const body = (await response.json().catch(() => null)) as
        | (IssueRouteResponse & { error?: string })
        | null;

      if (!response.ok) {
        // Verbatim. The engine's 422s name the offending step by its position
        // and say what to do — "Step 2: the 'role' rule needs a 'role_id'…",
        // "Narrow the rule, or raise documents.routing_max_recipients_per_step".
        // Re-keying them would lose the number and the setting name.
        const message = body?.error ?? t('routing.compose.error', 'The route could not be created.');
        setRefusal(message);
        addToast(message, 'error');
        return;
      }

      const resolved = body?.resolved ?? 0;
      const delivered = body?.delivered ?? 0;

      addToast(
        resolved === 0
          ? // Not an error, and not silent either. #989 makes the empty
            // distribution VISIBLE on purpose: a role nobody holds resolves to
            // nobody, the trail records an empty distribution, and the author has
            // to be told now rather than by a complaint later.
            t(
              'routing.compose.issuedEmpty',
              'Route created, but its first step resolved to nobody — no active member holds that role. Nothing is awaiting anyone.'
            )
          : t(
              'routing.compose.issued',
              'Route created. Its first step resolved to {resolved} people and reached {delivered}.',
              { resolved, delivered }
            ),
        resolved === 0 ? 'error' : 'success'
      );
      onIssued();
    } catch {
      const message = t('routing.compose.error.network', 'The route could not be sent.');
      setRefusal(message);
      addToast(message, 'error');
    } finally {
      setBusy(false);
    }
  };

  if (rules.length === 0) {
    // No kinds registered at all. An empty picker would read as a loading state
    // that never resolves (#756).
    return (
      <Alert>
        <AlertDescription>
          {t(
            'routing.compose.noRules',
            'This installation has no routing rules registered, so a route cannot name anybody. Core provides two; a plugin can add more.'
          )}
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="space-y-4" data-slot="route-composer">
      <div>
        <label htmlFor="route-title" className="text-xs font-medium text-foreground">
          {t('routing.compose.title.label', 'Route name (optional)')}
        </label>
        <input
          id="route-title"
          type="text"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          maxLength={255}
          placeholder={documentTitle}
          className="mt-1 w-full rounded-md border border-border bg-background p-2 text-sm text-foreground"
        />
        <p className="mt-1 text-xs text-muted-foreground">
          {t('routing.compose.title.help', 'Left blank, the document’s own title is used.')}
        </p>
      </div>

      {/*
        The preview contract, said out loud where the author is authoring. Without
        it, the absence of a "who will get this" button reads as a missing
        feature; with it, the author knows the count is coming and when.
      */}
      <Alert>
        <AlertDescription>
          {t(
            'routing.compose.preview.contract',
            'Each step names a RULE, not people. Who it reaches is worked out when the document is sent — and for every step after the first, relative to whoever forwards it. You will be told how many people the first step reached as soon as the route is created.'
          )}
        </AlertDescription>
      </Alert>

      <ol className="space-y-3">
        {steps.map((step, index) => {
          const rule = rulesByKind.get(step.rule_kind);
          const needsRole = isRoleConfiguredKind(step.rule_kind);
          const selectedRoleId = configuredRoleId(step.rule_config);
          const selectedGroupId = configuredGroupId(step.rule_config);
          const groupPreview = selectedGroupId === null ? undefined : previews[selectedGroupId];

          return (
            <li
              key={step.key}
              className="rounded-md border border-border p-3"
              data-slot="route-composer-step"
            >
              <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-xs font-medium text-muted-foreground">
                  {t('routing.compose.step.ordinal', 'Step {position}', { position: index + 1 })}
                </span>
                <div className="flex items-center gap-1">
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('routing.compose.step.up', 'Move step earlier')}
                    disabled={index === 0}
                    onClick={() => move(index, -1)}
                  >
                    <IconArrowUp className="size-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('routing.compose.step.down', 'Move step later')}
                    disabled={index === steps.length - 1}
                    onClick={() => move(index, 1)}
                  >
                    <IconArrowDown className="size-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('routing.compose.step.remove', 'Remove step')}
                    onClick={() => setSteps((c) => c.filter((s) => s.key !== step.key))}
                  >
                    <IconTrash className="size-4" />
                  </Button>
                </div>
              </div>

              <div className="grid gap-2 sm:grid-cols-2">
                <div>
                  <label className="text-xs text-muted-foreground" htmlFor={`kind-${step.key}`}>
                    {t('routing.compose.step.rule', 'Rule')}
                  </label>
                  <Select
                    value={step.rule_kind}
                    onValueChange={(kind) =>
                      // The config belongs to the OLD kind, so it is dropped
                      // rather than carried across. Carrying it would let a
                      // `role_id` survive onto a plugin kind that means something
                      // else by the same key.
                      updateStep(step.key, { rule_kind: kind, rule_config: {} })
                    }
                  >
                    <SelectTrigger id={`kind-${step.key}`} className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {rules.map((option) => (
                        <SelectItem key={option.kind} value={option.kind}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {rule !== undefined && rule.source !== 'core' && (
                    <Badge variant="outline" className="mt-1">
                      {t('routing.compose.step.fromPlugin', 'from {plugin}', { plugin: rule.source })}
                    </Badge>
                  )}
                </div>

                {needsRole && (
                  <div>
                    <label className="text-xs text-muted-foreground" htmlFor={`role-${step.key}`}>
                      {t('routing.compose.step.role', 'Role')}
                    </label>
                    {roles.length === 0 ? (
                      <p className="mt-1 text-xs text-muted-foreground">
                        {/*
                          The reason, not an empty dropdown. Somebody who may
                          route a document does not necessarily hold `roles:read`
                          — migration 113 grants `documents:route` by capability
                          (every role holding `documents:render`), which says
                          nothing about reading the role catalogue.
                        */}
                        {rolesUnavailableReason ??
                          t('routing.compose.step.noRoles', 'No roles are available to name.')}
                      </p>
                    ) : (
                      <>
                        <Select
                          value={selectedRoleId === null ? undefined : String(selectedRoleId)}
                          onValueChange={(value) =>
                            updateStep(step.key, { rule_config: { role_id: Number(value) } })
                          }
                        >
                          <SelectTrigger id={`role-${step.key}`} className="mt-1">
                            <SelectValue
                              placeholder={t('routing.compose.step.rolePlaceholder', 'Choose a role')}
                            />
                          </SelectTrigger>
                          <SelectContent>
                            {roles.map((role) => (
                              <SelectItem key={role.id} value={String(role.id)}>
                                {role.name}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        {/*
                          A SHORT list is not an absent one. Until #1015 this
                          sentence was passed in and never rendered: the host set
                          it alongside a POPULATED `roles`, and the only branch
                          that drew it was the empty one. A picker missing rows
                          while looking whole is how an author concludes a role
                          does not exist and names the wrong one — the exact
                          failure the host's own comment says must be prevented.
                        */}
                        {rolesIncompleteReason !== null && (
                          <p
                            className="mt-1 text-xs text-muted-foreground"
                            data-slot="route-composer-roles-incomplete"
                          >
                            {rolesIncompleteReason}
                          </p>
                        )}
                      </>
                    )}
                  </div>
                )}
              </div>

              {/*
                The two kinds #1015 made authorable, each BELOW the grid rather
                than beside the rule select: both carry more than a dropdown — a
                membership snapshot, a set of chips — and a half-width column
                would squeeze the part that is actually the point.
              */}
              {step.rule_kind === GROUP_KIND && (
                <div className="mt-2" data-slot="route-composer-group-step">
                  <label className="text-xs text-muted-foreground" htmlFor={`group-${step.key}`}>
                    {t('routing.compose.step.group', 'User group')}
                  </label>
                  <div className="mt-1">
                    <AudienceGroupPicker
                      id={`group-${step.key}`}
                      groups={groups}
                      value={selectedGroupId}
                      onChange={(groupId) => {
                        updateStep(step.key, {
                          rule_config: groupId === null ? {} : { group_id: groupId },
                        });
                        // Fetched on CHOOSING, never on rendering: resolving a
                        // rule costs exactly what using it costs, so a composer
                        // that previewed every group in the list would fan out
                        // across the whole tenant just to draw a dropdown.
                        if (groupId !== null) void loadPreview(groupId);
                      }}
                      unavailableReason={groupsUnavailableReason}
                      incompleteReason={groupsIncompleteReason}
                      previewStatus={groupPreview?.status ?? 'idle'}
                      preview={groupPreview?.preview ?? null}
                      previewError={groupPreview?.error ?? null}
                      onRetryPreview={
                        selectedGroupId === null
                          ? undefined
                          : () => void loadPreview(selectedGroupId)
                      }
                      placeholder={t('routing.compose.step.groupPlaceholder', 'Choose a user group')}
                      emptyLabel={t(
                        'routing.compose.step.noGroups',
                        'No user groups have been defined here yet. An administrator can define one under User Groups, and a step can then name it.'
                      )}
                      previewLoadingLabel={t(
                        'routing.compose.step.groupPreviewLoading',
                        'Working out who this group reaches…'
                      )}
                      previewCountLabel={(total) =>
                        t('routing.compose.step.groupPreviewCount', 'Reaches {count} people right now.', {
                          count: total,
                        })
                      }
                      previewEmptyLabel={t(
                        'routing.compose.step.groupPreviewEmpty',
                        'This group resolves to nobody right now. A step naming it would reach no one.'
                      )}
                      previewSampleLabel={(shown, total) =>
                        t(
                          'routing.compose.step.groupPreviewSample',
                          'Showing {shown} of the {total} — a sample, not the whole set:',
                          { shown, total }
                        )
                      }
                      previewAllLabel={t('routing.compose.step.groupPreviewAll', 'That is everybody:')}
                      previewDynamicNote={t(
                        'routing.compose.step.groupDynamic',
                        'A group is a rule, not a saved list of people. Who it reaches is worked out again every time the document moves, so this is what it means right now — not a set that has been fixed in place.'
                      )}
                      unnamedMemberLabel={(profileId) =>
                        t('routing.compose.step.groupUnnamed', 'Profile #{id}', { id: profileId })
                      }
                      previewRetryLabel={t('routing.compose.step.groupRetry', 'Try again')}
                      unknownGroupLabel={(groupId) =>
                        t(
                          'routing.compose.step.groupUnknown',
                          'This step names user group #{id}, which is not in the list you can see — it may have been deleted, or you may not be able to read it. Choosing another group here would replace it.',
                          { id: groupId }
                        )
                      }
                    />
                  </div>
                </div>
              )}

              {step.rule_kind === EXPLICIT_KIND && (
                <div className="mt-2" data-slot="route-composer-explicit-step">
                  <label className="text-xs text-muted-foreground" htmlFor={`people-${step.key}`}>
                    {t('routing.compose.step.people', 'People')}
                  </label>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'routing.compose.step.peopleHelp',
                      'This step means exactly these people and nobody else — it will not pick up somebody who joins later. For a set that keeps up with the organisation, name a user group instead.'
                    )}
                  </p>
                  <div className="mt-1">
                    <AudiencePeoplePicker
                      id={`people-${step.key}`}
                      people={people}
                      value={configuredProfileIds(step.rule_config)}
                      onChange={(profileIds) =>
                        updateStep(step.key, {
                          rule_config: profileIds.length === 0 ? {} : { profile_ids: profileIds },
                        })
                      }
                      unavailableReason={peopleUnavailableReason}
                      incompleteReason={peopleIncompleteReason}
                      searchPlaceholder={t('routing.compose.step.peopleSearch', 'Search people by name')}
                      emptyLabel={t('routing.compose.step.noPeople', 'There is nobody here to name.')}
                      nothingSelectedLabel={t(
                        'routing.compose.step.peopleNothingSelected',
                        'Nobody chosen yet.'
                      )}
                      noMatchesLabel={t('routing.compose.step.peopleNoMatches', 'Nobody matches that.')}
                      moreMatchesLabel={(shown, total) =>
                        t(
                          'routing.compose.step.peopleMoreMatches',
                          'Showing {shown} of {total} matches — keep typing to narrow it down.',
                          { shown, total }
                        )
                      }
                      removeLabel={(name) =>
                        t('routing.compose.step.peopleRemove', 'Remove {name}', { name })
                      }
                      unknownPersonLabel={(profileId) =>
                        t('routing.compose.step.peopleUnknown', 'Profile #{id}', { id: profileId })
                      }
                    />
                  </div>
                </div>
              )}

              {!isCoreConfiguredKind(step.rule_kind) && (
                <p className="mt-2 text-xs text-muted-foreground">
                  {/*
                    A plugin kind whose config this client cannot author. It is
                    offered anyway — refusing to show it would hide a rule the
                    install genuinely has — and the engine's own validator is
                    what says whether an empty config is enough. Its 422 names
                    the step and quotes the plugin's message.
                  */}
                  {t(
                    'routing.compose.step.pluginConfig',
                    'This rule is configured by the plugin that provides it. It will be sent with no settings; if it needs some, the server will say which.'
                  )}
                </p>
              )}


              <div className="mt-2">
                <label className="text-xs text-muted-foreground" htmlFor={`label-${step.key}`}>
                  {t('routing.compose.step.label', 'Label shown on the step (optional)')}
                </label>
                <input
                  id={`label-${step.key}`}
                  type="text"
                  value={step.label}
                  onChange={(e) => updateStep(step.key, { label: e.target.value })}
                  className="mt-1 w-full rounded-md border border-border bg-background p-2 text-sm text-foreground"
                />
              </div>
            </li>
          );
        })}
      </ol>

      <Button variant="outline" onClick={() => setSteps((c) => [...c, newStep(firstKind)])}>
        <IconPlus className="size-4 me-1" />
        {t('routing.compose.addStep', 'Add a step')}
      </Button>

      {refusal !== null && (
        <Alert variant="destructive" data-slot="route-composer-refusal">
          <AlertDescription>{refusal}</AlertDescription>
        </Alert>
      )}

      <div className="flex flex-wrap items-start gap-2 border-t border-border pt-3">
        {/* Disabled with its reason, never hidden (#951). */}
        <div className="flex flex-col">
          <span className="inline-flex" title={submitBlockedReason ?? undefined}>
            <Button
              disabled={busy || submitBlockedReason !== null}
              aria-disabled={busy || submitBlockedReason !== null}
              onClick={submitBlockedReason !== null ? undefined : () => void submit()}
              data-slot="route-composer-submit"
            >
              {busy
                ? t('routing.compose.sending', 'Sending…')
                : t('routing.compose.send', 'Send document')}
            </Button>
            {submitBlockedReason !== null && (
              <span className="sr-only" role="note">
                {submitBlockedReason}
              </span>
            )}
          </span>
          {submitBlockedReason !== null && (
            <p className="mt-1 max-w-md text-xs text-muted-foreground">{submitBlockedReason}</p>
          )}
        </div>
        <Button variant="ghost" onClick={onCancel} disabled={busy}>
          {t('routing.compose.cancel', 'Cancel')}
        </Button>
      </div>
    </div>
  );
}
