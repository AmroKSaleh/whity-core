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
 * offers rule kinds from `GET /api/v1/routing-rules` — core's `role` and
 * `role_below_actor`, plus whatever a plugin registered through
 * `RoutingRuleRegistry` — and never a person picker.
 *
 * THE RULE-PREVIEW CONTRACT: A COUNT, AFTER THE FACT, AND NO ROSTER EVER
 * ---------------------------------------------------------------------
 * There is no "who will this reach?" button, and that is deliberate rather than
 * unfinished. Three reasons, in order of how much they'd hurt:
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
 * If a pre-flight count is wanted later, the seam is a server-side
 * `POST /api/documents/{id}/routes/preview` that runs step 1's resolver and
 * returns `{count, sample}` — the count authoritative, the sample capped. That
 * belongs behind the resolver, not in front of it. It is flagged as follow-up
 * rather than built here, because a preview endpoint is engine surface and this
 * change is a UI one.
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

import { useMemo, useState } from 'react';
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
import { IconArrowDown, IconArrowUp, IconPlus, IconTrash } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import type { DraftStep, IssueRouteResponse, RoutingRule } from './routing-wire';
import { isRoleConfiguredKind } from './routing-wire';

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
  onIssued: () => void;
  onCancel: () => void;
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
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);

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
   * Only the shape this client is responsible for: a kind must be chosen, and a
   * role-configured kind must name a role. Everything else — the tenant's step
   * ceiling, a plugin rule's own required config — is the engine's to judge, and
   * guessing at it here would block routes the engine would have accepted.
   */
  const incompleteStep = useMemo(() => {
    for (const [index, step] of steps.entries()) {
      if (step.rule_kind === '') return index;
      if (isRoleConfiguredKind(step.rule_kind) && step.rule_config['role_id'] === undefined) {
        return index;
      }
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
          const selectedRoleId = step.rule_config['role_id'];

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

                {needsRole ? (
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
                      <Select
                        value={selectedRoleId === undefined ? '' : String(selectedRoleId)}
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
                    )}
                  </div>
                ) : (
                  <div>
                    <p className="mt-5 text-xs text-muted-foreground">
                      {/*
                        A plugin kind whose config this client cannot author. It is
                        offered anyway — refusing to show it would hide a rule the
                        install genuinely has — and the engine's own validator is
                        what says whether `{}` is enough. Its 422 names the step
                        and quotes the plugin's message.
                      */}
                      {t(
                        'routing.compose.step.pluginConfig',
                        'This rule is configured by the plugin that provides it. It will be sent with no settings; if it needs some, the server will say which.'
                      )}
                    </p>
                  </div>
                )}
              </div>

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
