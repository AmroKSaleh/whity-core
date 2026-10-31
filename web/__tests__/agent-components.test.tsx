import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ActionProposal } from '@whity-org/ui/action-proposal';
import { ApprovalQueue, type ApprovalQueueItem } from '@whity-org/ui/approval-queue';
import { ScopeGrant } from '@whity-org/ui/scope-grant';
import { UndoBar } from '@whity-org/ui/undo-bar';
import { AgentActivity } from '@whity-org/ui/agent-activity';

/**
 * The agent collaboration components (WHIT-808): the register's rules that a
 * test can hold — 7.5a summary first and two changes then Show all, the
 * permissions and the undo window always shown, actions only while waiting;
 * 7.5b one at a time; the scope grant's Off / Ask first / Allowed.
 */

const changes = [
  { label: 'Invoices', after: '12 overdue · JOD 4,860' },
  { label: 'Status', before: 'Overdue', after: 'Reminder sent' },
  { label: 'Next reminder', after: '3 Nov 2026' },
  { label: 'Channel', after: 'Email' },
];

describe('ActionProposal', () => {
  it('shows two changes, then Show all past three', async () => {
    render(<ActionProposal agent="Billing assistant" title="Send 12 reminders" changes={changes} />);
    expect(screen.queryByText('Next reminder')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Show all 4 changes' }));
    expect(screen.getByText('Next reminder')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Show less' })).toHaveAttribute('aria-expanded', 'true');
  });

  it('always names the permissions used and the undo window', () => {
    const { rerender } = render(
      <ActionProposal agent="Billing assistant" title="t" scope={['invoices:send']} undoWindow="30 minutes" />,
    );
    expect(screen.getByText('invoices:send')).toBeInTheDocument();
    expect(screen.getByText('Can be undone for 30 minutes')).toBeInTheDocument();
    rerender(<ActionProposal agent="Billing assistant" title="t" reversible={false} />);
    expect(screen.getByText("Can't be undone")).toBeInTheDocument();
  });

  it('offers Approve, Edit and Decline only while waiting, and is dashed until then', async () => {
    const onApprove = jest.fn();
    const { rerender, container } = render(
      <ActionProposal agent="Billing assistant" title="t" onApprove={onApprove} onDecline={() => {}} onEdit={() => {}} />,
    );
    const card = container.querySelector('[data-slot="action-proposal"]')!;
    expect(card.className).toContain('border-dashed');
    expect(card.className).not.toContain('shadow-plane');
    await userEvent.click(screen.getByRole('button', { name: 'Approve' }));
    expect(onApprove).toHaveBeenCalledTimes(1);

    rerender(<ActionProposal agent="Billing assistant" title="t" status="approved" decidedAt="14:02" onApprove={onApprove} />);
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.getByText('Approved · 14:02')).toBeInTheDocument();
    expect(container.querySelector('[data-slot="action-proposal"]')!.className).toContain('shadow-plane');
  });

  it('strikes a declined title through and offers Ask again when expired', () => {
    const onAskAgain = jest.fn();
    const { rerender } = render(<ActionProposal agent="a" title="Create a quote" status="declined" />);
    expect(screen.getByRole('heading', { name: 'Create a quote' }).className).toContain('line-through');
    rerender(<ActionProposal agent="a" title="Create a quote" status="expired" onAskAgain={onAskAgain} />);
    expect(screen.getByRole('button', { name: 'Ask again' })).toBeInTheDocument();
  });
});

describe('ApprovalQueue', () => {
  const items: ApprovalQueueItem[] = [
    { id: '1', agent: 'Billing assistant', title: 'First' },
    { id: '2', agent: 'Billing assistant', title: 'Second' },
    { id: '3', agent: 'HR assistant', title: 'Third' },
  ];

  it('shows one at a time with its position, and moves with Next', async () => {
    const onCurrentChange = jest.fn();
    render(<ApprovalQueue items={items} currentId="1" onCurrentChange={onCurrentChange} />);
    expect(screen.getByText('1 of 3 waiting · oldest first')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'First' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Second' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(onCurrentChange).toHaveBeenCalledWith('2');
  });

  it('says so when nothing is waiting', () => {
    render(<ApprovalQueue items={[]} onCurrentChange={() => {}} />);
    expect(screen.getByText('All caught up')).toBeInTheDocument();
  });
});

describe('ScopeGrant', () => {
  it('marks the current mode and reports a change; an action can withhold Allowed', async () => {
    const onChange = jest.fn();
    render(
      <ScopeGrant
        agent="Billing assistant"
        onBehalfOf="Rana"
        groups={[
          {
            key: 'invoices',
            label: 'Invoices',
            actions: [
              { key: 'invoices:read', label: 'See invoices' },
              { key: 'invoices:void', label: 'Void an invoice', modes: ['off', 'ask'] },
            ],
          },
        ]}
        modes={{ 'invoices:read': 'allow', 'invoices:void': 'off' }}
        onChange={onChange}
      />,
    );
    const read = screen.getByRole('radiogroup', { name: 'Invoices · See invoices' });
    expect(read.querySelector('[aria-checked="true"]')).toHaveTextContent('Allowed');
    const voidGroup = screen.getByRole('radiogroup', { name: 'Invoices · Void an invoice' });
    const buttons = voidGroup.querySelectorAll('button');
    expect(buttons[2]).toBeDisabled();
    await userEvent.click(buttons[1]);
    expect(onChange).toHaveBeenCalledWith('invoices:void', 'ask');
  });
});

describe('UndoBar and AgentActivity', () => {
  it('says how long is left, then who undid it', () => {
    const { rerender } = render(<UndoBar text="Sent 12 reminders" remainingLabel="24 minutes" onUndo={() => {}} />);
    expect(screen.getByText(/24 minutes left to undo/)).toBeInTheDocument();
    rerender(<UndoBar text="Sent 12 reminders" state="undone" undoneBy="Rana Haddad" />);
    expect(screen.getByText(/Undone by Rana Haddad/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Undo' })).not.toBeInTheDocument();
  });

  it('filters to one agent', async () => {
    render(
      <AgentActivity
        filter
        items={[
          { id: 'a', kind: 'read', actor: 'Billing assistant', text: 'Read 48 invoices', timeLabel: '13:58', tool: 'invoices.list' },
          { id: 'b', kind: 'act', actor: 'HR assistant', text: 'Approved leave', timeLabel: '14:00' },
        ]}
      />,
    );
    await userEvent.click(screen.getByRole('radio', { name: 'HR assistant' }));
    expect(screen.queryByText('Read 48 invoices')).not.toBeInTheDocument();
    expect(screen.getByText('Approved leave')).toBeInTheDocument();
  });
});
