/**
 * The admin MCP tools page (WHIT-754): each tool's toolset, how apps see it
 * and what agents may do with it, the list sizes, and the switch that lists a
 * plugin's always-on tool directly.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import McpToolsPage from '@/app/(protected)/admin/mcp-tools/page';
import type { McpTool, McpToolListResponse } from '@/app/(protected)/admin/mcp-tools/types';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));
const mockAddToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: mockAddToast }),
}));

function tool(overrides: Partial<McpTool>): McpTool {
  return {
    name: 'get_api_search',
    description: 'Search everything',
    requiredPermission: null,
    requiredRole: null,
    toolset: 'core.search',
    visibility: 'discoverable',
    agentPolicy: 'allow',
    plugin: null,
    alwaysOnRequested: false,
    alwaysOnEnabled: false,
    ...overrides,
  };
}

function json(body: unknown, status = 200): Response {
  return { ok: status < 300, status, json: async () => body } as Response;
}

function api(enabled: string[] = []) {
  const state = { patches: [] as unknown[], enabled };
  mockApiClient.mockImplementation(async (path: string, init?: RequestInit) => {
    if (path === '/api/v1/admin/mcp/tools') {
      const body: McpToolListResponse = {
        data: [
          tool({ name: 'get_api_search', visibility: 'always-on', alwaysOnEnabled: true }),
          tool({
            name: 'delete_api_users_id', description: 'Delete a user', toolset: 'core.users',
            requiredPermission: 'users:manage', agentPolicy: 'propose',
          }),
          tool({
            name: 'period_close_status', description: 'Where the close stands', toolset: 'period-close',
            visibility: 'always-on', plugin: 'Plugins\\PeriodClose\\PeriodClosePlugin',
            alwaysOnRequested: true, alwaysOnEnabled: state.enabled.includes('period_close_status'),
          }),
        ],
        sizes: {
          default: { tools: 7, bytes: 8000 },
          full: { tools: 313, bytes: 400000 },
          discover: { tools: 2, bytes: 1200 },
        },
      };
      return json(body);
    }
    if (path === '/api/v1/settings' && init?.method === 'PATCH') {
      const body = JSON.parse(String(init.body)) as { settings: Record<string, string> };
      state.patches.push(body);
      state.enabled = body.settings['mcp.always_on_plugin_tools'].split(',').filter((name) => name !== '');
      return json({ data: {} });
    }
    throw new Error(`unexpected request ${path}`);
  });
  return state;
}

beforeEach(() => {
  mockApiClient.mockReset();
  mockAddToast.mockReset();
});

describe('McpToolsPage', () => {
  it('shows each tool with its toolset, how apps see it and what agents may do', async () => {
    api();
    render(<McpToolsPage />);

    expect(await screen.findByText('delete_api_users_id')).toBeInTheDocument();
    expect(screen.getByText('core.users')).toBeInTheDocument();
    expect(screen.getByText('Proposes')).toBeInTheDocument();
    expect(screen.getByText('users:manage')).toBeInTheDocument();
    // A core pick is always listed; a plugin's request waits for the admin.
    expect(screen.getAllByText('Always listed')).toHaveLength(1);
    expect(screen.getByRole('switch', { name: 'List period_close_status without searching' })).not.toBeChecked();
  });

  it('says what each list mode costs an app', async () => {
    api();
    render(<McpToolsPage />);

    expect(await screen.findByText('7 actions, about 2,000 tokens')).toBeInTheDocument();
    expect(screen.getByText('313 actions, about 100,000 tokens')).toBeInTheDocument();
  });

  it('turns a plugin tool on and off through the workspace setting', async () => {
    const state = api();
    const user = userEvent.setup();
    render(<McpToolsPage />);

    await user.click(await screen.findByRole('switch', { name: 'List period_close_status without searching' }));
    await waitFor(() =>
      expect(state.patches).toEqual([{ settings: { 'mcp.always_on_plugin_tools': 'period_close_status' } }])
    );
    await waitFor(() =>
      expect(screen.getByRole('switch', { name: 'List period_close_status without searching' })).toBeChecked()
    );

    await user.click(screen.getByRole('switch', { name: 'List period_close_status without searching' }));
    await waitFor(() => expect(state.patches[1]).toEqual({ settings: { 'mcp.always_on_plugin_tools': '' } }));
  });

  it('says so when the setting cannot be saved', async () => {
    api();
    const base = mockApiClient.getMockImplementation()!;
    mockApiClient.mockImplementation(async (path: string, init?: RequestInit) =>
      path === '/api/v1/settings' ? json({ message: 'Insufficient permissions' }, 403) : base(path, init)
    );
    const user = userEvent.setup();
    render(<McpToolsPage />);

    await user.click(await screen.findByRole('switch', { name: 'List period_close_status without searching' }));
    await waitFor(() => expect(mockAddToast).toHaveBeenCalledWith('Insufficient permissions', 'error'));
  });
});
