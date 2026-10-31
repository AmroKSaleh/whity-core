/**
 * WHIT-809 — the Approvals screen over `/api/me/agent-proposals`.
 *
 * What the tests hold, from the register and owner decision 10:
 *  - one proposal at a time, oldest first, with "1 of N waiting" (7.5b);
 *  - the card names the agent by its credential and shows the permissions it
 *    uses, and says it can't be undone when the server gives no window (7.5a);
 *  - Approve and Decline call the server and move on only after it answers;
 *  - Edit sends the edited arguments with the approval, keeping their types.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { proposalChanges, undoWindowWords, type ProposalWords } from '@/components/agents/proposals';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import ApprovalsPage from '@/app/(protected)/admin/approvals/page';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

function proposal(id: number, overrides: Record<string, unknown> = {}) {
  return {
    id,
    agent: { name: 'Billing assistant' },
    status: 'waiting',
    tool: 'post_api_forms',
    summary: `Create form ${id}`,
    arguments: { name: `Leave request ${id}`, days: 2 },
    diff: null,
    permissions: ['forms:manage'],
    undoWindowSeconds: null,
    result: null,
    decidedAt: null,
    expiresAt: '2099-01-01 00:00:00',
    createdAt: `2026-10-31 0${id}:00:00`,
    ...overrides,
  };
}

function serve(list: unknown[], decide?: (url: string, init: RequestInit) => Promise<unknown>) {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/me/agent-proposals') return jsonResponse(200, { data: list });
    if (decide) return decide(url, init ?? {});
    return jsonResponse(404, { error: 'not found' });
  });
}

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
});

describe('Approvals page', () => {
  it('shows the oldest waiting proposal first, one at a time', async () => {
    serve([proposal(3), proposal(1), proposal(2), proposal(4, { status: 'approved', decidedAt: '2026-10-31 05:00:00' })]);
    render(<ApprovalsPage />);

    expect(await screen.findByText('1 of 3 waiting · oldest first')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Create form 1' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Create form 2' })).not.toBeInTheDocument();
    expect(screen.getAllByText('Billing assistant').length).toBeGreaterThan(0);
    expect(screen.getAllByText('forms:manage').length).toBeGreaterThan(0);
    expect(screen.getByText("Can't be undone")).toBeInTheDocument();
  });

  it('approves through the server, then shows the next one', async () => {
    let list = [proposal(1), proposal(2)];
    serve(list, (url) => {
      expect(url).toBe('/api/v1/me/agent-proposals/1/approve');
      list = [proposal(1, { status: 'approved' }), proposal(2)];
      serve(list);
      return jsonResponse(200, { data: list[0] });
    });
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Approved: Create form 1', 'success', 10000));
    expect(await screen.findByRole('heading', { name: 'Create form 2' })).toBeInTheDocument();
    expect(screen.getByText('1 of 1 waiting · oldest first')).toBeInTheDocument();
  });

  it('keeps the card when the server refuses, and says why', async () => {
    serve([proposal(1)], () => jsonResponse(409, { error: 'Already decided' }));
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Decline' }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Already decided', 'error', 6000));
    expect(screen.getByRole('heading', { name: 'Create form 1' })).toBeInTheDocument();
  });

  it('sends edited arguments with the approval, keeping a number a number', async () => {
    let sent: unknown = null;
    serve([proposal(1)], (url, init) => {
      sent = JSON.parse(String(init.body));
      return jsonResponse(200, { data: proposal(1, { status: 'approved' }) });
    });
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Days'), { target: { value: '3' } });
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve with changes' }));

    await waitFor(() => expect(sent).toEqual({ arguments: { name: 'Leave request 1', days: 3 } }));
  });

  it('says so when nothing waits', async () => {
    serve([]);
    render(<ApprovalsPage />);
    expect(await screen.findByText('All caught up')).toBeInTheDocument();
  });
});

describe('proposal helpers', () => {
  const words: ProposalWords = {
    unknownAgent: 'An agent', minute: '1 minute', minutes: '{n} minutes', hour: '1 hour', hours: '{n} hours',
    day: '1 day', days: '{n} days', asked: 'Asked: {summary}', approved: 'Approved: {summary}', declined: 'Declined: {summary}',
  };

  it('words the undo window', () => {
    expect(undoWindowWords(null, words)).toBeUndefined();
    expect(undoWindowWords(1800, words)).toBe('30 minutes');
    expect(undoWindowWords(7200, words)).toBe('2 hours');
    expect(undoWindowWords(86400, words)).toBe('1 day');
  });

  it('prefers a previewed diff over the arguments', () => {
    const base = proposal(1) as never;
    expect(proposalChanges(base)).toEqual([
      { label: 'Name', after: 'Leave request 1' },
      { label: 'Days', after: '2' },
    ]);
    expect(
      proposalChanges({ ...(proposal(1) as object), diff: [{ field: 'status', before: 'Unpaid', after: 'Paid' }] } as never),
    ).toEqual([{ label: 'Status', before: 'Unpaid', after: 'Paid' }]);
  });
});
