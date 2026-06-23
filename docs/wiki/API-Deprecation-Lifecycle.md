# API Deprecation & Lifecycle Policy

This document defines how Whity-Core routes are deprecated and removed, the support window that callers can rely on, and the mechanics for declaring and consuming deprecation signals.

---

## Quick Reference

| Signal | Where | Value |
|---|---|---|
| Route flag | `schema['deprecated']` | `true` |
| Sunset date | `schema['sunset']` | RFC 7231 HTTP-date, e.g. `Sat, 31 Dec 2025 00:00:00 GMT` |
| Response header | `Deprecation` | `true` |
| Response header | `Sunset` | RFC 7231 HTTP-date |
| OpenAPI | operation `deprecated` | `true` |

---

## Support Window

| Phase | Duration | Guarantee |
|---|---|---|
| **Active** | Indefinite | No breaking changes. Additive changes (new optional fields) only. |
| **Deprecated** | **Minimum 6 months** | Route still works. `Deprecation: true` (and `Sunset`) emitted on every response. Clients must migrate before the sunset date. |
| **Removed** | After sunset | Route returns `410 Gone`. |

The 6-month minimum starts from the date the `deprecated: true` flag lands in `main`, not the date the PR is opened.

---

## What Counts as a Breaking Change

The following changes are **always breaking** and require the deprecation process:

- Removing an endpoint (method + path)
- Removing or renaming a required request-body field
- Removing or renaming a response-body field that callers can read
- Changing a field's type in a way that invalidates existing values
- Narrowing an enum (removing previously accepted values)
- Removing or restructuring a query parameter that changes semantics

The following are **not breaking** (no deprecation required):

- Adding new optional request-body fields (with documented defaults)
- Adding new response-body fields
- Widening an enum (adding new accepted values)
- Adding new optional query parameters
- Performance improvements with no observable behavioral change

---

## How to Deprecate a Route

### 1. Mark the route in `public/index.php`

Add `'deprecated' => true` (and optionally `'sunset'`) to the route's `schema` array. Use RFC 7231 HTTP-date format for the sunset value.

```php
$router->register(
    'GET',
    '/api/users',
    [$usersHandler, 'list'],
    null,
    null,
    CorePermissions::USERS_READ,
    [
        'summary'    => 'List users (deprecated — use /api/v2/users)',
        'deprecated' => true,
        'sunset'     => 'Sun, 30 Jun 2026 00:00:00 GMT',
        'tags'       => ['Users'],
        // ...
    ]
);
```

The HTTP kernel automatically emits:

```
Deprecation: true
Sunset: Sun, 30 Jun 2026 00:00:00 GMT
```

on every response for that route. The OpenAPI spec at `/api/openapi.json` will show `"deprecated": true` on the operation.

### 2. Update the wiki/changelog

Document the deprecation in `docs/wiki/Changelog.md` (or the relevant feature doc) with:

- What is deprecated
- Why (what replaces it)
- Sunset date

### 3. Monitor usage

Check access logs or APM for traffic to the deprecated path before the sunset date. Reach out to known callers if usage is still high in the final 30 days.

### 4. Remove the route

On or after the sunset date:

1. Remove the route registration from `public/index.php`
2. Remove any handler code that is no longer needed
3. Update/remove the OpenAPI schema declaration
4. Update `public/openapi.json` by regenerating the spec

---

## How to Introduce a New Version

When a breaking change is unavoidable, introduce the new endpoint alongside the old one under a new path (e.g. `/api/v2/users`) and deprecate the old path. Both live in the same versioned API prefix (`/api/v1/` → `/api/v2/`); the version segment only increments when the resource shape fundamentally changes.

Alternatively, use a query-parameter strategy (e.g. `?format=v2`) for smaller shape changes, as long as the old behavior is still reachable without the parameter.

---

## Automated Signals

The deprecation machinery is automatic:

- **HTTP response headers**: emitted by `HttpKernel::buildCorePipeline()` whenever `schema['deprecated'] === true`.
- **OpenAPI `deprecated: true`**: emitted by `SchemaGenerator::addOperation()` for the same flag.
- **No manual header code** in handlers — declaring the flag in the schema is sufficient.

---

## Exception: Infrastructure Endpoints

Unversioned infrastructure routes (`/api/health`, `/api/version`, `/api/openapi.json`) are not subject to this policy. Changes to these paths are coordinated separately and communicated via release notes.
