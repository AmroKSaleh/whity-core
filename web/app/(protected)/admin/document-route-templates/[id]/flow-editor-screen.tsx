'use client';

/**
 * The node-based route-flow editor screen (#1027).
 *
 * The CANVAS is `@amroksaleh/ui/route-flow/editor` — a kit component, because a
 * graph editor is precisely what a second client wants and anything in
 * `web/components/` ships to one of three. This file is the HOST: it fetches,
 * it resolves audiences, it renders the inspector whose pickers are bound to
 * this app's APIs, and it saves.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE ONE THING THIS SCREEN EXISTS TO GET RIGHT
 * ─────────────────────────────────────────────────────────────────────────
 * A node is a TYPE. "Everyone holding Instructor" is one card, and it stays one
 * card whether it resolves to four people or four thousand. There is no code
 * path here that turns a rule into a list of people — not on the canvas, not in
 * what is saved.
 *
 * What the author gets instead is the COUNT, resolved live and shown on the card:
 * "reaches 1,043 people · all must approve". That single line is what makes the
 * safe quorum default survivable — an author who leaves `all` on a
 * 1,043-person node reads what it means while authoring, rather than finding out
 * in November when nothing has moved.
 *
 * The count comes from #1003's preview, which already answers exactly this
 * question with a count and a bounded sample. Only the count is used; the sample
 * is ignored on purpose, because a surface that rendered 1,043 names would have
 * rebuilt the problem the design exists to avoid.
 *
 * WHICH preview endpoint depends on the rule kind, and that is not an
 * optimisation — `POST /user-groups/preview` refuses `rule_kind: "group"`
 * outright, because a group cannot be DEFINED as another group. A group stage is
 * previewed through the stored group it names instead. See
 * `audiencePreviewRequest` in ../types.ts for the whole argument; getting this
 * wrong put a message about defining groups on the most important node type.
 *
 * A 403 is EXPECTED rather than exceptional — the draft preview is gated on
 * `groups:write` because it resolves an arbitrary composed rule, and somebody who
 * may design a flow does not necessarily hold it. The card then shows the reason
 * instead of a number. NEVER a zero: a zero reads as "this reaches nobody", which
 * is a very different and much more alarming statement than "you cannot see this".
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DIRECTION
 * ─────────────────────────────────────────────────────────────────────────
 * The canvas defaults to a VERTICAL flow, which reads identically in Arabic and
 * English and needs no mirroring — the argument is in the kit model's
 * `slotAt`. The orientation toggle is offered anyway, and when an author picks
 * horizontal the kit mirrors every horizontal axis (and the handles) from the
 * `direction` this screen passes down. Direction is read from the app's
 * language-derived `useDirection()` and never from a preference of its own.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useDirection } from '@/lib/direction-context';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { Switch } from '@amroksaleh/ui/switch';
import { RouteFlowEditor, appendStep } from '@amroksaleh/ui/route-flow/editor';
import {
  effectiveQuorum,
  type RouteFlowAudience,
  type RouteFlowGraph,
  type RouteFlowOrientation,
  type RouteFlowQuorum,
  type RouteFlowStep,
} from '@amroksaleh/ui/route-flow/model';
import { IconDeviceFloppy, IconPlus } from '@tabler/icons-react';
import type { RoutingRule } from '@/components/documents/routing-wire';
import { AudienceGroupPicker, type AudienceGroupPreview } from '@amroksaleh/ui/audience-group-picker';
import {
  audiencePreviewRequest,
  toAudienceGroupPreview,
  toFlowGraph,
  toGraphRequest,
  type AudiencePreviewWire,
  type RouteTemplateGraphWire,
} from '../types';

interface RoleOption {
  id: number;
  name: string;
}

interface GroupOption {
  id: number;
  name: string;
}

/** The two core kinds whose config is `{ role_id }`. */
const ROLE_KINDS = new Set(['role', 'role_below_actor']);
const GROUP_KIND = 'group';

