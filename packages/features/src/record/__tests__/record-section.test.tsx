/**
 * THREE STATES PER REGION (#910) — the mechanism that makes a record page
 * flexible rather than merely bigger.
 *
 * The operator's requirement was "some parts have permissions, not always
 * everything is allowed", and the shell modelled a page-level binary. Two is one
 * short, and the missing state is not a styling variant: the difference between
 * HIDDEN and READ-ONLY is an authorization decision, so each of the properties
 * below is one that could silently regress into the other.
 *
 *  1. **Hidden is ABSENT.** Not collapsed, not `display:none`, not a heading
 *     with an explanation under it. Its title, its description and its data are
 *     all out of the document — shipping a viewer the labels of things they may
 *     not see is a different bug wearing authorization's clothes.
 *  2. **Read-only says WHY**, following #951/#968 one level up: an unavailable
 *     control is disabled with its reason, never omitted, because "you may not"
 *     and "this is broken" are otherwise the same pixels.
 *  3. **Editable is the only state with controls**, and the only state that puts
 *     an action bar on the page.
 *  4. **The answer comes from the SERVER.** `sectionAccessFrom` reads a verdict;
 *     it never combines two permissions into a third the deployment never
 *     granted. A key that is not in the map is hidden, and a map that is not
 *     there at all hides everything — fail closed.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';

import { RecordPageShell } from '../record-page-shell';
import { resolveSectionAccess, sectionAccessFrom } from '../access';
import type { RecordFactsFn, RecordSectionSpec, RecordSectionVerdicts } from '../types';

/** English-fallback translator, like the other screens' tests use. */
const t = (_key: string, fallback?: string): string => fallback ?? _key;

interface DemoFields {
  name: string;
}

const demoFacts: RecordFactsFn<DemoFields> = (record) => ({ title: record.name });

const back = { label: 'Back to list', onBack: jest.fn() };

/** A region whose two renderings are trivially told apart. */
function section(
  key: string,
  access: RecordSectionSpec['access'],
  title = `${key} region`
): RecordSectionSpec {
  return {
    key,
    title,
    description: `${key} description`,
    access,
    editor: <input aria-label={`${key} input`} defaultValue="x" />,
    readOnly: <dl data-testid={`${key}-readonly-body`}>the saved value</dl>,
  };
}

function renderSections(sections: readonly RecordSectionSpec[]) {
  return render(
    <RecordPageShell
      testId="demo-record"
      fields={{ name: 'Demo' }}
      facts={demoFacts}
      t={t}
      back={back}
      actions={<button type="button">Save changes</button>}
      sections={sections}
    />
  );
}

const EDITABLE = { state: 'editable' as const, readOnlyReason: null };
const HIDDEN = { state: 'hidden' as const, readOnlyReason: null };
const readOnly = (reason: string) => ({ state: 'read-only' as const, readOnlyReason: reason });

