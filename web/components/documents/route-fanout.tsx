'use client';

/**
 * How far a route has actually got — WITHOUT claiming a single global position
 * (#978, over #989's engine).
 *
 * THE THING THIS COMPONENT EXISTS TO NOT DO
 * -----------------------------------------
 * A progress bar. "Step 3 of 5" is the obvious rendering for an ordered process
 * and it is a FALSE STATEMENT about this one.
 *
 * #989's second load-bearing semantic is that distribution fans out and does not
 * block. Each recipient resolves the next step relative to THEMSELVES — their
 * unit, their place in the tree — so a route is not one token moving along a
 * track. It is a forest: the dean's copy may be finished while the faculty
 * officer's copy has not been opened, and there is no aggregate anywhere that
 * could be holding the fast chains while the slow one catches up. `position` is
 * an authoring ordinal, not a depth, and migration 112 says so in as many words.
 *
 * So a bar showing "step 3 of 5" would have to pick one chain and present it as
 * the document's state. Whichever it picked, it would be reporting one branch's
 * progress as everybody's — and it would read as authoritative precisely because
 * a progress bar is the most confident-looking element on a page.
 *
 * WHAT IS RENDERED INSTEAD
 * ------------------------
 * Two views of the same rows, neither of which aggregates across chains:
 *
 *  1. PER STEP — how many people this step reached, how many still have it open,
 *     how many have acted. Several steps can show open items AT THE SAME TIME,
 *     and that is not a rendering artefact; it is the fan-out. A step nothing has
 *     reached yet says "not reached yet" rather than showing a zeroed bar, which
 *     would read as "reached, nobody acted".
 *
 *  2. PER CHAIN — the branches, built from `parent_recipient_id`. This is the
 *     shape that makes independence visible: you can see one branch three steps
 *     deep beside another still sitting at step 1.
 *
 * NEITHER VIEW EVER RENDERS A ROSTER
 * ----------------------------------
 * A step whose rule is "everyone holding a role" can resolve to four figures of
 * people. Both views cap the individual rows they draw at {@link SAMPLE_LIMIT}
 * and carry the full count beside the sample — the same contract the composer
 * applies to rule previews, for the same reason: a surface that renders 1,043
 * rows has recreated the problem the rule exists to avoid.
 */

import { useMemo, useState } from 'react';
import { Badge } from '@amroksaleh/ui/badge';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { DocumentRoute, RouteRecipient, RouteStep } from './routing-wire';
import { configuredRoleId } from './routing-wire';

/**
 * How many individual people either view will draw before collapsing to a count.
 *
 * Twelve rather than a round ten: it is enough to see a real unit's worth of
 * people and recognise the shape of the list, and still short enough that the
 * count beside it is obviously the authoritative number rather than a footnote
 * to a list that looks complete.
 *
 * Not a tenant setting. It is a property of what a human can read at a glance,
 * not a capacity an operator would tune — and #989's own ceiling
 * (`documents.routing_max_recipients_per_step`) already governs the thing an
 * operator has an opinion about, which is how many people a step may reach.
 */
export const SAMPLE_LIMIT = 12;

export interface RouteFanoutProps {
  route: DocumentRoute;
  /** Every recipient row of this route, open and closed. */
  recipients: RouteRecipient[];
  /**
   * Display names by profile id, best-effort.
   *
   * A missing entry renders as the profile id, never as a blank and never as an
   * invented name (#756). Naming people needs `users:read`, which a person who
   * may route a document does not necessarily hold — see the host page.
   */
  profileNames: Map<number, string>;
  /** Role names by role id, best-effort; a missing entry renders as the id. */
  roleNames: Map<number, string>;
  /** The viewer's own profile id, so their own rows can be marked. */
  viewerProfileId: number | null;
}

/** A node in the chain forest. */
interface ChainNode {
  recipient: RouteRecipient;
  children: ChainNode[];
}

/**
 * Build the forest from `parent_recipient_id`.
 *
 * Roots are rows with no parent — step 1, where nothing preceded them. A row
 * whose parent is not in the set is ALSO treated as a root rather than dropped:
 * the recipients endpoint returns every row for the document, so a missing parent
 * would mean the server sent an inconsistent set, and silently discarding the
 * subtree would under-report where the document has been. Showing it as its own
 * branch over-reports the number of branches, which is the safer direction — it
 * cannot hide a place the document reached.
 */
