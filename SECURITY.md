# Security Policy

## Reporting Vulnerabilities

**Do not open public issues for security vulnerabilities.**

Email: **amroksaleh@gmail.com**

Include: description, affected versions, reproduction steps, potential impact.

Response time: 48 hours. Patch: within 7 days.

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
- **Passwords:** Argon2id hashing

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

*Last updated: 2026-05-16*
