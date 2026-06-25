'use client';

import { useEffect, useRef, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { uploadPluginPackage } from '@/lib/api/plugin-upload';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useNavigation } from '@/lib/navigation-context';
import { usePluginFeatures } from '@/lib/plugin-features-context';
import { classifyPluginVersion, type PluginVersionTier } from '@/lib/plugin-version-badge';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { PermissionButton } from '@/components/rbac/permission-button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
  IconPlug,
  IconReload,
  IconTrash,
  IconPlayerPlay,
  IconPlayerPause,
  IconAlertCircle,
  IconChevronRight,
  IconUpload,
} from '@tabler/icons-react';

// Core permission required to view the plugins console (WC-218). Read access is
// gated on plugins:read; per-action gating uses the other five perms via
// <PermissionButton> (WC-221).
const PLUGINS_READ = 'plugins:read';
const PLUGINS_RELOAD = 'plugins:reload';
const PLUGINS_ENABLE = 'plugins:enable';
const PLUGINS_DISABLE = 'plugins:disable';
const PLUGINS_UNINSTALL = 'plugins:uninstall';
const PLUGINS_UPLOAD = 'plugins:upload';

// Reject client-side anything above this cap before hitting the network. The
// backend has its own limit; this is a fast-fail UX guard, not the authority.
const MAX_UPLOAD_BYTES = 32 * 1024 * 1024; // 32 MiB

type PluginEntry = components['schemas']['PluginEntry'];

// Extended type for backend plugin failure details
interface ExtendedPluginEntry extends PluginEntry {
  consecutive_errors?: number;
  last_error?: {
    message?: string;
    type?: string;
    trace?: string;
  };
}

// Map a version tier to a Badge variant (distinct styling per tier).
const VERSION_BADGE_VARIANT: Record<
  PluginVersionTier,
  React.ComponentProps<typeof Badge>['variant']
> = {
  alpha: 'destructive',
  beta: 'secondary',
  prerelease: 'outline',
  stable: 'default',
};