function buildChains(recipients: RouteRecipient[]): ChainNode[] {
  const nodes = new Map<number, ChainNode>();
  for (const recipient of recipients) {
    nodes.set(recipient.id, { recipient, children: [] });
  }

  const roots: ChainNode[] = [];
  for (const node of nodes.values()) {
    const parentId = node.recipient.parent_recipient_id;
    const parent = parentId !== null ? nodes.get(parentId) : undefined;
    if (parent === undefined) {
      roots.push(node);
    } else {
      parent.children.push(node);
    }
  }

  // Oldest first within each level: a chain is read as a sequence of events, so
  // the order rows were created is the order they happened.
  const sortLevel = (level: ChainNode[]): void => {
    level.sort((a, b) => a.recipient.id - b.recipient.id);
    for (const node of level) sortLevel(node.children);
  };
  sortLevel(roots);

  return roots;
}

/** How a step's rule reads to a human — a rule, never a roster. */
export function describeStepRule(
  step: RouteStep,
  roleNames: Map<number, string>,
  t: (key: string, fallback?: string, vars?: Record<string, string | number>) => string
): string {
  if (step.label !== null && step.label.trim() !== '') return step.label;

  const roleId = configuredRoleId(step.rule_config);
  const roleName =
    roleId === null
      ? null
      : (roleNames.get(roleId) ?? t('routing.role.byId', 'Role #{id}', { id: roleId }));

  if (roleName === null) {
    // A kind this client does not know how to describe. Its OWN key is the
    // honest answer — it is what the author picked and what the engine will
    // resolve — rather than a generic "custom rule", which would hide which
    // rule a route actually names.
    return step.rule_kind;
  }

  if (step.rule_kind === 'role_below_actor') {
    return t('routing.rule.roleBelowActor', 'Everyone holding {role}, in the sender’s unit and below', {
      role: roleName,
    });
  }
  if (step.rule_kind === 'role') {
    return t('routing.rule.role', 'Everyone holding {role}', { role: roleName });
  }
  return step.rule_kind;
}

function personLabel(
  profileId: number,
  profileNames: Map<number, string>,
  viewerProfileId: number | null,
  t: (key: string, fallback?: string, vars?: Record<string, string | number>) => string
): string {
  const name = profileNames.get(profileId) ?? t('routing.profile.byId', 'Profile #{id}', { id: profileId });
  return profileId === viewerProfileId ? t('routing.profile.you', '{name} (you)', { name }) : name;
}

