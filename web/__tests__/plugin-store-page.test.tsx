import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

/**
 * Behaviour tests for the Plugin Store admin page
 * (`app/(protected)/admin/plugins/store/page.tsx`).
 *
 * The page consumes the store-browse backend through the typed client
 * (`api.GET /plugins/store/allowed`, `api.GET /plugins/store/catalog`,
 * `api.POST /plugins/install-from-store`) and gates on:
 *   - plugins:read   → may view the page at all (else Access Denied)
 *   - plugins:upload → may Install (via <PermissionButton>)
 *
 * The typed client and capabilities hook are mocked so each gate and the
 * browse/search/install flow can be driven directly.
 */

const mockApiGet = jest.fn();
const mockApiPost = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: (...args: unknown[]) => mockApiPost(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const hasPermission = jest.fn<boolean, [string]>();
const mockCapabilities = { loading: false, permissions: [] as string[], hasPermission };
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => mockCapabilities,
}));

import PluginStorePage from '@/app/(protected)/admin/plugins/store/page';

const CATALOG = [
  { slug: 'quote-of-day', name: 'Quote of the Day', description: 'A rotating quote.', author: 'Demos', tags: ['demo', 'fun'], latest_version: '1.0.0' },
  { slug: 'system-ping', name: 'System Ping', description: 'Health pong.', author: 'Demos', tags: ['demo', 'ops'], latest_version: '1.0.0' },
];

function allowedResponse(enabled = true, hosts = ['store.example.com']) {
  return { data: { data: { enabled, hosts } }, error: undefined };
}

function catalogResponse(plugins = CATALOG) {
  return { data: { data: plugins, store_url: 'https://store.example.com', count: plugins.length }, error: undefined };
}

function routeGet(allowed: unknown = allowedResponse(), catalog: unknown = catalogResponse()) {
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/plugins/store/allowed') return Promise.resolve(allowed);
    if (path === '/api/v1/plugins/store/catalog') return Promise.resolve(catalog);
    return Promise.resolve({ data: undefined, error: 'unrouted' });
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true); // both plugins:read and plugins:upload
  mockApiPost.mockResolvedValue({ data: { data: {} }, error: undefined });
});

test('shows Access Denied without plugins:read', () => {
  hasPermission.mockImplementation((p: string) => p !== 'plugins:read');
  routeGet();
  render(<PluginStorePage />);
  expect(screen.getByText('Access Denied')).toBeInTheDocument();
});

test('shows the disabled state when no stores are configured', async () => {
  routeGet(allowedResponse(false, []));
  render(<PluginStorePage />);
  expect(await screen.findByText(/No trusted stores configured/i)).toBeInTheDocument();
});

test('browses the store and renders plugin cards', async () => {
  routeGet();
  render(<PluginStorePage />);
  // Wait for the single allowed store to be preselected (Browse enables), then click.
  await waitFor(() => expect(screen.getByRole('combobox')).toHaveValue('https://store.example.com'));
  fireEvent.click(screen.getByRole('button', { name: /Browse/i }));
  expect(await screen.findByText('Quote of the Day')).toBeInTheDocument();
  expect(screen.getByText('System Ping')).toBeInTheDocument();
  // The catalogue was fetched for the selected store.
  expect(mockApiGet).toHaveBeenCalledWith(
    '/api/v1/plugins/store/catalog',
    expect.objectContaining({ params: { query: expect.objectContaining({ store_url: 'https://store.example.com' }) } }),
  );
});

test('search passes the q term to the catalogue query', async () => {
  routeGet();
  render(<PluginStorePage />);
  await waitFor(() => expect(screen.getByRole('combobox')).toHaveValue('https://store.example.com'));
  const searchInput = await screen.findByPlaceholderText(/name, slug, tag/i);
  fireEvent.change(searchInput, { target: { value: 'ops' } });
  fireEvent.keyDown(searchInput, { key: 'Enter' });
  await waitFor(() =>
    expect(mockApiGet).toHaveBeenCalledWith(
      '/api/v1/plugins/store/catalog',
      expect.objectContaining({ params: { query: expect.objectContaining({ q: 'ops' }) } }),
    ),
  );
});

