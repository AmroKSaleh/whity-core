'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Button } from '@whity-org/ui/button';
import { Badge } from '@whity-org/ui/badge';
import { Alert, AlertDescription, AlertTitle } from '@whity-org/ui/alert';
import { RadioGroup, RadioGroupItem } from '@whity-org/ui/radio-group';
import { Spinner } from '@whity-org/ui/spinner';
import { Checkbox } from '@whity-org/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity-org/ui/select';
import { IconAlertCircle, IconCircleCheck, IconPlugConnected } from '@tabler/icons-react';
import { useTranslation } from '@whity-org/features/i18n';
import { titleCase } from '@whity-org/features/roles';
import {
  MCP_CLIENTS,
  mcpClient,
  mcpEndpoint,
  recommendsToolSearch,
  type McpClientId,
} from '@whity-org/features/mcp';
import { ConnectSetup } from './connect-setup';
import type { McpConnectInfo, McpPreviewTool, OwnMcpToken } from './types';

/**
 * "Connect an AI app" (WHIT-697): pick the app, pick what it may do in plain
 * words, then get setup that works for that app and watch it connect.
 *
 * Written for people who have never seen a bearer token. Every choice is
 * phrased as what the app will be able to do, the scope strings stay out of
 * sight, and the preview lists the actual actions the server would offer —
 * computed by the server's own tools/list filter, so it cannot promise more or
 * less than the app will get.
 */

type Step = 'app' | 'access' | 'connect';
type AccessMode = 'everything' | 'readOnly' | 'custom';
type AreaLevel = 'none' | 'view' | 'full';

interface Issued {
  jti: string;
  token: string;
  name: string;
  /** Load every tool at once (`?tools=full`) instead of search and call (WHIT-754). */
  allTools: boolean;
}

const CAPABILITIES = ['tools:call', 'resources:read', 'prompts:read'];
/** How often step 3 asks whether the app has called yet. */
const POLL_MS = 3000;
/** When to start suggesting what might be wrong. */
const HINT_AFTER_MS = 60_000;