/**
 * What a NEW stage names, in order of preference.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A LIST HERE AND NOT `rules[0]`
 * ─────────────────────────────────────────────────────────────────────────
 * It was `rules[0]`. `GET /api/v1/routing-rules` returns its catalogue sorted by
 * kind, so `rules[0]` is `explicit` — and every stage anybody added therefore
 * arrived as "Specific people, chosen by name", which is:
 *
 *  - unconfigurable HERE, because {@link StageInspector} has editors for role
 *    kinds and for groups and says so plainly for anything else, so the first
 *    thing an author did could not be finished; and
 *  - the enumerate-individuals shape this whole feature exists to replace. A
 *    node is a TYPE of person. Defaulting to the one kind that is a list of
 *    names taught the opposite in the first five seconds.
 *
 * The fix is NOT to reorder the server's response. A client that depends on the
 * order of a catalogue is the defect; the ordering is just what exposed it. So
 * the preference is stated here, and it is stated as the kinds this screen can
 * actually EDIT — which is the property that matters, and which changes when
 * this file changes rather than when a server sorts differently.
 *
 * Falls back to whatever the server does offer if none of these are installed,
 * because a stage has to name something.
 */
const NEW_STAGE_KIND_PREFERENCE = ['role', 'group', 'role_below_actor'];

const QUORUMS: RouteFlowQuorum[] = ['all', 'any', 'majority'];

export function RouteFlowEditorScreen({
  templateId,
  canWrite,
}: {
  templateId: number;
  canWrite: boolean;
}) {
  const { apiClient } = useAuth();
  const t = useTranslation('admin');

  const templateResult = useFetch(async () => {
    const response = await apiClient(`/api/v1/document-route-templates/${templateId}`);
    if (!response.ok) {
      throw new Error(t('routeTemplates.error.loadOne', 'This route template could not be loaded'));
    }
    const body = (await response.json()) as { data: RouteTemplateGraphWire };
    return body.data;
  }, [apiClient, templateId]);

  /** The kinds a stage may name, from the server's own registry. */
  const rulesResult = useFetch(async () => {
    const response = await apiClient('/api/v1/routing-rules');
    if (!response.ok) return [] as RoutingRule[];
    const body = await response.json();
    return (body.data ?? []) as RoutingRule[];
  }, [apiClient]);

  /**
   * Roles and groups for the two core config shapes.
   *
   * A 403 on either is expected rather than exceptional — designing a flow does
   * not imply `roles:read` or `groups:read` — so both fail SOFT into an empty
   * list with a reason, instead of throwing and taking the canvas down with the
   * picker.
   */
  const rolesResult = useFetch(async () => {
    const response = await apiClient('/api/v1/roles?per_page=100');
    if (!response.ok) return [] as RoleOption[];
    const body = await response.json();
    return ((body.data ?? []) as RoleOption[]).map((r) => ({ id: r.id, name: r.name }));
  }, [apiClient]);

  const groupsResult = useFetch(async () => {
    const response = await apiClient('/api/v1/user-groups?per_page=100');
    if (!response.ok) return [] as GroupOption[];
    const body = await response.json();
    return ((body.data ?? []) as GroupOption[]).map((g) => ({ id: g.id, name: g.name }));
  }, [apiClient]);

  if (templateResult.error !== null) {
    return <p className="text-sm text-destructive">{templateResult.error}</p>;
  }
  if (templateResult.data === null) {
    return <p className="text-sm text-muted-foreground">{t('routeTemplates.loading', 'Loading…')}</p>;
  }

  // The editing body mounts only once the design has arrived, so its state can be
  // INITIALISED from it rather than synced into it by an effect. That is not a
  // lint workaround: an effect that copies fetched data into state renders once
  // with an empty canvas and once with the real one, and anything that ran in
  // between — an audience lookup, a fit-to-view — did so against a graph with no
  // nodes in it. Keyed by the template id so navigating between designs starts
  // clean instead of showing the previous one's unsaved edits.
  return (
    <RouteFlowBody
      key={templateId}
      templateId={templateId}
      template={templateResult.data}
      canWrite={canWrite}
      rules={rulesResult.data ?? []}
      roles={rolesResult.data ?? []}
      groups={groupsResult.data ?? []}
    />
  );
}

