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
import {
  IconBuildingStore,
  IconSearch,
  IconDownload,
  IconAlertCircle,
  IconRefresh,
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

  // Load the operator's trusted store hosts once, to populate the picker.
  useEffect(() => {
    if (!hasAccess) return;
    let active = true;
    (async () => {
      const { data, error } = await api.GET('/api/v1/plugins/store/allowed');
      if (!active) return;
      if (error || !data) {
        addToast('Could not load the configured stores.', 'error');
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
        addToast('The store catalogue could not be loaded.', 'error');
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
        const { error } = await api.POST('/api/v1/plugins/install-from-store', {
          body: { store_url: storeUrl, slug: plugin.slug, version, token: token || undefined },
        });
        if (error) {
          addToast(`Failed to install "${plugin.name}".`, 'error');
          return;
        }
        addToast(`"${plugin.name}" v${version} installed (disabled). Enable it from Plugins.`, 'success');
      } finally {
        setInstallingSlug(null);
      }
    },
    [storeUrl, token, addToast],
  );

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!hasAccess) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[450px] p-8 text-center bg-card border border-border rounded-2xl shadow-sm">
        <div className="p-4 bg-destructive/10 rounded-full text-destructive mb-4">
          <IconAlertCircle size={48} />
        </div>
        <h2 className="text-xl font-bold mb-2">Access Denied</h2>
        <p className="text-muted-foreground max-w-md mb-6 text-sm">
          You do not have the required permission (`plugins:read`) to browse the Plugin Store.
        </p>
        <Button onClick={() => window.history.back()} variant="outline">
          Go Back
        </Button>
      </div>
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
                    className="flex-1 bg-transparent text-sm outline-none"
                  />
                </div>
              </label>
              <label className="flex flex-1 flex-col gap-1 text-sm">
                <span className="font-medium text-muted-foreground">Access token (optional)</span>
                <input
                  value={token}
                  onChange={(e) => setToken(e.target.value)}
                  type="password"
                  placeholder="store download token"
                  className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                />
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