describe('a region is hidden, read-only or editable — and hidden means ABSENT', () => {
  it('renders the editor and nothing else for an editable region', () => {
    renderSections([section('details', EDITABLE)]);

    expect(screen.getByTestId('demo-record-section-details')).toBeInTheDocument();
    expect(screen.getByLabelText('details input')).toBeInTheDocument();
    expect(screen.queryByTestId('details-readonly-body')).not.toBeInTheDocument();
    expect(screen.queryByTestId('demo-record-section-details-readonly')).not.toBeInTheDocument();
  });

  it('renders the read-only body AND the reason, with no controls, for a read-only region', () => {
    // Paired with an EDITABLE region on purpose: the reason then belongs to this
    // region rather than to the page, so it renders where the refusal is. The
    // hoisting case — every visible region refused for one shared reason — is
    // its own test below.
    renderSections([
      section('details', readOnly('Only the system tenant can change it.')),
      section('permissions', EDITABLE),
    ]);

    expect(screen.getByTestId('details-readonly-body')).toBeInTheDocument();
    expect(screen.queryByLabelText('details input')).not.toBeInTheDocument();
    // #951 one level up: refused WITH a reason, not silently inert.
    expect(screen.getByTestId('demo-record-section-details-readonly')).toHaveTextContent(
      'Only the system tenant can change it.'
    );
  });

  it('hoists the reason to the page when a lone region carries it — one cause, said once', () => {
    renderSections([section('details', readOnly('Only the system tenant can change it.'))]);

    expect(screen.getByTestId('demo-record-readonly-notice')).toHaveTextContent(
      'Only the system tenant can change it.'
    );
    expect(screen.queryByTestId('demo-record-section-details-readonly')).not.toBeInTheDocument();
  });

  it('renders NOTHING for a hidden region — not its title, not its description, not either body', () => {
    renderSections([section('details', EDITABLE), section('permissions', HIDDEN)]);

    expect(screen.getByTestId('demo-record-section-details')).toBeInTheDocument();

    expect(screen.queryByTestId('demo-record-section-permissions')).not.toBeInTheDocument();
    // The LABELS are the disclosure, so they are asserted against by name rather
    // than inferred from the container's absence.
    expect(screen.queryByText('permissions region')).not.toBeInTheDocument();
    expect(screen.queryByText('permissions description')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('permissions input')).not.toBeInTheDocument();
    expect(screen.queryByTestId('permissions-readonly-body')).not.toBeInTheDocument();
  });

  it('does not merely SUPPRESS a hidden region — nothing in the document carries its key', () => {
    const { container } = renderSections([section('details', EDITABLE), section('permissions', HIDDEN)]);

    // A `display:none`/`hidden` implementation would leave a node behind that
    // still names the region. Nothing may.
    expect(container.innerHTML).not.toContain('permissions');
  });

  it('mixes the three states on one page, each governed independently', () => {
    renderSections([
      section('details', EDITABLE),
      section('permissions', readOnly('You may see what this grants, but not change it.')),
      section('audit', HIDDEN),
    ]);

    expect(screen.getByLabelText('details input')).toBeInTheDocument();
    expect(screen.getByTestId('permissions-readonly-body')).toBeInTheDocument();
    expect(screen.queryByLabelText('permissions input')).not.toBeInTheDocument();
    expect(screen.queryByTestId('demo-record-section-audit')).not.toBeInTheDocument();
  });
});

