'use client';

import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

export default function DemoDashboard() {
  const [isDark, setIsDark] = useState(false);
  const [selectedRole, setSelectedRole] = useState('admin');

  return (
    <html className={isDark ? 'dark' : ''}>
      <body className="bg-background text-foreground transition-colors">
        <div className="min-h-screen">
          {/* Header */}
          <header className="border-b border-border bg-card">
            <div className="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
              <div>
                <h1 className="text-2xl font-semibold">Whity Dashboard</h1>
                <p className="text-sm text-muted-foreground">shadcn-ui Demo</p>
              </div>
              <Button
                variant="outline"
                onClick={() => setIsDark(!isDark)}
              >
                {isDark ? '☀️ Light' : '🌙 Dark'}
              </Button>
            </div>
          </header>

          {/* Main Content */}
          <main className="max-w-7xl mx-auto px-4 py-8">
            {/* Alerts Section */}
            <section className="mb-8">
              <h2 className="text-xl font-semibold mb-4">Alerts & Status</h2>
              <div className="space-y-4">
                <Alert>
                  <AlertTitle>Welcome</AlertTitle>
                  <AlertDescription>
                    This is a demo dashboard showcasing shadcn-ui components with Whity Core design tokens.
                  </AlertDescription>
                </Alert>
                <Alert className="border-green-600 bg-green-50 dark:bg-green-950">
                  <AlertTitle className="text-green-900 dark:text-green-100">Success</AlertTitle>
                  <AlertDescription className="text-green-800 dark:text-green-200">
                    All components are working correctly.
                  </AlertDescription>
                </Alert>
              </div>
            </section>

            {/* Stats Cards */}
            <section className="mb-8">
              <h2 className="text-xl font-semibold mb-4">Overview</h2>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <Card>
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">Total Users</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold">2,543</div>
                    <p className="text-xs text-muted-foreground">+12% from last month</p>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">Active Sessions</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold">483</div>
                    <p className="text-xs text-muted-foreground">Currently online</p>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">Revenue</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold">$12,543</div>
                    <p className="text-xs text-muted-foreground">+5% from last month</p>
                  </CardContent>
                </Card>
              </div>
            </section>

            {/* Components Showcase */}
            <section className="mb-8">
              <h2 className="text-xl font-semibold mb-4">Components</h2>
              <Tabs defaultValue="buttons" className="w-full">
                <TabsList className="grid w-full grid-cols-3">
                  <TabsTrigger value="buttons">Buttons</TabsTrigger>
                  <TabsTrigger value="forms">Forms</TabsTrigger>
                  <TabsTrigger value="badges">Badges</TabsTrigger>
                </TabsList>

                {/* Buttons Tab */}
                <TabsContent value="buttons">
                  <Card>
                    <CardHeader>
                      <CardTitle>Button Variants</CardTitle>
                      <CardDescription>All available button styles</CardDescription>
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-6">
                        {/* Primary Buttons */}
                        <div>
                          <h4 className="text-sm font-medium mb-3">Primary</h4>
                          <div className="flex gap-2 flex-wrap">
                            <Button>Default</Button>
                            <Button disabled>Disabled</Button>
                            <Button size="sm">Small</Button>
                            <Button size="lg">Large</Button>
                          </div>
                        </div>

                        {/* Secondary Buttons */}
                        <div>
                          <h4 className="text-sm font-medium mb-3">Secondary</h4>
                          <div className="flex gap-2 flex-wrap">
                            <Button variant="secondary">Default</Button>
                            <Button variant="secondary" disabled>Disabled</Button>
                            <Button variant="secondary" size="sm">Small</Button>
                            <Button variant="secondary" size="lg">Large</Button>
                          </div>
                        </div>

                        {/* Outline Buttons */}
                        <div>
                          <h4 className="text-sm font-medium mb-3">Outline</h4>
                          <div className="flex gap-2 flex-wrap">
                            <Button variant="outline">Default</Button>
                            <Button variant="outline" disabled>Disabled</Button>
                            <Button variant="outline" size="sm">Small</Button>
                            <Button variant="outline" size="lg">Large</Button>
                          </div>
                        </div>

                        {/* Ghost Buttons */}
                        <div>
                          <h4 className="text-sm font-medium mb-3">Ghost</h4>
                          <div className="flex gap-2 flex-wrap">
                            <Button variant="ghost">Default</Button>
                            <Button variant="ghost" disabled>Disabled</Button>
                            <Button variant="ghost" size="sm">Small</Button>
                            <Button variant="ghost" size="lg">Large</Button>
                          </div>
                        </div>

                        {/* Destructive Buttons */}
                        <div>
                          <h4 className="text-sm font-medium mb-3">Destructive</h4>
                          <div className="flex gap-2 flex-wrap">
                            <Button variant="destructive">Delete</Button>
                            <Button variant="destructive" disabled>Disabled</Button>
                            <Button variant="destructive" size="sm">Small</Button>
                            <Button variant="destructive" size="lg">Large</Button>
                          </div>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                </TabsContent>

                {/* Forms Tab */}
                <TabsContent value="forms">
                  <Card>
                    <CardHeader>
                      <CardTitle>Form Elements</CardTitle>
                      <CardDescription>Input fields and selects</CardDescription>
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-6 max-w-md">
                        <div>
                          <label className="text-sm font-medium mb-2 block">Email</label>
                          <Input type="email" placeholder="user@example.com" />
                        </div>

                        <div>
                          <label className="text-sm font-medium mb-2 block">Password</label>
                          <Input type="password" placeholder="Enter password" />
                        </div>

                        <div>
                          <label className="text-sm font-medium mb-2 block">Role</label>
                          <Select value={selectedRole} onValueChange={setSelectedRole}>
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="admin">Administrator</SelectItem>
                              <SelectItem value="manager">Manager</SelectItem>
                              <SelectItem value="user">User</SelectItem>
                              <SelectItem value="guest">Guest</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>

                        <Button className="w-full">Submit</Button>
                      </div>
                    </CardContent>
                  </Card>
                </TabsContent>

                {/* Badges Tab */}
                <TabsContent value="badges">
                  <Card>
                    <CardHeader>
                      <CardTitle>Badges</CardTitle>
                      <CardDescription>Status and category indicators</CardDescription>
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-4">
                        <div>
                          <h4 className="text-sm font-medium mb-3">Default</h4>
                          <div className="flex gap-2 flex-wrap">
                            <Badge>Default</Badge>
                            <Badge variant="secondary">Secondary</Badge>
                            <Badge variant="destructive">Destructive</Badge>
                            <Badge variant="outline">Outline</Badge>
                          </div>
                        </div>

                        <div>
                          <h4 className="text-sm font-medium mb-3">Status</h4>
                          <div className="flex gap-2 flex-wrap">
                            <Badge className="bg-green-100 text-green-900 dark:bg-green-900 dark:text-green-100">
                              Active
                            </Badge>
                            <Badge className="bg-yellow-100 text-yellow-900 dark:bg-yellow-900 dark:text-yellow-100">
                              Pending
                            </Badge>
                            <Badge className="bg-red-100 text-red-900 dark:bg-red-900 dark:text-red-100">
                              Inactive
                            </Badge>
                            <Badge className="bg-blue-100 text-blue-900 dark:bg-blue-900 dark:text-blue-100">
                              Processing
                            </Badge>
                          </div>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                </TabsContent>
              </Tabs>
            </section>

            {/* Data Table Preview */}
            <section className="mb-8">
              <h2 className="text-xl font-semibold mb-4">Data Table Example</h2>
              <Card>
                <CardContent className="pt-6">
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b border-border">
                          <th className="text-left py-3 px-4 font-medium">ID</th>
                          <th className="text-left py-3 px-4 font-medium">Name</th>
                          <th className="text-left py-3 px-4 font-medium">Status</th>
                          <th className="text-left py-3 px-4 font-medium">Email</th>
                          <th className="text-left py-3 px-4 font-medium">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        {[
                          { id: 1, name: 'Alice Johnson', status: 'active', email: 'alice@example.com' },
                          { id: 2, name: 'Bob Smith', status: 'active', email: 'bob@example.com' },
                          { id: 3, name: 'Carol White', status: 'pending', email: 'carol@example.com' },
                          { id: 4, name: 'David Brown', status: 'inactive', email: 'david@example.com' },
                        ].map((row) => (
                          <tr key={row.id} className="border-b border-border hover:bg-muted">
                            <td className="py-3 px-4">{row.id}</td>
                            <td className="py-3 px-4 font-medium">{row.name}</td>
                            <td className="py-3 px-4">
                              <Badge
                                variant={row.status === 'active' ? 'default' : row.status === 'pending' ? 'secondary' : 'outline'}
                              >
                                {row.status.charAt(0).toUpperCase() + row.status.slice(1)}
                              </Badge>
                            </td>
                            <td className="py-3 px-4 text-muted-foreground">{row.email}</td>
                            <td className="py-3 px-4">
                              <Button variant="ghost" size="sm">Edit</Button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </CardContent>
              </Card>
            </section>

            {/* Theme Info */}
            <section>
              <Card>
                <CardHeader>
                  <CardTitle>Theme Information</CardTitle>
                  <CardDescription>Current theme and token usage</CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <h4 className="font-medium mb-3">Current Mode</h4>
                      <p className="text-muted-foreground">{isDark ? 'Dark' : 'Light'} mode is active</p>
                      <p className="text-sm text-muted-foreground mt-2">
                        Theme: {isDark ? '<html className="dark">' : '<html>'}
                      </p>
                    </div>
                    <div>
                      <h4 className="font-medium mb-3">Color System</h4>
                      <p className="text-muted-foreground">OKLCH color space with 32 design tokens</p>
                      <p className="text-sm text-muted-foreground mt-2">
                        Includes light/dark modes and semantic colors
                      </p>
                    </div>
                    <div>
                      <h4 className="font-medium mb-3">Components</h4>
                      <p className="text-muted-foreground">Built with Radix UI + Tailwind CSS</p>
                      <p className="text-sm text-muted-foreground mt-2">
                        Fully accessible and customizable
                      </p>
                    </div>
                    <div>
                      <h4 className="font-medium mb-3">Framework</h4>
                      <p className="text-muted-foreground">Next.js 16 with TypeScript</p>
                      <p className="text-sm text-muted-foreground mt-2">
                        shadcn-ui preset: b1D0eTWj
                      </p>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </section>
          </main>
        </div>
      </body>
    </html>
  );
}
