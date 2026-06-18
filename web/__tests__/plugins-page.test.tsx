/**
 * WC-221: plugins console — per-action RBAC gating, the Upload dialog, version
 * badges, and the optimistic sidenav update.
 *
 * The page is gated at the page level on `plugins:read` (Access Denied without
 * it). Each ACTION is then gated through <PermissionButton>:
 *   - Reload          plugins:reload   (non-destructive: disabled + tooltip)
 *   - Enable/Disable  plugins:enable / plugins:disable, chosen by status
 *   - Re-enable       plugins:enable   (non-destructive)
 *   - Uninstall       plugins:uninstall (DESTRUCTIVE: hidden when unpermitted)
 *   - Upload          plugins:upload   (non-destructive)
 *
 * The Upload dialog POSTs multipart to /api/v1/plugins/upload and refetches the
 * list on success; disabling a plugin optimistically removes its sidebar links
 * and refreshes the nav.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import PluginsPage from '@/app/(protected)/admin/plugins/page';
import { useCapabilities } from '@/hooks/useCapabilities';
import { api } from '@/lib/api/client';
import { uploadPluginPackage } from '@/lib/api/plugin-upload';
import { useNavigation } from '@/lib/navigation-context';
import { usePluginFeatures } from '@/lib/plugin-features-context';
import { useToast } from '@/lib/toast-context';

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: jest.fn(),
}));
jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn(), POST: jest.fn() },
}));
jest.mock('@/lib/api/plugin-upload', () => ({
  uploadPluginPackage: jest.fn(),
}));
jest.mock('@/lib/navigation-context', () => ({
  useNavigation: jest.fn(),
}));
jest.mock('@/lib/plugin-features-context', () => ({
  usePluginFeatures: jest.fn(),
}));
jest.mock('@/lib/toast-context', () => ({
  useToast: jest.fn(),
}));

const mockUseCapabilities = useCapabilities as jest.MockedFunction<
  typeof useCapabilities
>;
const mockUseNavigation = useNavigation as jest.MockedFunction<
  typeof useNavigation
>;
const mockUsePluginFeatures = usePluginFeatures as jest.MockedFunction<
  typeof usePluginFeatures
>;
const mockUseToast = useToast as jest.MockedFunction<typeof useToast>;
const mockUpload = uploadPluginPackage as jest.MockedFunction<
  typeof uploadPluginPackage
>;
const mockApiGet = api.GET as unknown as jest.Mock;
const mockApiPost = api.POST as unknown as jest.Mock;

const addToast = jest.fn();
const refresh = jest.fn(() => Promise.resolve());
const removeItemsByHref = jest.fn();

interface PluginRow {
  id: string;
  name: string;
  enabled: boolean;
  file: string | null;
  status?: string;
  version?: string;
  routes_count?: number;
  permissions_count?: number;
}

const ACTIVE_PLUGIN: PluginRow = {
  id: 'hello-world',
  name: 'HelloWorld',
  enabled: true,
  file: 'HelloWorld/Plugin.php',
  status: 'active',
  version: '1.2.0',
  routes_count: 2,
  permissions_count: 1,
};

const DISABLED_PLUGIN: PluginRow = {
  id: 'beta-thing',
  name: 'BetaThing',
  enabled: false,
  file: 'BetaThing.php',
  status: 'disabled',
  version: '0.3.0-beta',
};

/** A jest mock that resolves the typed-client `{ data }` plugin-list shape. */
function mockPluginList(plugins: PluginRow[]): void {
  mockApiGet.mockResolvedValue({ data: { data: plugins } });
}

