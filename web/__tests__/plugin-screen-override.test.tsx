/**
 * #964: a registered plugin screen override actually RENDERS.
 *
 * The plugin UI registry (`lib/plugin-ui-registry.tsx`) is a module-level Map,
 * and the module that fills it (`lib/plugin-screens.tsx`) used to be pulled in
 * by a bare side-effect `import '@/lib/plugin-screens'` from the SERVER
 * component `app/layout.tsx`. The lookup, though, happens in
 * `/admin/x/[featureId]` — a `'use client'` page. Server graph and client graph
 * hold two separate instances of any module they both reach, so every
 * registration ran against the copy nobody reads: the browser's registry was
 * empty on every render, no override ever won, and the host fell through to the
 * generic screen (or, for `screen: 'custom'`, to the "no screen registered"
 * placeholder).
 *
 * NOTHING FAILED. That is the whole reason this survived two months and shipped
 * as the documented reference for the mechanism: an override that never runs
 * still leaves a page that renders and works. There is no error to notice, no
 * blank screen, no console warning — just a different screen than the one that
 * was asked for. So it has to be pinned from two directions, because neither
 * one alone catches a revert:
 *
 *   1. BEHAVIOUR — mount the real host page with the real registrations and
 *      assert the OVERRIDE renders, not the generic screen. This is the
 *      invariant in the issue, and it holds the precedence branch honest.
 *   2. THE BOUNDARY — assert the registration module is a client module the
 *      app shell RENDERS. Jest cannot see the server/client split (its module
 *      graph is one graph, so behaviour test #1 would pass against the broken
 *      code too), and this is exactly the half that breaks when an import
 *      moves. It is checked statically because static is the only place the
 *      difference is visible.
 */

import React from 'react';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { render, screen, waitFor } from '@testing-library/react';

import type { PluginFeature } from '@/lib/plugin-features';

// ---------------------------------------------------------------------------
// Provider seams the host page and the screens under it own.
// ---------------------------------------------------------------------------

let params: Record<string, string> = {};
jest.mock('next/navigation', () => ({
  useParams: () => params,
  useRouter: () => ({ push: jest.fn() }),
}));

let features: PluginFeature[] = [];
jest.mock('@/lib/plugin-features-context', () => ({
  usePluginFeatures: () => ({ features, isLoading: false }),
}));

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission: () => true }),
}));

import PluginFeaturePage from '@/app/(protected)/admin/x/[featureId]/page';
// The very module the app shell renders — importing it here is what runs the
// real registrations, exactly as loading the shell's client bundle does.
import { PluginScreenRegistrations } from '@/lib/plugin-screens';
import {
  registerPluginScreen,
  unregisterPluginScreen,
  type PluginScreenComponent,
} from '@/lib/plugin-ui-registry';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function feature(overrides: Partial<PluginFeature>): PluginFeature {
  return {
    id: 'demo-catalog',
    plugin: 'DemoCatalog',
    label: 'Demo Catalog',
    icon: 'box',
    group: 'plugins',
    order: 30,
    screen: 'custom',
    resource: null,
    action: null,
    embed: null,
    requiredPermission: 'demo_catalog:view',
    capabilities: { canCreate: true, canEdit: true, canDelete: true },
    ...overrides,
  };
}

/** A `screen: 'crud'` feature — the host would serve the generic CRUD screen. */
const CRUD_FEATURE = feature({
  id: 'acme-things',
  plugin: 'Acme',
  label: 'Things',
  screen: 'crud',
  resource: { basePath: '/api/v1/acme/things', titleField: 'name' },
  requiredPermission: 'acme:view',
});

const DEMO_CATALOG_FEATURE = feature({});

