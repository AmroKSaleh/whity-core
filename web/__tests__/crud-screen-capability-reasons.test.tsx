/**
 * Issue #951 — an unavailable crud action is DISABLED and says why, never
 * omitted.
 *
 * The defect being pinned: "no route behind this action", "you lack the
 * permission" and "the plugin author declared it wrong" all used to render as
 * a missing button, which made a correct screen the viewer has no rights on
 * pixel-identical to a broken one. A plugin shipped seven screens with no Edit
 * control because it registered PUT where editability is derived from PATCH,
 * and nothing on any screen said so.
 *
 * So these assert PRESENCE-plus-reason, not absence. A test that only checked
 * `queryByText('Edit')` to be non-null would still pass if the reason were
 * dropped again, which is exactly the regression to guard.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { OpenApiSpec } from '@/lib/plugin-crud-schema';
import type { CapabilityDenial, PluginFeature } from '@/lib/plugin-features';

const mockApiClient = jest.fn();
const recordPush = jest.fn();

jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

// Module-level, so `addToast` keeps a STABLE identity: it is a dependency of
// the load effect, and a fresh mock per render re-triggers the fetch forever.
const addToast = jest.fn();
// crud-screen navigates to the record page for Edit (#948/#960), so the
// router has to exist even for the tests that only assert denial states.
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: recordPush }) }));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

// Who is shown the author-facing half. Flipped per test so both audiences are
// exercised against the same payload.
let hasPluginsRead = false;
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission: () => hasPluginsRead }),
}));

import { CrudScreen } from '@/components/plugin/crud-screen';

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

/**
 * A spec publishing every write operation, so the SERVER's answer is the only
 * thing that can deny a control. The client-derived denials get their own
 * fixture below.
 */
const FULL_SPEC: OpenApiSpec = {
  paths: {
    '/api/demo/things': {
      get: {
        responses: {
          '200': {
            content: {
              'application/json': {
                schema: {
                  type: 'object',
                  properties: { data: { type: 'array', items: { $ref: '#/components/schemas/Thing' } } },
                },
              },
            },
          },
        },
      },
      post: { requestBody: { content: { 'application/json': { schema: { $ref: '#/components/schemas/Thing' } } } } },
    },
    '/api/demo/things/{id}': {
      patch: { requestBody: { content: { 'application/json': { schema: { $ref: '#/components/schemas/Thing' } } } } },
      delete: {},
    },
  },
  components: {
    schemas: {
      Thing: {
        type: 'object',
        properties: { id: { type: 'integer' }, name: { type: 'string' } },
      },
    },
  },
};

/** The reported shape: the item path exists but carries PUT, never PATCH. */
const PUT_ONLY_SPEC: OpenApiSpec = {
  paths: {
    '/api/demo/things': FULL_SPEC.paths['/api/demo/things'],
    '/api/demo/things/{id}': {},
  },
  components: FULL_SPEC.components,
};

const BASE_FEATURE: PluginFeature = {
  id: 'demo-things',
  plugin: 'Demo',
  label: 'Things',
  icon: null,
  group: 'plugins',
  order: 1,
  screen: 'crud',
  resource: { basePath: '/api/demo/things', titleField: 'name' },
  action: null,
  embed: null,
  requiredPermission: 'demo:read',
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
};

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

function mountWith(spec: OpenApiSpec, feature: PluginFeature) {
  global.fetch = jest.fn((input: RequestInfo | URL) =>
    String(input) === '/openapi.json' ? jsonResponse(200, spec) : jsonResponse(404, {})
  ) as unknown as typeof fetch;

  mockApiClient.mockImplementation((url: string) =>
    url === '/api/demo/things'
      ? jsonResponse(200, { data: [{ id: 1, name: 'Widget' }] })
      : jsonResponse(404, {})
  );

  return render(<CrudScreen feature={feature} />);
}

/** Open the row-actions menu, which must exist even when both items are denied. */
async function openRowActions() {
  // Wait for the list AND the spec to settle first: until they do, every
  // control is deliberately inert-but-silent, so asserting on a reason before
  // that point would be asserting on the loading state.
  await screen.findByText('Widget');
  const trigger = await screen.findByRole('button', { name: 'Row actions' });
  await userEvent.setup().click(trigger);
  await screen.findByRole('menu');
}

const forbidden = (reason: string, detail: string | null): CapabilityDenial => ({
  code: 'forbidden',
  reason,
  detail,
});

beforeEach(() => {
  jest.clearAllMocks();
  hasPluginsRead = false;
});

