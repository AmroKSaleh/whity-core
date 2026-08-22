/**
 * The record-page SHELL (#882) — the four properties that make it a shell rather
 * than a layout, each of them a decision that could silently regress:
 *
 *  1. **A fact comes from the record, never from a permission.** This is #895:
 *     the roles page derived "is this global" from `manageable`, which is true of
 *     EVERY role for a tenant-0 caller, so the system tenant read "Your tenant's
 *     role" on the one record whose edit reaches every tenant. The shell's
 *     projection is given the record and the dictionary and nothing else — the
 *     compile-time half of that guard lives in `types.ts`; this is the runtime
 *     half, driving the exact caller/record combination that was wrong.
 *  2. **Read-only is a distinct RENDERING, not a disabled form.** The shell takes
 *     two and picks one, so the greyed-out form cannot ship by omission.
 *  3. **Which gate refused, once.** The first refusal is shown and only the
 *     first.
 *  4. **An ungranted capability is a CLEAN ABSENCE.** A forbidden panel is not in
 *     the document at all — not an error box, not an empty card.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';

import { RecordPageShell } from '../record-page-shell';
import { RecordCollectionPanel } from '../record-panel';
import { resolveAccess } from '../access';
import type { RecordFactsFn, RecordResource } from '../types';

/** English-fallback translator, like the other screens' tests use. */
const t = (_key: string, fallback?: string, vars?: Record<string, string | number>): string => {
  const text = fallback ?? _key;
  if (!vars) return text;
  return Object.entries(vars).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), text);
};

/**
 * A record whose FIELDS carry the fact (`global`) and whose caller-permission
 * flag lives nowhere near them — the shape #895 forced.
 */
interface DemoFields {
  name: string;
  global: boolean;
  holderCount: number | null;
}

const demoFacts: RecordFactsFn<DemoFields> = (record, translate) => ({
  title: record.name,
  subtitle: translate('demo.subtitle', 'A record'),
  badges: record.global
    ? [{ key: 'global', label: translate('demo.global', 'Global base role'), tone: 'warning' }]
    : [],
  stats: [
    { key: 'holders', label: translate('demo.holders', 'Holders'), value: record.holderCount },
    {
      key: 'scope',
      label: translate('demo.scope', 'Scope'),
      value: record.global
        ? translate('demo.global', 'Global base role')
        : translate('demo.tenant', "Your tenant's record"),
    },
  ],
});

const back = { label: 'Back to list', onBack: jest.fn() };

function renderShell(
  fields: DemoFields,
  access = resolveAccess([]),
  side?: React.ReactNode
) {
  return render(
    <RecordPageShell
      testId="demo-record"
      fields={fields}
      facts={demoFacts}
      t={t}
      access={access}
      back={back}
      actions={<button type="button">Save changes</button>}
      main={{
        editor: <input aria-label="Name" defaultValue={fields.name} />,
        readOnly: (
          <dl>
            <dt>Name</dt>
            <dd>{`${fields.name} (stated, not typed)`}</dd>
          </dl>
        ),
      }}
      side={side}
    />
  );
}

describe('RecordPageShell — a fact comes from the record, not from a permission (#895)', () => {
  /**
   * THE REGRESSION, driven exactly as it happened: the caller may manage the
   * record (they are the system tenant, for whom everything is manageable) AND
   * the record is global. The old page inferred scope from manageability and so
   * said "Your tenant's role" to the one operator whose save reaches every
   * tenant.
   */
  it('says GLOBAL for a global record even when the caller may edit it', () => {
    renderShell(
      { name: 'admin', global: true, holderCount: 3 },
      // Editable — nothing refused. This is the tenant-0 caller.
      resolveAccess([{ allowed: true, reason: 'unused' }])
    );

    expect(screen.getByTestId('demo-record-badge-global')).toHaveTextContent('Global base role');
    expect(screen.getByTestId('demo-record-stat-scope')).toHaveTextContent('Global base role');
    expect(screen.queryByText("Your tenant's record")).not.toBeInTheDocument();
  });

  /** The mirror image: a tenant record stays a tenant record when read-only. */
  it('says TENANT for a tenant record even when the caller may not edit it', () => {
    renderShell(
      { name: 'Support', global: false, holderCount: 1 },
      resolveAccess([{ allowed: false, reason: 'No permission.' }])
    );

    expect(screen.getByTestId('demo-record-stat-scope')).toHaveTextContent("Your tenant's record");
    expect(screen.queryByTestId('demo-record-badge-global')).not.toBeInTheDocument();
  });

  it('renders a stat the server has not answered yet as an em dash, not as blank or zero', () => {
    renderShell({ name: 'Support', global: false, holderCount: null });

    expect(screen.getByTestId('demo-record-stat-holders')).toHaveTextContent('—');
  });
});

