/**
 * #868: the `ouScopePicker` block (web renderer).
 *
 * The block's whole job is to produce a RULE — `{unit, scope, type}` — that a
 * consuming plugin persists and resolves later, so what is worth pinning is the
 * VALUE, not the pixels:
 *
 *   - every write carries the complete rule, so no path can persist one without
 *     its `scope` (the one field a consumer cannot recover by guessing);
 *   - "this unit" and "this unit's subtree" are two distinguishable values, not
 *     one value read two ways;
 *   - `(unit: null, scope: 'unit')` — the row of the resolution table that is
 *     never produced — is unreachable through the controls;
 *   - a `unit` scope always carries `type: null`;
 *   - `anchorType` narrows the fetch (`?type=`), `memberType` pins the value's
 *     `type` and costs no vocabulary request at all;
 *   - an untouched picker submits NOTHING, so a form editing a stored rule can
 *     never blank it;
 *   - the units come from CORE's endpoints and nowhere else, and pagination is
 *     exhausted (#870) — a truncated unit list is what made this unbuildable.
 *
 * The desktop twin asserts the same contract in
 * `templates/tauri-desktop/src/plugin-blocks/__tests__/block-renderer-ou-scope.test.tsx`.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import * as actionPermissionModule from '@/hooks/useActionPermission';
import type { ActionPermission } from '@/hooks/useActionPermission';
import { ToastProvider } from '@/lib/toast-context';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
jest.mock('@/hooks/useActionPermission', () => ({ useActionPermission: jest.fn() }));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;
const mockUseActionPermission =
  actionPermissionModule.useActionPermission as jest.MockedFunction<
    typeof actionPermissionModule.useActionPermission
  >;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
}

/** A small hierarchy: two roots, one with two children. */
const UNITS = [
  { id: 1, name: 'Engineering', parent_id: null, ou_type_key: 'faculty' },
  { id: 2, name: 'Science', parent_id: null, ou_type_key: 'faculty' },
  { id: 3, name: 'Software', parent_id: 1, ou_type_key: 'department' },
  { id: 4, name: 'Civil', parent_id: 1, ou_type_key: 'department' },
];

const OU_TYPES = [
  { id: 10, key: 'faculty', label: 'Faculty', sort_order: 10 },
  { id: 11, key: 'department', label: 'Department', sort_order: 20 },
];

// Radix Select uses Pointer Capture + scrollIntoView, which jsdom does not
// implement — polyfill them so the dropdowns can open in tests.
beforeAll(() => {
  window.HTMLElement.prototype.hasPointerCapture = jest.fn();
  window.HTMLElement.prototype.setPointerCapture = jest.fn();
  window.HTMLElement.prototype.releasePointerCapture = jest.fn();
  window.HTMLElement.prototype.scrollIntoView = jest.fn();
});

