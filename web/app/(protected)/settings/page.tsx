'use client';

import { useAuth } from '@/lib/auth-context';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { TwoFactorSettings } from '@/components/TwoFactorSettings';

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

      {/* Account Settings */}
      <Card>
        <CardHeader>
          <CardTitle>Account Information</CardTitle>
          <CardDescription>Your account details</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div>
            <label className="text-sm font-medium text-muted-foreground">Email Address</label>
            <p className="mt-2 font-mono text-sm bg-muted p-3 rounded">{auth.user?.email}</p>
          </div>

          <div>
            <label className="text-sm font-medium text-muted-foreground">Role</label>
            <p className="mt-2 text-sm bg-muted p-3 rounded capitalize">{auth.user?.role}</p>
          </div>
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
    </div>
  );
}