export function ConnectWizard({
  isOpen,
  onOpenChange,
  onConnected,
  renew = null,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called once a connection is issued (or the old one revoked), so the list can refresh. */
  onConnected: () => void;
  /**
   * Renew an existing connection of the caller's: same name and scope, a new
   * token. The old token keeps working until the new one first connects, and
   * is revoked then — so the app is never left without a working key while
   * the user is still pasting the new setup.
   */
  renew?: { jti: string; name: string; scope: string[] } | null;
}) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [step, setStep] = useState<Step>('app');
  const [clientId, setClientId] = useState<McpClientId | null>(null);
  const [name, setName] = useState('');
  const [mode, setMode] = useState<AccessMode>('everything');
  const [levels, setLevels] = useState<Record<string, AreaLevel>>({});
  const [info, setInfo] = useState<McpConnectInfo | null>(null);
  const [infoError, setInfoError] = useState(false);
  const [preview, setPreview] = useState<{ key: string; tools: McpPreviewTool[] | null } | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [issued, setIssued] = useState<Issued | null>(null);
  const [connected, setConnected] = useState(false);
  const [waitedLong, setWaitedLong] = useState(false);
  const [oldRevoked, setOldRevoked] = useState<boolean | null>(null);
  // The plain /mcp (search and call) unless the user asks for every action.
  const [allTools, setAllTools] = useState(false);

  // ── Pre-flight: where to connect, and whether it can work at all ──────────
  useEffect(() => {
    if (!isOpen) return;
    let cancelled = false;
    (async () => {
      try {
        const response = await apiClient('/api/v1/mcp/connect');
        if (!response.ok) throw new Error(String(response.status));
        const body = (await response.json()) as McpConnectInfo;
        if (!cancelled) setInfo(body);
      } catch {
        if (!cancelled) setInfoError(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [isOpen, apiClient]);

  const endpoint =
    info?.endpoint ?? (typeof window !== 'undefined' ? `${window.location.origin}/mcp` : '/mcp');
  const blocked = info !== null && (!info.serverEnabled || !info.tenantEnabled);

  // ── Scope, from the plain-language choice ──────────────────────────────────
  const scope = useMemo<string[] | null>(() => {
    if (info === null) return null;
    if (mode === 'everything') return info.presets.everything;
    if (mode === 'readOnly') return info.presets.readOnly;
    const entries: string[] = [];
    for (const { area, permissions } of info.areas) {
      const level = levels[area] ?? 'none';
      if (level === 'full') entries.push(`${area}:*`);
      if (level === 'view') entries.push(...permissions.filter((p) => p.endsWith(':read')));
    }
    // A custom scope with no permission at all would not narrow anything: it
    // would quietly mean "everything". Refuse it rather than surprise anyone.
    return entries.length === 0 ? null : [...CAPABILITIES, ...entries];
  }, [info, mode, levels]);

  // ── Preview: what an app holding this scope could do, for this user ───────
  // The result is keyed by the scope it answers, so a result for an earlier
  // choice is never shown against the current one, and "loading" is simply
  // "no answer for this scope yet".
  const scopeKey = step === 'access' && scope !== null ? JSON.stringify(scope) : null;
  useEffect(() => {
    if (scopeKey === null) return;
    let cancelled = false;
    const timer = setTimeout(async () => {
      let tools: McpPreviewTool[] | null = null;
      try {
        const response = await apiClient('/api/v1/mcp/connect/preview', {
          method: 'POST',
          body: JSON.stringify({ scope: JSON.parse(scopeKey) as string[] }),
        });
        if (response.ok) tools = ((await response.json()) as { tools: McpPreviewTool[] }).tools;
      } catch {
        // No preview is shown; the choice itself still works.
      }
      if (!cancelled) setPreview({ key: scopeKey, tools });
    }, 300);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [scopeKey, apiClient]);
  const previewTools = preview !== null && preview.key === scopeKey ? preview.tools : null;
  const previewLoading = scopeKey !== null && (preview === null || preview.key !== scopeKey);

  // ── Step 3: wait for the app's first call ─────────────────────────────────
  useEffect(() => {
    if (step !== 'connect' || issued === null || connected) return;
    const startedAt = Date.now();
    const interval = setInterval(async () => {
      if (Date.now() - startedAt > HINT_AFTER_MS) setWaitedLong(true);
      try {
        const response = await apiClient('/api/v1/mcp/tokens');
        if (!response.ok) return;
        const body = (await response.json()) as { tokens: OwnMcpToken[] };
        if (body.tokens.some((token) => token.jti === issued.jti && token.last_used_at !== null)) {
          setConnected(true);
          if (renew !== null) {
            const revoked = await apiClient(`/api/v1/mcp/tokens/${encodeURIComponent(renew.jti)}`, { method: 'DELETE' })
              .then((r) => r.ok)
              .catch(() => false);
            setOldRevoked(revoked);
            onConnected();
          }
        }
      } catch {
        // A missed poll is retried on the next tick.
      }
    }, POLL_MS);
    return () => clearInterval(interval);
  }, [step, issued, connected, apiClient, renew, onConnected]);

  const reset = () => {
    setStep('app');
    setClientId(null);
    setName('');
    setMode('everything');
    setLevels({});
    setPreview(null);
    setIssued(null);
    setConnected(false);
    setWaitedLong(false);
    setOldRevoked(null);
    setAllTools(false);
  };

  const close = (open: boolean) => {
    if (!open) reset();
    onOpenChange(open);
  };

  const chooseApp = (id: McpClientId) => {
    setClientId(id);
    if (renew !== null) {
      // The access was chosen when the connection was made; renewing keeps it.
      void create(id, renew.name, renew.scope);
      return;
    }
    setName(mcpClient(id).name);
    setStep('access');
  };

  const create = async (id: McpClientId | null = clientId, issueName: string = name, issueScope: string[] | null = scope) => {
    if (issueScope === null || id === null) return;
    setSubmitting(true);
    try {
      const response = await apiClient('/api/v1/mcp/tokens', {
        method: 'POST',
        body: JSON.stringify({ name: issueName.trim() || mcpClient(id).name, scope: issueScope }),
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as { message?: string };
        throw new Error(body.message ?? t('aiPrincipals.connect.createError', 'Could not create the connection'));
      }
      const body = (await response.json()) as Omit<Issued, 'allTools'>;
      // A renewal skips the access step, so it gets the plain /mcp: search
      // and call reach a catalogue of any size.
      setIssued({ jti: body.jti, token: body.token, name: body.name, allTools: renew === null && allTools });
      setStep('connect');
      onConnected();
    } catch (error) {
      addToast(
        error instanceof Error ? error.message : t('aiPrincipals.connect.createError', 'Could not create the connection'),
        'error'
      );
    } finally {
      setSubmitting(false);
    }
  };

  const client = clientId !== null ? mcpClient(clientId) : null;
  const tooManyForApp =
    client !== null && previewTools !== null && recommendsToolSearch(client, previewTools.length);
  const otherLabel = t('aiPrincipals.connect.app.other', 'Another app');
  // A product name is not translated; "other" is not a product.
  const appName = client === null ? '' : client.id === 'other' ? otherLabel : client.name;

  return (
    <Dialog open={isOpen} onOpenChange={close}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {step === 'app' &&
              (renew !== null
                ? t('aiPrincipals.connect.renew.title', 'Renew {name}', { name: renew.name })
                : t('aiPrincipals.connect.app.title', 'Connect an AI app'))}
            {step === 'access' && t('aiPrincipals.connect.access.title', 'What may {app} do?', { app: appName })}
            {step === 'connect' && t('aiPrincipals.connect.setup.title', 'Connect {app}', { app: appName })}
          </DialogTitle>
          <DialogDescription>
            {step === 'app' &&
              (renew !== null
                ? t(
                    'aiPrincipals.connect.renew.description',
                    'Pick the app this connection is for to get its new setup. It keeps the same access. The old key keeps working until the new one connects, and is turned off then.'
                  )
                : t(
                    'aiPrincipals.connect.app.description',
                    'Let an AI app work with your data here, using your account. Pick the app you use.'
                  ))}
            {step === 'access' &&
              t(
                'aiPrincipals.connect.access.description',
                'The app can never do more than you can. You can limit it further here.'
              )}
            {step === 'connect' &&
              t('aiPrincipals.connect.setup.description', 'Follow these steps in {app}. This page will tell you when it is connected.', {
                app: appName,
              })}
          </DialogDescription>
        </DialogHeader>

        <Preflight info={info} infoError={infoError} />

        {step === 'app' && (
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {MCP_CLIENTS.map((c) => (
              <button
                key={c.id}
                type="button"
                disabled={c.requiresSignIn === true || submitting}
                onClick={() => chooseApp(c.id)}
                className="flex min-h-16 flex-col items-start justify-center gap-1 rounded-lg border border-border bg-background px-3 py-2 text-start text-sm font-medium transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-background"
              >
                <span>{c.id === 'other' ? otherLabel : c.name}</span>
                {c.requiresSignIn === true && (
                  <Badge variant="secondary">{t('aiPrincipals.connect.app.comingSoon', 'Coming with sign-in')}</Badge>
                )}
              </button>
            ))}
          </div>
        )}

        {step === 'access' && info !== null && (
          <div className="space-y-4">
            <RadioGroup value={mode} onValueChange={(value) => setMode(value as AccessMode)} className="space-y-2">
              <AccessOption
                value="everything"
                title={t('aiPrincipals.connect.access.everything', 'Everything I can do')}
                help={t('aiPrincipals.connect.access.everythingHelp', 'Read, create, change and delete, wherever your account can.')}
              />
              {info.presets.readOnly !== null && (
                <AccessOption
                  value="readOnly"
                  title={t('aiPrincipals.connect.access.readOnly', 'Read only')}
                  help={t('aiPrincipals.connect.access.readOnlyHelp', 'Look things up. It cannot change or delete anything.')}
                />
              )}
              <AccessOption
                value="custom"
                title={t('aiPrincipals.connect.access.custom', 'Choose areas')}
                help={t('aiPrincipals.connect.access.customHelp', 'Pick which parts it may see or change.')}
              />
            </RadioGroup>

            {mode === 'custom' && (
              <div className="max-h-64 space-y-1 overflow-y-auto rounded-md border border-border p-2">
                {info.areas.map(({ area, permissions }) => {
                  const canView = permissions.some((p) => p.endsWith(':read'));
                  return (
                    <div key={area} className="flex items-center justify-between gap-3 px-1 py-1">
                      <span className="text-sm">{titleCase(area)}</span>
                      <Select
                        value={levels[area] ?? 'none'}
                        onValueChange={(value) => setLevels((prev) => ({ ...prev, [area]: value as AreaLevel }))}
                      >
                        <SelectTrigger className="w-40" aria-label={titleCase(area)}>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">{t('aiPrincipals.connect.level.none', 'No access')}</SelectItem>
                          {canView && <SelectItem value="view">{t('aiPrincipals.connect.level.view', 'View')}</SelectItem>}
                          <SelectItem value="full">{t('aiPrincipals.connect.level.full', 'View and change')}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  );
                })}
              </div>
            )}

            <PreviewPanel scope={scope} tools={previewTools} loading={previewLoading} />

            {previewTools !== null && previewTools.length > 0 && (
              <label htmlFor="mcp-all-tools" className="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3">
                <Checkbox
                  id="mcp-all-tools"
                  checked={allTools}
                  onCheckedChange={(checked) => setAllTools(checked === true)}
                  className="mt-0.5"
                />
                <span className="space-y-0.5">
                  <span className="block text-sm font-medium">
                    {t('aiPrincipals.connect.allTools.label', 'Load every action at once')}
                  </span>
                  <span className="block text-xs text-muted-foreground">
                    {tooManyForApp
                      ? t(
                          'aiPrincipals.connect.allTools.helpTooMany',
                          'Not recommended: {app} works best with fewer actions than these {count}. Without this, it gets a few everyday actions and searches for the rest when it needs them.',
                          { app: appName, count: previewTools.length }
                        )
                      : t(
                          'aiPrincipals.connect.allTools.help',
                          'Without this, the app gets a few everyday actions and searches for the other {count} when it needs them, which keeps it fast.',
                          { count: previewTools.length }
                        )}
                  </span>
                </span>
              </label>
            )}

            <div className="space-y-1">
              <label htmlFor="mcp-connection-name" className="text-sm font-medium">
                {t('aiPrincipals.connect.name.label', 'Name this connection')}
              </label>
              <Input id="mcp-connection-name" value={name} maxLength={255} onChange={(e) => setName(e.target.value)} />
              <p className="text-xs text-muted-foreground">
                {t('aiPrincipals.connect.name.help', 'Shown in your list of connections, so you can tell them apart.')}
              </p>
            </div>
          </div>
        )}

        {step === 'connect' && issued !== null && clientId !== null && (
          <div className="space-y-5">
            <ConnectSetup
              clientId={clientId}
              connection={{ name: issued.name, url: mcpEndpoint(endpoint, { allTools: issued.allTools }), token: issued.token }}
            />
            <ConnectionStatus connected={connected} waitedLong={waitedLong} app={appName} />
            {renew !== null && oldRevoked === true && (
              <p className="text-sm text-muted-foreground">
                {t('aiPrincipals.connect.renew.revoked', 'The old key has been turned off.')}
              </p>
            )}
            {renew !== null && oldRevoked === false && (
              <Alert variant="warning">
                <IconAlertCircle />
                <AlertDescription>
                  {t('aiPrincipals.connect.renew.revokeFailed', 'The new key works, but the old one could not be turned off. Revoke it from the list.')}
                </AlertDescription>
              </Alert>
            )}
          </div>
        )}

        <DialogFooter>
          {step === 'access' && (
            <>
              <Button type="button" variant="outline" onClick={() => setStep('app')} disabled={submitting}>
                {t('aiPrincipals.connect.back', 'Back')}
              </Button>
              <Button type="button" onClick={() => void create()} disabled={submitting || scope === null || blocked || info === null}>
                {submitting
                  ? t('aiPrincipals.connect.creating', 'Creating…')
                  : t('aiPrincipals.connect.create', 'Create connection')}
              </Button>
            </>
          )}
          {step === 'connect' && (
            <Button type="button" onClick={() => close(false)}>
              {connected ? t('aiPrincipals.connect.done', 'Done') : t('aiPrincipals.connect.finishLater', 'Close')}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function AccessOption({ value, title, help }: { value: AccessMode; title: string; help: string }) {
  const id = `mcp-access-${value}`;
  return (
    <label htmlFor={id} className="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 hover:bg-muted">
      <RadioGroupItem id={id} value={value} className="mt-0.5" />
      <span className="space-y-0.5">
        <span className="block text-sm font-medium">{title}</span>
        <span className="block text-xs text-muted-foreground">{help}</span>
      </span>
    </label>
  );
}

function Preflight({ info, infoError }: { info: McpConnectInfo | null; infoError: boolean }) {
  const t = useTranslation('admin');
  if (infoError) {
    return (
      <Alert variant="destructive">
        <IconAlertCircle />
        <AlertDescription>
          {t('aiPrincipals.connect.preflight.error', 'Could not check whether AI apps can connect. Try again in a moment.')}
        </AlertDescription>
      </Alert>
    );
  }
  if (info === null) return null;
  if (!info.serverEnabled) {
    return (
      <Alert variant="warning">
        <IconAlertCircle />
        <AlertTitle>{t('aiPrincipals.connect.preflight.serverOffTitle', 'AI apps are turned off on this server')}</AlertTitle>
        <AlertDescription>
          {t(
            'aiPrincipals.connect.preflight.serverOff',
            'Whoever runs this server needs to set MCP_ENABLED=true and restart it. Until then no app can connect.'
          )}
        </AlertDescription>
      </Alert>
    );
  }
  if (!info.tenantEnabled) {
    return (
      <Alert variant="warning">
        <IconAlertCircle />
        <AlertTitle>{t('aiPrincipals.connect.preflight.tenantOffTitle', 'AI apps are turned off for this organization')}</AlertTitle>
        <AlertDescription>
          {t('aiPrincipals.connect.preflight.tenantOff', 'Turn on the Model Context Protocol endpoint under Settings, Integrations, then come back. If it is already on, the plan for this organization does not include AI apps.')}{' '}
          <Link href="/admin/settings" className="underline">
            {t('aiPrincipals.connect.preflight.openSettings', 'Open settings')}
          </Link>
        </AlertDescription>
      </Alert>
    );
  }
  if (info.endpoint === null) {
    return (
      <Alert variant="info">
        <IconAlertCircle />
        <AlertDescription>
          {t(
            'aiPrincipals.connect.preflight.noAppUrl',
            'This server does not know its public address (APP_URL is not set), so the address of this page is used. If apps cannot reach it, ask whoever runs the server to set APP_URL.'
          )}
        </AlertDescription>
      </Alert>
    );
  }
  return null;
}

function PreviewPanel({ scope, tools, loading }: { scope: string[] | null; tools: McpPreviewTool[] | null; loading: boolean }) {
  const t = useTranslation('admin');
  const [expanded, setExpanded] = useState(false);
  if (scope === null) {
    return (
      <p className="text-xs text-muted-foreground">
        {t('aiPrincipals.connect.preview.pickArea', 'Pick at least one area.')}
      </p>
    );
  }
  if (loading && tools === null) {
    return <Spinner size="sm" />;
  }
  if (tools === null) return null;
  const shown = expanded ? tools : tools.slice(0, 6);
  return (
    <div className="space-y-2 rounded-md border border-border bg-muted/40 p-3">
      <p className="text-sm font-medium">
        {tools.length === 0
          ? t('aiPrincipals.connect.preview.none', 'With this choice the app could not do anything. Choose more.')
          : t('aiPrincipals.connect.preview.count', 'Actions the app will be able to use: {count}. For example:', {
              count: tools.length,
            })}
      </p>
      <ul className="space-y-1 text-xs text-muted-foreground">
        {shown.map((tool) => (
          <li key={tool.name}>{tool.description !== '' ? tool.description : tool.name}</li>
        ))}
      </ul>
      {tools.length > shown.length && (
        <Button type="button" variant="link" size="sm" className="h-auto p-0" onClick={() => setExpanded(true)}>
          {t('aiPrincipals.connect.preview.showAll', 'Show all {count}', { count: tools.length })}
        </Button>
      )}
    </div>
  );
}

function ConnectionStatus({ connected, waitedLong, app }: { connected: boolean; waitedLong: boolean; app: string }) {
  const t = useTranslation('admin');
  if (connected) {
    return (
      <Alert variant="success">
        <IconCircleCheck />
        <AlertTitle>{t('aiPrincipals.connect.status.connected', 'Connected')}</AlertTitle>
        <AlertDescription>
          {t('aiPrincipals.connect.status.connectedHelp', '{app} reached this server. You can close this window.', { app })}
        </AlertDescription>
      </Alert>
    );
  }
  return (
    <div className="space-y-2 rounded-md border border-dashed border-border p-3">
      <div className="flex items-center gap-2 text-sm">
        <IconPlugConnected size={16} className="text-muted-foreground" />
        <span>{t('aiPrincipals.connect.status.waiting', 'Waiting for {app} to connect…', { app })}</span>
        <Spinner size="sm" />
      </div>
      {waitedLong && (
        <ul className="list-disc space-y-1 ps-5 text-xs text-muted-foreground">
          <li>{t('aiPrincipals.connect.status.hintRestart', 'Restart {app} after changing its settings.', { app })}</li>
          <li>{t('aiPrincipals.connect.status.hintAsk', 'Some apps connect only when you first ask them something. Ask it to list what it can do here.')}</li>
          <li>{t('aiPrincipals.connect.status.hintReach', 'The app must be able to reach this server over the internet or your network.')}</li>
        </ul>
      )}
    </div>
  );
}