function stubResponse(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

/**
 * Renders the app shell's registrar above the host page — the same order the
 * browser evaluates them in: `app/layout.tsx` renders
 * `<PluginScreenRegistrations />`, the route renders below it.
 */
function renderHost() {
  return render(
    <>
      <PluginScreenRegistrations />
      <PluginFeaturePage />
    </>
  );
}

beforeEach(() => {
  mockApiClient.mockReset();
  mockApiClient.mockResolvedValue(stubResponse({ data: [] }));
});

// ---------------------------------------------------------------------------
// 1. BEHAVIOUR — the registered override is what renders.
// ---------------------------------------------------------------------------

describe('a registered override renders instead of the host-derived screen', () => {
  it('renders the app-registered screen for demo-catalog, not the placeholder', async () => {
    params = { featureId: 'demo-catalog' };
    features = [DEMO_CATALOG_FEATURE];

    renderHost();

    // The bespoke screen fetches the plugin's own collection through its
    // adapter on mount. Asserted on the REQUEST rather than on rendered copy:
    // both screens render the feature label as their <h1>, so the heading
    // cannot tell them apart — and the DemoCatalog components render raw i18n
    // keys today (see the note in the PR), which is not something a test
    // pinning the override mechanism should be coupled to.
    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/demo-catalog/items');
    });

    // The placeholder the host shows when a `screen: 'custom'` feature has no
    // registration — which is what this route served in the browser before the
    // registry was reachable from the client.
    expect(screen.queryByText('No screen registered')).toBeNull();
  });

  it('wins over the generic CRUD screen for a crud feature', async () => {
    params = { featureId: CRUD_FEATURE.id };
    features = [CRUD_FEATURE];

    const Bespoke: PluginScreenComponent = ({ feature: f }) => (
      <div data-testid="bespoke-screen">bespoke screen for {f.label}</div>
    );
    registerPluginScreen(CRUD_FEATURE.id, Bespoke);

    try {
      renderHost();

      expect(await screen.findByTestId('bespoke-screen')).toHaveTextContent(
        'bespoke screen for Things'
      );
      // The generic CRUD screen loads the plugin's OpenAPI spec and its
      // collection on mount; the override above fetches nothing. No request at
      // all is therefore proof the generic screen never mounted.
      expect(mockApiClient).not.toHaveBeenCalled();
    } finally {
      unregisterPluginScreen(CRUD_FEATURE.id);
    }
  });

  it('falls through to the generic CRUD screen once the override is gone', async () => {
    // Non-vacuity for the test above: the same feature, with nothing
    // registered, DOES reach the generic screen — so "no request" there is the
    // override winning, not a fixture that never renders anything.
    params = { featureId: CRUD_FEATURE.id };
    features = [CRUD_FEATURE];

    renderHost();

    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/acme/things');
    });
    expect(screen.queryByTestId('bespoke-screen')).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// 2. THE BOUNDARY — the registrations run where the registry is consulted.
// ---------------------------------------------------------------------------

const WEB_ROOT = path.join(__dirname, '..');
const REGISTRATIONS = path.join(WEB_ROOT, 'lib', 'plugin-screens.tsx');
const LAYOUT = path.join(WEB_ROOT, 'app', 'layout.tsx');
const SPECIFIER = '@/lib/plugin-screens';

/**
 * A side-effect-only `import '@/lib/plugin-screens'` — the #964 bug in one
 * line. Anchored to the start of a line (and NOT global, so `.test()` keeps no
 * cursor between calls) so the several docblocks that quote the broken form in
 * prose are not mistaken for the thing itself.
 */
function bareImport(): RegExp {
  return new RegExp(String.raw`^[ 	]*import\s+['"]${SPECIFIER}['"]`, 'm');
}

function source(file: string): string {
  // The registration module is authored with a BOM in places; strip it so the
  // 'use client' assertion below reads the directive and not the byte order.
  return readFileSync(file, 'utf8').replace(/^﻿/, '');
}

describe('the registrations reach the browser', () => {
  it("declares 'use client', so registering happens in the client graph", () => {
    // The directive must be the module's FIRST statement to count.
    const first = source(REGISTRATIONS)
      .split('\n')
      .map((line) => line.trim())
      .find((line) => line !== '');

    expect(first).toMatch(/^['"]use client['"];?$/);
  });

  it('is pulled into the app shell by a rendered element, not a bare import', () => {
    const layout = source(LAYOUT);

    // A bare side-effect import from this SERVER component is the #964 bug
    // itself: it runs the registrations in the server's module graph and
    // leaves the browser's registry empty.
    expect(layout).not.toMatch(bareImport());

    expect(layout).toMatch(
      new RegExp(
        String.raw`import\s*\{[^}]*\bPluginScreenRegistrations\b[^}]*\}\s*from\s*['"]${SPECIFIER}['"]`
      )
    );
    expect(layout).toMatch(/<PluginScreenRegistrations\s*\/>/);
  });

  it('is imported by nobody else for its side effects alone', () => {
    // Generalised: wherever else the registrations get pulled in, it must be
    // through a binding somebody renders. A second bare import would put the
    // module back in a graph the browser may never evaluate.
    const offenders = sourceFiles(WEB_ROOT).filter((file) =>
      bareImport().test(source(file))
    );

    expect(offenders).toEqual([]);
  });
});

/** Every .ts/.tsx file under web/, excluding build output and dependencies. */
function sourceFiles(root: string): string[] {
  const skip = new Set(['node_modules', '.next', 'coverage', 'e2e', '__tests__']);
  const found: string[] = [];

  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      if (entry.name.startsWith('.') || skip.has(entry.name)) {
        continue;
      }
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walk(full);
      } else if (/\.tsx?$/.test(entry.name)) {
        found.push(full);
      }
    }
  };

  walk(root);
  return found;
}
