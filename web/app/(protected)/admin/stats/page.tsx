"use client";

import { useEffect, useState } from "react";
import { AdminHeader } from "@/components/admin/admin-header";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@amroksaleh/ui/card";
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@amroksaleh/ui/tabs";
import { Alert, AlertTitle, AlertDescription, AlertAction } from "@amroksaleh/ui/alert";
import { Button } from "@amroksaleh/ui/button";
import { useTranslation } from "@amroksaleh/features/i18n";
import { useDateDisplay } from "@amroksaleh/features/datetime";

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

/**
 * What this deployment is running, from `GET /api/v1/platform/version`.
 *
 * `php_version` is declared because the endpoint sends it, but it is NOT read
 * here: the System card already shows a PHP version, sourced from
 * `/api/v1/admin/stats`, which every viewer of this page can read. Switching
 * that row to this narrower source would make it disappear for people who see
 * it today — a regression dressed as a consolidation.
 */
interface PlatformVersion {
  core_version: string;
  sdk_version: string;
  php_version: string;
}

// The stats query aggregates several COUNT/GROUP BY queries server-side with
// no request-level timeout of its own (see AdminApiHandler::stats()) — if the
// backend is unhealthy (DB lock contention, connection exhaustion during a
// crash-loop recovery, etc.) the fetch can otherwise hang indefinitely and
// this page would show its loading skeletons forever with no way out. Bound
// it client-side so a stuck backend degrades to a retryable error instead.
const STATS_FETCH_TIMEOUT_MS = 15_000;

// The version endpoint reads local constants only — no database, no network
// call to a release stream (that is the separate /latest route) — so it is
// expected to answer immediately. The bound exists so a wedged backend leaves
// no request dangling behind an unmounted page, not because a slow answer is
// anticipated.
const PLATFORM_FETCH_TIMEOUT_MS = 10_000;

