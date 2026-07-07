'use client';

import { useAuth } from '@/lib/auth-context';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { TwoFactorSettings } from '@/components/TwoFactorSettings';
import { SessionsSettings } from '@/components/SessionsSettings';
import { ProfileForm } from './profile-form';

export default function SettingsPage() {
  const auth = useAuth();

  return (
    <div className="space-y-8">
      {/* Header */}
      <div>
        <h1 className="text-4xl font-bold">Settings</h1>
        <p className="text-muted-foreground mt-2">
          Manage your account and security preferences
        </p>
      </div>

      {/* Profile — self-service edit (WC-64) */}
      <Card>
        <CardHeader>
          <CardTitle>Profile</CardTitle>
          <CardDescription>Update your email and password</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div>
            <label className="text-sm font-medium text-muted-foreground">Role</label>
            <p className="mt-2 text-sm bg-muted p-3 rounded capitalize">{auth.user?.role}</p>
          </div>

          <ProfileForm />
        </CardContent>
      </Card>

      {/* Security Settings */}
      <Card>
        <CardHeader>
          <CardTitle>Security</CardTitle>
          <CardDescription>Protect your account with two-factor authentication</CardDescription>
        </CardHeader>
        <CardContent>
          <TwoFactorSettings />
        </CardContent>
      </Card>

      {/* Sessions & devices (WC-b-logout-others) */}
      <Card>
        <CardHeader>
          <CardTitle>Sessions &amp; devices</CardTitle>
          <CardDescription>Sign out of sessions on your other browsers, apps, and devices</CardDescription>
        </CardHeader>
        <CardContent>
          <SessionsSettings />
        </CardContent>
      </Card>
    </div>
  );
}