function RouteFlowBody({
  templateId,
  template,
  canWrite,
  rules,
  roles,
  groups,
}: {
  templateId: number;
  template: RouteTemplateGraphWire;
  canWrite: boolean;
  rules: RoutingRule[];
  roles: RoleOption[];
  groups: GroupOption[];
}) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { dir } = useDirection();
  const t = useTranslation('admin');

  const [graph, setGraph] = useState<RouteFlowGraph>(() => toFlowGraph(template));
  const [selected, setSelected] = useState<number | null>(null);
  const [orientation, setOrientation] = useState<RouteFlowOrientation>('vertical');
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);

  const maxSteps = template.max_steps;

  // ── audience resolution ───────────────────────────────────────────────────
  //
  // Keyed by the rule ITSELF (kind + config), not by the step's position. Two
  // stages naming the same rule are one question, and a position-keyed cache
  // would re-ask it on every renumber — which happens on every delete.
  // The count feeds the CANVAS (one number per node, never a roster) and the full
  // preview feeds the INSPECTOR's group picker, which draws the snapshot itself.
  // One request answers both, so they are cached together under one key rather
  // than fetched twice.
  const [audiences, setAudiences] = useState<Record<string, RouteFlowAudience>>({});
  const [previews, setPreviews] = useState<Record<string, AudienceGroupPreview>>({});
  const inFlight = useRef<Set<string>>(new Set());

  const audienceKey = useCallback(
    (step: RouteFlowStep) => `${step.ruleKind}::${JSON.stringify(step.ruleConfig)}`,
    []
  );

  useEffect(() => {
    for (const step of graph.steps) {
      const key = audienceKey(step);
      if (key in audiences || inFlight.current.has(key)) continue;

      // A rule with no configuration yet is not a question worth asking — the
      // server would refuse it, and the refusal would be shown on the card as
      // though the rule were broken rather than merely unfinished. Which
      // endpoint answers depends on the KIND; see `audiencePreviewRequest`.
      const request = audiencePreviewRequest(step.ruleKind, step.ruleConfig);
      if (request === null) continue;

      inFlight.current.add(key);
      void (async () => {
        try {
          const response = await apiClient(request.url, request.init);

          if (!response.ok) {
            const body = (await response.json().catch(() => null)) as { error?: string } | null;
            setAudiences((prev) => ({
              ...prev,
              [key]: {
                count: null,
                unavailableReason:
                  response.status === 403
                    ? t(
                        'routeTemplates.audience.forbidden',
                        'You cannot preview audiences here, so the size of this stage is not shown.'
                      )
                    : (body?.error ??
                      t('routeTemplates.audience.error', 'Audience size could not be resolved.')),
              },
            }));
            return;
          }

          // `total`, not `count` — the field #1003's presenter actually emits.
          const body = (await response.json()) as AudiencePreviewWire;
          setAudiences((prev) => ({ ...prev, [key]: { count: body.data.total } }));
          setPreviews((prev) => ({ ...prev, [key]: toAudienceGroupPreview(body) }));
        } finally {
          inFlight.current.delete(key);
        }
      })();
    }
  }, [apiClient, audienceKey, audiences, graph, t]);

  const audienceFor = useCallback(
    (step: RouteFlowStep): RouteFlowAudience | undefined => audiences[audienceKey(step)],
    [audienceKey, audiences]
  );

  const ruleLabelFor = useCallback(
    (step: RouteFlowStep): string => {
      const rule = rules.find((r) => r.kind === step.ruleKind);
      const base = rule?.label ?? step.ruleKind;

      // The card shows WHICH role or group, not just "everyone holding a role" —
      // otherwise every node in a four-stage approval reads identically.
      if (ROLE_KINDS.has(step.ruleKind)) {
        const roleId = step.ruleConfig.role_id;
        const role = roles.find((r) => r.id === roleId);
        return role !== undefined ? `${base}: ${role.name}` : base;
      }
      if (step.ruleKind === GROUP_KIND) {
        const groupId = step.ruleConfig.group_id;
        const group = groups.find((g) => g.id === groupId);
        return group !== undefined ? `${base}: ${group.name}` : base;
      }

      return base;
    },
    [groups, roles, rules]
  );

  // ── mutation ──────────────────────────────────────────────────────────────

  const change = useCallback((next: RouteFlowGraph) => {
    setGraph(next);
    setDirty(true);
  }, []);

  const addStage = useCallback(() => {
    const offered = new Set(rules.map((r) => r.kind));
    const kind =
      NEW_STAGE_KIND_PREFERENCE.find((k) => offered.has(k)) ??
      rules[0]?.kind ??
      NEW_STAGE_KIND_PREFERENCE[0];

    const next = appendStep(
      graph,
      { ruleKind: kind, ruleConfig: {}, label: null, decision: false, decisionQuorum: null },
      orientation,
      dir
    );
    change(next);
    setSelected(next.steps[next.steps.length - 1].position);
  }, [change, dir, graph, orientation, rules]);

  const updateSelected = useCallback(
    (patch: Partial<RouteFlowStep>) => {
      if (selected === null) return;
      change({
        ...graph,
        steps: graph.steps.map((s) => (s.position === selected ? { ...s, ...patch } : s)),
      });
    },
    [change, graph, selected]
  );

  const save = useCallback(async () => {
    setSaving(true);
    try {
      const response = await apiClient(`/api/v1/document-route-templates/${templateId}/graph`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(toGraphRequest(graph)),
      });

      if (!response.ok) {
        // The server's 422 names the stage and the reason ("Step 3: the 'group'
        // rule needs a 'group_id' ..."), which is exactly what an author needs
        // to act. Shown verbatim rather than replaced.
        const body = (await response.json().catch(() => null)) as { error?: string } | null;
        addToast(
          body?.error ?? t('routeTemplates.error.save', 'The flow could not be saved'),
          'error'
        );
        return;
      }

      const body = (await response.json()) as { data: RouteTemplateGraphWire };
      setGraph(toFlowGraph(body.data));
      setDirty(false);
      addToast(t('routeTemplates.saved', 'Flow saved'), 'success');
    } finally {
      setSaving(false);
    }
  }, [addToast, apiClient, graph, t, templateId]);

  const selectedStep = useMemo(
    () => graph.steps.find((s) => s.position === selected) ?? null,
    [graph, selected]
  );

  const atCeiling = graph.steps.length >= maxSteps;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="me-auto text-lg font-semibold">{template.name}</h1>

        <Select
          value={orientation}
          onValueChange={(v) => setOrientation(v as RouteFlowOrientation)}
        >
          <SelectTrigger className="w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="vertical">
              {t('routeTemplates.orientation.vertical', 'Top to bottom')}
            </SelectItem>
            <SelectItem value="horizontal">
              {t('routeTemplates.orientation.horizontal', 'Along the text direction')}
            </SelectItem>
          </SelectContent>
        </Select>

        {canWrite && (
          <>
            <Button variant="outline" onClick={addStage} disabled={atCeiling}>
              <IconPlus className="me-1 size-4" />
              {t('routeTemplates.action.addStage', 'Add stage')}
            </Button>
            <Button onClick={save} disabled={!dirty || saving}>
              <IconDeviceFloppy className="me-1 size-4" />
              {t('routeTemplates.action.save', 'Save flow')}
            </Button>
          </>
        )}
      </div>

      {atCeiling && (
        <Alert>
          <AlertDescription>
            {t(
              'routeTemplates.atCeiling',
              'This flow has as many stages as this tenant allows ({max}). Raise documents.routing_max_steps to add more.',
              { max: String(maxSteps) }
            )}
          </AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <RouteFlowEditor
          graph={graph}
          onGraphChange={change}
          selectedPosition={selected}
          onSelectStep={setSelected}
          orientation={orientation}
          direction={dir}
          // The tenant's own ceiling, not the kit's readability default: the
          // server is the authority on how many stages may be saved.
          maxNodes={maxSteps}
          readOnly={!canWrite}
          audienceFor={audienceFor}
          ruleLabelFor={ruleLabelFor}
          labels={{
            empty: t('routeTemplates.canvas.empty', 'No stages yet. Add the first one to start the flow.'),
            decision: t('routeTemplates.canvas.decision', 'Decision'),
            reaches: t('routeTemplates.canvas.reaches', 'Reaches'),
            people: t('routeTemplates.canvas.people.other', 'people'),
            person: t('routeTemplates.canvas.people.one', 'person'),
            audienceUnavailable: t('routeTemplates.canvas.audienceUnavailable', 'Audience size unavailable'),
            approved: t('routeTemplates.canvas.approved', 'Approved'),
            rejected: t('routeTemplates.canvas.rejected', 'Rejected'),
            continues: t('routeTemplates.canvas.continues', 'Continues'),
            implicit: t('routeTemplates.canvas.implicit', 'implicit'),
            ends: t('routeTemplates.canvas.ends', 'Ends here'),
            arrivalsMerge: t(
              'routeTemplates.canvas.arrivalsMerge',
              'Paths merge — 1 item per person'
            ),
            inCycle: t('routeTemplates.canvas.inCycle', 'Can come back round — loops'),
            deleteStep: t('routeTemplates.canvas.deleteStep', 'Delete stage'),
            quorumAll: t('routeTemplates.quorum.all', 'all must approve'),
            quorumAny: t('routeTemplates.quorum.any', 'any one may approve'),
            quorumMajority: t('routeTemplates.quorum.majority', 'a majority must approve'),
            tooManyNodes: t(
              'routeTemplates.canvas.tooMany',
              'This flow is larger than the canvas will draw. Showing the first stages only.'
            ),
          }}
        />

        <aside className="space-y-3 rounded-lg border border-border bg-card p-4">
          {selectedStep === null ? (
            <p className="text-sm text-muted-foreground">
              {t(
                'routeTemplates.inspector.none',
                'Select a stage to edit who it reaches and what it asks of them.'
              )}
            </p>
          ) : (
            <StageInspector
              step={selectedStep}
              graph={graph}
              rules={rules}
              roles={roles}
              groups={groups}
              audience={audienceFor(selectedStep)}
              preview={previews[audienceKey(selectedStep)] ?? null}
              readOnly={!canWrite}
              onChange={updateSelected}
              t={t}
            />
          )}
        </aside>
      </div>
    </div>
  );
}