export default function AdminStats() {
  const { apiClient } = useAuth();
  const t = useTranslation("admin");
  const dates = useDateDisplay();

  /**
   * The first and last labels under a growth chart, or undefined for no axis.
   *
   * #1068: the chart component is a published registry item and cannot reach
   * either the reader's language or this tenant's preference, so the formatting
   * happens here and the strip simply does not render when dates are hidden.
   * A trend still reads as a trend without a scale under it.
   */
  const axisLabelsFor = (
    points: { date: string }[]
  ): { start: string; end: string } | undefined => {
    if (points.length === 0) return undefined;
    const start = dates.date(points[0].date);
    const end = dates.date(points[points.length - 1].date);

    return start === null || end === null ? undefined : { start, end };
  };

  const [stats, setStats] = useState<StatsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [retryKey, setRetryKey] = useState(0);
  // Null means "not shown", and it is reached by every failure path — a 403, a
  // timeout, a network error, a malformed body. See the effect below for why
  // that collapse is deliberate rather than lazy.
  const [platform, setPlatform] = useState<PlatformVersion | null>(null);

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    // Plain setTimeout+abort rather than AbortSignal.timeout(), which is
    // unsupported in the jsdom test environment this page is exercised under.
    const hangGuard = setTimeout(() => controller.abort(), STATS_FETCH_TIMEOUT_MS);

    async function fetchStats() {
      setLoading(true);
      setError(false);
      try {
        const response = await apiClient("/api/v1/admin/stats", {
          signal: controller.signal,
        });
        if (cancelled) return;
        if (response.ok) {
          const data = await response.json();
          if (!cancelled) setStats(data.stats);
        } else {
          setError(true);
        }
      } catch (err) {
        console.error("Failed to fetch stats:", err);
        if (!cancelled) setError(true);
      } finally {
        clearTimeout(hangGuard);
        if (!cancelled) setLoading(false);
      }
    }

    fetchStats();
    return () => {
      cancelled = true;
      clearTimeout(hangGuard);
      controller.abort();
    };
  }, [apiClient, retryKey]);

  /**
   * Platform versions — a SEPARATE request, and a separate failure story.
   *
   * WHY NOT FOLDED INTO THE STATS FETCH. The two answer to different gates.
   * This page's data needs the `admin` role; `GET /api/v1/platform/version`
   * needs `settings:manage` AND the system tenant, because it describes the
   * whole DEPLOYMENT rather than a tenant's slice of it. Merging them would
   * have forced one of the two to move: either the dashboard narrows to the
   * operator gate, or deployment state widens to every tenant admin. Two
   * requests keep both gates exactly where their owners put them.
   *
   * WHY EVERY FAILURE IS SILENCE. A 403 here is the endpoint working
   * correctly, and it is the EXPECTED outcome for a tenant admin on a shared
   * install — not an incident. So there is no alert, no toast, and
   * deliberately no `console.error`: a dashboard that logs an error on every
   * ordinary render teaches people that the console is noise, which costs more
   * than the diagnostic is worth. Timeouts and network errors collapse to the
   * same absence, because the distinction has no action attached to it — a
   * version readout is context, not the thing this page exists to show, and
   * the page already has one honest error surface for the data that is.
   *
   * WHAT THE VIEWER SEES. The System card renders without the two version
   * rows and is otherwise complete. Nothing is greyed out, nothing says
   * "unavailable", nothing hints at a permission they do not have.
   */
  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    const hangGuard = setTimeout(() => controller.abort(), PLATFORM_FETCH_TIMEOUT_MS);

    async function fetchPlatformVersion() {
      try {
        const response = await apiClient("/api/v1/platform/version", {
          signal: controller.signal,
        });
        if (cancelled || !response.ok) return;
        const data: PlatformVersion = await response.json();
        if (!cancelled) setPlatform(data);
      } catch {
        // Intentionally empty: absence IS the degraded rendering.
      } finally {
        clearTimeout(hangGuard);
      }
    }

    fetchPlatformVersion();
    return () => {
      cancelled = true;
      clearTimeout(hangGuard);
      controller.abort();
    };
  }, [apiClient, retryKey]);

  const statCards = [
    {
      title: t("stats.card.users.title", "Total Users"),
      value: stats?.totals.users ?? "--",
      description: t("stats.card.users.description", "Registered accounts"),
      icon: IconUsers,
      color: "text-blue-500",
    },
    {
      title: t("stats.card.roles.title", "Active Roles"),
      value: stats?.totals.roles ?? "--",
      description: t("stats.card.roles.description", "Configured permissions"),
      icon: IconUserShield,
      color: "text-purple-500",
    },
    {
      title: t("stats.card.tenants.title", "Total Tenants"),
      value: stats?.totals.tenants ?? "--",
      description: t("stats.card.tenants.description", "Organizations"),
      icon: IconBuildingCommunity,
      color: "text-orange-500",
    },
    {
      title: t("stats.card.permissions.title", "Permissions"),
      value: stats?.totals.permissions ?? "--",
      description: t("stats.card.permissions.description", "Available actions"),
      icon: IconLock,
      color: "text-green-500",
    },
  ];

  return (
    <div className="space-y-8">
      {/* Header */}
      <AdminHeader
        title={t("stats.title", "System Statistics")}
        description={t("stats.description", "Real-time overview of system-wide metrics")}
      />

      {error && (
        <Alert variant="destructive" data-testid="stats-fetch-error">
          <AlertTitle>{t("stats.error.title", "Couldn’t load system statistics")}</AlertTitle>
          <AlertDescription>
            {t(
              "stats.error.description",
              "The request failed or timed out. This page’s data comes from several " +
                "database queries with no bound of their own — a slow/unhealthy " +
                "backend can take a while to respond."
            )}
          </AlertDescription>
          <AlertAction>
            <Button size="xs" variant="outline" onClick={() => setRetryKey((k) => k + 1)}>
              {t("stats.error.retry", "Retry")}
            </Button>
          </AlertAction>
        </Alert>
      )}

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
            <CardTitle>{t("stats.roles.title", "Users per Role")}</CardTitle>
            <CardDescription>
              {t("stats.roles.description", "Distribution of users across system roles")}
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
                          {t("stats.roles.userCount", "{count} users", {
                            count: role.count,
                          })}
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
            <CardTitle>{t("stats.growth.title", "Growth Trends")}</CardTitle>
            <CardDescription>
              {t("stats.growth.description", "Last 7 days registration activity")}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Tabs defaultValue="users" className="w-full">
              <TabsList className="grid w-full grid-cols-2 mb-4">
                <TabsTrigger value="users">{t("stats.growth.tab.users", "Users")}</TabsTrigger>
                <TabsTrigger value="tenants">
                  {t("stats.growth.tab.tenants", "Tenants")}
                </TabsTrigger>
              </TabsList>
              <TabsContent value="users" className="h-[200px] mt-0">
                {loading ? (
                  <div className="h-full w-full animate-pulse bg-muted rounded" />
                ) : (
                  <StatsChart
                    data={stats?.growth.users ?? []}
                    tooltipLabel={(count, term) =>
                      t("stats.growth.tooltip", "{count} {term}", { count, term })
                    }
                    label={t("stats.growth.users.label", "new users")}
                    color="var(--primary)"
                    axisLabels={axisLabelsFor(stats?.growth.users ?? [])}
                  />
                )}
              </TabsContent>
              <TabsContent value="tenants" className="h-[200px] mt-0">
                {loading ? (
                  <div className="h-full w-full animate-pulse bg-muted rounded" />
                ) : (
                  <StatsChart
                    data={stats?.growth.tenants ?? []}
                    tooltipLabel={(count, term) =>
                      t("stats.growth.tooltip", "{count} {term}", { count, term })
                    }
                    label={t("stats.growth.tenants.label", "new tenants")}
                    color="var(--chart-2)"
                    axisLabels={axisLabelsFor(stats?.growth.tenants ?? [])}
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
              <CardTitle>{t("stats.database.title", "Database")}</CardTitle>
              <CardDescription>{t("stats.database.subtitle", "PostgreSQL Status")}</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">{t("stats.database.size", "Size")}</span>
              <span className="font-medium">
                {stats?.database.size ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">{t("stats.database.version", "Version")}</span>
              <span className="font-medium truncate max-w-[150px]">
                {stats?.database.version ?? "--"}
              </span>
            </div>
            <div className="pt-2 border-t">
              <div className="flex justify-between items-center text-sm mb-1">
                <span className="text-muted-foreground">
                  {t("stats.database.migrations", "Migrations")}
                </span>
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
              <CardTitle>{t("stats.system.title", "System")}</CardTitle>
              <CardDescription>{t("stats.system.subtitle", "Versions and resource usage")}</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            {/*
              Rendered only once resolved, with no "--" placeholder and no
              skeleton. The two states that must not be confused are "still
              loading" and "you may not read this", and a placeholder makes them
              look identical until one of them vanishes. Appearing once on the
              permitted path is a better transition than collapsing once on the
              denied path: content arriving is expected, content disappearing
              reads as a fault. Versions are values, never translated — only the
              labels beside them are.
            */}
            {platform && (
              <>
                <div className="flex justify-between items-center text-sm">
                  <span className="text-muted-foreground">
                    {t("stats.system.coreVersion", "Core Version")}
                  </span>
                  <span className="font-medium">{platform.core_version}</span>
                </div>
                <div className="flex justify-between items-center text-sm">
                  <span className="text-muted-foreground">
                    {t("stats.system.sdkVersion", "Plugin SDK")}
                  </span>
                  <span className="font-medium">{platform.sdk_version}</span>
                </div>
              </>
            )}
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">
                {t("stats.system.phpVersion", "PHP Version")}
              </span>
              <span className="font-medium">
                {stats?.system.php_version ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">
                {t("stats.system.memoryUsage", "Memory Usage")}
              </span>
              <span className="font-medium">
                {stats?.system.memory_usage ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">
                {t("stats.system.peakMemory", "Peak Memory")}
              </span>
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
              <CardTitle>{t("stats.environment.title", "Environment")}</CardTitle>
              <CardDescription>
                {t("stats.environment.subtitle", "Server Details")}
              </CardDescription>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">{t("stats.environment.os", "OS")}</span>
              <span className="font-medium">{stats?.system.os ?? "--"}</span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">
                {t("stats.environment.server", "Server Software")}
              </span>
              <span className="font-medium truncate max-w-[150px]">
                {stats?.system.server ?? "--"}
              </span>
            </div>
            <div className="flex justify-between items-center text-sm">
              <span className="text-muted-foreground">
                {t("stats.environment.timezone", "Timezone")}
              </span>
              <span className="font-medium">
                {/*
                  @date-display-ignore: this reads the browser's time ZONE NAME
                  ("Europe/Berlin"), which is a label for a place, not a date or
                  a time. Nothing here is formatted from an instant, so
                  `ui.hide_dates` has nothing to hide — and blanking it would
                  remove a diagnostic an operator uses to explain why two
                  machines disagree about what "today" is.
                */}
                {Intl.DateTimeFormat().resolvedOptions().timeZone}
              </span>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
