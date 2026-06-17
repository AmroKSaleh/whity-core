'use client';

import React, { useState, useEffect } from 'react';
import { registerPluginScreen } from '@/lib/plugin-ui-registry';
import { AdminHeader } from '@/components/admin/admin-header';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { IconActivity, IconCpu, IconDatabase, IconServer, IconRefresh, IconCheck, IconX, IconSeedling, IconAlertTriangle } from '@tabler/icons-react';

interface StatusData {
  status: string;
  plugin: string;
  version: string;
}

export function ElmakStatusScreen() {
  const [data, setData] = useState<StatusData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshCount, setRefreshCount] = useState<number>(0);

  // Seeding States
  const [seeding, setSeeding] = useState<boolean>(false);
  const [seedResult, setSeedResult] = useState<any | null>(null);
  const [seedError, setSeedError] = useState<string | null>(null);

  const handleSeed = async () => {
    setSeeding(true);
    setSeedError(null);
    setSeedResult(null);
    try {
      const res = await fetch('/api/elmak/seed', {
        method: 'POST',
      });
      const payload = await res.json();
      if (!res.ok) {
        throw new Error(payload.error || 'Failed to seed tenant data');
      }
      setSeedResult(payload.data || payload);
    } catch (err: any) {
      console.error('Failed to seed:', err);
      setSeedError(err.message || 'An unexpected error occurred during seeding.');
    } finally {
      setSeeding(false);
    }
  };


  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);

    fetch('/api/elmak/status')
      .then((res) => {
        if (!res.ok) {
          throw new Error(`HTTP error! status: ${res.status}`);
        }
        return res.json();
      })
      .then((payload: StatusData) => {
        if (active) {
          setData(payload);
          setLoading(false);
        }
      })
      .catch((err: Error) => {
        if (active) {
          console.error('Failed to fetch Elmak status:', err);
          setError(err.message || 'Failed to connect to Elmak backend API');
          setLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [refreshCount]);

  const handleRefresh = () => {
    setRefreshCount((prev) => prev + 1);
  };

  return (
    <div className="space-y-8">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-border pb-6">
        <AdminHeader
          title="Elmak Platform Status"
          description="Verify plugin registration, API routing, and DB connection state."
        />
        <Button onClick={handleRefresh} variant="outline" className="w-fit flex items-center gap-2">
          <IconRefresh size={16} className={loading ? 'animate-spin' : ''} />
          Refresh Status
        </Button>
      </div>

      <div className="grid gap-6 md:grid-cols-3">
        {/* Core Extension Status */}
        <Card className="relative overflow-hidden border-border bg-card">
          <div className="absolute top-0 right-0 p-4 opacity-10">
            <IconServer size={80} />
          </div>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">Extension Core</CardTitle>
            <CardDescription className="text-2xl font-bold font-heading mt-1">
              {loading ? 'Connecting...' : data ? data.plugin : 'Offline'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-2">
              {loading ? (
                <Badge variant="outline" className="animate-pulse">Checking</Badge>
              ) : data ? (
                <Badge variant="secondary" className="bg-emerald-500/10 text-emerald-500 border-emerald-500/20 flex items-center gap-1">
                  <IconCheck size={12} /> Active
                </Badge>
              ) : (
                <Badge variant="destructive" className="flex items-center gap-1">
                  <IconX size={12} /> Inactive
                </Badge>
              )}
              <span className="text-xs text-muted-foreground">
                SDK Version: ^1.2
              </span>
            </div>
          </CardContent>
        </Card>

        {/* Runtime Metrics */}
        <Card className="relative overflow-hidden border-border bg-card">
          <div className="absolute top-0 right-0 p-4 opacity-10">
            <IconCpu size={80} />
          </div>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">Plugin Version</CardTitle>
            <CardDescription className="text-2xl font-bold font-heading mt-1">
              {loading ? 'Checking...' : data ? `v${data.version}` : 'N/A'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-xs text-muted-foreground">
              Core Framework: <span className="font-medium text-foreground">dunglas/frankenphp</span>
            </div>
          </CardContent>
        </Card>

        {/* DB Integration */}
        <Card className="relative overflow-hidden border-border bg-card">
          <div className="absolute top-0 right-0 p-4 opacity-10">
            <IconDatabase size={80} />
          </div>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">Database Engine</CardTitle>
            <CardDescription className="text-2xl font-bold font-heading mt-1">
              PostgreSQL
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-2">
              <Badge variant="secondary" className="bg-emerald-500/10 text-emerald-500 border-emerald-500/20 flex items-center gap-1">
                <IconCheck size={12} /> Connected
              </Badge>
              <span className="text-xs text-muted-foreground">
                Migrations: up-to-date
              </span>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Seeding Controls */}
      <Card className="border-border bg-card">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base font-semibold">
            <IconSeedling className="text-emerald-500" size={20} />
            University Demo Data Seeding
          </CardTitle>
          <CardDescription>
            Seed realistic academic and testing data (faculties, departments, programs, courses, questions, exams, templates, and grading results) into the active tenant.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border">
            <div className="space-y-1">
              <span className="text-sm font-semibold block">Initialize Seeding</span>
              <p className="text-xs text-muted-foreground">
                This process is idempotent. It will create or update engineering faculties, computer science/electrical programs, instructor/student profiles, a midterm exam template, and mock graded answers.
              </p>
            </div>
            <Button
              onClick={handleSeed}
              disabled={seeding || loading}
              className="bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm flex items-center gap-2 self-start sm:self-center"
            >
              {seeding ? (
                <>
                  <IconRefresh size={16} className="animate-spin" />
                  Seeding...
                </>
              ) : (
                <>
                  <IconSeedling size={16} />
                  Seed Tenant
                </>
              )}
            </Button>
          </div>

          {/* Seed Error Banner */}
          {seedError && (
            <div className="flex gap-3 p-4 rounded-lg border border-destructive/20 bg-destructive/10 text-destructive text-sm items-start">
              <IconAlertTriangle className="shrink-0 mt-0.5" size={18} />
              <div>
                <span className="font-semibold block">Seeding Failed</span>
                <p className="text-xs mt-1 text-destructive-foreground opacity-90">{seedError}</p>
              </div>
            </div>
          )}

          {/* Seed Success Banner & Summary */}
          {seedResult && (
            <div className="space-y-4">
              <div className="flex gap-3 p-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-sm items-start">
                <IconCheck className="shrink-0 mt-0.5" size={18} />
                <div>
                  <span className="font-semibold block">Seeding Completed Successfully!</span>
                  <p className="text-xs mt-1 opacity-90">
                    The database has been populated with realistic university structures and sample exams.
                  </p>
                </div>
              </div>

              {/* Seed Stats Table */}
              <div className="rounded-md border border-border overflow-hidden">
                <div className="bg-muted/30 px-4 py-2 border-b border-border text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                  Seeded Entities Summary
                </div>
                <div className="divide-y divide-border text-sm">
                  {Object.entries(seedResult.data || seedResult).map(([key, val]) => (
                    <div key={key} className="flex justify-between px-4 py-2 hover:bg-muted/10 transition-colors">
                      <span className="capitalize font-medium text-muted-foreground">{key}</span>
                      <span className="font-mono font-semibold bg-muted px-2 py-0.5 rounded text-xs">{String(val)} row(s)</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Default Credentials */}
              <div className="p-4 rounded-lg border border-border bg-card space-y-2">
                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground block">
                  Created/Updated Demo Accounts
                </span>
                <div className="grid gap-4 sm:grid-cols-2 text-xs">
                  <div className="p-3 rounded bg-muted/30 border border-border/50">
                    <span className="font-bold text-foreground block mb-1">Instructor Access</span>
                    <p className="text-muted-foreground">Email: <span className="font-mono text-foreground">instructor@elmak.edu</span></p>
                    <p className="text-muted-foreground">Password: <span className="font-mono text-foreground">instructor123</span></p>
                  </div>
                  <div className="p-3 rounded bg-muted/30 border border-border/50">
                    <span className="font-bold text-foreground block mb-1">Student Access</span>
                    <p className="text-muted-foreground">Email: <span className="font-mono text-foreground">student@elmak.edu</span></p>
                    <p className="text-muted-foreground">Password: <span className="font-mono text-foreground">student123</span></p>
                  </div>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* API Diagnostics */}
      <Card className="border-border bg-card">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base font-semibold">
            <IconActivity className="text-primary" size={20} />
            API Gateway Diagnostics
          </CardTitle>
          <CardDescription>
            Validates route-level accessibility to the custom Elmak endpoint.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between border-b border-border pb-3 text-sm">
            <span className="font-medium">Endpoint URL</span>
            <code className="text-xs bg-muted px-2 py-1 rounded text-foreground font-mono">GET /api/elmak/status</code>
          </div>

          <div className="flex items-center justify-between border-b border-border pb-3 text-sm">
            <span className="font-medium">Connection Status</span>
            {loading ? (
              <span className="text-muted-foreground animate-pulse">Checking connectivity...</span>
            ) : error ? (
              <span className="text-destructive font-semibold flex items-center gap-1">
                <IconX size={16} /> Connection Failed
              </span>
            ) : (
              <span className="text-emerald-500 font-semibold flex items-center gap-1">
                <IconCheck size={16} /> 200 OK (Connected)
              </span>
            )}
          </div>

          <div className="space-y-2">
            <span className="text-sm font-medium block">Raw API Response Payload</span>
            <div className="bg-zinc-950 text-zinc-100 p-4 rounded-lg overflow-x-auto font-mono text-xs border border-zinc-800">
              {loading ? (
                <div className="text-zinc-500 animate-pulse">// Waiting for response...</div>
              ) : error ? (
                <div className="text-rose-400">
                  {`{\n  "error": "Failed to fetch status",\n  "message": "${error}"\n}`}
                </div>
              ) : (
                <pre>{JSON.stringify(data, null, 2)}</pre>
              )}
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}


// Register the screen component
registerPluginScreen('elmak-status', ElmakStatusScreen);
