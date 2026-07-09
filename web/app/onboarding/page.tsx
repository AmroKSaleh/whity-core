'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import {
  IconAlertCircle,
  IconArrowLeft,
  IconArrowRight,
  IconCheck,
  IconRocket,
} from '@tabler/icons-react';
import {
  SETTINGS_MANAGE,
  SYSTEM_TENANT_ID,
  RegistrySettingControl,
  fieldMetaFor,
  errorMessage,
  fieldErrorsFrom,
  type RegistryEntry,
  type SettingsMap,
} from '../(protected)/admin/settings/settings-shared';

/**
 * First-run onboarding wizard (WC-2b9d4f6a).
 *
 * A guided flow that walks a new operator through the core install parameters
 * (instance identity, sign-up policy, sign-in/SSO) and writes them through the
 * SAME endpoint as the Global Settings page — PATCH /api/v1/settings/global. It
 * is registry-driven: each step renders the controls for its keys straight from
 * the backend registry, so keys added later (email, storage, …) can join a step
 * by listing the key below.
 *
 * Access: writing global defaults requires settings:manage AND acting in the
 * system tenant (id 0); the backend rejects anyone else with 403, so this page
 * mirrors that gate rather than presenting a form the backend will reject.
 *
 * FIRST-RUN FLAG — COORDINATION POINT (backend not built yet):
 * A backend "instance configured / first-run" flag does NOT exist yet. The two
 * hooks below (`useInstanceConfigured` / `markInstanceConfigured`) isolate every
 * touch-point so wiring is a one-function change once the endpoint lands:
 *   - on mount, a configured instance should redirect away from the wizard;
 *   - on finish, completing the wizard should SET the flag.
 * Until then the check returns `null` (unknown → always show the wizard) and the
 * setter is a no-op. See the PR description for the backend coordination note.
 */

// ---------------------------------------------------------------------------
// First-run flag shim — replace the bodies once the backend endpoint exists.
// ---------------------------------------------------------------------------

/**
 * Whether the instance has already completed first-run setup.
 * TODO(WC-2b9d4f6a / backend): resolve from the real first-run flag, e.g.
 *   GET /api/v1/instance/status -> { configured: boolean }
 * and redirect a configured instance away from /onboarding. Returns `null`
 * (unknown) for now so the wizard always renders and never fakes completion.
 */
function useInstanceConfigured(): boolean | null {
  return null;
}

/**
 * Mark first-run setup complete.
 * TODO(WC-2b9d4f6a / backend): persist the first-run flag, e.g.
 *   POST /api/v1/instance/complete-setup
 * so the wizard is not shown again. No-op until the endpoint exists — the saved
 * global settings are the durable result; this only flips the "seen" flag.
 */
async function markInstanceConfigured(): Promise<void> {
  // Intentionally empty until the backend flag lands (see TODO above).
}

interface WizardStep {
  id: string;
  title: string;
  /** Registry keys rendered on this step, in order (empty for intro/review). */
  keys: readonly string[];
}

const STEPS: readonly WizardStep[] = [
  { id: 'welcome', title: 'Welcome', keys: [] },
  { id: 'general', title: 'Instance basics', keys: ['site_name', 'support_email', 'timezone', 'locale'] },
  { id: 'signup', title: 'Sign-up governance', keys: ['auth.self_registration_enabled', 'auth.registration_approval_required'] },
  { id: 'signin', title: 'Sign-in & SSO', keys: ['auth.sso_enabled'] },
  { id: 'review', title: 'Review & finish', keys: [] },
];

