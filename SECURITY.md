# Security Policy

## Supported Versions

Whity Core is pre-1.0 and has no legacy-compatibility stance: there is one
supported line — the latest tagged release plus `main`. Security fixes land
on `main` and are included in the next tag; there is no backport policy to
older tags while the project is pre-1.0.

| Version       | Supported          |
| ------------- | ------------------ |
| `main` (HEAD) | :white_check_mark: |
| Latest tag    | :white_check_mark: |
| Older tags    | :x:                |

## Reporting Vulnerabilities

**Do not open public issues for security vulnerabilities.** This keeps
exploit details out of the public tracker until a fix ships.

Email: **amroksaleh@gmail.com**

Include: description, affected versions, reproduction steps, potential impact.

Response time: 48 hours. Patch: within 7 days.

### Coordinated Disclosure

- We ask reporters to give us a reasonable window (target: 7 days for a
  patch, longer only by mutual agreement) before any public disclosure.
- We will acknowledge the report, confirm reproduction, and keep the reporter
  updated on remediation progress.
- Once a fix is released, we credit the reporter (unless they prefer to stay
  anonymous) in the release notes / `CHANGELOG.md`.
- No bug-bounty program exists today; reports are handled on a best-effort,
  goodwill basis.

## Critical Security Principles

### 1. RBAC Enforcement (Non-Negotiable)

Every data operation must verify permissions:

```php
public function update(Task $task) {
    $this->authorize($user, 'tasks:update', $task);
    return $task->update(...);
}
```

### 2. Tenant Isolation (Non-Negotiable)

All queries must filter by tenant_id:

```php
// ✅ CORRECT
Task::where('tenant_id', auth()->user()->tenant_id)->get();

// ❌ WRONG
Task::where('status', 'open')->get();  // Leaks data!
```

### 3. No Static State (Critical)

Static properties persist across requests:

```php
// ❌ CRITICAL - User A's cache leaks to User B!
private static $cache = [];

// ✅ CORRECT - Request-scoped
Cache::get('key');  // Redis, not static
```

### 4. Input Validation

Validate all user input:

```php
// ✅ CORRECT
public function create(CreateTaskRequest $request) {
    return Task::create($request->validated());
}
```

### 5. SQL Injection Prevention

Use parameterized queries:

```php
// ✅ SAFE
Task::where('title', $title)->get();

// ❌ VULNERABLE
Task::whereRaw("title = '" . $title . "'");
```

## Encryption Standards

- **In Transit:** TLS 1.3+
- **At Rest:** AES-256 for sensitive fields
- **Passwords:** bcrypt hashing via PHP `password_hash()` with `PASSWORD_BCRYPT`
  (default cost factor 10). The same algorithm secures user passwords and
  two-factor backup/recovery codes; all verification goes through
  `password_verify()`.

> **Future hardening:** evaluate migrating to Argon2id (`PASSWORD_ARGON2ID`).
> This is a non-trivial change requiring rehash-on-login so existing bcrypt
> hashes are upgraded as users authenticate; it is intentionally out of scope
> here and tracked separately.

## Deployment Security

- Strong DB passwords (>16 chars)
- Environment variables (no committed secrets)
- Regular updates & security patches
- Automated backups with encryption

## Audit Logging

Log all permission denials and data modifications with user, tenant, IP, timestamp.

## Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security](https://www.php.net/manual/en/security.php)

---

*Last updated: 2026-06-05*
