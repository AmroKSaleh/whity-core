'use client';

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { PermissionButton } from '@/components/rbac/permission-button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import {
  IconBuildingStore,
  IconSearch,
  IconDownload,
  IconRefresh,
  IconKey,
} from '@tabler/icons-react';

// Read access to the store browser mirrors the plugins console (plugins:read);
// the per-plugin Install action is separately gated on plugins:upload.
const PLUGINS_READ = 'plugins:read';
const PLUGINS_UPLOAD = 'plugins:upload';

type CataloguePlugin = components['schemas']['StoreCataloguePlugin'];

export default function PluginStorePage() {
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const hasAccess = hasPermission(PLUGINS_READ);

  const [allowedHosts, setAllowedHosts] = useState<string[]>([]);
  const [featureEnabled, setFeatureEnabled] = useState<boolean | null>(null);
  const [storeUrl, setStoreUrl] = useState('');
  const [token, setToken] = useState('');
  const [search, setSearch] = useState('');

  const [plugins, setPlugins] = useState<CataloguePlugin[]>([]);
  const [browsing, setBrowsing] = useState(false);
  const [installingSlug, setInstallingSlug] = useState<string | null>(null);
  const [hasBrowsed, setHasBrowsed] = useState(false);
  const [mintingToken, setMintingToken] = useState(false);

  // Load the operator's trusted store hosts once, to populate the picker.
  useEffect(() => {
    if (!hasAccess) return;
    let active = true;
    (async () => {
      const { data, error } = await api.GET('/api/v1/plugins/store/allowed');
      if (!active) return;
      if (error || !data) {
        addToast(error?.error || 'Could not load the configured stores.', 'error');
        return;
      }
      setAllowedHosts(data.data.hosts);
      setFeatureEnabled(data.data.enabled);
      // Preselect the only store so a one-store deployment is one click away.
      if (data.data.hosts.length > 0) {
        setStoreUrl(`https://${data.data.hosts[0]}`);
      }
    })();
    return () => {
      active = false;
    };
  }, [hasAccess, addToast]);

  const browse = useCallback(async () => {
    if (!storeUrl) {
      addToast('Choose a store first.', 'error');
      return;
    }
    setBrowsing(true);
    try {
      const { data, error } = await api.GET('/api/v1/plugins/store/catalog', {
        params: { query: { store_url: storeUrl, q: search || undefined } },
      });
      if (error || !data) {
        addToast(error?.error || 'The store catalogue could not be loaded.', 'error');
        return;
      }
      setPlugins(data.data);
      setHasBrowsed(true);
    } finally {
      setBrowsing(false);
    }
  }, [storeUrl, search, addToast]);

  const install = useCallback(
    async (plugin: CataloguePlugin) => {
      const version = plugin.latest_version;
      if (!version) {
        addToast(`"${plugin.name}" has no published version to install.`, 'error');
        return;
      }
      setInstallingSlug(plugin.slug);
      try {
        const { data, error } = await api.POST('/api/v1/plugins/install-from-store', {
          body: { store_url: storeUrl, slug: plugin.slug, version, token: token || undefined },
        });
        if (error) {
          // Surface the ACTUAL backend reason (e.g. "A valid store download
          // token is required", "already installed", allowlist rejection) —
          // a generic message left every failure equally unexplained,
          // including the common case of installing without a token.
          addToast(error.error || `Failed to install "${plugin.name}".`, 'error');
          return;
        }
        addToast(
          `"${plugin.name}" v${data?.data.version ?? version} installed (disabled). Enable it from Plugins.`,
          'success',
        );
      } finally {
        setInstallingSlug(null);
      }
    },
    [storeUrl, token, addToast],
  );

  /**
   * Convenience for the common case: the store being browsed IS this instance
   * itself (the operator's own deployment), so the browser already carries a
   * session on it. Mints a download token via the store's own token-mint route
   * and fills it in — sparing the operator a separate curl/API call before
   * every install. This route is plugin-declared (whity-plugin-store), not part
   * of this instance's OWN OpenAPI contract, so it is called directly rather
   * than through the typed client; the backend's own admin-role gate is the
   * real authority regardless of what this button does.
   */
  const mintToken = useCallback(async () => {
    if (!storeUrl) {
      addToast('Choose a store first.', 'error');
      return;
    }
    setMintingToken(true);
    try {
      const res = await fetch(`${storeUrl}/api/v1/plugin-store/tokens`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ label: 'admin-store-page' }),
      });
      const body = await res.json().catch(() => null);
      if (!res.ok || !body?.data?.token) {
        addToast(body?.error || 'Could not mint a store token for this store.', 'error');
        return;
      }
      setToken(body.data.token);
      addToast('Store token minted and filled in below.', 'success');
    } catch {
      addToast('Could not reach the store to mint a token.', 'error');
    } finally {
      setMintingToken(false);
    }
  }, [storeUrl, addToast]);

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!hasAccess) {
    return (
      <AccessDenied
        description={
          <>
            You do not have the required permission (`plugins:read`) to browse the Plugin
            Store.
          </>
        }
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            Go Back
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-8 max-w-7xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title="Plugin Store"
        description="Browse a trusted store and install plugins into this instance."
      />

      {featureEnabled === false ? (
        <Card className="border border-border/80 bg-card/50">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-lg">
              <IconBuildingStore className="w-5 h-5" /> No trusted stores configured
            </CardTitle>
            <CardDescription>
              Installing from a store is disabled. An operator must set{' '}
              <code>plugins.store_allowed_hosts</code> in global settings before you can browse a store.
            </CardDescription>
          </CardHeader>
        </Card>
      ) : (
        <>
          {/* Store picker + search */}
          <Card className="border border-border/80 bg-card/50 shadow-sm">
            <CardContent className="flex flex-col gap-4 pt-6 md:flex-row md:items-end">
              <label className="flex flex-1 flex-col gap-1 text-sm">
                <span className="font-medium text-muted-foreground">Store</span>
                <select
                  value={storeUrl}
                  onChange={(e) => setStoreUrl(e.target.value)}
                  className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="" disabled>
                    Choose a store…
                  </option>
                  {allowedHosts.map((h) => (
                    <option key={h} value={`https://${h}`}>
                      {h}
                    </option>
                  ))}
                </select>
              </label>
              <label className="flex flex-1 flex-col gap-1 text-sm">
                <span className="font-medium text-muted-foreground">Search</span>
                <div className="flex items-center gap-2 rounded-md border border-input bg-background px-3 h-10">
                  <IconSearch className="w-4 h-4 text-muted-foreground" />
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') browse();
                    }}
                    placeholder="name, slug, tag…"
                    type="search"
                    name="plugin-store-search"
                    autoComplete="off"
                    className="flex-1 bg-transparent text-sm outline-none"
                  />
                </div>
              </label>
              <label className="flex flex-1 flex-col gap-1 text-sm">
                <span className="font-medium text-muted-foreground">Access token</span>
                <div className="flex items-center gap-1.5">
                  <input
                    value={token}
                    onChange={(e) => setToken(e.target.value)}
                    type="password"
                    name="plugin-store-access-token"
                    autoComplete="new-password"
                    placeholder="required to install"
                    className="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm"
                  />
                  <Button
                    type="button"
                    variant="outline"
                    onClick={mintToken}
                    disabled={mintingToken || !storeUrl}
                    title="Mint a download token from this store (requires an admin session on it)"
                    className="h-10 w-10 shrink-0 p-0"
                  >
                    <IconKey className={`w-4 h-4 ${mintingToken ? 'animate-pulse' : ''}`} />
                  </Button>
                </div>
              </label>
              <Button onClick={browse} disabled={browsing || !storeUrl} className="gap-2 h-10">
                <IconRefresh className={`w-4 h-4 ${browsing ? 'animate-spin' : ''}`} />
                Browse
              </Button>
            </CardContent>
          </Card>

          {/* Results */}
          {plugins.length === 0 ? (
            <p className="text-sm text-muted-foreground px-1">
              {hasBrowsed ? 'No plugins matched your search.' : 'Choose a store and press Browse to see its plugins.'}
            </p>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              {plugins.map((plugin) => (
                <Card key={plugin.slug} className="flex flex-col border border-border/80 bg-card/50 shadow-sm">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-base font-heading">{plugin.name}</CardTitle>
                    <CardDescription className="font-mono text-xs">{plugin.slug}</CardDescription>
                  </CardHeader>
                  <CardContent className="flex-1 space-y-3">
                    {plugin.description ? (
                      <p className="text-sm text-muted-foreground line-clamp-3">{plugin.description}</p>
                    ) : null}
                    <div className="flex flex-wrap items-center gap-1.5">
                      {plugin.latest_version ? (
                        <Badge variant="secondary">v{plugin.latest_version}</Badge>
                      ) : null}
                      {(plugin.tags ?? []).map((tag) => (
                        <Badge key={tag} variant="outline">
                          {tag}
                        </Badge>
                      ))}
                    </div>
                  </CardContent>
                  <CardFooter className="justify-between">
                    <span className="text-xs text-muted-foreground">{plugin.author || '—'}</span>
                    <PermissionButton
                      permission={PLUGINS_UPLOAD}
                      onClick={() => install(plugin)}
                      disabled={installingSlug === plugin.slug || !plugin.latest_version}
                      className="gap-2"
                    >
                      <IconDownload className="w-4 h-4" />
                      {installingSlug === plugin.slug ? 'Installing…' : 'Install'}
                    </PermissionButton>
                  </CardFooter>
                </Card>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}