test('Install posts install-from-store with the store_url, slug and version', async () => {
  routeGet();
  render(<PluginStorePage />);
  await waitFor(() => expect(screen.getByRole('combobox')).toHaveValue('https://store.example.com'));
  fireEvent.click(screen.getByRole('button', { name: /Browse/i }));
  await screen.findByText('Quote of the Day');
  const installButtons = screen.getAllByRole('button', { name: /Install/i });
  fireEvent.click(installButtons[0]);
  await waitFor(() =>
    expect(mockApiPost).toHaveBeenCalledWith(
      '/api/v1/plugins/install-from-store',
      expect.objectContaining({
        body: expect.objectContaining({ store_url: 'https://store.example.com', slug: 'quote-of-day', version: '1.0.0' }),
      }),
    ),
  );
});

/**
 * Regression: a failed install (e.g. no/invalid store token, already
 * installed, allowlist rejection) used to show the SAME generic
 * "Failed to install X" toast for every reason, leaving the actual cause
 * (most commonly: no download token was supplied) invisible to the operator.
 * The backend's real `error` message must now surface verbatim.
 */
test('Install surfaces the real backend error message on failure', async () => {
  routeGet();
  mockApiPost.mockResolvedValue({
    data: undefined,
    error: { error: 'A valid store download token is required.' },
  });
  render(<PluginStorePage />);
  await waitFor(() => expect(screen.getByRole('combobox')).toHaveValue('https://store.example.com'));
  fireEvent.click(screen.getByRole('button', { name: /Browse/i }));
  await screen.findByText('Quote of the Day');
  fireEvent.click(screen.getAllByRole('button', { name: /Install/i })[0]);
  await waitFor(() => expect(addToast).toHaveBeenCalledWith('A valid store download token is required.', 'error'));
});

test('Browse surfaces the real backend error message on failure', async () => {
  routeGet(allowedResponse(), { data: undefined, error: { error: 'The store host is not in the trusted allowlist.' } });
  render(<PluginStorePage />);
  await waitFor(() => expect(screen.getByRole('combobox')).toHaveValue('https://store.example.com'));
  fireEvent.click(screen.getByRole('button', { name: /Browse/i }));
  await waitFor(() =>
    expect(addToast).toHaveBeenCalledWith('The store host is not in the trusted allowlist.', 'error'),
  );
});

describe('mint token', () => {
  const originalFetch = global.fetch;

  afterEach(() => {
    global.fetch = originalFetch;
  });

  test('mints a token from the selected store and fills the field', async () => {
    routeGet();
    const mockFetch = jest.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { token: 'wps_abc123', label: 'admin-store-page' } }),
    });
    global.fetch = mockFetch as unknown as typeof fetch;

    render(<PluginStorePage />);
    await waitFor(() => expect(screen.getByRole('combobox')).toHaveValue('https://store.example.com'));

    const mintButton = screen.getByTitle(/Mint a download token/i);
    fireEvent.click(mintButton);

    await waitFor(() =>
      expect(mockFetch).toHaveBeenCalledWith(
        'https://store.example.com/api/v1/plugin-store/tokens',
        expect.objectContaining({ method: 'POST', credentials: 'include' }),
      ),
    );
    await waitFor(() => expect(screen.getByPlaceholderText(/required to install/i)).toHaveValue('wps_abc123'));
    expect(addToast).toHaveBeenCalledWith('Store token minted and filled in below.', 'success');
  });

  test('surfaces the real error when minting fails (e.g. not an admin on that store)', async () => {
    routeGet();
    const mockFetch = jest.fn().mockResolvedValue({
      ok: false,
      json: () => Promise.resolve({ error: 'Forbidden' }),
    });
    global.fetch = mockFetch as unknown as typeof fetch;

    render(<PluginStorePage />);
    await waitFor(() => expect(screen.getByRole('combobox')).toHaveValue('https://store.example.com'));
    fireEvent.click(screen.getByTitle(/Mint a download token/i));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Forbidden', 'error'));
    expect(screen.getByPlaceholderText(/required to install/i)).toHaveValue('');
  });
});
