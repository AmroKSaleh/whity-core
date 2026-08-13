'use client';

import { useAuth } from '@/lib/auth-context';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Badge } from '@amroksaleh/ui/badge';
import { useTranslation } from '@amroksaleh/features/i18n';

export default function DashboardPage() {
  const auth = useAuth();
  const t = useTranslation('common');

  return (
    <div className="space-y-8">
      {/* Welcome Section */}
      <div>
        <h1 className="text-4xl font-bold">{t('dashboard.welcome', 'Welcome back!')}</h1>
        {/*
          NOT translated, deliberately: the address is emphasised with <span>
          inside the sentence, and `t()` returns a string — keeping the emphasis
          would mean handing a translator "You're logged in as" as a fragment,
          which no language can be trusted to put in front of the value. Needs
          the emphasis lifted out of the sentence first.
        */}
        <p className="text-muted-foreground mt-2">
          You&apos;re logged in as <span className="font-semibold">{auth.user?.email}</span>
        </p>
      </div>

      {/* Quick Stats */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">
              {t('dashboard.stat.userId', 'Your User ID')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{auth.user?.id}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">
              {t('dashboard.stat.role', 'Your Role')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Badge className="text-base py-1">
              {auth.user?.role?.charAt(0).toUpperCase()}{auth.user?.role?.slice(1)}
            </Badge>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">
              {t('dashboard.stat.authentication', 'Authentication')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Badge variant="secondary">{t('dashboard.stat.tokenActive', 'JWT Token Active')}</Badge>
          </CardContent>
        </Card>
      </div>

      {/* User Information Card */}
      <Card>
        <CardHeader>
          <CardTitle>{t('dashboard.details.title', 'Authentication Details')}</CardTitle>
          <CardDescription>
            {t('dashboard.details.description', 'Your user information')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {/* Email */}
          <div>
            <label className="text-sm font-medium text-muted-foreground">
              {t('dashboard.details.email', 'Email')}
            </label>
            <p className="mt-2 font-mono text-sm bg-muted p-3 rounded">{auth.user?.email}</p>
          </div>

          {/* Auth Note */}
          <div>
            <label className="text-sm font-medium text-muted-foreground">
              {t('dashboard.details.method', 'Authentication Method')}
            </label>
            <p className="mt-2 text-sm text-muted-foreground">
              {t(
                'dashboard.details.methodNote',
                'You are authenticated using secure httpOnly cookies. Your JWT token is stored securely on the server and is not accessible from JavaScript for security purposes.'
              )}
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Getting Started */}
      <Card>
        <CardHeader>
          <CardTitle>{t('dashboard.gettingStarted.title', 'Getting Started')}</CardTitle>
          <CardDescription>
            {t('dashboard.gettingStarted.description', 'Explore the admin panel using the sidebar')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {/*
            Each row is a TERM and its gloss, not one sentence broken in half —
            the bullet and the dash are layout, and either half stands on its
            own. So both halves are keyed, and the emphasis survives.
          */}
          <ul className="space-y-2 text-sm">
            <li>• <strong>{t('dashboard.gettingStarted.users', 'Users')}</strong> - {t('dashboard.gettingStarted.users.gloss', 'Manage system users and their roles')}</li>
            <li>• <strong>{t('dashboard.gettingStarted.roles', 'Roles')}</strong> - {t('dashboard.gettingStarted.roles.gloss', 'Create and manage roles with permissions')}</li>
            <li>• <strong>{t('dashboard.gettingStarted.tenants', 'Tenants')}</strong> - {t('dashboard.gettingStarted.tenants.gloss', 'Manage multi-tenant organizations')}</li>
            <li>• <strong>{t('dashboard.gettingStarted.statistics', 'Statistics')}</strong> - {t('dashboard.gettingStarted.statistics.gloss', 'View system statistics and metrics')}</li>
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}
