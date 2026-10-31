import { parseWireDate } from '@whity-org/features/datetime';
import type { components } from '@/lib/api/schema';
import type { ActionProposalChange, ActionProposalStatus } from '@whity-org/ui/action-proposal';
import type { AgentActivityItem } from '@whity-org/ui/agent-activity';

/**
 * Wire helpers for agent proposals (`GET /api/me/agent-proposals`, WHIT-784):
 * turning what the server stores into what the ActionProposal card and the
 * activity timeline show. No React here, so the rules are testable alone.
 */

export type AgentProposal = components['schemas']['AgentProposal'];

/**
 * The words these helpers put on screen, translated by the caller. They take
 * words rather than a translate function because the catalogue extractor
 * reads `t()` calls only where `useTranslation` names their domain.
 */
export interface ProposalWords {
  /** For an agent whose credential is gone. */
  unknownAgent: string;
  minute: string;
  /** `{n}` minutes. */
  minutes: string;
  hour: string;
  hours: string;
  day: string;
  days: string;
  /** `{summary}`. */
  asked: string;
  approved: string;
  declined: string;
}

/** The server's statuses are the card's, one for one. */
export function proposalStatus(proposal: AgentProposal): ActionProposalStatus {
  return proposal.status;
}

/** `invoice_due_date` → "Invoice due date". */
export function humanizeKey(key: string): string {
  const words = key.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').trim().toLowerCase();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

function display(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) return value.map(display).join(', ');
  return JSON.stringify(value);
}

/**
 * What will change. A `diff` the operation previewed wins: a list of
 * `{label|field, before, after}`. Without one, the arguments the agent will
 * send are the change: one row per argument, with no "before".
 */
export function proposalChanges(proposal: AgentProposal): ActionProposalChange[] {
  const diff = proposal.diff as unknown;
  if (Array.isArray(diff)) {
    return diff
      .filter((row): row is Record<string, unknown> => typeof row === 'object' && row !== null)
      .map((row) => ({
        label: humanizeKey(String(row.label ?? row.field ?? '')),
        before: row.before === undefined ? undefined : display(row.before),
        after: display(row.after),
      }));
  }
  return Object.entries((proposal.arguments ?? {}) as Record<string, unknown>).map(([key, value]) => ({
    label: humanizeKey(key),
    after: display(value),
  }));
}

/** "30 minutes", "2 hours", "1 day": the undo window in words. */
export function undoWindowWords(seconds: number | null | undefined, words: ProposalWords): string | undefined {
  if (!seconds || seconds <= 0) return undefined;
  if (seconds < 3600) {
    const n = Math.max(1, Math.round(seconds / 60));
    return n === 1 ? words.minute : words.minutes.replace('{n}', String(n));
  }
  if (seconds < 86400) {
    const n = Math.round(seconds / 3600);
    return n === 1 ? words.hour : words.hours.replace('{n}', String(n));
  }
  const n = Math.round(seconds / 86400);
  return n === 1 ? words.day : words.days.replace('{n}', String(n));
}

/** The agent's name, or a plain word when its credential is gone. */
export function agentName(proposal: AgentProposal, words: ProposalWords): string {
  return proposal.agent?.name || words.unknownAgent;
}

/** Oldest first: the queue's order (register 7.5b). */
export function oldestFirst(proposals: AgentProposal[]): AgentProposal[] {
  return [...proposals].sort((a, b) => a.id - b.id);
}

/**
 * The timeline from proposals alone: each one asked (dashed), and each
 * decision the person made. What an agent did with Allowed permissions comes
 * from the audit log with WHIT-811.
 */
export function proposalActivity(
  proposals: AgentProposal[],
  options: { words: ProposalWords; you: string; time: (iso: string) => string },
): AgentActivityItem[] {
  const { words, you, time } = options;
  const items: AgentActivityItem[] = [];
  for (const proposal of proposals) {
    const agent = agentName(proposal, words);
    items.push({
      id: `${proposal.id}-asked`,
      kind: 'propose',
      actor: agent,
      onBehalfOf: you,
      text: words.asked.replace('{summary}', proposal.summary),
      timeLabel: time(proposal.createdAt),
      dateTime: proposal.createdAt,
      tool: proposal.tool,
    });
    if (proposal.decidedAt && (proposal.status === 'approved' || proposal.status === 'declined')) {
      items.push({
        id: `${proposal.id}-decided`,
        kind: proposal.status === 'approved' ? 'approve' : 'undo',
        actor: you,
        actorKind: 'person',
        text: (proposal.status === 'approved' ? words.approved : words.declined).replace('{summary}', proposal.summary),
        timeLabel: time(proposal.decidedAt),
        dateTime: proposal.decidedAt,
      });
    }
  }
  // Newest first, by the ISO time each row carries.
  return items.sort((a, b) => ((a.dateTime ?? '') < (b.dateTime ?? '') ? 1 : (a.dateTime ?? '') > (b.dateTime ?? '') ? -1 : 0));
}

/** Whether a server timestamp falls on the viewer's today (read as UTC, as stored). */
export function isToday(value: string, now: Date = new Date()): boolean {
  const date = parseWireDate(value);
  if (date === null) return false;
  return (
    date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate()
  );
}
