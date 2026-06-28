"use client";

import { useEffect, useState } from "react";
import { AdminHeader } from "@/components/admin/admin-header";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@whity/ui/card";
import { useAuth } from "@/lib/auth-context";
import {
  IconUsers,
  IconUserShield,
  IconBuildingCommunity,
  IconLock,
  IconDatabase,
  IconServer,
  IconCpu,
} from "@tabler/icons-react";

import { StatsChart } from "@/components/admin/stats-chart";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@whity/ui/tabs";

interface StatsData {
  totals: {
    users: number;
    tenants: number;
    roles: number;
    permissions: number;
  };
  breakdown: {
    users_per_role: Array<{ name: string; count: number }>;
  };
  growth: {
    users: Array<{ date: string; count: number }>;
    tenants: Array<{ date: string; count: number }>;
  };
  maintenance: {
    migrations_executed: number;
    migrations_total: number;
    pending_migrations: number;
  };
  database: {
    size: string;
    version: string;
  };
  system: {
    php_version: string;
    memory_usage: string;
    peak_memory: string;
    os: string;
    server: string;
  };
}

export default function AdminStats() {
  const { apiClient } = useAuth();
  const [stats, setStats] = useState<StatsData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function fetchStats() {
      try {
        const response = await apiClient("/api/v1/admin/stats");
        if (response.ok) {
          const data = await response.json();
          setStats(data.stats);
        }
      } catch (error) {
        console.error("Failed to fetch stats:", error);
      } finally {
        setLoading(false);
      }
    }

    fetchStats();
  }, [apiClient]);

  const statCards = [
    {
      title: "Total Users",
      value: stats?.totals.users ?? "--",
      description: "Registered accounts",
      icon: IconUsers,
      color: "text-blue-500",
    },
    {
      title: "Active Roles",
      value: stats?.totals.roles ?? "--",
      description: "Configured permissions",
      icon: IconUserShield,
      color: "text-purple-500",
    },
    {
      title: "Total Tenants",
      value: stats?.totals.tenants ?? "--",
      description: "Organizations",
      icon: IconBuildingCommunity,
      color: "text-orange-500",
    },
    {
      title: "Permissions",
      value: stats?.totals.permissions ?? "--",
      description: "Available actions",
      icon: IconLock,
      color: "text-green-500",
    },
  ];

  return (
    <div className="space-y-8">
      {/* Header */}
      <AdminHeader
        title="System Statistics"
        description="Real-time overview of system-wide metrics"
      />

      {/* Stats Cards */}
      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        {statCards.map((stat, i) => (
          <Card key={i}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">
                {stat.title}
              </CardTitle>
              <stat.icon className={`h-4 w-4 ${stat.color}`} />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">
                {loading ? (
                  <div className="h-8 w-12 animate-pulse bg-muted rounded" />
                ) : (
                  stat.value
                )}
              </div>
              <p className="text-xs text-muted-foreground">
                {stat.description}
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {/* Role Breakdown */}
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>Users per Role</CardTitle>
            <CardDescription>
              Distribution of users across system roles
            </CardDescription>
          </CardHeader>
          <CardContent>
            {loading ? (
              <div className="space-y-4">
                {[1, 2, 3].map((i) => (
                  <div
                    key={i}
                    className="h-4 w-full animate-pulse bg-muted rounded"
                  />
                ))}
              </div>
            ) : (
              <div className="space-y-4">
                {stats?.breakdown.users_per_role.map((role) => {
                  const percentage =
                    (stats.totals.users ?? 0) > 0
                      ? (role.count / stats.totals.users) * 100
                      : 0;
                  return (
                    <div key={role.name} className="space-y-1">
                      <div className="flex items-center justify-between text-sm">
                        <span className="font-medium capitalize">
                          {role.name}
                        </span>
                        <span className="text-muted-foreground">
                          {role.count} users
                        </span>
                      </div>
                      <div className="h-2 w-full bg-secondary rounded-full overflow-hidden">
                        <div
                          className="h-full bg-primary rounded-full"
                          style={{ width: `${percentage}%` }}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Growth Charts */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Growth Trends</CardTitle>
            <CardDescription>Last 7 days registration activity</CardDescription>
          </CardHeader>
          <CardContent>
            <Tabs defaultValue="users" className="w-full">
              <TabsList className="grid w-full grid-cols-2 mb-4">
                <TabsTrigger value="users">Users</TabsTrigger>
                <TabsTrigger value="tenants">Tenants</TabsTrigger>
              </TabsList>
              <TabsContent value="users" className="h-[200px] mt-0">
                {loading ? (
                  <div className="h-full w-full animate-pulse bg-muted rounded" />
                ) : (
                  <StatsChart
                    data={stats?.growth.users ?? []}
                    label="new users"
                    color="hsl(var(--primary))"
                  />
                )}
              </TabsContent>
              <TabsContent value="tenants" className="h-[200px] mt-0">
                {loading ? (
                  <div className="h-full w-full animate-pulse bg-muted rounded" />
                ) : (
                  <StatsChart
                    data={stats?.growth.tenants ?? []}
                    label="new tenants"
                    color="hsl(var(--chart-2, 25 95% 53%))"
                  />
                )}
              </TabsContent>
            </Tabs>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {/* Database Info */}
        <Card>
          <CardHeader className="flex flex-row items-center gap-4">
            <div className="p-2 bg-info/15 rounded-lg">
              <IconDatabase className="h-5 w-5 text-info" />
            </div>
            <div>
              <CardTitle>Database</CardTitle>
              <CardDescription>PostgreSQL Status</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">Size</span>
              <span className="font-medium">
                {stats?.database.size ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">Version</span>
              <span className="font-medium truncate max-w-[150px]">
                {stats?.database.version ?? "--"}
              </span>
            </div>
            <div className="pt-2 border-t">
              <div className="flex justify-between items-center text-sm mb-1">
                <span className="text-muted-foreground">Migrations</span>
                <span className="text-xs">
                  {stats?.maintenance.migrations_executed} /{" "}
                  {stats?.maintenance.migrations_total}
                </span>
              </div>
              <div className="h-2 w-full bg-secondary rounded-full overflow-hidden">
                <div
                  className="h-full bg-primary rounded-full"
                  style={{
                    width: `${((stats?.maintenance.migrations_executed ?? 0) / (stats?.maintenance.migrations_total ?? 1)) * 100}%`,
                  }}
                />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* System Resources */}
        <Card>
          <CardHeader className="flex flex-row items-center gap-4">
            <div className="p-2 bg-success/15 rounded-lg">
              <IconCpu className="h-5 w-5 text-success" />
            </div>
            <div>
              <CardTitle>System</CardTitle>
              <CardDescription>Resource Usage</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">PHP Version</span>
              <span className="font-medium">
                {stats?.system.php_version ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">Memory Usage</span>
              <span className="font-medium">
                {stats?.system.memory_usage ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">Peak Memory</span>
              <span className="font-medium">
                {stats?.system.peak_memory ?? "--"}
              </span>
            </div>
          </CardContent>
        </Card>

        {/* Environment Info */}
        <Card>
          <CardHeader className="flex flex-row items-center gap-4">
            <div className="p-2 bg-warning/15 rounded-lg">
              <IconServer className="h-5 w-5 text-warning" />
            </div>
            <div>
              <CardTitle>Environment</CardTitle>
              <CardDescription>Server Details</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">OS</span>
              <span className="font-medium">{stats?.system.os ?? "--"}</span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">Server Software</span>
              <span className="font-medium truncate max-w-[150px]">
                {stats?.system.server ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">Timezone</span>
              <span className="font-medium">
                {Intl.DateTimeFormat().resolvedOptions().timeZone}
              </span>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
