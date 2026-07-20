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
  IconExternalLink,
  IconHelpCircle,
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
 * First-run onboarding wizard (WC-2b9d4f6a, WC-onboarding-full-setup).
 *
 * A guided, full-instance setup flow that walks a new operator through the
 * instance-wide defaults — identity, sign-up policy, sign-in/SSO, outbound email
 * (SMTP), and file storage — writing them through the SAME endpoint as the Global
 * Settings page (PATCH /api/v1/settings/global). It is registry-driven: each step
 * renders the controls for its keys straight from the backend registry, and a
 * step whose keys the backend does not publish is skipped automatically.
 *
 * Every configuration step carries a collapsible HELPER GUIDE (see STEP_GUIDES)
 * with doc-sourced, provider-specific guidance — e.g. where to find SMTP
 * credentials (cPanel / Google Workspace / Amazon SES), the port↔encryption
 * pairing, and S3 endpoint/bucket/key conventions — so an operator can complete
 * setup without leaving the wizard to hunt through the manual.
 *
 * Secrets that are write-only server-side (the SMTP password) are NOT collected
 * here; the email guide deep-links to the Email settings page, which owns the
 * encrypted password write and the "send test email" round-trip.
 *
 * Access: writing global defaults requires settings:manage AND acting in the
 * system tenant (id 0); the backend rejects anyone else with 403, so this page
 * mirrors that gate rather than presenting a form the backend will reject.
 *
 * First-run flag: completion is recorded via markInstanceConfigured()
 * (POST /api/v1/instance/complete-setup); the protected layout routes an
 * unconfigured instance's operator here until it is set (WC-instance-first-run).
 * The wizard stays directly accessible afterwards, so setup is re-runnable.
 */

// ---------------------------------------------------------------------------
// First-run flag shim — replace the bodies once the backend endpoint exists.
// ---------------------------------------------------------------------------

/**
 * Mark first-run setup complete (WC-instance-first-run): POST
 * /api/v1/instance/complete-setup. Idempotent server-side. Throws on failure so
 * the caller can surface it — the saved global settings are the durable result;
 * this flips the operator-visible "seen" flag so the wizard is not shown again.
 */
async function markInstanceConfigured(): Promise<void> {
  const { error } = await api.POST('/api/v1/instance/complete-setup', {});
  if (error) {
    throw new Error(errorMessage(error, 'Could not finalize first-run setup'));
  }
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
  {
    id: 'email',
    title: 'Email (SMTP)',
    keys: [
      'mail.transport',
      'mail.smtp.host',
      'mail.smtp.port',
      'mail.smtp.encryption',
      'mail.smtp.username',
      'mail.from_address',
      'mail.from_name',
    ],
  },
  {
    id: 'storage',
    title: 'File storage',
    keys: [
      'storage.driver',
      'storage.s3.endpoint',
      'storage.s3.region',
      'storage.s3.bucket',
      'storage.s3.access_key',
      'storage.s3.path_style',
      'storage.s3.public_base_url',
    ],
  },
  { id: 'review', title: 'Review & finish', keys: [] },
];

/**
 * Keys that are only relevant once a gating field has a particular value, so the
 * wizard reveals them conditionally (mirroring the dedicated settings pages):
 * SMTP detail fields appear only for the `smtp` transport; S3 fields appear only
 * once a non-local storage driver is chosen. A field hidden by this map is never
 * rendered AND never counted as a change (its draft is left at the stored value).
 */
const CONDITIONAL_KEYS: Record<string, { gate: string; showWhen: (value: string) => boolean }> = {
  'mail.smtp.host': { gate: 'mail.transport', showWhen: (v) => v === 'smtp' },
  'mail.smtp.port': { gate: 'mail.transport', showWhen: (v) => v === 'smtp' },
  'mail.smtp.encryption': { gate: 'mail.transport', showWhen: (v) => v === 'smtp' },
  'mail.smtp.username': { gate: 'mail.transport', showWhen: (v) => v === 'smtp' },
  'mail.from_address': { gate: 'mail.transport', showWhen: (v) => v === 'smtp' },
  'mail.from_name': { gate: 'mail.transport', showWhen: (v) => v === 'smtp' },
  'storage.s3.endpoint': { gate: 'storage.driver', showWhen: (v) => v !== '' && v !== 'local' },
  'storage.s3.region': { gate: 'storage.driver', showWhen: (v) => v !== '' && v !== 'local' },
  'storage.s3.bucket': { gate: 'storage.driver', showWhen: (v) => v !== '' && v !== 'local' },
  'storage.s3.access_key': { gate: 'storage.driver', showWhen: (v) => v !== '' && v !== 'local' },
  'storage.s3.path_style': { gate: 'storage.driver', showWhen: (v) => v !== '' && v !== 'local' },
  'storage.s3.public_base_url': { gate: 'storage.driver', showWhen: (v) => v !== '' && v !== 'local' },
};

