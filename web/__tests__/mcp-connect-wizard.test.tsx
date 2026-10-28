/**
 * The "Connect an AI app" wizard (WHIT-697): pick an app, pick what it may do,
 * get its setup, and see it connect.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConnectWizard } from '@/app/(protected)/admin/ai-principals/connect-wizard';
import type { McpConnectInfo } from '@/app/(protected)/admin/ai-principals/types';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));
const mockAddToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: mockAddToast }),
}));

const INFO: McpConnectInfo = {
  endpoint: 'https://api.acme.test/mcp',
  serverEnabled: true,
  tenantEnabled: true,
  presets: {
    everything: ['tools:call', 'resources:read', 'prompts:read'],
    readOnly: ['tools:call', 'resources:read', 'prompts:read', 'users:read'],
  },
  areas: [{ area: 'users', permissions: ['users:manage', 'users:read'] }],
};
const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJqdGkiOiJqLTEifQ.sig';

function json(body: unknown, status = 200): Response {
  return { ok: status < 300, status, json: async () => body } as Response;
}

/** A fake API. `used` flips the issued token to "last used" for the poll. */
function api(info: McpConnectInfo = INFO) {
  const state = { used: false, previews: [] as string[][], issued: [] as unknown[] };
  mockApiClient.mockImplementation(async (path: string, init?: RequestInit) => {
    if (path === '/api/v1/mcp/connect') return json(info);
    if (path === '/api/v1/mcp/connect/preview') {
      const scope = (JSON.parse(String(init?.body)) as { scope: string[] }).scope;
      state.previews.push(scope);
      return json({
        tools: scope.includes('users:read') && !scope.includes('users:*')
          ? [{ name: 'get_api_users', description: 'List users' }]
          : [
              { name: 'get_api_users', description: 'List users' },
              { name: 'delete_api_users_id', description: 'Delete a user' },
            ],
      });
    }
    if (path === '/api/v1/mcp/tokens' && init?.method === 'POST') {
      state.issued.push(JSON.parse(String(init.body)));
      return json({ jti: 'j-1', token: TOKEN, name: 'Claude Code', scope: [], expires_at: '2027-01-26T00:00:00Z' }, 201);
    }
    if (path === '/api/v1/mcp/tokens') {
      return json({ tokens: [{ jti: 'j-1', name: 'Claude Code', last_used_at: state.used ? '2026-10-28 10:00:00' : null }] });
    }
    throw new Error(`unexpected request ${path}`);
  });
  return state;
}

function renderWizard() {
  const onConnected = jest.fn();
  render(<ConnectWizard isOpen onOpenChange={jest.fn()} onConnected={onConnected} />);
  return { onConnected };
}

beforeEach(() => {
  mockApiClient.mockReset();
  mockAddToast.mockReset();
});

describe('ConnectWizard', () => {
  it('walks from app to setup, then shows Connected once the app calls', async () => {
    const state = api();
    const user = userEvent.setup();
    const { onConnected } = renderWizard();

    await user.click(await screen.findByRole('button', { name: 'Claude Code' }));
    expect(await screen.findByText('Actions the app will be able to use: 2. For example:')).toBeInTheDocument();
    expect(state.previews[0]).toEqual(INFO.presets.everything);

    await user.click(screen.getByRole('radio', { name: /Read only/ }));
    expect(await screen.findByText('Actions the app will be able to use: 1. For example:')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Create connection' }));

    expect(state.issued).toEqual([{ name: 'Claude Code', scope: INFO.presets.readOnly }]);
    expect(onConnected).toHaveBeenCalled();
    expect(
      await screen.findByText(
        `claude mcp add --transport http claude-code "https://api.acme.test/mcp" --header "Authorization: Bearer ${TOKEN}"`
      )
    ).toBeInTheDocument();
    expect(screen.getByText('Waiting for Claude Code to connect…')).toBeInTheDocument();

    state.used = true;
    expect(await screen.findByText('Connected', {}, { timeout: 5000 })).toBeInTheDocument();
  }, 15_000);

  it('offers sign-in-only apps as coming, not as choices', async () => {
    api();
    renderWizard();

    expect(await screen.findByRole('button', { name: /Claude Coming with sign-in/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: /ChatGPT/ })).toBeDisabled();
  });

  it('refuses a custom choice with no area, which would otherwise mean everything', async () => {
    api();
    const user = userEvent.setup();
    renderWizard();

    await user.click(await screen.findByRole('button', { name: 'Cursor' }));
    await user.click(screen.getByRole('radio', { name: /Choose areas/ }));

    expect(screen.getByText('Pick at least one area.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create connection' })).toBeDisabled();
  });

  it('says in plain words when the server has MCP off, and blocks creating', async () => {
    api({ ...INFO, serverEnabled: false });
    const user = userEvent.setup();
    renderWizard();

    expect(await screen.findByText('AI apps are turned off on this server')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Cursor' }));
    expect(screen.getByRole('button', { name: 'Create connection' })).toBeDisabled();
  });

  it('points to settings when the organization has MCP off', async () => {
    api({ ...INFO, tenantEnabled: false });
    renderWizard();

    expect(await screen.findByText('AI apps are turned off for this organization')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open settings' })).toHaveAttribute('href', '/admin/settings');
  });

  it('warns when the server does not know its own address', async () => {
    api({ ...INFO, endpoint: null });
    renderWizard();

    expect(await screen.findByText(/APP_URL is not set/)).toBeInTheDocument();
  });
});
