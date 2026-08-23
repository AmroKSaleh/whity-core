/**
 * WC-fk — CrudScreen x-whity-reference (FK dropdown) render test.
 *
 * Exercises the REAL path (deriveCrudModel + CrudScreen + ReferenceField +
 * usePluginData + Select), not a mocked spec: an FK property marked
 * x-whity-reference must render as a dropdown fed from the referenced
 * collection — NOT a bare number input.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import type { OpenApiSpec } from '@/lib/plugin-crud-schema';
import type { PluginFeature } from '@/lib/plugin-features';

const mockApiClient = jest.fn();
// Lazy wrapper: the factory is hoisted above the const, so reference
// mockApiClient only when apiClient is CALLED (not at module-eval time).
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

// #948: the Edit row action is a navigation now, so the screen calls
// useRouter() on every render — unmounted here, the app router invariant throws
// before the FK dropdown this file is about ever renders.
const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));
// CrudScreen asks who may read a denial's author-facing half (issue #951).
// Irrelevant to this spec — every capability here is granted — but the real
// hook throws without its provider.
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission: () => false }),
}));

import { CrudScreen } from '@/components/plugin/crud-screen';
import { toPayload } from '@/components/plugin/crud-form';

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

const REF = { resource: '/api/demo/categories', valueField: 'id', labelField: 'name' };

const SPEC: OpenApiSpec = {
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
      post: { requestBody: { content: { 'application/json': { schema: { $ref: '#/components/schemas/ThingInput' } } } } },
    },
    '/api/demo/things/{id}': {
      patch: { requestBody: { content: { 'application/json': { schema: { $ref: '#/components/schemas/ThingInput' } } } } },
      delete: {},
    },
  },
  components: {
    schemas: {
      Thing: {
        type: 'object',
        properties: { id: { type: 'integer' }, name: { type: 'string' }, category_id: { type: 'integer', 'x-whity-reference': REF } },
      },
      ThingInput: {
        type: 'object',
        required: ['name', 'category_id'],
        properties: { name: { type: 'string' }, category_id: { type: 'integer', 'x-whity-reference': REF } },
      },
    },
  },
};

const FEATURE: PluginFeature = {
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
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

beforeEach(() => {
  jest.clearAllMocks();
  // fetchSpec() reads the OpenAPI doc via the same-origin proxy route.
  global.fetch = jest.fn((input: RequestInfo | URL) =>
    String(input) === '/openapi.json'
      ? jsonResponse(200, SPEC)
      : jsonResponse(404, {})
  ) as unknown as typeof fetch;

  mockApiClient.mockImplementation((url: string, options?: { method?: string }) => {
    const method = options?.method ?? 'GET';
    if (url === '/api/demo/things' && method === 'GET') {
      return jsonResponse(200, { data: [{ id: 1, name: 'Widget', category_id: 5 }] });
    }
    if (url === '/api/demo/categories') {
      return jsonResponse(200, { data: [{ id: 5, name: 'Hardware' }, { id: 6, name: 'Software' }] });
    }
    if (url === '/api/demo/things' && method === 'POST') {
      return jsonResponse(201, { data: { id: 2 } });
    }
    return jsonResponse(404, {});
  });
});

describe('CrudScreen — x-whity-reference FK dropdown', () => {
  it('renders the FK field as a dropdown fed from the referenced collection (not a number input)', async () => {
    render(<CrudScreen feature={FEATURE} />);

    // List loads.
    await waitFor(() => expect(screen.getByText('Widget')).toBeInTheDocument());

    // Open the create dialog.
    fireEvent.click(screen.getByRole('button', { name: /^create$/i }));

    // The category_id field is a Select (role combobox), NOT a numeric input.
    const combobox = await screen.findByRole('combobox');
    expect(combobox).toBeInTheDocument();
    // Its options came from the referenced resource.
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/demo/categories', expect.anything()));

    // A plain text field is still a text input; no number input was rendered
    // for the FK (the regression this feature fixes).
    const dialog = combobox.closest('[role="dialog"]') ?? document.body;
    expect(dialog.querySelector('input[type="number"]')).toBeNull();
  });

});

// Radix Select's open interaction is unreliable in jsdom, so the picked-value
// coercion is verified against the pure toPayload helper instead of driving the
// dropdown UI.
describe('toPayload — reference coercion', () => {
  const nameField = { name: 'name', label: 'Name', kind: 'text' as const, required: true };

  it('coerces a numeric FK id to a number', () => {
    const payload = toPayload(
      [nameField, { name: 'category_id', label: 'Category', kind: 'reference', required: true, reference: REF }],
      { name: 'Gadget', category_id: '5' }
    );
    expect(payload).toEqual({ name: 'Gadget', category_id: 5 });
  });

  it('leaves a non-numeric FK key as a string', () => {
    const payload = toPayload(
      [{ name: 'slug', label: 'Slug', kind: 'reference', required: true, reference: { ...REF, valueField: 'slug' } }],
      { slug: 'hardware' }
    );
    expect(payload).toEqual({ slug: 'hardware' });
  });

  it('omits an empty optional reference', () => {
    const payload = toPayload(
      [{ name: 'category_id', label: 'Category', kind: 'reference', required: false, reference: REF }],
      { category_id: '' }
    );
    expect(payload).toEqual({});
  });
});