interface GuideSection {
  heading?: string;
  lines: readonly string[];
}

interface StepGuideContent {
  /** One-line teaser shown on the collapsed "Need help?" toggle. */
  summary: string;
  sections: readonly GuideSection[];
  /** Optional deep-link to the admin page that owns the fuller flow. */
  link?: { href: string; label: string };
}

/**
 * Doc-sourced helper guides, one per configuration step. Content is distilled
 * from docs/wiki (Email-SMTP-Setup, SSO-Google-Setup) so an operator can find the
 * information a field needs — where SMTP credentials live, the port↔encryption
 * pairing, S3 conventions — without leaving the wizard.
 */
const STEP_GUIDES: Record<string, StepGuideContent> = {
  signup: {
    summary: 'What open sign-up and approval mean for your instance.',
    sections: [
      {
        lines: [
          'Public sign-up lets anyone create an account from the sign-in screen. It is OFF by default — an operator-provisioned instance opens it deliberately.',
          'Require admin approval holds each new account as “pending” until an administrator approves it. Leave it on when sign-up is open so nobody gets access unreviewed.',
        ],
      },
    ],
  },
  signin: {
    summary: 'The SSO master switch; add individual providers on the SSO page.',
    sections: [
      {
        lines: [
          'This is the instance-wide master switch for federated sign-in. When off, every configured identity provider is disabled.',
          'Individual providers (e.g. Google) are added on the SSO settings page: create an OAuth client in your provider, set the redirect URI it gives you, then paste the client ID/secret there.',
        ],
      },
    ],
    link: { href: '/admin/settings/sso', label: 'Open SSO settings' },
  },
  email: {
    summary: 'How to connect an SMTP mailbox — and where to find the details.',
    sections: [
      {
        heading: 'Transport',
        lines: [
          '“None” disables email entirely. “Log” writes messages to the app log (handy while testing). “SMTP” sends real email through your mail provider.',
        ],
      },
      {
        heading: 'SMTP connection',
        lines: [
          'Host — your provider’s SMTP server, e.g. mail.yourdomain.com (cPanel), smtp.gmail.com (Google), or email-smtp.<region>.amazonaws.com (Amazon SES).',
          'Port + Encryption go together: 587 → TLS (STARTTLS) or 465 → SSL. Use a hostname the TLS certificate actually covers.',
          'Username is usually the full email address of the mailbox you send from.',
          'From address / name is what recipients see the mail come from.',
        ],
      },
      {
        heading: 'Where to find your credentials',
        lines: [
          'cPanel: Email Accounts → Connect Devices shows the host, ports and username.',
          'Google Workspace / Gmail: create an App Password (with 2-Step Verification on) and use it as the SMTP password.',
          'Amazon SES: create SMTP credentials in the SES console (these are distinct from your AWS keys).',
        ],
      },
      {
        heading: 'Password & test send',
        lines: [
          'The SMTP password is stored encrypted and set on the Email settings page, which also has a “send test email” button to verify the connection end-to-end.',
        ],
      },
    ],
    link: { href: '/admin/settings/email', label: 'Open Email settings (password + test send)' },
  },
  storage: {
    summary: 'Keep files on local disk, or point at an S3-compatible object store.',
    sections: [
      {
        heading: 'Driver',
        lines: [
          '“local” stores uploaded files on the server’s disk — fine for a single-node deployment.',
          'For durability or multi-node, use an S3-compatible object store (AWS S3, MinIO, Cloudflare R2, Backblaze B2, …) by setting the driver to “s3”.',
        ],
      },
      {
        heading: 'S3 settings',
        lines: [
          'Endpoint — the object store’s base URL (e.g. https://s3.amazonaws.com, or your MinIO/R2 endpoint).',
          'Region / Bucket — as created in your provider’s console.',
          'Access key — the public key ID. The matching SECRET key is supplied via the deployment environment and is never stored here.',
          'Path-style addressing — turn on for most self-hosted gateways (MinIO); AWS uses virtual-hosted style.',
          'Public base URL — set only if assets are served from a different (CDN) host than the endpoint.',
        ],
      },
    ],
  },
};