export default function OnboardingPage() {
  const router = useRouter();
  const { isLoading: authLoading, user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();
  const configured = useInstanceConfigured();

  const canManage = hasPermission(SETTINGS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  // Unauthenticated visitors are sent to sign in (there is no protected-layout
  // guard on this standalone route).
  useEffect(() => {
    if (!authLoading && !user) {
      router.replace('/login');
    }
  }, [authLoading, user, router]);

  // TODO(first-run flag): once `useInstanceConfigured` reads the real flag, an
  // already-configured instance should be redirected out of the wizard here.
  useEffect(() => {
    if (configured === true) {
      router.replace('/admin/settings/global');
    }
  }, [configured, router]);

  if (authLoading || capsLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
      </div>
    );
  }

  if (!user) {
    return null;
  }

  if (!isSystemTenant || !canManage) {
    return (
      <div className="flex min-h-screen items-center justify-center p-6">
        <div className="flex max-w-md flex-col items-center rounded-2xl border border-border bg-card p-8 text-center shadow-sm">
          <div className="mb-4 rounded-full bg-destructive/10 p-4 text-destructive">
            <IconAlertCircle size={40} />
          </div>
          <h1 className="mb-2 text-xl font-bold">Setup is operator-only</h1>
          <p className="mb-6 text-sm text-muted-foreground">
            First-run setup configures instance-wide defaults, so it can only be run by an operator
            of the system tenant. Sign in with the operator account to continue.
          </p>
          <Button asChild variant="outline">
            <Link href="/dashboard">Go to dashboard</Link>
          </Button>
        </div>
      </div>
    );
  }

  return <OnboardingWizard />;
}

function OnboardingWizard() {
  const router = useRouter();
  const { addToast } = useToast();

  const { data, loading, error } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load instance settings'));
    }
    return body.data;
  }, []);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const byKey = useMemo(() => new Map(registry.map((e) => [e.key, e])), [registry]);

  // Only show a settings step when the backend actually publishes at least one
  // of its keys; intro/review (no keys) always show. So a step for keys not yet
  // on this instance (e.g. SSO before it lands) is skipped rather than rendered
  // empty, and appears automatically once the backend adds the key.
  const activeSteps = useMemo(
    () => STEPS.filter((s) => s.keys.length === 0 || s.keys.some((k) => byKey.has(k))),
    [byKey]
  );

  const [stepIndex, setStepIndex] = useState(0);
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  // Clamp if the active-step set shrank (e.g. data loaded after first render).
  const safeIndex = Math.min(stepIndex, activeSteps.length - 1);
  const step = activeSteps[safeIndex];
  const isFirst = safeIndex === 0;
  const isReview = step.id === 'review';

  const valueOf = (entry: RegistryEntry): string =>
    draft[entry.key] ?? global?.[entry.key] ?? entry.default;

  const setField = (key: string, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  // Only keys whose draft value differs from the stored/effective value are sent.
  const changedKeys = useMemo(() => {
    if (!global) return [];
    return Object.keys(draft).filter((key) => {
      const entry = byKey.get(key);
      const current = global[key] ?? entry?.default ?? '';
      return draft[key].trim() !== current.trim();
    });
  }, [draft, global, byKey]);

  const goNext = () => setStepIndex(Math.min(safeIndex + 1, activeSteps.length - 1));
  const goBack = () => setStepIndex(Math.max(safeIndex - 1, 0));

  const handleFinish = async () => {
    if (!global) return;

    // No changes: still complete setup (the operator accepted the defaults).
    if (changedKeys.length === 0) {
      await markInstanceConfigured();
      addToast('Setup complete. You can fine-tune everything in Global Settings.', 'success');
      router.push('/admin/settings/global');
      return;
    }

    const settings: Record<string, string> = {};
    for (const key of changedKeys) {
      settings[key] = draft[key].trim();
    }

    setSaving(true);
    setFieldErrors({});
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', {
        body: { settings },
      });
      if (patchError) {
        const errors = fieldErrorsFrom(patchError);
        setFieldErrors(errors);
        // Jump back to the earliest step that owns a rejected field.
        const firstBadStep = activeSteps.findIndex((s) => s.keys.some((k) => k in errors));
        if (firstBadStep >= 0) setStepIndex(firstBadStep);
        throw new Error(errorMessage(patchError, 'Could not save your settings'));
      }
      await markInstanceConfigured();
      addToast('Setup complete. Your instance is ready.', 'success');
      router.push('/admin/settings/global');
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Could not save your settings', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center bg-background px-4 py-10">
      <div className="w-full max-w-2xl space-y-8">
        <header className="space-y-4">
          <div className="flex items-center gap-3">
            <span className="rounded-lg bg-primary/10 p-2 text-primary">
              <IconRocket className="h-5 w-5" />
            </span>
            <div>
              <h1 className="text-2xl font-bold text-foreground">Set up your instance</h1>
              <p className="text-sm text-muted-foreground">
                A few defaults to get started. You can change any of these later in Global Settings.
              </p>
            </div>
          </div>
          <Stepper steps={activeSteps} current={safeIndex} />
        </header>

        <Card className="border border-border bg-card shadow-sm">
          <CardHeader>
            <CardTitle className="text-lg font-bold font-heading">
              <h2>{step.title}</h2>
            </CardTitle>
            <CardDescription className="text-sm">{stepDescription(step.id)}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            {loading || !global ? (
              <div className="space-y-4">
                {Array.from({ length: 3 }).map((_, i) => (
                  <div key={i} className="h-12 animate-pulse rounded-md bg-muted/40" />
                ))}
              </div>
            ) : step.id === 'welcome' ? (
              <WelcomeStep />
            ) : isReview ? (
              <ReviewStep
                changedKeys={changedKeys}
                valueForKey={(key) => {
                  const entry = byKey.get(key);
                  return entry ? valueOf(entry) : (draft[key] ?? '');
                }}
              />
            ) : (
              <div className="space-y-5">
                {step.keys
                  .map((key) => byKey.get(key))
                  .filter((entry): entry is RegistryEntry => entry !== undefined)
                  .map((entry) => (
                    <RegistrySettingControl
                      key={entry.key}
                      entry={entry}
                      idPrefix="onboarding"
                      value={valueOf(entry)}
                      error={fieldErrors[entry.key]}
                      onChange={(value) => setField(entry.key, value)}
                    />
                  ))}
              </div>
            )}
          </CardContent>
        </Card>

        <div className="flex items-center justify-between">
          <Button
            variant="ghost"
            onClick={goBack}
            disabled={isFirst || saving}
            className="gap-2"
            data-testid="onboarding-back"
          >
            <IconArrowLeft className="h-4 w-4" />
            Back
          </Button>

          {isReview ? (
            <Button
              onClick={handleFinish}
              disabled={saving || !global}
              className="gap-2"
              data-testid="onboarding-finish"
            >
              <IconCheck className="h-4 w-4" />
              {saving ? 'Saving…' : 'Finish setup'}
            </Button>
          ) : (
            <Button
              onClick={goNext}
              disabled={loading || !global}
              className="gap-2"
              data-testid="onboarding-next"
            >
              {isFirst ? 'Get started' : 'Continue'}
              <IconArrowRight className="h-4 w-4" />
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

function stepDescription(id: string): string {
  switch (id) {
    case 'welcome':
      return 'Welcome — this short wizard configures the instance-wide defaults for your deployment.';
    case 'general':
      return 'How your instance identifies itself to every tenant.';
    case 'signup':
      return 'Decide whether people can register themselves, and whether new accounts need approval.';
    case 'signin':
      return 'Federated sign-in across the whole instance.';
    case 'review':
      return 'Review the changes below, then finish. Everything remains editable in Global Settings.';
    default:
      return '';
  }
}

function Stepper({ steps, current }: { steps: readonly WizardStep[]; current: number }) {
  return (
    <ol className="flex items-center gap-2" aria-label="Setup progress">
      {steps.map((step, i) => {
        const state = i < current ? 'done' : i === current ? 'active' : 'upcoming';
        return (
          <li key={step.id} className="flex flex-1 items-center gap-2">
            <span
              data-state={state}
              className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=done]:border-primary data-[state=done]:bg-primary/15 data-[state=done]:text-primary data-[state=upcoming]:border-border data-[state=upcoming]:text-muted-foreground"
            >
              {state === 'done' ? <IconCheck className="h-3.5 w-3.5" /> : i + 1}
            </span>
            {i < steps.length - 1 && (
              <span
                data-state={state}
                className="h-px flex-1 bg-border data-[state=done]:bg-primary/40"
              />
            )}
          </li>
        );
      })}
    </ol>
  );
}

function WelcomeStep() {
  return (
    <div className="space-y-3 text-sm text-muted-foreground">
      <p>
        This is a fresh instance. The next few steps set the defaults every tenant inherits — the
        instance name and support contact, whether people may sign themselves up, and how sign-in
        works.
      </p>
      <p>
        None of it is permanent: each setting can be changed at any time from{' '}
        <span className="font-medium text-foreground">Global Settings</span>. Email, storage and SSO
        providers can be configured there once you&rsquo;re set up.
      </p>
    </div>
  );
}

function ReviewStep({
  changedKeys,
  valueForKey,
}: {
  changedKeys: string[];
  valueForKey: (key: string) => string;
}) {
  if (changedKeys.length === 0) {
    return (
      <p className="text-sm text-muted-foreground" data-testid="onboarding-review-empty">
        You&rsquo;ve kept the recommended defaults for everything. Finish to complete setup — you can
        adjust any setting later in Global Settings.
      </p>
    );
  }

  return (
    <dl className="divide-y divide-border" data-testid="onboarding-review-list">
      {changedKeys.map((key) => {
        const value = valueForKey(key);
        return (
          <div key={key} className="flex items-center justify-between gap-4 py-2.5">
            <dt className="text-sm font-medium text-foreground">{fieldMetaFor(key).label}</dt>
            <dd className="text-sm text-muted-foreground">{formatReviewValue(value)}</dd>
          </div>
        );
      })}
    </dl>
  );
}

function formatReviewValue(value: string): string {
  if (value === 'true') return 'On';
  if (value === 'false') return 'Off';
  return value.trim() === '' ? '—' : value;
}