/** Answer core's two OU endpoints; every write succeeds. */
function stubHost(options: { units?: unknown[]; types?: unknown[]; unitsFail?: boolean } = {}) {
  const { units = UNITS, types = OU_TYPES, unitsFail = false } = options;

  mockApiClient.mockImplementation((url: string) => {
    if (url.startsWith('/api/v1/ou-types')) {
      return Promise.resolve(stubResponse(true, 200, { data: types }));
    }
    if (url.startsWith('/api/v1/ous')) {
      if (unitsFail) return Promise.resolve(stubResponse(false, 500, {}));
      return Promise.resolve(stubResponse(true, 200, { data: units }));
    }
    return Promise.resolve(stubResponse(true, 200, { data: {} }));
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseActionPermission.mockReturnValue({
    allowed: true,
    hidden: false,
    disabled: false,
    reason: null,
  } as ActionPermission);
  stubHost();
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

/** A form whose only input is the picker, plus a submit button. */
function pickerForm(extra: Record<string, unknown> = {}): Block {
  return {
    type: 'form',
    submit: { method: 'POST', endpoint: '/api/v1/x/save' },
    children: [
      { type: 'ouScopePicker', name: 'appliesTo', label: 'Applies to', ...extra } as Block,
      { type: 'submitButton', label: 'Save' } as Block,
    ],
  } as Block;
}

/** Every path requested, in order. */
function requestedPaths(): string[] {
  return mockApiClient.mock.calls.map(([url]) => url as string);
}

/** The JSON body of the last POST to the form's endpoint. */
function submittedPayload(): Record<string, unknown> | undefined {
  const call = [...mockApiClient.mock.calls]
    .reverse()
    .find(([url, init]) => url === '/api/v1/x/save' && (init as RequestInit | undefined)?.method === 'POST');
  const body = (call?.[1] as RequestInit | undefined)?.body;
  return typeof body === 'string' ? (JSON.parse(body) as Record<string, unknown>) : undefined;
}

/** Open the named combobox and click the option with the given text. */
async function choose(comboboxName: RegExp, optionText: RegExp | string): Promise<void> {
  await userEvent.click(screen.getByRole('combobox', { name: comboboxName }));
  await userEvent.click(await screen.findByRole('option', { name: optionText }));
}

/** Wait for the unit dropdown to finish loading. */
async function ready(): Promise<void> {
  await waitFor(() => expect(screen.getByRole('combobox', { name: /applies to/i })).not.toBeDisabled());
}

describe('ouScopePicker — where the data comes from', () => {
  it("fetches core's OU list and type vocabulary, and nothing plugin-owned", async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);
    await ready();

    const paths = requestedPaths();
    expect(paths.some((p) => p.startsWith('/api/v1/ous'))).toBe(true);
    expect(paths.some((p) => p.startsWith('/api/v1/ou-types'))).toBe(true);
    // The block declares no `source`, so there is nothing else it could fetch.
    expect(paths.every((p) => p.startsWith('/api/v1/ous') || p.startsWith('/api/v1/ou-types'))).toBe(true);
  });

  it('narrows the ANCHOR list at the source with `anchorType`, not client-side', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ anchorType: 'faculty' })]} />);
    await ready();

    expect(requestedPaths().some((p) => p.startsWith('/api/v1/ous?type=faculty'))).toBe(true);
  });

  it('costs no vocabulary request when `memberType` pins the kind', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ memberType: 'department' })]} />);
    await ready();

    expect(requestedPaths().some((p) => p.startsWith('/api/v1/ou-types'))).toBe(false);
    expect(screen.queryByRole('combobox', { name: /kind/i })).not.toBeInTheDocument();
  });

  it('exhausts pagination rather than offering page 1 as the whole tree (#870/#824)', async () => {
    const page1 = UNITS.slice(0, 2);
    mockApiClient.mockImplementation((url: string) => {
      if (url.startsWith('/api/v1/ou-types')) {
        return Promise.resolve(stubResponse(true, 200, { data: OU_TYPES }));
      }
      if (url.includes('page=')) {
        // The walk asks for page 1 at the server maximum; answer the whole set.
        return Promise.resolve(
          stubResponse(true, 200, {
            data: UNITS,
            pagination: { page: 1, perPage: 100, total: 4, totalPages: 1 },
          })
        );
      }
      return Promise.resolve(
        stubResponse(true, 200, {
          data: page1,
          pagination: { page: 1, perPage: 2, total: 4, totalPages: 2 },
        })
      );
    });

    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);
    await ready();
    await userEvent.click(screen.getByRole('combobox', { name: /applies to/i }));

    // Unit 4 lives past the first page; a truncated list would drop it.
    expect(await screen.findByRole('option', { name: /Civil/ })).toBeInTheDocument();
  });

  it('renders a retry affordance when the unit list fails', async () => {
    stubHost({ unitsFail: true });
    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);

    expect(await screen.findByText(/failed to load organizational units/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('degrades to a placeholder outside a form, and fetches nothing', () => {
    renderWrapped(
      <BlockRenderer
        blocks={[{ type: 'ouScopePicker', name: 'appliesTo', label: 'Applies to' } as Block]}
      />
    );

    expect(screen.queryByRole('combobox', { name: /applies to/i })).not.toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
  });
});

describe('ouScopePicker — the unit list reads as a hierarchy', () => {
  it('orders parents before their children and keeps every unit', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);
    await ready();
    await userEvent.click(screen.getByRole('combobox', { name: /applies to/i }));

    const options = (await screen.findAllByRole('option')).map((el) => (el.textContent ?? '').trim());
    // "All organizational units" first, then Engineering with its two children,
    // then Science — a depth-first walk, sorted by name at each level.
    expect(options[0]).toMatch(/all organizational units/i);
    expect(options.slice(1)).toEqual(['Engineering', 'Civil', 'Software', 'Science']);
  });

  it('keeps a unit whose parent is absent from the list rather than dropping it', async () => {
    // `anchorType` filtering can legitimately remove a parent from the answer.
    stubHost({ units: [{ id: 3, name: 'Software', parent_id: 1 }] });
    renderWrapped(<BlockRenderer blocks={[pickerForm({ anchorType: 'department' })]} />);
    await ready();
    await userEvent.click(screen.getByRole('combobox', { name: /applies to/i }));

    expect(await screen.findByRole('option', { name: /Software/ })).toBeInTheDocument();
  });
});

