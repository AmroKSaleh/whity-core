# Email (SMTP) Setup

How to configure outbound email for a Whity instance, end to end — from an SMTP
mailbox (e.g. a cPanel account) to the Whity mail settings to a verified test
send. The same steps apply to any SMTP provider (cPanel, Google Workspace,
Microsoft 365, Amazon SES, Postmark, a self-hosted relay); only the place you
read the credentials from differs.

Related: [Permission System](PERMISSION_SYSTEM.md), [Tenant Isolation](TENANT_ISOLATION.md).

---

## How Whity sends mail

Email is **operator-configured for the whole instance** and **best-effort**: it
is a side channel, so a misconfigured or unreachable server never crashes the
app — it degrades to sending nothing.

- The transport is chosen by the `mail.transport` global setting: `none`
  (default, email off), `log` (write to the app log, for debugging), or `smtp`.
- The `smtp` transport speaks standard SMTP submission:
  `EHLO → [STARTTLS] → [AUTH LOGIN] → MAIL FROM → RCPT TO → DATA → QUIT`.
- All mail settings are **global-only** (system tenant, id 0). A regular tenant
  admin can never see or change them.
- The **SMTP password is write-only**: it is stored encrypted at rest and is
  never returned by any API or shown in the UI again.

Mail settings live in the **`mail.*`** global settings and are edited by the
operator (a superuser acting in the **system tenant, id 0**) via **Admin →
Settings → Email**, or the settings API.

| Setting | Meaning |
|---|---|
| `mail.transport` | `none` \| `log` \| `smtp` |
| `mail.smtp.host` | SMTP server hostname |
| `mail.smtp.port` | `465` (SSL) or `587` (STARTTLS) |
| `mail.smtp.encryption` | `ssl` (implicit TLS, port 465) · `tls` (STARTTLS, port 587) · `none` (plaintext, dev only) |
| `mail.smtp.username` | usually the full email address |
| `mail.from_address` | the visible From address (must be a real mailbox on the domain) |
| `mail.from_name` | the visible From display name |
| SMTP password | write-only, encrypted at rest — set via the password field / `PUT /mail/smtp-password` |
| `mail.events.*` | per-notification toggles (welcome / approval / invitation / verification) |

> **TLS is verified.** Whity validates the server's certificate and does **not**
> allow a silent downgrade. Always use a hostname the certificate actually
> covers (see the cPanel note below) — pointing at a bare IP or a hostname the
> cert doesn't match will fail the connection.

---

## Setting it up with cPanel

cPanel gives you standard SMTP credentials; you just map them onto the `mail.*`
settings.

### 1. Create (or pick) the mailbox

1. cPanel → **Email Accounts** → **Create** (e.g. `no-reply@yourdomain.com`),
   set a strong password.
2. Open that account's **Connect Devices** (aka *Mail Client Configuration*).
   Use the **Secure SSL/TLS Settings** block. Note:
   - **Outgoing Server (SMTP)** — usually `mail.yourdomain.com`
   - **SMTP Port** — `465` (SSL) and/or `587` (STARTTLS)
   - **Username** — the **full email address**, not just the local part

### 2. Map cPanel → Whity

| Whity field | Value from cPanel |
|---|---|
| **Transport** | `SMTP` |
| **SMTP host** | `mail.yourdomain.com` (the FQDN from *Connect Devices*) |
| **SMTP port** | `465` **or** `587` |
| **Encryption** | `465` → **SSL** · `587` → **TLS** |
| **Username** | full address, e.g. `no-reply@yourdomain.com` |
| **Password** | the mailbox password (write-only field) |
| **From address** | a real mailbox on that domain, e.g. `no-reply@yourdomain.com` |
| **From name** | e.g. `Your Company` |

Then click **Send test** and check the recipient inbox.

### cPanel gotchas (these bite people)

- **Use the hostname the TLS cert covers.** cPanel AutoSSL covers
  `mail.yourdomain.com`, so use that. A raw IP or the shared node hostname
  (`serverN.host.com`) won't match the cert and the connection is rejected —
  symptom: *"SMTP connect failed"* or *"STARTTLS negotiation failed"* on the test.
- **Prefer 465 / SSL.** Most reliable on cPanel. Try `587` / TLS only if 465 is
  blocked. Port `25` is almost always blocked outbound — don't use it.
- **From address must belong to the domain.** cPanel's Exim rejects or rewrites
  a `From:` that isn't a real mailbox on the server. Keep `From address` = the
  authenticated mailbox (or another address on the same domain).
- **Deliverability:** in cPanel → **Email Deliverability**, make sure **SPF** and
  **DKIM** are green for the domain, or mail may land in spam.
- Make sure the Whity server can reach the cPanel host on 465/587 (outbound
  firewall).

---

## Doing it via the API

All three endpoints require `settings:manage` **and** the system tenant (id 0).

```bash
# 1. plaintext config (transport + host/port/encryption/from)
PATCH /api/v1/settings/global
{ "settings": {
    "mail.transport": "smtp",
    "mail.smtp.host": "mail.yourdomain.com",
    "mail.smtp.port": "465",
    "mail.smtp.encryption": "ssl",
    "mail.smtp.username": "no-reply@yourdomain.com",
    "mail.from_address": "no-reply@yourdomain.com",
    "mail.from_name": "Your Company"
} }

# 2. the write-only password (204 No Content; never returned)
PUT /api/v1/settings/mail/smtp-password
{ "password": "the-mailbox-password" }        # send null to clear it

# 3. check status (transport + whether a password is stored)
GET /api/v1/settings/mail/status
# → { "data": { "transport": "smtp", "has_smtp_password": true } }

# 4. send a test message via the current transport
POST /api/v1/settings/mail/test
{ "to": "you@example.com" }                    # → { "data": { "sent": true } }
```

The test endpoint returns **422** when email isn't configured (transport `none`
or the SMTP config is incomplete) and **502** on a transport failure (the SMTP
error detail is written to the server log, never returned to the client).

---

## Testing locally without a real server (Mailpit)

The dev stack ships an opt-in **Mailpit** SMTP sink so nothing leaves your
machine:

```bash
docker compose --profile mail up -d mailpit
```

Then configure the instance to point at it:

| Field | Value |
|---|---|
| Transport | `SMTP` |
| Host | `mailpit` (from inside the compose network) |
| Port | `1025` |
| Encryption | `none` |
| From address | any address, e.g. `no-reply@whity.local` |

Send a test and watch it arrive in the Mailpit web inbox at
**http://localhost:8025**. No auth or password is needed for Mailpit.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Test returns **422 "Email is not configured"** | Transport is `none`, or host / from-address is blank. |
| Test returns **502**, log shows *"SMTP connect failed"* | Wrong host/port, firewall, or the TLS cert doesn't match the host (use the AutoSSL FQDN). |
| Test returns **502**, log shows *"unexpected SMTP reply 535"* | Auth failed — check username (full email) and re-enter the password. |
| Test returns **502**, log shows *"STARTTLS negotiation failed"* | Certificate/host mismatch on port 587, or the server doesn't offer STARTTLS — try port 465 with **SSL**. |
| Mail sends but lands in spam | Set up **SPF** + **DKIM** for the domain (cPanel → Email Deliverability). |
| Email settings page shows **Access Denied** | You're not a superuser in the **system tenant (0)** — mail is instance-wide and operator-only. |
