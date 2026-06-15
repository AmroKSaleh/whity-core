'use client';

import { useAuth } from '@/lib/auth-context';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

export default function DashboardPage() {
  const auth = useAuth();

  return (
    <div className="space-y-8">
      {/* Welcome Section */}
      <div>
        <h1 className="text-4xl font-bold">Welcome back!</h1>
        <p className="text-muted-foreground mt-2">
          You&apos;re logged in as <span className="font-semibold">{auth.user?.email}</span>
        </p>
      </div>

      {/* Quick Stats */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Your User ID</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{auth.user?.id}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Your Role</CardTitle>
          </CardHeader>
          <CardContent>
            <Badge className="text-base py-1">
              {auth.user?.role?.charAt(0).toUpperCase()}{auth.user?.role?.slice(1)}
            </Badge>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Authentication</CardTitle>
          </CardHeader>
          <CardContent>
            <Badge variant="secondary">JWT Token Active</Badge>
          </CardContent>
        </Card>
      </div>

      {/* User Information Card */}
      <Card>
        <CardHeader>
          <CardTitle>Authentication Details</CardTitle>
          <CardDescription>Your user information</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {/* Email */}
          <div>
            <label className="text-sm font-medium text-muted-foreground">Email</label>
            <p className="mt-2 font-mono text-sm bg-muted p-3 rounded">{auth.user?.email}</p>
          </div>

          {/* Auth Note */}
          <div>
            <label className="text-sm font-medium text-muted-foreground">Authentication Method</label>
            <p className="mt-2 text-sm text-muted-foreground">
              You are authenticated using secure httpOnly cookies. Your JWT token is stored securely on the server and is not accessible from JavaScript for security purposes.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Getting Started */}
      <Card>
        <CardHeader>
          <CardTitle>Getting Started</CardTitle>
          <CardDescription>Explore the admin panel using the sidebar</CardDescription>
        </CardHeader>
        <CardContent>
          <ul className="space-y-2 text-sm">
            <li>• <strong>Users</strong> - Manage system users and their roles</li>
            <li>• <strong>Roles</strong> - Create and manage roles with permissions</li>
            <li>• <strong>Tenants</strong> - Manage multi-tenant organizations</li>
            <li>• <strong>Statistics</strong> - View system statistics and metrics</li>
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}