function setCapabilities(perms: string[]): void {
  mockUseCapabilities.mockReturnValue({
    permissions: perms,
    loading: false,
    hasPermission: (slug: string) => perms.includes(slug),
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseToast.mockReturnValue({
    toasts: [],
    addToast,
    removeToast: jest.fn(),
  });
  mockUseNavigation.mockReturnValue({
    items: [],
    isLoading: false,
    getGroupedItems: () => new Map(),
    refresh,
    removeItemsByHref,
  });
  mockUsePluginFeatures.mockReturnValue({ features: [], isLoading: false });
  mockApiPost.mockResolvedValue({ data: {}, error: undefined });
});

describe('PluginsPage RBAC page gate', () => {
  it('shows Access Denied when the caller lacks plugins:read', () => {
    setCapabilities([]);
    mockPluginList([]);

    render(<PluginsPage />);

    expect(screen.getByText('Access Denied')).toBeInTheDocument();
  });
});

describe('PluginsPage per-action gating (plugins:read only)', () => {
  beforeEach(() => {
    setCapabilities(['plugins:read']);
    mockPluginList([ACTIVE_PLUGIN]);
  });

  it('renders the list once read access resolves', async () => {
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');
  });

  it('DISABLES Reload + Upload (non-destructive) with a tooltip reason', async () => {
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    const reload = screen.getByRole('button', { name: /Reload Plugins/i });
    expect(reload).toBeDisabled();
    expect(reload.closest('span')).toHaveAttribute(
      'title',
      'Requires plugins:reload'
    );

    const upload = screen.getByRole('button', { name: /Upload Plugin/i });
    expect(upload).toBeDisabled();
    expect(upload.closest('span')).toHaveAttribute(
      'title',
      'Requires plugins:upload'
    );
  });

  it('DISABLES the Disable toggle (active plugin) keyed off plugins:disable', async () => {
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    const toggle = screen.getByRole('button', { name: /Disable/i });
    expect(toggle).toBeDisabled();
    expect(toggle.closest('span')).toHaveAttribute(
      'title',
      'Requires plugins:disable'
    );
  });

  it('HIDES the destructive Uninstall trigger in the detail modal', async () => {
    const user = userEvent.setup();
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    await user.click(screen.getByRole('button', { name: /Details/i }));
    // The modal opened (Status heading is modal-only) but the destructive
    // Uninstall trigger is hidden for a read-only caller.
    await screen.findByText('Status');
    expect(
      screen.queryByRole('button', { name: /Uninstall/i })
    ).not.toBeInTheDocument();
  });
});

describe('PluginsPage per-action gating (matching perms)', () => {
  it('ENABLES Reload + Upload when the caller holds those perms', async () => {
    setCapabilities([
      'plugins:read',
      'plugins:reload',
      'plugins:upload',
      'plugins:disable',
    ]);
    mockPluginList([ACTIVE_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    expect(
      screen.getByRole('button', { name: /Reload Plugins/i })
    ).toBeEnabled();
    expect(
      screen.getByRole('button', { name: /Upload Plugin/i })
    ).toBeEnabled();
    expect(screen.getByRole('button', { name: /Disable/i })).toBeEnabled();
  });

  it('chooses plugins:enable for a disabled plugin toggle', async () => {
    // The caller holds disable but NOT enable: a DISABLED plugin's toggle
    // performs Enable, so it must be gated on plugins:enable -> disabled here.
    setCapabilities(['plugins:read', 'plugins:disable']);
    mockPluginList([DISABLED_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('BetaThing');

    const toggle = screen.getByRole('button', { name: /Enable/i });
    expect(toggle).toBeDisabled();
    expect(toggle.closest('span')).toHaveAttribute(
      'title',
      'Requires plugins:enable'
    );
  });
});

describe('PluginsPage version badges', () => {
  it('renders a Stable badge for a 1.x plugin and Beta for a 0.x-beta plugin', async () => {
    setCapabilities(['plugins:read']);
    mockPluginList([ACTIVE_PLUGIN, DISABLED_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    expect(screen.getByText('Stable')).toBeInTheDocument();
    expect(screen.getByText('Beta')).toBeInTheDocument();
  });
});

describe('PluginsPage upload dialog', () => {
  it('uploads multipart and refetches the list on success', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:upload']);
    mockPluginList([ACTIVE_PLUGIN]);
    mockUpload.mockResolvedValue({ data: { data: DISABLED_PLUGIN } });

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');
    expect(mockApiGet).toHaveBeenCalledTimes(1);

    await user.click(screen.getByRole('button', { name: /Upload Plugin/i }));

    const dialog = await screen.findByRole('dialog');
    const file = new File(['<?php'], 'demo.php', { type: 'text/x-php' });
    const input = dialog.querySelector(
      'input[type="file"]'
    ) as HTMLInputElement;
    expect(input).toBeTruthy();
    expect(input).toHaveAttribute('accept', '.zip,.php');
    await user.upload(input, file);

    await user.click(
      within(dialog).getByRole('button', { name: /^Upload$/i })
    );

    await waitFor(() => expect(mockUpload).toHaveBeenCalledTimes(1));
    expect(mockUpload).toHaveBeenCalledWith(file);
    // Success refetches the plugin list (the staged plugin lands disabled).
    await waitFor(() => expect(mockApiGet).toHaveBeenCalledTimes(2));
  });

  it('rejects an oversized file before uploading', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:upload']);
    mockPluginList([ACTIVE_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    await user.click(screen.getByRole('button', { name: /Upload Plugin/i }));
    const dialog = await screen.findByRole('dialog');

    // 33 MiB > the 32 MiB cap.
    const huge = new File(['x'], 'huge.zip', { type: 'application/zip' });
    Object.defineProperty(huge, 'size', { value: 33 * 1024 * 1024 });
    const input = dialog.querySelector(
      'input[type="file"]'
    ) as HTMLInputElement;
    await user.upload(input, huge);
    await user.click(
      within(dialog).getByRole('button', { name: /^Upload$/i })
    );

    expect(mockUpload).not.toHaveBeenCalled();
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(
        expect.stringMatching(/too large|32/i),
        'error'
      )
    );
  });
});

describe('PluginsPage optimistic sidenav on disable', () => {
  it('optimistically removes the plugin nav links and refreshes the sidebar', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:disable']);
    mockPluginList([ACTIVE_PLUGIN]);
    // The active plugin contributes one feature -> /admin/x/hello-greetings.
    mockUsePluginFeatures.mockReturnValue({
      features: [
        {
          id: 'hello-greetings',
          plugin: 'HelloWorld',
          label: 'Greetings',
          icon: null,
          group: 'plugins',
          order: 10,
          screen: 'crud',
          resource: { basePath: '/api/v1/hello/greetings', titleField: 'message' },
          action: null,
          requiredPermission: 'hello:view',
          capabilities: { canCreate: true, canEdit: true, canDelete: true },
        },
      ],
      isLoading: false,
    });
    mockApiPost.mockResolvedValue({ data: {}, error: undefined });

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    await user.click(screen.getByRole('button', { name: /Disable/i }));

    await waitFor(() => expect(mockApiPost).toHaveBeenCalled());
    // Optimistic removal targets the plugin's contributed nav hrefs...
    await waitFor(() =>
      expect(removeItemsByHref).toHaveBeenCalledWith([
        '/admin/x/hello-greetings',
      ])
    );
    // ...and a refresh reconciles with the server.
    await waitFor(() => expect(refresh).toHaveBeenCalled());
  });
});
