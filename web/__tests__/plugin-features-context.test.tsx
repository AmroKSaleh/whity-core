/**
 * WC-169 review regression: the plugin feature list is AUTH-AWARE.
 *
 * The features endpoint is server-side permission-filtered, so the list is
 * only meaningful for the CURRENT authenticated user. The provider must wait
 * for auth to settle, fetch per signed-in user, clear on logout, and refetch
 * when the user changes — a fetch fired once pre-auth would pin an empty
 * list for the whole SPA session ("Feature unavailable" after login until a
 * hard refresh, the bug this test pins).
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import {
  PluginFeaturesProvider,
  usePluginFeatures,
} from '@/lib/plugin-features-context';
import { fetchPluginFeatures } from '@/lib/plugin-features';
import { useAuth } from '@/lib/auth-context';

jest.mock('@/lib/plugin-features', () => ({
  fetchPluginFeatures: jest.fn(),
}));

jest.mock('@/lib/auth-context', () => ({
  useAuth: jest.fn(),
}));

const mockFetch = fetchPluginFeatures as jest.MockedFunction<
  typeof fetchPluginFeatures
>;
const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;

function authState(user: { id: number } | null, isLoading = false) {
  return { user, isLoading } as unknown as ReturnType<typeof useAuth>;
}

function Probe() {
  const { features, isLoading } = usePluginFeatures();
  return (
    <div>
      <span data-testid="loading">{String(isLoading)}</span>
      <span data-testid="ids">{features.map((f) => f.id).join(',')}</span>
    </div>
  );
}

const FEATURE = {
  id: 'hello-greetings',
  plugin: 'HelloWorld',
  label: 'Greetings',
  icon: null,
  group: 'plugins',
  order: 10,
  screen: 'crud' as const,
  resource: { basePath: '/api/hello/greetings', titleField: 'message' },
  requiredPermission: 'hello:view',
};

describe('PluginFeaturesProvider auth-awareness', () => {
  beforeEach(() => {
    mockFetch.mockReset();
    mockUseAuth.mockReset();
  });

  it('does not fetch while auth state is still resolving', () => {
    mockUseAuth.mockReturnValue(authState(null, true));

    render(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );

    expect(mockFetch).not.toHaveBeenCalled();
    expect(screen.getByTestId('loading').textContent).toBe('true');
  });

  it('exposes an empty list without fetching when signed out', async () => {
    mockUseAuth.mockReturnValue(authState(null));

    render(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );

    await waitFor(() =>
      expect(screen.getByTestId('loading').textContent).toBe('false')
    );
    expect(mockFetch).not.toHaveBeenCalled();
    expect(screen.getByTestId('ids').textContent).toBe('');
  });

  it('fetches when a user signs in after mounting signed-out (the SPA login flow)', async () => {
    mockUseAuth.mockReturnValue(authState(null));
    mockFetch.mockResolvedValue([FEATURE]);

    const { rerender } = render(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );

    await waitFor(() =>
      expect(screen.getByTestId('loading').textContent).toBe('false')
    );
    expect(mockFetch).not.toHaveBeenCalled();

    // The user signs in via the SPA login page — no full page reload.
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    rerender(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );

    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('hello-greetings')
    );
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('clears the list on logout', async () => {
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    mockFetch.mockResolvedValue([FEATURE]);

    const { rerender } = render(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('hello-greetings')
    );

    mockUseAuth.mockReturnValue(authState(null));
    rerender(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );

    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('')
    );
    expect(mockFetch).toHaveBeenCalledTimes(1);
  });

  it('refetches when a different user signs in', async () => {
    mockUseAuth.mockReturnValue(authState({ id: 2 }));
    mockFetch.mockResolvedValue([FEATURE]);

    const { rerender } = render(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );
    await waitFor(() => expect(mockFetch).toHaveBeenCalledTimes(1));

    mockFetch.mockResolvedValue([]);
    mockUseAuth.mockReturnValue(authState({ id: 7 }));
    rerender(
      <PluginFeaturesProvider>
        <Probe />
      </PluginFeaturesProvider>
    );

    await waitFor(() => expect(mockFetch).toHaveBeenCalledTimes(2));
    await waitFor(() =>
      expect(screen.getByTestId('ids').textContent).toBe('')
    );
  });
});