export function RouteFanout({
  route,
  recipients,
  profileNames,
  roleNames,
  viewerProfileId,
}: RouteFanoutProps) {
  const t = useTranslation('documents');
  const [expandedChains, setExpandedChains] = useState(false);

  const forRoute = useMemo(
    () => recipients.filter((r) => r.route_id === route.id),
    [recipients, route.id]
  );

  const byStep = useMemo(() => {
    const map = new Map<number, RouteRecipient[]>();
    for (const recipient of forRoute) {
      const bucket = map.get(recipient.step_id);
      if (bucket === undefined) map.set(recipient.step_id, [recipient]);
      else bucket.push(recipient);
    }
    return map;
  }, [forRoute]);

  const chains = useMemo(() => buildChains(forRoute), [forRoute]);

  const openTotal = forRoute.filter((r) => r.open).length;

  // How many DISTINCT steps currently hold an open item. This number, and not a
  // position, is what tells a reader the route is fanned out: two or more means
  // the document is in several places at once, and no single "current step"
  // exists to report.
  const liveSteps = useMemo(() => {
    const steps = new Set<number>();
    for (const recipient of forRoute) if (recipient.open) steps.add(recipient.step_id);
    return steps.size;
  }, [forRoute]);

  /**
   * What the settled sentence may claim about how this route closed.
   *
   * "Every chain has been acted on" is false the moment ANY stage was a
   * delivery — nobody acted on those, they were told. Rather than soften every
   * route into one vague sentence, the three cases are distinguished: a route
   * made only of deliveries was delivered, one made only of acts was acted on,
   * and a mixed route says the one thing true of both.
   */
  const settledClaim: 'acted' | 'delivered' | 'closed' = useMemo(() => {
    const touched = new Set(forRoute.map((r) => r.step_id));
    const kinds = new Set(
      route.steps.filter((s) => touched.has(s.id)).map((s) => s.satisfied_by ?? 'act')
    );
    if (kinds.size === 1 && kinds.has('delivery')) return 'delivered';
    if (kinds.size === 1 && kinds.has('act')) return 'acted';
    return 'closed';
  }, [forRoute, route.steps]);

  const visibleChains = expandedChains ? chains : chains.slice(0, SAMPLE_LIMIT);

  return (
    <div className="space-y-6" data-slot="route-fanout">
      {/*
        The headline is a COUNT OF PLACES, never a position. When more than one
        step is live it says so explicitly, because a reader who has met progress
        bars everywhere else will otherwise assume the first step listed with open
        items is "the" current one.
      */}
      <div className="rounded-md border border-border bg-muted/30 p-4">
        <p className="text-sm text-foreground">
          {openTotal === 0
            ? settledClaim === 'delivered'
              ? t(
                  'routing.fanout.settledDelivered',
                  'Nobody has this open. Every chain of this route has been delivered.'
                )
              : settledClaim === 'closed'
                ? t(
                    'routing.fanout.settledMixed',
                    'Nobody has this open. Every chain of this route is closed.'
                  )
                : t(
                    'routing.fanout.settled',
                    'Nobody has this open. Every chain of this route has been acted on.'
                  )
            : t('routing.fanout.open', '{people} awaiting action, across {steps} of this route’s steps.', {
                people: openTotal,
                steps: liveSteps,
              })}
        </p>
        {liveSteps > 1 && (
          <p className="mt-1 text-xs text-muted-foreground">
            {t(
              'routing.fanout.independent',
              'These branches move independently — each recipient resolves the next step relative to themselves, so one branch can finish while another has not been opened. There is no single step the document as a whole is "on".'
            )}
          </p>
        )}
      </div>

      {/* ---- per step ---- */}
      <section>
        <h4 className="mb-2 text-sm font-semibold text-foreground">
          {t('routing.fanout.steps.heading', 'Steps, and who each one reached')}
        </h4>
        <ol className="space-y-2">
          {route.steps.map((step) => {
            const rows = byStep.get(step.id) ?? [];
            const open = rows.filter((r) => r.open);
            const done = rows.length - open.length;
            const sample = open.slice(0, SAMPLE_LIMIT);

            return (
              <li
                key={step.id}
                className="rounded-md border border-border p-3"
                data-slot="route-fanout-step"
              >
                <div className="flex flex-wrap items-baseline gap-2">
                  <span className="text-xs font-medium text-muted-foreground">
                    {/*
                      "Step N" is the authoring ordinal — the number the author
                      gave it — and the label says so rather than implying a
                      sequence position the document is at.
                    */}
                    {t('routing.fanout.step.ordinal', 'Step {position}', { position: step.position })}
                  </span>
                  <span className="text-sm text-foreground">
                    {describeStepRule(step, roleNames, t)}
                  </span>
                </div>

                <div className="mt-2 flex flex-wrap items-center gap-2">
                  {rows.length === 0 ? (
                    // Never a zeroed bar. "Not reached yet" and "reached, nobody
                    // has acted" are different states and a 0% bar renders them
                    // identically (#756).
                    <span className="text-xs text-muted-foreground">
                      {t('routing.fanout.step.unreached', 'Not reached yet')}
                    </span>
                  ) : (
                    <>
                      <Badge variant="outline">
                        {t('routing.fanout.step.reached', '{count} reached', { count: rows.length })}
                      </Badge>
                      {open.length > 0 && (
                        <Badge variant="warning">
                          {t('routing.fanout.step.open', '{count} awaiting', { count: open.length })}
                        </Badge>
                      )}
                      {done > 0 && (
                        <Badge variant="success">
                          {/*
                            A delivery stage never asked anybody, so counting its
                            closed rows as people who "acted" overstates what the
                            trail records. The count is the same; the verb is not.
                          */}
                          {step.satisfied_by === 'delivery'
                            ? t('routing.fanout.step.sent', '{count} sent', { count: done })
                            : t('routing.fanout.step.done', '{count} acted', { count: done })}
                        </Badge>
                      )}
                    </>
                  )}

                  {/*
                    #1037: how many times a rejection sent the document BACK from
                    here. Outside the `rows.length === 0` branch on purpose — a
                    stage can have been round the loop and hold no rows right now,
                    which is exactly the state that was invisible.

                    Rendered only when non-zero. The server publishes 0 as a real
                    answer, but a "sent back 0 times" badge on every stage is
                    noise that would bury the one stage where it is not zero —
                    and absence is unambiguous here, because a stage that HAD
                    been round would be carrying the badge.
                  */}
                  {step.rejection_count > 0 && (
                    <Badge variant="warning" data-slot="route-fanout-step-rejections">
                      {t('routing.fanout.step.sentBack', 'sent back {count}x', {
                        count: step.rejection_count,
                      })}
                    </Badge>
                  )}
                </div>

                {sample.length > 0 && (
                  <div className="mt-2">
                    <p className="text-xs text-muted-foreground">
                      {t('routing.fanout.step.awaitingLabel', 'Awaiting:')}
                    </p>
                    <ul className="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                      {sample.map((recipient) => (
                        <li key={recipient.id} className="text-xs text-foreground">
                          {personLabel(recipient.profile_id, profileNames, viewerProfileId, t)}
                        </li>
                      ))}
                      {open.length > sample.length && (
                        // A count, never the rest of the list. See SAMPLE_LIMIT.
                        <li className="text-xs text-muted-foreground">
                          {t('routing.fanout.step.more', '+{count} more', {
                            count: open.length - sample.length,
                          })}
                        </li>
                      )}
                    </ul>
                  </div>
                )}
              </li>
            );
          })}
        </ol>
      </section>

      {/* ---- per chain ---- */}
      <section>
        <h4 className="mb-2 text-sm font-semibold text-foreground">
          {t('routing.fanout.chains.heading', 'Branches')}
        </h4>
        {chains.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {/*
              A route whose first step resolved to nobody. #989 makes this
              visible on purpose — the trail records an empty distribution and
              the issue response reports zero — so the UI has to say it rather
              than render an empty list that reads as "still loading".
            */}
            {t(
              'routing.fanout.chains.none',
              'This route reached nobody. Its first step resolved to an empty set of people — the rule found no one holding that role.'
            )}
          </p>
        ) : (
          <>
            <ul className="space-y-1">
              {visibleChains.map((node) => (
                <ChainBranch
                  key={node.recipient.id}
                  node={node}
                  depth={0}
                  stepsById={route.steps}
                  profileNames={profileNames}
                  viewerProfileId={viewerProfileId}
                  t={t}
                />
              ))}
            </ul>
            {chains.length > visibleChains.length && (
              <button
                type="button"
                onClick={() => setExpandedChains(true)}
                className="mt-2 text-xs text-primary underline"
              >
                {t('routing.fanout.chains.showAll', 'Show the remaining {count} branches', {
                  count: chains.length - visibleChains.length,
                })}
              </button>
            )}
          </>
        )}
      </section>
    </div>
  );
}