describe('a capability the server denied (#951)', () => {
  it('renders Create disabled and carrying the reason, not absent', async () => {
    mountWith(FULL_SPEC, {
      ...BASE_FEATURE,
      capabilities: { canCreate: false, canEdit: true, canDelete: true },
      capabilityReasons: {
        canCreate: forbidden('You do not have permission to create records here.', null),
      },
    });

    await screen.findByText('Widget');
    const create = await screen.findByRole('button', { name: /Create/ });

    // The whole point: present, inert, and explaining itself.
    expect(create).toBeDisabled();
    expect(
      screen.getByText('You do not have permission to create records here.')
    ).toBeInTheDocument();
  });

  it('exposes the reason on hover, via a wrapper a disabled control cannot host itself', async () => {
    const { container } = mountWith(FULL_SPEC, {
      ...BASE_FEATURE,
      capabilities: { canCreate: false, canEdit: true, canDelete: true },
      capabilityReasons: {
        canCreate: forbidden('You do not have permission to create records here.', null),
      },
    });

    await screen.findByText('Widget');

    expect(
      container.querySelector(
        '[title="You do not have permission to create records here."]'
      )
    ).not.toBeNull();
  });

  it('keeps the row-actions menu even when BOTH item actions are denied', async () => {
    mountWith(FULL_SPEC, {
      ...BASE_FEATURE,
      capabilities: { canCreate: true, canEdit: false, canDelete: false },
      capabilityReasons: {
        canEdit: forbidden('You do not have permission to edit records here.', null),
        canDelete: forbidden('You do not have permission to delete records here.', null),
      },
    });

    // Previously the entire column vanished here — the same erasure one level
    // up from the button.
    await openRowActions();

    expect(screen.getByText('Edit').closest('[role="menuitem"]')).toHaveAttribute(
      'aria-disabled',
      'true'
    );
    expect(screen.getByText('Delete').closest('[role="menuitem"]')).toHaveAttribute(
      'aria-disabled',
      'true'
    );
    expect(
      screen.getByText('You do not have permission to edit records here.')
    ).toBeInTheDocument();
  });

  it('appends the author-facing detail only for a caller who may read it', async () => {
    const denial = forbidden(
      'You do not have permission to edit records here.',
      "PATCH /api/demo/things/{id} requires permission 'demo:write'"
    );

    hasPluginsRead = true;
    const { unmount } = mountWith(FULL_SPEC, {
      ...BASE_FEATURE,
      capabilities: { canCreate: true, canEdit: false, canDelete: true },
      capabilityReasons: { canEdit: denial },
    });
    await openRowActions();

    // Queried off the document, not the render container: the menu content is
    // portaled to the body.
    expect(
      screen.getByTitle(`${denial.reason} (${denial.detail as string})`)
    ).toBeInTheDocument();
    unmount();

    // The server is what actually withholds it: a caller without plugins:read
    // is sent `detail: null`, and the control then shows the reason alone.
    hasPluginsRead = false;
    mountWith(FULL_SPEC, {
      ...BASE_FEATURE,
      capabilities: { canCreate: true, canEdit: false, canDelete: true },
      capabilityReasons: { canEdit: forbidden(denial.reason, null) },
    });
    await openRowActions();

    expect(screen.getByTitle(denial.reason)).toBeInTheDocument();
    expect(document.body.innerHTML).not.toContain('demo:write');
  });
});

describe('a capability the plugin never published (#951)', () => {
  it('reports a wrong-method registration as unavailable, and names PATCH to an author', async () => {
    // The reported case, from the client's side: the caller is fully permitted
    // (the server granted canEdit) and the spec still publishes no PATCH.
    hasPluginsRead = true;
    mountWith(PUT_ONLY_SPEC, BASE_FEATURE);

    await openRowActions();

    expect(screen.getByText('Edit').closest('[role="menuitem"]')).toHaveAttribute(
      'aria-disabled',
      'true'
    );
    expect(
      screen.getByText(/Editing records is not available on this screen\./)
    ).toBeInTheDocument();
    expect(document.body.innerHTML).toContain('publishes no PATCH on the item path');
  });

  it('does not show that diagnostic to an ordinary user', async () => {
    hasPluginsRead = false;
    mountWith(PUT_ONLY_SPEC, BASE_FEATURE);

    await openRowActions();

    expect(
      screen.getByText('Editing records is not available on this screen.')
    ).toBeInTheDocument();
    expect(document.body.innerHTML).not.toContain('OpenAPI');
  });
});

describe('a capability that is genuinely available', () => {
  it('renders the control enabled and with no reason attached', async () => {
    mountWith(FULL_SPEC, BASE_FEATURE);

    await screen.findByText('Widget');
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Create/ })).not.toBeDisabled()
    );
    await openRowActions();

    expect(screen.getByText('Edit').closest('[role="menuitem"]')).not.toHaveAttribute(
      'aria-disabled',
      'true'
    );
    expect(screen.queryByRole('note')).toBeNull();
  });
});
