/**
 * #1015 — the two `@amroksaleh/ui` audience controls, on their own.
 *
 * These are kit primitives, so they are tested through their PROPS and their
 * rendered output only. Each case pins a claim the component's docblock makes
 * that a plausible refactor could quietly break:
 *
 *  - a preview must never read as a membership LIST (the count is "right now",
 *    a partial sample says it is partial, and the "a group is a rule" note is
 *    always there — there is no `user_group_members` table behind any of it);
 *  - `truncated` comes from the SERVER and is never re-derived, so a sample that
 *    happens to be shorter than the total is still labelled by the flag;
 *  - a missing name renders as an id, never as a blank, because seeing group
 *    definitions does not imply `users:read`;
 *  - an unreadable catalogue produces a REASON, never an empty dropdown;
 *  - the kit ships into Arabic UIs, so the markup uses logical properties.
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';

import {
  AudienceGroupPicker,
  type AudienceGroupOption,
  type AudienceGroupPreview,
} from '@amroksaleh/ui/audience-group-picker';
import {
  AudiencePeoplePicker,
  type AudiencePersonOption,
} from '@amroksaleh/ui/audience-people-picker';

const GROUPS: AudienceGroupOption[] = [
  { id: 1, name: 'Instructors', description: 'Everyone holding the instructor role.' },
  { id: 2, name: 'Tender committee', description: null },
];

const PEOPLE: AudiencePersonOption[] = [
  { id: 11, name: 'Aisha Karim', secondary: 'aisha@demo.example.com' },
  { id: 12, name: 'Omar Haddad', secondary: 'omar@demo.example.com' },
  { id: 13, name: 'Lena Farouk', secondary: 'lena@demo.example.com' },
];

beforeAll(() => {
  // Radix Select needs these in jsdom.
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

describe('AudienceGroupPicker', () => {
  it('renders the reason instead of an empty dropdown when the catalogue is unreadable', () => {
    render(
      <AudienceGroupPicker
        groups={[]}
        value={null}
        onChange={jest.fn()}
        unavailableReason="You cannot list user groups here. An administrator would need to grant you groups:read."
      />
    );

    expect(screen.getByText(/grant you groups:read/i)).toBeInTheDocument();
    // The whole point: no control at all, rather than one that cannot be used.
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('says the workspace has no groups only when the catalogue really is empty', () => {
    render(<AudienceGroupPicker groups={[]} value={null} onChange={jest.fn()} />);

    expect(screen.getByText(/no user groups have been defined/i)).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('reports a truncated preview AS a sample, from the flag and not from the numbers', () => {
    // `truncated: true` with a sample the same length as `total` — impossible in
    // practice, and chosen on purpose: a component that re-derived truncation
    // from `total > members.length` would call this "everybody" and pass a test
    // built from realistic numbers.
    const preview: AudienceGroupPreview = {
      total: 2,
      truncated: true,
      sampleSize: 2,
      members: [
        { profileId: 11, displayName: 'Aisha Karim' },
        { profileId: 12, displayName: 'Omar Haddad' },
      ],
    };

    render(
      <AudienceGroupPicker
        groups={GROUPS}
        value={1}
        onChange={jest.fn()}
        preview={preview}
        previewStatus="ready"
      />
    );

    expect(screen.getByText(/a sample, not the whole set/i)).toBeInTheDocument();
    expect(screen.queryByText(/that is everybody/i)).not.toBeInTheDocument();
  });

  it('says the sample IS everybody when the server did not truncate', () => {
    render(
      <AudienceGroupPicker
        groups={GROUPS}
        value={1}
        onChange={jest.fn()}
        previewStatus="ready"
        preview={{
          total: 2,
          truncated: false,
          sampleSize: 10,
          members: [
            { profileId: 11, displayName: 'Aisha Karim' },
            { profileId: 12, displayName: 'Omar Haddad' },
          ],
        }}
      />
    );

    expect(screen.getByText(/that is everybody/i)).toBeInTheDocument();
    expect(screen.queryByText(/a sample, not the whole set/i)).not.toBeInTheDocument();
  });

  it('states that membership is worked out afresh, on every preview it shows', () => {
    render(
      <AudienceGroupPicker
        groups={GROUPS}
        value={1}
        onChange={jest.fn()}
        previewStatus="ready"
        preview={{ total: 1043, truncated: true, sampleSize: 1, members: [{ profileId: 11 }] }}
      />
    );

    const note = screen.getByText(/a group is a rule, not a saved list of people/i);
    expect(note).toBeInTheDocument();
    expect(note.textContent).toMatch(/every time the document moves/i);
    // Present tense on the count, never "has 1043 members".
    expect(screen.getByText(/reaches 1043 people right now/i)).toBeInTheDocument();
  });

  it('shows a person with no readable name as an id rather than a blank', () => {
    render(
      <AudienceGroupPicker
        groups={GROUPS}
        value={1}
        onChange={jest.fn()}
        previewStatus="ready"
        preview={{
          total: 2,
          truncated: false,
          sampleSize: 10,
          members: [
            { profileId: 11, displayName: null },
            { profileId: 12, displayName: 'Omar Haddad' },
          ],
        }}
      />
    );

    expect(screen.getByText('Profile #11')).toBeInTheDocument();
    expect(screen.getByText('Omar Haddad')).toBeInTheDocument();
  });

  it('reports an empty resolution as a fact, with nobody listed', () => {
    render(
      <AudienceGroupPicker
        groups={GROUPS}
        value={2}
        onChange={jest.fn()}
        previewStatus="ready"
        preview={{ total: 0, truncated: false, sampleSize: 10, members: [] }}
      />
    );

    expect(screen.getByText(/resolves to nobody right now/i)).toBeInTheDocument();
    expect(screen.queryByText(/that is everybody/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
  });

  it('offers a way back when the preview failed, and shows the refusal verbatim', () => {
    const retry = jest.fn();
    render(
      <AudienceGroupPicker
        groups={GROUPS}
        value={1}
        onChange={jest.fn()}
        previewStatus="error"
        previewError="user group 1 does not exist in this tenant."
        onRetryPreview={retry}
      />
    );

    expect(screen.getByText('user group 1 does not exist in this tenant.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));
    expect(retry).toHaveBeenCalledTimes(1);
  });

  it('says so when the step names a group that is not in the list', () => {
    // A saved step pointing at a deleted group, or one past the pages the
    // caller loaded. Radix draws the placeholder for a value it has no item
    // for, so silence here reads as "nothing chosen" and invites the author to
    // overwrite a `group_id` that was perfectly good.
    render(<AudienceGroupPicker groups={GROUPS} value={404} onChange={jest.fn()} />);

    expect(screen.getByText(/names user group #404/i)).toBeInTheDocument();
    expect(screen.getByText(/would replace it/i)).toBeInTheDocument();
  });

  it('says nothing of the sort when the chosen group IS in the list', () => {
    render(<AudienceGroupPicker groups={GROUPS} value={1} onChange={jest.fn()} />);

    expect(screen.queryByText(/is not in the list you can see/i)).not.toBeInTheDocument();
  });

  it('uses logical directional properties only, so it mirrors under RTL', () => {
    const { container } = render(
      <div dir="rtl">
        <AudienceGroupPicker
          groups={GROUPS}
          value={1}
          onChange={jest.fn()}
          previewStatus="ready"
          preview={{
            total: 3,
            truncated: true,
            sampleSize: 1,
            members: [{ profileId: 11, displayName: 'Aisha Karim' }],
          }}
        />
      </div>
    );

    expect(physicalDirectionClasses(container)).toEqual([]);
  });
});

describe('AudiencePeoplePicker', () => {
  it('does not open onto the whole roster — a search is required first', () => {
    render(<AudiencePeoplePicker people={PEOPLE} value={[]} onChange={jest.fn()} />);

    expect(screen.getByText(/nobody chosen yet/i)).toBeInTheDocument();
    for (const person of PEOPLE) {
      expect(screen.queryByText(person.name)).not.toBeInTheDocument();
    }
  });

  it('names a person by searching, and stops offering somebody already named', () => {
    const onChange = jest.fn();
    render(<AudiencePeoplePicker people={PEOPLE} value={[]} onChange={onChange} />);

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'omar' } });
    fireEvent.click(screen.getByRole('button', { name: /Omar Haddad/ }));

    expect(onChange).toHaveBeenCalledWith([12]);

    // Re-render as the caller would, with the choice applied.
    render(<AudiencePeoplePicker people={PEOPLE} value={[12]} onChange={jest.fn()} />);
    const searchBoxes = screen.getAllByRole('searchbox');
    fireEvent.change(searchBoxes[searchBoxes.length - 1], { target: { value: 'omar' } });
    expect(screen.getByText(/nobody matches that/i)).toBeInTheDocument();
  });

  it('shows a chosen id the catalogue does not cover as an id, never as a blank', () => {
    render(<AudiencePeoplePicker people={PEOPLE} value={[11, 999]} onChange={jest.fn()} />);

    expect(screen.getByText('Aisha Karim')).toBeInTheDocument();
    expect(screen.getByText('Profile #999')).toBeInTheDocument();
  });

  it('removes a named person without disturbing the others, keeping their order', () => {
    const onChange = jest.fn();
    render(<AudiencePeoplePicker people={PEOPLE} value={[11, 12, 13]} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: 'Remove Omar Haddad' }));

    expect(onChange).toHaveBeenCalledWith([11, 13]);
  });

  it('renders the reason instead of a search box when people cannot be listed', () => {
    render(
      <AudiencePeoplePicker
        people={[]}
        value={[]}
        onChange={jest.fn()}
        unavailableReason="You cannot list people here. An administrator would need to grant you users:read."
      />
    );

    expect(screen.getByText(/grant you users:read/i)).toBeInTheDocument();
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('caps how many matches it renders and says how many it is hiding', () => {
    const many: AudiencePersonOption[] = Array.from({ length: 12 }, (_, index) => ({
      id: 100 + index,
      name: `Person ${index}`,
      secondary: null,
    }));

    render(
      <AudiencePeoplePicker people={many} value={[]} onChange={jest.fn()} maxResults={3} />
    );
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Person' } });

    expect(screen.getAllByRole('listitem')).toHaveLength(3);
    expect(screen.getByText(/showing 3 of 12 matches/i)).toBeInTheDocument();
  });

  it('uses logical directional properties only, so it mirrors under RTL', () => {
    const { container } = render(
      <div dir="rtl">
        <AudiencePeoplePicker people={PEOPLE} value={[11, 12]} onChange={jest.fn()} />
      </div>
    );

    expect(physicalDirectionClasses(container)).toEqual([]);
  });
});

/**
 * Every Tailwind class in the tree that pins a side PHYSICALLY.
 *
 * The expectation is derived from the RENDERED MARKUP rather than from anything
 * the component says about itself, which is the point: `ml-2` and `text-left`
 * survive a `dir="rtl"` container and put the control on the wrong edge in
 * Arabic, and nothing about the component's own logic would reveal that. The
 * logical spellings (`ms-`, `me-`, `ps-`, `pe-`, `text-start`, `text-end`) are
 * the ones that follow the document direction.
 */
function physicalDirectionClasses(container: HTMLElement): string[] {
  const PHYSICAL = /^-?(ml|mr|pl|pr|left|right|border-l|border-r|rounded-l|rounded-r)-/;
  const found = new Set<string>();

  for (const element of Array.from(container.querySelectorAll('*'))) {
    for (const name of Array.from(element.classList)) {
      if (PHYSICAL.test(name) || name === 'text-left' || name === 'text-right') {
        found.add(name);
      }
    }
  }

  return [...found].sort();
}