export default function OnboardingPage() {
  const router = useRouter();
  const { isLoading: authLoading, user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();

  const canManage = hasPermission(SETTINGS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  // Unauthenticated visitors are sent to sign in (there is no protected-layout
  // guard on this standalone route).
  useEffect(() => {
    if (!authLoading && !user) {
      router.replace('/login');
    }
  }, [authLoading, user, router]);

  // The wizard is directly accessible even after setup completes (re-runnable),
  // so there is no "already configured → redirect away" here. The first-run
  // FUNNEL — auto-routing an operator INTO the wizard until the instance is
  // configured — lives in the protected layout (WC-instance-first-run).

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
  const stepGuide = STEP_GUIDES[step.id];

  const valueOf = (entry: RegistryEntry): string =>
    draft[entry.key] ?? global?.[entry.key] ?? entry.default;

  // Current effective value for any key (draft > stored > registry default),
  // used to evaluate conditional-field gates.
  const currentValueOf = (key: string): string =>
    draft[key] ?? global?.[key] ?? byKey.get(key)?.default ?? '';

  // A conditional field is shown only when its gate value permits it (e.g. SMTP
  // detail fields appear only for the `smtp` transport).
  const isKeyVisible = (key: string): boolean => {
    const cond = CONDITIONAL_KEYS[key];
    return !cond || cond.showWhen(currentValueOf(cond.gate));
  };

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
      setSaving(true);
      try {
        await markInstanceConfigured();
        addToast('Setup complete. You can fine-tune everything in Settings.', 'success');
        router.push('/admin/settings');
      } catch (err) {
        addToast(err instanceof Error ? err.message : 'Could not finalize setup', 'error');
      } finally {
        setSaving(false);
      }
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
      router.push('/admin/settings');
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
                {stepGuide && <StepGuide content={stepGuide} />}
                {step.keys
                  .filter(isKeyVisible)
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
    case 'email':
      return 'Connect a mailbox so the instance can send verification, invitation and notification email.';
    case 'storage':
      return 'Where uploaded files (documents, branding, exports) are kept.';
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

/**
 * Collapsible, doc-sourced helper guide shown above a step's fields. Uses a
 * native <details> element so it needs no extra dependency and stays keyboard-
 * and screen-reader-accessible. Internal deep-links open in a new tab so the
 * operator never loses their place in the wizard.
 */
function StepGuide({ content }: { content: StepGuideContent }) {
  return (
    <details
      className="group rounded-lg border border-border bg-muted/30"
      data-testid="onboarding-guide"
    >
      <summary className="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 text-sm">
        <IconHelpCircle className="h-4 w-4 shrink-0 text-primary" aria-hidden />
        <span className="flex-1 font-medium text-foreground">
          Need help? <span className="font-normal text-muted-foreground">{content.summary}</span>
        </span>
        <span className="shrink-0 text-xs text-muted-foreground group-open:hidden">Show</span>
        <span className="hidden shrink-0 text-xs text-muted-foreground group-open:inline">Hide</span>
      </summary>
      <div className="space-y-3 border-t border-border px-3 py-3 text-sm text-muted-foreground">
        {content.sections.map((section, i) => (
          <div key={i} className="space-y-1">
            {section.heading !== undefined && (
              <p className="font-medium text-foreground">{section.heading}</p>
            )}
            <ul className="list-disc space-y-1 pl-5">
              {section.lines.map((line, j) => (
                <li key={j}>{line}</li>
              ))}
            </ul>
          </div>
        ))}
        {content.link !== undefined && (
          <Link
            href={content.link.href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
          >
            {content.link.label}
            <IconExternalLink className="h-3.5 w-3.5" aria-hidden />
          </Link>
        )}
      </div>
    </details>
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
        We&rsquo;ll also connect <span className="font-medium text-foreground">email (SMTP)</span> and{' '}
        <span className="font-medium text-foreground">file storage</span> — each step has a short guide
        that points you to the details it needs. None of it is permanent: every setting stays editable
        later from <span className="font-medium text-foreground">Global Settings</span>.
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
