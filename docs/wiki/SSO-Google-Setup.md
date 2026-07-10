# Sign in with Google (SSO / OIDC)

How to enable "Sign in with Google" for a Whity instance, end to end — from the
Google OAuth client to the Whity provider config to the first login. The same
steps apply to any OpenID Connect provider (Microsoft, generic OIDC); only the
Google-specific console steps differ.

Related: [Permission System](PERMISSION_SYSTEM.md), [Tenant Isolation](TENANT_ISOLATION.md).

---

## How Whity's federated login works

Whity federates via **OpenID Connect** (Authorization Code + PKCE). Two public
routes drive the browser flow:

- `GET /api/v1/auth/sso/{provider}/start` — begins the flow (redirects to Google).
- `GET /api/v1/auth/sso/{provider}/callback` — Google returns here; Whity verifies
  the ID token and mints a session.

A provider is a row in `identity_providers`, and **where you configure it decides
its trust tier**:

| Configured at… | Trust tier | Meaning |
|---|---|---|
| **System tenant (id 0)**, by the operator (superuser) | **GLOBAL-TRUST** | The instance-wide "Sign in with Google". Its verified email is authoritative across the whole profile namespace: it can link to an existing account by verified email, or provision a brand-new one. |
| **A specific tenant**, by that tenant's admin | **TENANT-TRUST** | The tenant's own bring-your-own IdP. Confined to that tenant — it can never reach another tenant's accounts. |

For the usual consumer-style "Sign in with Google", configure it at the **system
tenant (0)** as the superuser.

---

## Prerequisites

- Operator access: the system-tenant superuser (`superuser@example.com` in dev).
- The permission `auth-providers:manage` (the superuser role has it).
- **`APP_URL`** set on the backend to the app's public base URL. It builds the
  OAuth `redirect_uri` and must exactly match what you register with Google.
  In dev it defaults to `http://localhost:3000` (the Next proxy); in
  staging/production set it to your real `https://…` origin.

---

## Step 1 — Create the Google OAuth client

In the [Google Cloud Console](https://console.cloud.google.com):

1. Create or select a project.
2. **APIs & Services → OAuth consent screen**:
   - User type **External** (or **Internal** for a Google Workspace org).
   - Set app name + support/developer emails.
   - Default scopes (`openid`, `email`, `profile`) are sufficient — no sensitive
     scopes needed.
   - While the app is in *Testing*, add your Google account under **Test users**
     (an unverified external app only admits listed test users).
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   - Application type **Web application**.
   - **Authorized redirect URI** — must match `APP_URL` exactly:
     ```
     <APP_URL>/api/v1/auth/sso/google/callback
     ```
     Dev: `http://localhost:3000/api/v1/auth/sso/google/callback`
     Prod: `https://app.example.com/api/v1/auth/sso/google/callback`
   - "Authorized JavaScript origins" is not required (this is a server-side
     redirect flow).
4. Copy the **Client ID** and **Client secret**.

---

## Step 2 — Configure the provider in Whity

Create the provider with these values (as the **system-tenant superuser** for a
global "Sign in with Google", or as a **tenant admin** for that tenant's own IdP):

| Field | Value |
|---|---|
| `provider_key` | `google` (allowed: `google`, `microsoft`, `oidc`) |
| `display_name` | `Google` (shown on the login button) |
| `client_id` | *(from step 1)* |
| `client_secret` | *(from step 1 — stored encrypted; never returned by the API)* |
| `issuer` | `https://accounts.google.com` (must be https) |
| `discovery_url` | *(optional; defaults to `<issuer>/.well-known/openid-configuration`)* |
| `scopes` | `openid email profile` (default) |
| `enabled` | `true` |

Two ways to set it:

**a) Admin UI** — the SSO settings page (`/admin/settings/sso`), signed in as the
operator. Paste the client id/secret and save. The secret is write-only: the UI
shows "secret set" and lets you replace it, but never displays it.

**b) Admin API** — `POST /api/v1/identity-providers` (requires
`auth-providers:manage`; system-tenant context for a global provider):

```jsonc
{
  "provider_key": "google",
  "display_name": "Google",
  "client_id": "…apps.googleusercontent.com",
  "client_secret": "…",
  "issuer": "https://accounts.google.com",
  "scopes": "openid email profile",
  "enabled": true
}
```

Full CRUD (all `auth-providers:manage`, tenant-scoped):