/**
 * "Reaches N people right now", in the form the count calls for.
 *
 * One function because the sentence is rendered in two places — under the group
 * picker and under the stage heading — and a count of one used to read "Reaches
 * 1 people right now" in both (#1042). Singular and plural are separate KEYS,
 * following `tenants.delete.userCount.one` / `.other`: a suffix rule applied to
 * a translated string is an English rule, and the two forms differ by more than
 * an "s" in most languages.
 *
 * This is a one/other split and Arabic has six categories, so "reaches 2" still
 * takes the plural where the dual would be right. That is a limitation of the
 * translator, not of these call sites; a plural-aware `t()` would change this
 * function and nothing else.
 */
function reachesRightNow(t: ReturnType<typeof useTranslation>, count: number): string {
  return count === 1
    ? t('routeTemplates.inspector.reaches.one', 'Reaches 1 person right now')
    : t('routeTemplates.inspector.reaches.other', 'Reaches {count} people right now', {
        count: count.toLocaleString(),
      });
}

/**
 * The panel that edits one stage.
 *
 * Deliberately in the HOST and not in the kit: choosing a rule means pickers
 * bound to this app's `/api/v1/roles` and `/api/v1/user-groups`, and the kit
 * cannot fetch. The canvas reports which stage is selected; this decides what
 * that means.
 */
