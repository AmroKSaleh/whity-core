'use client';

import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export default function DashboardPage() {
  const router = useRouter();
  const auth = useAuth();

  const handleLogout = () => {
    auth.logout();
    router.push('/login');
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-background to-muted p-8">
      {/* Header */}
      <div className="mb-8 flex items-center justify-between">
        <h1 className="text-4xl font-bold">Dashboard</h1>
        <Button onClick={handleLogout} variant="outline">
          Logout
        </Button>
      </div>

      {/* Protected Page Notice */}
      <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">
        This is a protected page. You are viewing this because you are authenticated.
      </div>

      {/* User Information Card */}
      <Card className="max-w-2xl">
        <CardHeader>
          <CardTitle>User Information</CardTitle>
          <CardDescription>Your authenticated user details</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid gap-6 md:grid-cols-2">
            {/* Email */}
            <div>
              <label className="text-sm font-medium text-muted-foreground">Email</label>
              <p className="mt-1 font-mono text-lg">{auth.user?.email || 'Not available'}</p>
            </div>

            {/* User ID */}
            <div>
              <label className="text-sm font-medium text-muted-foreground">User ID</label>
              <p className="mt-1 font-mono text-lg">{auth.user?.id || 'Not available'}</p>
            </div>

            {/* Role */}
            <div>
              <label className="text-sm font-medium text-muted-foreground">Role</label>
              <p className="mt-1 font-mono text-lg">{auth.user?.role ? auth.user.role.charAt(0).toUpperCase() + auth.user.role.slice(1) : 'Not available'}</p>
            </div>

            {/* Token Preview */}
            <div>
              <label className="text-sm font-medium text-muted-foreground">JWT Token (Preview)</label>
              <p className="mt-1 break-all font-mono text-sm">{auth.token ? auth.token.substring(0, 50) + '...' : 'Not available'}</p>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