describe('the page-level answer is DERIVED from the regions, never stated twice', () => {
  it('shows the action bar when at least one region is editable', () => {
    renderSections([
      section('details', readOnly('Details are locked.')),
      section('permissions', EDITABLE),
    ]);

    // The save is real — it reaches less than the page, which is the point.
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeInTheDocument();
  });

  it('hides the action bar when no visible region is editable', () => {
    renderSections([
      section('details', readOnly('Details are locked.')),
      section('permissions', readOnly('Permissions are locked.')),
    ]);

    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
  });

  it('hoists ONE shared reason to the page and stops each region repeating it', () => {
    const shared = 'This is a global base role. Only the system tenant can change it.';
    renderSections([section('details', readOnly(shared)), section('permissions', readOnly(shared))]);

    expect(screen.getByTestId('demo-record-readonly-notice')).toHaveTextContent(shared);
    expect(screen.queryByTestId('demo-record-section-details-readonly')).not.toBeInTheDocument();
    expect(screen.queryByTestId('demo-record-section-permissions-readonly')).not.toBeInTheDocument();
  });

  it('leaves DIFFERENT reasons where they belong — one per region, none at the page', () => {
    renderSections([
      section('details', readOnly('You may not change roles.')),
      section('permissions', readOnly('You may not change what this grants.')),
    ]);

    // A page-level summary of two different refusals is either wrong about one
    // of them or vague about both.
    expect(screen.queryByTestId('demo-record-readonly-notice')).not.toBeInTheDocument();
    expect(screen.getByTestId('demo-record-section-details-readonly')).toHaveTextContent(
      'You may not change roles.'
    );
    expect(screen.getByTestId('demo-record-section-permissions-readonly')).toHaveTextContent(
      'You may not change what this grants.'
    );
  });

  it('renders a header and no body at all when every region is hidden', () => {
    renderSections([section('details', HIDDEN), section('permissions', HIDDEN)]);

    expect(screen.getByTestId('demo-record')).toBeInTheDocument();
    expect(screen.getByText('Demo')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
    // Nothing to say: saying more would describe what was withheld.
    expect(screen.queryByTestId('demo-record-readonly-notice')).not.toBeInTheDocument();
  });
});

describe('sectionAccessFrom — the server decides, the client reads', () => {
  const localize = (code: string) => (code === 'permission' ? 'A localized sentence.' : null);

  const verdicts: RecordSectionVerdicts = {
    details: { state: 'editable', denial: null },
    permissions: {
      state: 'read-only',
      denial: { code: 'permission', reason: 'The server sentence.', detail: null },
    },
  };

  it('reads editable and read-only straight off the verdict', () => {
    expect(sectionAccessFrom(verdicts, 'details', localize).state).toBe('editable');
    expect(sectionAccessFrom(verdicts, 'permissions', localize)).toEqual({
      state: 'read-only',
      readOnlyReason: 'A localized sentence.',
    });
  });

  it('treats a key that is NOT in the map as hidden — absence is the representation', () => {
    expect(sectionAccessFrom(verdicts, 'audit', localize)).toEqual({
      state: 'hidden',
      readOnlyReason: null,
    });
  });

  it('fails closed when the payload carried no verdicts at all', () => {
    // A screen that asked to be told and was told nothing has not been told yes.
    expect(sectionAccessFrom(undefined, 'details', localize).state).toBe('hidden');
  });

  it("falls back to the SERVER's sentence for a code this build has never heard of", () => {
    const future: RecordSectionVerdicts = {
      details: {
        state: 'read-only',
        denial: { code: 'quota-exhausted', reason: 'Your plan does not include this.', detail: null },
      },
    };

    // Correctly read-only with a vague explanation beats correctly read-only
    // with a blank space where the explanation goes.
    expect(sectionAccessFrom(future, 'details', localize).readOnlyReason).toBe(
      'Your plan does not include this.'
    );
  });

  it("appends the operator-grade detail when the server sent one", () => {
    const withDetail: RecordSectionVerdicts = {
      permissions: {
        state: 'read-only',
        denial: {
          code: 'permission',
          reason: 'The server sentence.',
          detail: "changing this requires the 'roles:manage' permission",
        },
      },
    };

    expect(sectionAccessFrom(withDetail, 'permissions', localize).readOnlyReason).toBe(
      "A localized sentence. (changing this requires the 'roles:manage' permission)"
    );
  });
});

describe('resolveSectionAccess — folding gates a screen knows locally', () => {
  it('is editable when every gate allows, and when there are none', () => {
    expect(resolveSectionAccess([]).state).toBe('editable');
    expect(
      resolveSectionAccess([{ allowed: true, effect: 'read-only', reason: 'unused' }]).state
    ).toBe('editable');
  });

  it('reports the first read-only refusal, in the order the screen listed them', () => {
    expect(
      resolveSectionAccess([
        { allowed: false, effect: 'read-only', reason: 'first' },
        { allowed: false, effect: 'read-only', reason: 'second' },
      ])
    ).toEqual({ state: 'read-only', readOnlyReason: 'first' });
  });

  it('lets a hide gate win wherever it appears, and carries no reason with it', () => {
    // Rendering a region read-only because an earlier gate got there first would
    // show a caller a region a later gate says they may not see at all.
    expect(
      resolveSectionAccess([
        { allowed: false, effect: 'read-only', reason: 'would have been shown' },
        { allowed: false, effect: 'hide' },
      ])
    ).toEqual({ state: 'hidden', readOnlyReason: null });
  });
});