describe('RecordPageShell — read-only is a state, not a disabled form', () => {
  it('renders the editor and the actions when the record is editable', () => {
    renderShell({ name: 'Support', global: false, holderCount: 0 });

    expect(screen.getByLabelText('Name')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeInTheDocument();
    expect(screen.queryByTestId('demo-record-readonly-notice')).not.toBeInTheDocument();
  });

  it('renders the read-only view instead — with no inputs and no actions at all', () => {
    renderShell(
      { name: 'Support', global: false, holderCount: 0 },
      resolveAccess([{ allowed: false, reason: 'You may not edit this.' }])
    );

    // The other rendering, not the same one disabled.
    expect(screen.queryByLabelText('Name')).not.toBeInTheDocument();
    expect(document.querySelectorAll('input')).toHaveLength(0);
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
    expect(screen.getByText('Support (stated, not typed)')).toBeInTheDocument();
  });

  it('names the FIRST gate that refused, and only that one', () => {
    renderShell(
      { name: 'Support', global: false, holderCount: 0 },
      resolveAccess([
        { allowed: false, reason: 'You lack the capability.' },
        { allowed: false, reason: 'The record is managed elsewhere.' },
      ])
    );

    const notice = screen.getByTestId('demo-record-readonly-notice');
    expect(notice).toHaveTextContent('You lack the capability.');
    expect(screen.queryByText('The record is managed elsewhere.')).not.toBeInTheDocument();
  });
});

describe('resolveAccess', () => {
  it('is editable when every gate allows', () => {
    expect(resolveAccess([{ allowed: true, reason: 'a' }, { allowed: true, reason: 'b' }])).toEqual({
      editable: true,
      readOnlyReason: null,
    });
  });

  it('is editable when there are no gates — a record nobody is stopping you changing', () => {
    expect(resolveAccess([])).toEqual({ editable: true, readOnlyReason: null });
  });

  it('reports the first refusal, in the order the screen listed them', () => {
    expect(
      resolveAccess([
        { allowed: true, reason: 'a' },
        { allowed: false, reason: 'b' },
        { allowed: false, reason: 'c' },
      ])
    ).toEqual({ editable: false, readOnlyReason: 'b' });
  });
});

describe('RecordCollectionPanel — a side panel never takes the page down with it', () => {
  const panel = (resource: RecordResource<readonly string[]>) => (
    <RecordCollectionPanel
      testId="demo-record-things"
      title="Things"
      resource={resource}
      emptyLabel="Nothing here yet."
    >
      {(items) => (
        <ul>
          {items.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      )}
    </RecordCollectionPanel>
  );

  it('is ABSENT from the document when the caller lacks the capability', () => {
    renderShell({ name: 'Support', global: false, holderCount: 0 }, undefined, panel({ status: 'forbidden' }));

    // Not an error box, not an empty card, not a heading — nothing. A panel that
    // exists only to say "you may not see this" is noise about a decision the
    // operator made deliberately.
    expect(screen.queryByTestId('demo-record-things')).not.toBeInTheDocument();
    expect(screen.queryByText('Things')).not.toBeInTheDocument();
    // …and the record itself rendered.
    expect(screen.getByLabelText('Name')).toBeInTheDocument();
  });

  it('shows its own failure, and leaves the record standing', () => {
    renderShell(
      { name: 'Support', global: false, holderCount: 0 },
      undefined,
      panel({ status: 'error', message: 'Failed to load the things', detail: 'SQLSTATE[42P01]' })
    );

    expect(screen.getByText('Failed to load the things')).toBeInTheDocument();
    // `detail` is deliberately NOT shown here: raw backend text beside a side
    // panel's title says nothing to the operator reading it.
    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
    expect(screen.getByLabelText('Name')).toBeInTheDocument();
  });

  it('says the collection is empty rather than rendering an empty panel', () => {
    renderShell({ name: 'Support', global: false, holderCount: 0 }, undefined, panel({ status: 'ready', value: [] }));

    expect(screen.getByText('Nothing here yet.')).toBeInTheDocument();
  });

  it('renders the items when they arrive', () => {
    renderShell(
      { name: 'Support', global: false, holderCount: 0 },
      undefined,
      panel({ status: 'ready', value: ['one', 'two'] })
    );

    expect(screen.getByText('one')).toBeInTheDocument();
    expect(screen.getByText('two')).toBeInTheDocument();
  });
});