function StageInspector({
  step,
  graph,
  rules,
  roles,
  groups,
  audience,
  preview,
  readOnly,
  onChange,
  t,
}: {
  step: RouteFlowStep;
  graph: RouteFlowGraph;
  rules: RoutingRule[];
  roles: RoleOption[];
  groups: GroupOption[];
  audience: RouteFlowAudience | undefined;
  preview: AudienceGroupPreview | null;
  readOnly: boolean;
  onChange: (patch: Partial<RouteFlowStep>) => void;
  t: ReturnType<typeof useTranslation>;
}) {
  const isRoleKind = ROLE_KINDS.has(step.ruleKind);
  const isGroupKind = step.ruleKind === GROUP_KIND;

  return (
    <div className="space-y-3">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">
        {t('routeTemplates.inspector.stage', 'Stage {n}', { n: String(step.position) })}
      </p>

      <label className="block space-y-1">
        <span className="text-sm font-medium">{t('routeTemplates.inspector.label', 'Label')}</span>
        <Input
          value={step.label ?? ''}
          disabled={readOnly}
          maxLength={160}
          placeholder={t('routeTemplates.inspector.labelPlaceholder', 'Head of department')}
          onChange={(e) => onChange({ label: e.target.value === '' ? null : e.target.value })}
        />
      </label>

      <label className="block space-y-1">
        <span className="text-sm font-medium">{t('routeTemplates.inspector.rule', 'Who it reaches')}</span>
        <Select
          value={step.ruleKind}
          disabled={readOnly}
          onValueChange={(kind) =>
            // The config belongs to the KIND. Carrying it across a kind change
            // would store a `{role_id}` under a rule that expects a `{group_id}`
            // — the exact mismatch #999 refuses on user groups for the same
            // reason, and it would only surface when the flow was finally run.
            onChange({ ruleKind: kind, ruleConfig: {} })
          }
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {rules.map((rule) => (
              <SelectItem key={rule.kind} value={rule.kind}>
                {rule.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </label>

      {isRoleKind && (
        <label className="block space-y-1">
          <span className="text-sm font-medium">{t('routeTemplates.inspector.role', 'Role')}</span>
          {roles.length === 0 ? (
            <p className="text-xs text-muted-foreground">
              {t(
                'routeTemplates.inspector.noRoles',
                'No roles are available to name. You may not have permission to list them.'
              )}
            </p>
          ) : (
            <Select
              value={typeof step.ruleConfig.role_id === 'number' ? String(step.ruleConfig.role_id) : ''}
              disabled={readOnly}
              onValueChange={(v) => onChange({ ruleConfig: { role_id: Number(v) } })}
            >
              <SelectTrigger>
                <SelectValue placeholder={t('routeTemplates.inspector.pickRole', 'Choose a role')} />
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
        </label>
      )}

      {/* #1015's kit primitive, composed rather than duplicated.

          It is the same control the linear route composer uses, which is the
          whole reason it is a kit primitive: a route step saying "everyone in
          Instructors" is authored in two places and a second copy is where the
          two drift.

          It also does the thing the provisional <Select> here could not — it
          draws the membership SNAPSHOT beside the choice, in the present tense
          ("reaches N people right now"), labels a sample as a sample from the
          server's own `truncated` flag, and states outright that the rule is
          re-resolved every time it is reached. A group node is exactly where
          that caveat has to be visible: the whole point of naming a group rather
          than people is that the answer changes after you author it. */}
      {isGroupKind && (
        <div className="space-y-1">
          <label className="text-sm font-medium" htmlFor="route-flow-group">
            {t('routeTemplates.inspector.group', 'User group')}
          </label>
          <AudienceGroupPicker
            id="route-flow-group"
            groups={groups}
            value={typeof step.ruleConfig.group_id === 'number' ? step.ruleConfig.group_id : null}
            onChange={(groupId) =>
              onChange({ ruleConfig: groupId === null ? {} : { group_id: groupId } })
            }
            disabled={readOnly}
            unavailableReason={
              groups.length === 0
                ? t(
                    'routeTemplates.inspector.noGroups',
                    'No user groups are available to name. You may not have permission to list them.'
                  )
                : null
            }
            preview={preview}
            // `audience` is the SAME request's answer, so the two cannot disagree:
            // a resolved count means the preview arrived, a null count with a
            // reason means it did not.
            previewStatus={
              step.ruleConfig.group_id === undefined
                ? 'idle'
                : preview !== null
                  ? 'ready'
                  : audience?.count === null
                    ? 'error'
                    : 'loading'
            }
            previewError={audience?.count === null ? (audience.unavailableReason ?? null) : null}
            placeholder={t('routeTemplates.inspector.pickGroup', 'Choose a group')}
            previewCountLabel={(total) => reachesRightNow(t, total)}
            previewDynamicNote={t(
              'routeTemplates.inspector.groupDynamic',
              'This is who the group means right now. It is re-resolved every time the stage is reached, so a document routed next month reaches whoever matches then.'
            )}
          />
        </div>
      )}

      {!isRoleKind && !isGroupKind && (
        <Alert>
          <AlertDescription className="text-xs">
            {t(
              'routeTemplates.inspector.unsupportedKind',
              'This rule kind has no editor here yet. Its configuration is kept exactly as it was saved.'
            )}
          </AlertDescription>
        </Alert>
      )}

      {/* THE COUNT, BESIDE THE QUORUM. This pairing is the point: an author
          setting "all must approve" on a node that reaches 1,043 people reads
          both facts in one glance, at the moment the decision is made.

          NOT for a group stage. `AudienceGroupPicker` already draws this exact
          sentence, from this exact number, a few rows above — so a group stage
          said "Reaches 2 people right now" twice, once under the picker and once
          under this heading (#1042). The picker's copy is the one to keep: it
          sits beside the sample and the "re-resolved every time" caveat, which
          is what makes the number mean something. */}
      {!isGroupKind && audience !== undefined && audience.count !== null && (
        <p className="rounded bg-muted px-2 py-1 text-xs text-muted-foreground">
          {reachesRightNow(t, audience.count)}
        </p>
      )}

      <label className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">
          {t('routeTemplates.inspector.decision', 'Requires a decision')}
        </span>
        <Switch
          checked={step.decision}
          disabled={readOnly}
          onCheckedChange={(checked) =>
            // Dropping the quorum when a stage stops being a gate: a quorum on a
            // stage that asks for no verdict can never be consulted, and the
            // server refuses to store one. Clearing it here means the refusal is
            // never reached rather than reported.
            onChange({ decision: checked, decisionQuorum: checked ? step.decisionQuorum : null })
          }
        />
      </label>

      {step.decision && (
        <label className="block space-y-1">
          <span className="text-sm font-medium">
            {t('routeTemplates.inspector.quorum', 'What counts as approved')}
          </span>
          <Select
            value={step.decisionQuorum ?? 'inherit'}
            disabled={readOnly}
            onValueChange={(v) =>
              onChange({ decisionQuorum: v === 'inherit' ? null : (v as RouteFlowQuorum) })
            }
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {/* "Inherit" is a real, distinct choice and not a blank: it means
                  "follow the tenant setting, whatever it becomes", which is
                  different from freezing today's answer onto the stage. */}
              <SelectItem value="inherit">
                {t('routeTemplates.quorum.inherit', 'Follow the tenant default ({value})', {
                  value: graph.defaultQuorum,
                })}
              </SelectItem>
              {QUORUMS.map((q) => (
                <SelectItem key={q} value={q}>
                  {q === 'all'
                    ? t('routeTemplates.quorum.all', 'all must approve')
                    : q === 'any'
                      ? t('routeTemplates.quorum.any', 'any one may approve')
                      : t('routeTemplates.quorum.majority', 'a majority must approve')}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            {t(
              'routeTemplates.inspector.quorumEffective',
              'Right now this stage is satisfied when: {value}',
              { value: effectiveQuorum(step, graph) }
            )}
          </p>
        </label>
      )}

      {step.decision && (
        <p className="text-xs text-muted-foreground">
          {t(
            'routeTemplates.inspector.edgeHint',
            'Drag from the green handle to draw where an approval goes, and from the red one for a rejection. With no approval edge the flow continues to the next stage; with no rejection edge it stops.'
          )}
        </p>
      )}
    </div>
  );
}