describe('ouScopePicker — the value it writes', () => {
  it('submits nothing at all while untouched, so a stored rule is never blanked', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);
    await ready();
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(submittedPayload()).toBeDefined());
    expect(submittedPayload()).toEqual({});
  });

  it('writes the COMPLETE rule on the first touch — never a partial patch', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);
    await ready();
    await choose(/applies to/i, /Engineering/);
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(submittedPayload()).toBeDefined());
    const rule = submittedPayload()?.appliesTo as Record<string, unknown>;
    expect(Object.keys(rule).sort()).toEqual(['scope', 'type', 'unit']);
    expect(rule.unit).toBe(1);
    expect(typeof rule.scope).toBe('string');
  });

  it('distinguishes "this unit" from "this unit and its subtree"', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);
    await ready();
    await choose(/applies to/i, /Engineering/);

    await choose(/scope/i, /this unit only/i);
    await userEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(submittedPayload()?.appliesTo).toBeDefined());
    expect(submittedPayload()?.appliesTo).toEqual({ unit: 1, scope: 'unit', type: null });

    await choose(/scope/i, /everything below it/i);
    await userEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() =>
      expect((submittedPayload()?.appliesTo as Record<string, unknown>)?.scope).toBe('subtree')
    );
    expect(submittedPayload()?.appliesTo).toEqual({ unit: 1, scope: 'subtree', type: null });
  });

  it('carries the chosen kind, and drops it the moment the scope becomes `unit`', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ scopes: ['subtree', 'unit'] })]} />);
    await ready();
    await choose(/applies to/i, /Engineering/);
    await choose(/kind/i, /Department/);

    await userEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(submittedPayload()?.appliesTo).toBeDefined());
    expect(submittedPayload()?.appliesTo).toEqual({ unit: 1, scope: 'subtree', type: 'department' });

    // A kind filter over the single unit the user picked can only subtract it,
    // so the control disappears and the rule is written without it.
    await choose(/scope/i, /this unit only/i);
    expect(screen.queryByRole('combobox', { name: /kind/i })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() =>
      expect((submittedPayload()?.appliesTo as Record<string, unknown>)?.scope).toBe('unit')
    );
    expect(submittedPayload()?.appliesTo).toEqual({ unit: 1, scope: 'unit', type: null });
  });

  it('pins the kind from `memberType` without a control, on every write', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ memberType: 'department' })]} />);
    await ready();
    await choose(/applies to/i, /Engineering/);
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(submittedPayload()?.appliesTo).toBeDefined());
    expect((submittedPayload()?.appliesTo as Record<string, unknown>).type).toBe('department');
  });

  it('never produces the (unit: null, scope: "unit") row of the resolution table', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm()]} />);
    await ready();
    await choose(/applies to/i, /Engineering/);
    await choose(/scope/i, /this unit only/i);

    // Dropping the anchor while the scope is "this unit" would leave a rule that
    // resolves to nothing; the scope moves instead.
    await choose(/applies to/i, /all organizational units/i);
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(submittedPayload()?.appliesTo).toBeDefined());
    const rule = submittedPayload()?.appliesTo as Record<string, unknown>;
    expect(rule.unit).toBeNull();
    expect(rule.scope).not.toBe('unit');
  });

  it('writes a tenant-wide rule when no anchor is chosen', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ scopes: ['subtree', 'children'] })]} />);
    await ready();
    await choose(/scope/i, /direct children only/i);
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(submittedPayload()?.appliesTo).toBeDefined());
    expect(submittedPayload()?.appliesTo).toEqual({ unit: null, scope: 'children', type: null });
  });
});

describe('ouScopePicker — what the author controls', () => {
  it('offers only the declared scopes, in the declared order', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ scopes: ['children', 'subtree'] })]} />);
    await ready();
    await userEvent.click(screen.getByRole('combobox', { name: /scope/i }));

    const options = (await screen.findAllByRole('option')).map((el) => (el.textContent ?? '').trim());
    expect(options).toEqual(['Direct children only', 'This unit and everything below it']);
  });

  it('collapses the scope control when only one scope is offerable', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ scopes: ['subtree'] })]} />);
    await ready();

    expect(screen.queryByRole('combobox', { name: /scope/i })).not.toBeInTheDocument();
  });

  it('drops the tenant-wide option when `required` — the rule must be anchored', async () => {
    renderWrapped(<BlockRenderer blocks={[pickerForm({ required: true })]} />);
    await ready();
    await userEvent.click(screen.getByRole('combobox', { name: /applies to/i }));

    const options = (await screen.findAllByRole('option')).map((el) => (el.textContent ?? '').trim());
    expect(options).not.toContain('All organizational units');
    expect(options).toContain('Engineering');
  });
});