export default function PluginsPage() {
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const hasAccess = hasPermission(PLUGINS_READ);

  // Sidebar nav + plugin feature descriptors: used to optimistically drop a
  // disabled/uninstalled plugin's contributed nav links and then reconcile
  // with the server, all without a full page reload (WC-221).
  const { refresh: refreshNavigation, removeItemsByHref } = useNavigation();
  const { features } = usePluginFeatures();

  // Fetch actual backend plugins using useFetch to avoid set-state-in-effect issues
  const { data, loading: loadingPlugins, error, refetch: fetchPlugins } = useFetch(async () => {
    const { data: responseData } = await api.GET('/api/v1/plugins');
    if (responseData === undefined) {
      throw new Error('Failed to fetch plugins');
    }
    return responseData.data;
  }, []);

  const plugins = data ?? [];

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  // Modals & Dialogs
  const [detailPlugin, setDetailPlugin] = useState<PluginEntry | null>(null);
  const [uninstallTarget, setUninstallTarget] = useState<PluginEntry | null>(null);
  const [forceUninstall, setForceUninstall] = useState(false);
  const [uninstalling, setUninstalling] = useState(false);
  const [reloadPending, setReloadPending] = useState(false);

  // Upload dialog state
  const [uploadOpen, setUploadOpen] = useState(false);
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  // RBAC gate: Access Denied if user lacks plugins:read
  if (!hasAccess) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[450px] p-8 text-center bg-card border border-border rounded-2xl shadow-sm">
        <div className="p-4 bg-destructive/10 rounded-full text-destructive mb-4">
          <IconAlertCircle size={48} />
        </div>
        <h2 className="text-xl font-bold mb-2">Access Denied</h2>
        <p className="text-muted-foreground max-w-md mb-6 text-sm">
          You do not have the required permissions (`plugins:read`) to access the Plugin Management Console.
        </p>
        <Button onClick={() => window.history.back()} variant="outline">
          Go Back
        </Button>
      </div>
    );
  }

  // Compute the sidebar nav hrefs contributed by a given plugin. Plugin
  // features render at /admin/x/{featureId} and the feature descriptor names
  // the plugin that provides it, so we can attribute nav links to a plugin
  // entirely client-side (no backend nav-shape change needed). The plugin
  // entry's `name` matches the feature's `plugin` field.
  const pluginNavHrefs = (plugin: PluginEntry): string[] =>
    features
      .filter((f) => f.plugin === plugin.name)
      .map((f) => `/admin/x/${f.id}`);

  // After a state change that may add/remove a plugin's nav links, reflect it
  // in the sidebar immediately: optimistically drop the plugin's hrefs (only
  // meaningful for disable/uninstall; a no-op set on enable), then refresh the
  // authoritative server-filtered nav. Both are additive — no page reload.
  const syncSidebar = (hrefs: string[]): void => {
    if (hrefs.length > 0) {
      removeItemsByHref(hrefs);
    }
    void refreshNavigation();
  };

  // Enable/Disable a backend plugin. The action is derived from the plugin's
  // lifecycle `status` (the same field that drives the button label/badge), NOT
  // from `enabled`: a plugin can be present-on-disk (`enabled: true`) yet have a
  // per-worker lifecycle `status` of `disabled`, so keying off `enabled` would
  // make the button do the opposite of its label.
  const togglePluginState = async (plugin: PluginEntry) => {
    const isCurrentlyActive = plugin.status === 'active';
    const action = isCurrentlyActive ? 'disable' : 'enable';
    // Capture the contributed nav hrefs BEFORE the await so an optimistic
    // removal on disable can fire the instant the request succeeds.
    const hrefs = pluginNavHrefs(plugin);

    addToast(`${isCurrentlyActive ? 'Disabling' : 'Enabling'} plugin ${plugin.name}...`, 'info');

    try {
      let response;
      if (action === 'enable') {
        response = await api.POST('/api/v1/plugins/{name}/enable', {
          params: { path: { name: plugin.id } },
        });
      } else {
        response = await api.POST('/api/v1/plugins/{name}/disable', {
          params: { path: { name: plugin.id } },
        });
      }

      if (response.error) {
        throw new Error(response.error.error || `Failed to ${action} plugin`);
      }

      addToast(`Plugin ${plugin.name} successfully ${action}d.`, 'success');
      fetchPlugins();
      // Disabling drops the plugin's nav links optimistically; enabling has no
      // hrefs to remove yet, so the refresh alone restores them.
      syncSidebar(isCurrentlyActive ? hrefs : []);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error triggering plugin state';
      addToast(msg, 'error');
    }
  };

  // Re-enable a failed plugin
  const handleReEnable = async (pluginId: string) => {
    addToast(`Clearing failed state and re-enabling plugin...`, 'info');
    try {
      const { error: reEnableError } = await api.POST('/api/v1/plugins/{id}/re-enable', {
        params: { path: { id: pluginId } },
      });
      if (reEnableError) {
        throw new Error(reEnableError.error || 'Failed to re-enable plugin');
      }
      addToast(`Plugin re-enabled successfully.`, 'success');
      if (detailPlugin) {
        setDetailPlugin(null);
      }
      fetchPlugins();
      // Re-enabling can restore contributed nav links — refresh the sidebar.
      void refreshNavigation();
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error re-enabling plugin';
      addToast(msg, 'error');
    }
  };

  // Reload the plugin directory
  const handleReload = async () => {
    setReloadPending(true);
    addToast('Scanning plugins directory...', 'info');
    try {
      const { data: reloadData, error: reloadError } = await api.POST('/api/v1/plugins/reload');
      if (reloadError) {
        throw new Error(reloadError.error || 'Failed to reload plugins');
      }

      const reloadBody = reloadData as unknown as {
        message?: string;
        data?: { message?: string; worker_restart_required?: boolean };
        worker_restart_required?: boolean;
      };
      const msg = reloadBody?.data?.message || reloadBody?.message || 'Plugins scanned';
      addToast(msg, 'success');

      if (reloadBody?.data?.worker_restart_required || reloadBody?.worker_restart_required) {
        addToast('Warning: A worker restart is required to load modified plugins.', 'warning');
      }

      fetchPlugins();
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error reloading plugins';
      addToast(msg, 'error');
    } finally {
      setReloadPending(false);
    }
  };

  // Upload a plugin package (.zip or single .php) for staged install.
  const handleUpload = async () => {
    if (!uploadFile) {
      addToast('Choose a .zip or .php plugin package to upload.', 'error');
      return;
    }
    if (uploadFile.size > MAX_UPLOAD_BYTES) {
      addToast(
        `Package is too large (max ${MAX_UPLOAD_BYTES / (1024 * 1024)} MiB).`,
        'error'
      );
      return;
    }

    setUploading(true);
    addToast(`Uploading ${uploadFile.name}...`, 'info');
    try {
      const { error: uploadError } = await uploadPluginPackage(uploadFile);
      if (uploadError) {
        throw new Error(uploadError.error || 'Failed to upload plugin');
      }
      addToast('Plugin staged successfully. Review it, then Enable.', 'success');
      closeUploadDialog();
      // The staged plugin appears with status `disabled` for review.
      fetchPlugins();
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Upload failed';
      addToast(msg, 'error');
    } finally {
      setUploading(false);
    }
  };

  const closeUploadDialog = () => {
    setUploadOpen(false);
    setUploadFile(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  // Trigger Uninstall Dialog
  const confirmUninstall = (target: PluginEntry) => {
    setUninstallTarget(target);
    setForceUninstall(false);
  };

  // Execute Uninstall
  const handleUninstall = async () => {
    if (!uninstallTarget) return;
    setUninstalling(true);
    const hrefs = pluginNavHrefs(uninstallTarget);

    try {
      addToast(`Uninstalling plugin ${uninstallTarget.name}...`, 'info');
      const postUninstall = api.POST as unknown as (
        url: string,
        options: { params: { path: { id: string } }; body: { force: boolean } }
      ) => Promise<{ error?: { error?: string; message?: string } }>;

      const { error: uninstallError } = await postUninstall('/api/v1/plugins/{id}/uninstall', {
        params: { path: { id: uninstallTarget.id } },
        body: { force: forceUninstall },
      });

      if (uninstallError) {
        throw new Error(uninstallError.error || uninstallError.message || 'Failed to uninstall plugin');
      }

      addToast(`Plugin ${uninstallTarget.name} uninstalled successfully.`, 'success');
      setUninstallTarget(null);
      if (detailPlugin) setDetailPlugin(null);
      fetchPlugins();
      // Uninstalling removes the plugin's nav links — drop them optimistically
      // then refresh the authoritative server-filtered nav.
      syncSidebar(hrefs);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Uninstall failed';
      addToast(msg, 'error');
    } finally {
      setUninstalling(false);
    }
  };

  // Counts for summary cards (backend plugins only)
  const totalInstalled = plugins.length;
  const activeCount = plugins.filter((p) => p.status === 'active').length;
  const disabledCount = plugins.filter((p) => p.status === 'disabled').length;
  const failedCount = plugins.filter((p) => p.status === 'failed').length;

  return (
    <div className="space-y-8 max-w-7xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title="Plugin Management"
        description="Configure and manage the extension packages installed on your application."
        action={
          <div className="flex items-center gap-2">
            <PermissionButton
              permission={PLUGINS_UPLOAD}
              variant="outline"
              onClick={() => setUploadOpen(true)}
              className="gap-2 shadow-sm font-medium"
            >
              <IconUpload className="w-4 h-4" />
              Upload Plugin
            </PermissionButton>
            <PermissionButton
              permission={PLUGINS_RELOAD}
              onClick={handleReload}
              disabled={reloadPending}
              className="gap-2 shadow-sm font-medium hover:scale-[1.02] active:scale-[0.98] transition-all"
            >
              <IconReload className={`w-4 h-4 ${reloadPending ? 'animate-spin' : ''}`} />
              Reload Plugins
            </PermissionButton>
          </div>
        }
      />

      {/* Summary Statistics */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <Card className="border border-border/80 bg-card/50 backdrop-blur-sm shadow-sm hover:shadow-md transition-shadow">
          <CardHeader className="pb-2">
            <CardDescription className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/80">Total Plugins</CardDescription>
            <CardTitle className="text-3xl font-bold font-heading">{totalInstalled}</CardTitle>
          </CardHeader>
        </Card>
        <Card className="border border-border/80 bg-card/50 backdrop-blur-sm shadow-sm hover:shadow-md transition-shadow">
          <CardHeader className="pb-2">
            <CardDescription className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/80">Active</CardDescription>
            <CardTitle className="text-3xl font-bold font-heading text-success">{activeCount}</CardTitle>
          </CardHeader>
        </Card>
        <Card className="border border-border/80 bg-card/50 backdrop-blur-sm shadow-sm hover:shadow-md transition-shadow">
          <CardHeader className="pb-2">
            <CardDescription className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/80">Disabled</CardDescription>
            <CardTitle className="text-3xl font-bold font-heading text-warning">{disabledCount}</CardTitle>
          </CardHeader>
        </Card>
        <Card className="border border-border/80 bg-card/50 backdrop-blur-sm shadow-sm hover:shadow-md transition-shadow">
          <CardHeader className="pb-2">
            <CardDescription className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/80">Failed</CardDescription>
            <CardTitle className="text-3xl font-bold font-heading text-error">{failedCount}</CardTitle>
          </CardHeader>
        </Card>
      </div>

      {/* Installed Plugins */}
      {loadingPlugins ? (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {[1, 2, 3].map((n) => (
            <Card key={n} className="border border-border/50 animate-pulse">
              <div className="h-32 bg-muted/40 rounded-t-xl" />
              <div className="p-6 space-y-3">
                <div className="h-4 w-2/3 bg-muted/60 rounded" />
                <div className="h-3 w-full bg-muted/50 rounded" />
                <div className="h-8 w-1/3 bg-muted/60 rounded mt-4" />
              </div>
            </Card>
          ))}
        </div>
      ) : plugins.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 text-center text-muted-foreground">
          <IconPlug className="w-10 h-10 mb-3 opacity-60" />
          <p className="text-sm">No plugins are installed. Drop a plugin into the <code className="font-mono">plugins/</code> directory and use <strong>Reload Plugins</strong>, or use <strong>Upload Plugin</strong>.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {plugins.map((plugin) => {
            const isActive = plugin.status === 'active';
            const versionBadge = classifyPluginVersion(plugin.version);
            return (
            <Card
              key={plugin.id}
              className="flex flex-col border border-border bg-card shadow-sm hover:shadow-md transition-all hover:translate-y-[-2px] relative overflow-hidden"
            >
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="p-2 bg-primary/10 rounded-lg text-primary">
                    <IconPlug className="w-6 h-6" />
                  </div>
                  <div className="flex items-center gap-1.5">
                    {versionBadge && (
                      <Badge
                        variant={VERSION_BADGE_VARIANT[versionBadge.tier]}
                        className="font-medium"
                        title={`Version ${plugin.version}`}
                        data-testid={`version-badge-${plugin.id}`}
                      >
                        {versionBadge.label}
                      </Badge>
                    )}
                    <Badge
                      variant={
                        plugin.status === 'active'
                          ? 'default'
                          : plugin.status === 'failed'
                          ? 'destructive'
                          : 'secondary'
                      }
                      className="font-medium capitalize"
                    >
                      {plugin.status || (plugin.enabled ? 'enabled' : 'disabled')}
                    </Badge>
                  </div>
                </div>
                <CardTitle className="text-lg font-bold font-heading mt-4">{plugin.name}</CardTitle>
                <CardDescription className="text-xs font-mono text-muted-foreground/80 truncate">
                  {plugin.id}
                </CardDescription>
              </CardHeader>

              <CardContent className="flex-1 pb-4 text-xs space-y-4">
                <p className="text-muted-foreground line-clamp-2">
                  Local plugin installed on disk. {plugin.file && `Loaded from file: ${plugin.file}`}
                </p>
                <div className="flex flex-wrap gap-2 text-[10px] text-muted-foreground">
                  <span className="px-2 py-1 bg-muted rounded-full">
                    Version: {plugin.version || '1.0.0'}
                  </span>
                  {plugin.routes_count !== undefined && (
                    <span className="px-2 py-1 bg-muted rounded-full">
                      Routes: {plugin.routes_count}
                    </span>
                  )}
                  {plugin.permissions_count !== undefined && (
                    <span className="px-2 py-1 bg-muted rounded-full">
                      Permissions: {plugin.permissions_count}
                    </span>
                  )}
                </div>
              </CardContent>

              <CardFooter className="pt-2 border-t border-border flex justify-between gap-2">
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => setDetailPlugin(plugin)}
                  className="font-semibold text-xs gap-1"
                >
                  Details <IconChevronRight size={14} />
                </Button>
                <div className="flex gap-2">
                  {/*
                    The toggle's permission is DYNAMIC by current state: an
                    active plugin's button performs Disable (plugins:disable);
                    otherwise it performs Enable (plugins:enable). Both are
                    non-destructive (disabled + tooltip when unpermitted).
                  */}
                  <PermissionButton
                    permission={isActive ? PLUGINS_DISABLE : PLUGINS_ENABLE}
                    size="sm"
                    variant={isActive ? 'outline' : 'default'}
                    onClick={() => togglePluginState(plugin)}
                    disabled={plugin.status === 'failed'}
                    className="text-xs font-medium gap-1"
                  >
                    {isActive ? (
                      <>
                        <IconPlayerPause size={14} /> Disable
                      </>
                    ) : (
                      <>
                        <IconPlayerPlay size={14} /> Enable
                      </>
                    )}
                  </PermissionButton>
                </div>
              </CardFooter>
            </Card>
            );
          })}
        </div>
      )}

      {/* Upload Plugin Dialog */}
      <Dialog open={uploadOpen} onOpenChange={(open) => (open ? setUploadOpen(true) : closeUploadDialog())}>
        <DialogContent className="sm:max-w-md text-xs">
          <DialogHeader>
            <DialogTitle className="text-base font-bold font-heading flex items-center gap-2">
              <IconUpload className="w-5 h-5" /> Upload Plugin
            </DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              Upload a plugin package as a <code className="font-mono">.zip</code> (a plugin directory)
              or a single <code className="font-mono">.php</code> file. It is staged{' '}
              <strong>disabled</strong> for review — Enable it once you have inspected it.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2">
            <label className="block">
              <span className="sr-only">Plugin package</span>
              <input
                ref={fileInputRef}
                type="file"
                accept=".zip,.php"
                aria-label="Plugin package"
                onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                disabled={uploading}
                className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-xs file:font-semibold file:text-primary-foreground hover:file:bg-primary/80 file:cursor-pointer cursor-pointer"
              />
            </label>
            {uploadFile && (
              <p className="text-[11px] text-muted-foreground">
                Selected: <span className="font-mono">{uploadFile.name}</span>{' '}
                ({(uploadFile.size / 1024).toFixed(1)} KiB)
              </p>
            )}
            <p className="text-[10px] text-muted-foreground/80">
              Maximum size {MAX_UPLOAD_BYTES / (1024 * 1024)} MiB.
            </p>
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" size="sm" onClick={closeUploadDialog} disabled={uploading}>
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleUpload}
              disabled={uploading || !uploadFile}
              loading={uploading}
              className="gap-1 font-semibold"
            >
              {!uploading && <IconUpload size={14} />}
              Upload
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Plugin Details Modal */}
      {detailPlugin && (
        <Dialog open={!!detailPlugin} onOpenChange={() => setDetailPlugin(null)}>
          <DialogContent className="sm:max-w-xl text-xs max-h-[85vh] overflow-y-auto">
            <DialogHeader>
              <div className="flex items-center gap-3">
                <div className="p-2 bg-primary/10 rounded-lg text-primary">
                  <IconPlug className="w-6 h-6" />
                </div>
                <div>
                  <DialogTitle className="text-base font-bold font-heading">{detailPlugin.name}</DialogTitle>
                  <DialogDescription className="text-xs font-mono font-semibold">
                    ID: {detailPlugin.id}
                  </DialogDescription>
                </div>
              </div>
            </DialogHeader>

            <div className="space-y-4 py-4 border-t border-b border-border my-2">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <h4 className="font-semibold text-muted-foreground">Status</h4>
                  <Badge
                    variant={
                      detailPlugin.status === 'active'
                        ? 'default'
                        : detailPlugin.status === 'failed'
                        ? 'destructive'
                        : 'secondary'
                    }
                    className="font-medium capitalize mt-1"
                  >
                    {detailPlugin.status || (detailPlugin.enabled ? 'enabled' : 'disabled')}
                  </Badge>
                </div>
                <div>
                  <h4 className="font-semibold text-muted-foreground">Version</h4>
                  <p className="mt-1 font-medium">{detailPlugin.version || '1.0.0'}</p>
                </div>
              </div>

              {detailPlugin.file && (
                <div>
                  <h4 className="font-semibold text-muted-foreground">Disk File</h4>
                  <p className="mt-1 font-mono break-all">{detailPlugin.file}</p>
                </div>
              )}

              {/* If plugin failed, render the details stack */}
              {detailPlugin.status === 'failed' && (
                <div className="p-3 bg-destructive/10 border border-destructive/20 rounded-xl space-y-2 text-destructive">
                  <div className="flex items-center gap-1.5 font-bold">
                    <IconAlertCircle size={16} /> Failed State Details
                  </div>
                  {(detailPlugin as ExtendedPluginEntry).consecutive_errors !== undefined && (
                    <p className="text-[11px]">
                      Consecutive failures: <strong>{(detailPlugin as ExtendedPluginEntry).consecutive_errors}</strong>
                    </p>
                  )}
                  {(detailPlugin as ExtendedPluginEntry).last_error && (
                    <div className="space-y-1">
                      <p className="text-[11px] font-semibold">
                        {(detailPlugin as ExtendedPluginEntry).last_error?.type}: {(detailPlugin as ExtendedPluginEntry).last_error?.message}
                      </p>
                      {(detailPlugin as ExtendedPluginEntry).last_error?.trace && (
                        <pre className="text-[9px] bg-background/50 p-2 rounded max-h-32 overflow-auto font-mono whitespace-pre-wrap">
                          {(detailPlugin as ExtendedPluginEntry).last_error?.trace}
                        </pre>
                      )}
                    </div>
                  )}
                  {/* Re-enable a failed plugin -> plugins:enable (non-destructive). */}
                  <PermissionButton
                    permission={PLUGINS_ENABLE}
                    size="sm"
                    variant="destructive"
                    onClick={() => handleReEnable(detailPlugin.id)}
                    className="w-full text-xs font-semibold"
                  >
                    Clear Error & Re-enable
                  </PermissionButton>
                </div>
              )}

              {/* Routes List */}
              {detailPlugin.routes_count !== undefined && (
                <div>
                  <h4 className="font-semibold text-muted-foreground mb-1.5">Registered Routes</h4>
                  <Badge variant="outline" className="font-medium">
                    {detailPlugin.routes_count} active API route(s) registered
                  </Badge>
                </div>
              )}

              {/* Permissions List */}
              {detailPlugin.permissions_count !== undefined && (
                <div>
                  <h4 className="font-semibold text-muted-foreground mb-1.5">Custom Permissions</h4>
                  <Badge variant="outline" className="font-medium">
                    {detailPlugin.permissions_count} RBAC permission(s) added
                  </Badge>
                </div>
              )}
            </div>

            <DialogFooter className="flex justify-between sm:justify-between items-center w-full gap-2">
              {/*
                Uninstall is DESTRUCTIVE: the trigger itself is HIDDEN (not just
                disabled) for a caller without plugins:uninstall, so the entry
                point disappears entirely.
              */}
              <PermissionButton
                permission={PLUGINS_UNINSTALL}
                destructive
                variant="outline"
                size="sm"
                onClick={() => confirmUninstall(detailPlugin)}
                className="text-destructive border-destructive hover:bg-destructive/10 hover:text-destructive gap-1 font-semibold"
              >
                <IconTrash size={14} /> Uninstall
              </PermissionButton>
              <Button onClick={() => setDetailPlugin(null)} size="sm" variant="outline">
                Close
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}

      {/* Uninstall Confirmation Alert Dialog */}
      {uninstallTarget && (
        <AlertDialog open={!!uninstallTarget} onOpenChange={() => setUninstallTarget(null)}>
          <AlertDialogContent className="sm:max-w-md text-xs">
            <AlertDialogHeader>
              <AlertDialogTitle className="text-sm font-bold flex items-center gap-1.5 text-destructive font-heading">
                <IconAlertCircle className="w-5 h-5" /> Confirm Plugin Uninstallation
              </AlertDialogTitle>
              <AlertDialogDescription className="text-xs text-muted-foreground space-y-2" asChild>
                <div className="space-y-2">
                  <p>
                    Are you absolutely sure you want to uninstall the plugin{' '}
                    <strong className="text-foreground font-semibold font-heading">&ldquo;{uninstallTarget.name}&rdquo;</strong>?
                  </p>

                  <div className="pt-2 p-3 bg-muted/40 rounded-lg space-y-2 border border-border">
                    <p className="text-[11px] font-semibold text-foreground/90">Database Cleanup Options</p>
                    <label className="flex items-start gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={forceUninstall}
                        onChange={(e) => setForceUninstall(e.target.checked)}
                        className="mt-0.5 rounded border-border text-primary focus:ring-primary h-3.5 w-3.5"
                      />
                      <span className="text-[10px] text-muted-foreground select-none leading-tight">
                        <strong>Force deletion:</strong> Remove the plugin directory even if database migration rollback fails. Use with caution.
                      </span>
                    </label>
                  </div>
                </div>
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter className="gap-2">
              <AlertDialogCancel disabled={uninstalling}>Cancel</AlertDialogCancel>
              <PermissionButton
                permission={PLUGINS_UNINSTALL}
                destructive
                variant="destructive"
                onClick={handleUninstall}
                disabled={uninstalling}
                className="font-semibold text-xs gap-1"
              >
                {uninstalling ? (
                  <>
                    <div className="animate-spin rounded-full h-3 w-3 border-b-2 border-primary-foreground"></div>
                    Uninstalling...
                  </>
                ) : (
                  <>
                    <IconTrash size={14} /> Confirm Uninstall
                  </>
                )}
              </PermissionButton>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      )}
    </div>
  );
}
