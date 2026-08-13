'use client';

import { useAuth } from '@/lib/auth-context';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { TwoFactorSettings } from '@/components/TwoFactorSettings';
import { SessionsSettings } from '@/components/SessionsSettings';
import { DevicesSettings } from '@/components/DevicesSettings';
import { EmailAddressesSettings } from '@/components/EmailAddressesSettings';
import { LanguageSwitcher, useTranslation } from '@amroksaleh/features/i18n';
import { ProfileForm } from './profile-form';

export default function SettingsPage() {
  const auth = useAuth();
  const t = useTranslation('auth');

  return (
    <div className="space-y-8">
      {/* Header */}
      <div>
        <h1 className="text-4xl font-bold">{t('settings.title', 'Settings')}</h1>
        <p className="text-muted-foreground mt-2">
          {t('settings.subtitle', 'Manage your account and security preferences')}
        </p>
      </div>

      {/* Profile — self-service edit (WC-64) */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.profile.title', 'Profile')}</CardTitle>
          <CardDescription>
            {t('settings.profile.description', 'Update your email and password')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div>
            <label className="text-sm font-medium text-muted-foreground">
              {t('settings.profile.role', 'Role')}
            </label>
            <p className="mt-2 text-sm bg-muted p-3 rounded capitalize">{auth.user?.role}</p>
          </div>

          <ProfileForm />
        </CardContent>
      </Card>

      {/* Language. This also sets the interface DIRECTION: each language
          carries its own 'ltr'/'rtl', so choosing Arabic mirrors the interface.
          See lib/direction-context.tsx — there is no separate toggle. */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.language.title', 'Language')}</CardTitle>
          <CardDescription>
            {t(
              'settings.language.description',
              'Choose the language used across the interface. Right-to-left languages mirror the layout automatically.'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <LanguageSwitcher
            variant="dropdown"
            className="h-9 w-full max-w-xs rounded-md border border-input bg-input/20 px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
          />
        </CardContent>
      </Card>

      {/* Email addresses — multi-email self-service (WC-54fb5c37) */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.emails.title', 'Email addresses')}</CardTitle>
          <CardDescription>
            {t(
              'settings.emails.description',
              'Add, verify, and manage the email addresses on your account'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <EmailAddressesSettings />
        </CardContent>
      </Card>

      {/* Security Settings */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.security.title', 'Security')}</CardTitle>
          <CardDescription>
            {t(
              'settings.security.description',
              'Protect your account with two-factor authentication'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <TwoFactorSettings />
        </CardContent>
      </Card>

      {/* Sessions (WC-b-logout-others) — interactive browser/app logins only;
          native-device credentials are a genuinely separate list below (#409). */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.sessions.title', 'Sessions')}</CardTitle>
          <CardDescription>
            {t(
              'settings.sessions.description',
              'Sign out of your active sessions on other browsers and apps'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <SessionsSettings />
        </CardContent>
      </Card>

      {/* Devices (#409) — long-lived native-client credentials (desktop and
          mobile apps), distinct from the interactive sessions above. */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.devices.title', 'Devices')}</CardTitle>
          <CardDescription>
            {t(
              'settings.devices.description',
              'Manage native apps and devices with long-lived access to your account'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <DevicesSettings />
        </CardContent>
      </Card>
    </div>
  );
}