| Method | Path |
|---|---|
| `GET` | `/api/v1/identity-providers` — list (secret omitted; only a `has_secret` flag) |
| `POST` | `/api/v1/identity-providers` — create |
| `PATCH` | `/api/v1/identity-providers/{id}` — update (omit `client_secret` to keep the existing one) |
| `DELETE` | `/api/v1/identity-providers/{id}` — delete |

---

## Step 3 — Confirm SSO is enabled

SSO has a global kill-switch, `auth.sso_enabled` (Global Settings), default
**`true`** — so it is already on. Setting it to `false` disables federated
sign-in instance-wide (both operator and tenant IdPs) and empties the login
button list. Manage it under **Admin → Settings → Global**.

---

## Step 4 — Sign in

- The login page (`/login`) renders a **"Sign in with Google"** button for each
  enabled provider, sourced from the public endpoint:

  `GET /api/v1/auth/sso/providers` → `{ "data": [ { "provider_key": "google",
  "display_name": "Google" } ] }` (display-safe fields only; empty when SSO is
  disabled).

- The button links to `GET /api/v1/auth/sso/google/start`. After Google consent,
  the callback lands the user on `/dashboard`, on `/login?sso=select` (choose a
  tenant when they belong to several), or on `/login?sso_error=<reason>`.

---

## First-login behaviour

For a Google-verified email, a **global-trust** (operator) Google will:

- **link** to an existing profile that owns that verified email, or
- **provision** a new passwordless profile if none exists (sign-in is then only
  possible via the IdP — there is no password).

A brand-new user with **no tenant membership** is bounced (`no_membership`): this
is the sign-up governance gate. To auto-place users into a workspace, that tenant
registers and **DNS-verifies** its email domain (with auto-provision on), which
triggers domain-claim JIT onboarding. See "Instance governance" below.

For a **tenant-trust** IdP, a person is onboarded only if they are already a
member (or explicitly invited) of that tenant, or the tenant has a DNS-verified
claim on the email's domain. A tenant IdP can never reach another tenant.

---

## Instance governance knobs (Global Settings)

| Setting | Default | Effect |
|---|---|---|
| `auth.sso_enabled` | `true` | Master SSO kill-switch. |
| `auth.self_registration_enabled` | `false` | Whether public self-registration (new workspace signup) is open. |
| `auth.registration_approval_required` | `true` | Whether new signups start pending admin approval. |

Email-domain auto-onboarding (`tenant_email_domains`) requires the tenant to
**prove domain ownership** via a DNS TXT challenge before it will auto-provision
memberships — a self-asserted claim does nothing.

---

## Troubleshooting

The callback bounces to `/login?sso_error=<reason>`:

| Reason | Meaning / fix |
|---|---|
| `sso_disabled` | `auth.sso_enabled` is `false`. Enable it in Global Settings. |
| `provider_unavailable` | No enabled provider for this host/tenant, or the discovery/token endpoint could not be reached, or the client-secret failed to decrypt. Check the provider config + `ENCRYPTION_KEY`. |
| `email_unverified` | The IdP did not assert a verified email — Whity never links/provisions on an unverified address. |
| `link_conflict` | The email matches a local account whose email is unverified — refused (anti-takeover). |
| `no_account` / `no_membership` | Authenticated, but the person has no membership in a reachable tenant (see first-login behaviour). |
| `state_mismatch` / `expired` | The flow-state cookie was missing, expired (10 min), or the `state` did not match — restart the flow. |
| `denied` | The user declined consent at Google. |
| `failed` | Token exchange or ID-token verification failed — usually a redirect-URI / client-id / client-secret mismatch. Re-check that the Google **Authorized redirect URI** exactly equals `<APP_URL>/api/v1/auth/sso/google/callback`. |

Common gotchas:
- **Redirect URI mismatch** — the single most common failure. It must match
  `APP_URL` byte-for-byte (scheme, host, port, path).
- **`APP_URL` unset** — the `redirect_uri` is then malformed. Set it (dev default
  `http://localhost:3000`).
- **App in Testing** — add your account under Google's *Test users*, or publish
  the consent screen.

---

## Production notes

- Set `APP_URL` to the public `https://` origin and register the matching
  `https://…/api/v1/auth/sso/google/callback` redirect URI with Google.
- Keep `ENCRYPTION_KEY` stable — client secrets are encrypted with it at rest.
- Outbound OIDC fetches (discovery/JWKS/token) are made through an SSRF-guarded
  client: the target must be an https, publicly-routable host.