interface ChainBranchProps {
  node: ChainNode;
  depth: number;
  stepsById: RouteStep[];
  profileNames: Map<number, string>;
  viewerProfileId: number | null;
  t: (key: string, fallback?: string, vars?: Record<string, string | number>) => string;
}

/**
 * One node of a chain and its children.
 *
 * Indentation uses a logical padding utility so it flows from the start edge in
 * both directions — an RTL reader sees the tree grow leftward, which is what a
 * tree means in Arabic.
 */
function ChainBranch({
  node,
  depth,
  stepsById,
  profileNames,
  viewerProfileId,
  t,
}: ChainBranchProps) {
  const step = stepsById.find((s) => s.id === node.recipient.step_id);
  const children = node.children.slice(0, SAMPLE_LIMIT);
  const hidden = node.children.length - children.length;

  return (
    <li data-slot="route-fanout-chain">
      <div
        className="flex flex-wrap items-center gap-2 border-s border-border py-1 ps-3"
        style={{ marginInlineStart: `${depth * 12}px` }}
      >
        <span className="text-xs text-muted-foreground">
          {step === undefined
            ? t('routing.fanout.chain.unknownStep', 'Unknown step')
            : t('routing.fanout.step.ordinal', 'Step {position}', { position: step.position })}
        </span>
        <span className="text-sm text-foreground">
          {personLabel(node.recipient.profile_id, profileNames, viewerProfileId, t)}
        </span>
        {node.recipient.open ? (
          <Badge variant="warning">{t('routing.fanout.chain.open', 'Has it open')}</Badge>
        ) : node.recipient.closed_by_delivery ? (
          // A delivery row was closed the moment it was created. Saying "Acted"
          // here told a technician who had merely been SENT a copy that they had
          // acted on it — in the second person, on a screen about an audit trail
          // that had deliberately kept the two apart.
          <Badge variant="secondary">{t('routing.fanout.chain.sent', 'Sent')}</Badge>
        ) : (
          <Badge variant="secondary">{t('routing.fanout.chain.acted', 'Acted')}</Badge>
        )}
      </div>
      {children.length > 0 && (
        <ul>
          {children.map((child) => (
            <ChainBranch
              key={child.recipient.id}
              node={child}
              depth={depth + 1}
              stepsById={stepsById}
              profileNames={profileNames}
              viewerProfileId={viewerProfileId}
              t={t}
            />
          ))}
        </ul>
      )}
      {hidden > 0 && (
        <p
          className="text-xs text-muted-foreground"
          style={{ marginInlineStart: `${(depth + 1) * 12 + 12}px` }}
        >
          {t('routing.fanout.chain.more', '+{count} more on this branch', { count: hidden })}
        </p>
      )}
    </li>
  );
}
