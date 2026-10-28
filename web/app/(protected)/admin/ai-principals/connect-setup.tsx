'use client';

import { CopyBlock } from '@amroksaleh/ui/copy-block';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { IconAlertCircle, IconExternalLink } from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import {
  mcpClient,
  type McpClientId,
  type McpConnection,
  type McpFormField,
} from '@amroksaleh/features/mcp';
import { useToast } from '@/lib/toast-context';

/**
 * Step 3 of the connect wizard (WHIT-697): the setup for one app, built by
 * `@amroksaleh/features/mcp` and explained here in the user's language.
 *
 * The order is easiest first. A one-click link comes first when the app has
 * one, but the copy-paste setup is always shown under it: the link schemes are
 * the least stable part of each vendor's docs, and a link that quietly stops
 * working must not leave the user with nothing to fall back to.
 */
export function ConnectSetup({ clientId, connection }: { clientId: McpClientId; connection: McpConnection }) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const client = mcpClient(clientId);
  const app = client.id === 'other' ? t('aiPrincipals.connect.app.other', 'Another app') : client.name;
  const setup = client.setup?.(connection);
  if (setup === undefined) return null;

  const copyProps = {
    copyLabel: t('aiPrincipals.connect.copy', 'Copy'),
    copiedLabel: t('aiPrincipals.connect.copied', 'Copied'),
    onCopy: (copied: boolean) => {
      if (!copied) addToast(t('aiPrincipals.connect.copyError', 'Could not copy. Select the text and copy it yourself.'), 'error');
    },
  };

  return (
    <div className="space-y-5">
      <Alert variant="warning">
        <IconAlertCircle />
        <AlertDescription>
          {t(
            'aiPrincipals.connect.secretWarning',
            'This setup contains a secret key. It is shown only now. Anyone who has it can act as you in this app, so do not share it.'
          )}
        </AlertDescription>
      </Alert>

      {setup.install && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold">
            {t('aiPrincipals.connect.oneClick.title', 'Fastest: install with one click')}
          </h3>
          <Button asChild className="gap-2">
            <a href={setup.install.href}>
              <IconExternalLink size={16} />
              {t('aiPrincipals.connect.oneClick.button', 'Open in {app}', { app })}
            </a>
          </Button>
          <p className="text-xs text-muted-foreground">
            {setup.install.verified
              ? t('aiPrincipals.connect.oneClick.help', '{app} opens and asks you to confirm.', { app })
              : t(
                  'aiPrincipals.connect.oneClick.unverifiedHelp',
                  '{app} opens and asks you to confirm. If nothing happens, use the steps below instead.',
                  { app }
                )}
          </p>
        </section>
      )}

      {setup.command && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold">
            {setup.install
              ? t('aiPrincipals.connect.command.titleAlt', 'Or run this command')
              : t('aiPrincipals.connect.command.title', 'Run this command')}
          </h3>
          <p className="text-xs text-muted-foreground">
            {t('aiPrincipals.connect.command.help', 'Paste it into a terminal and press Enter.')}
          </p>
          <CopyBlock value={setup.command.text} multiline {...copyProps} />
        </section>
      )}

      {setup.fields && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold">
            {t('aiPrincipals.connect.fields.title', 'Fill in these settings in {app}', { app })}
          </h3>
          {setup.fields.map((field) => (
            <CopyBlock
              key={field.key}
              caption={fieldLabel(t, field)}
              value={field.value}
              display={field.secret ? mask(field.value) : undefined}
              {...copyProps}
            />
          ))}
        </section>
      )}

      {setup.file && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold">
            {setup.install || setup.command || setup.fields
              ? t('aiPrincipals.connect.file.titleAlt', 'Or add it to the settings file')
              : t('aiPrincipals.connect.file.title', 'Add this to the settings file')}
          </h3>
          <p className="text-xs text-muted-foreground">
            {setup.file.paths.length > 0
              ? t(
                  'aiPrincipals.connect.file.help',
                  'Open {path} (create it if it does not exist), paste this in, save, and restart {app}. If the file already lists other servers, add this one beside them.',
                  { path: setup.file.paths.join(' · '), app }
                )
              : t(
                  'aiPrincipals.connect.file.helpGeneric',
                  'Most apps that read an "mcpServers" settings file accept this.'
                )}
          </p>
          <CopyBlock value={setup.file.text} multiline caption={setup.file.paths[0]} {...copyProps} />
        </section>
      )}
    </div>
  );
}

function fieldLabel(t: TranslateFn, field: McpFormField): string {
  switch (field.key) {
    case 'endpoint':
      return t('aiPrincipals.connect.field.endpoint', 'Server URL (endpoint)');
    case 'transport':
      return t('aiPrincipals.connect.field.transport', 'Server transport');
    case 'authentication':
      return t('aiPrincipals.connect.field.authentication', 'Authentication');
    case 'token':
      return t('aiPrincipals.connect.field.token', 'Bearer token');
    case 'headerName':
      return t('aiPrincipals.connect.field.headerName', 'Header name');
    case 'headerValue':
      return t('aiPrincipals.connect.field.headerValue', 'Header value');
  }
}

function mask(secret: string): string {
  return secret.length <= 12 ? '••••••••' : `${secret.slice(0, 12)}••••••••`;
}
