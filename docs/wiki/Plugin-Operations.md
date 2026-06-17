# Plugin Operations

This page describes how plugin lifecycle changes (enable, disable, uninstall)
behave at runtime across a FrankenPHP worker pool, and the operator contract for
making an administrative change propagate immediately.

For building a plugin see [Plugin-Development.md](./Plugin-Development.md); for
distribution see [Plugin-Distribution.md](./Plugin-Distribution.md).

---

## Lifecycle state is per-worker

Whity Core runs under FrankenPHP with a pool of long-lived worker processes
(`worker ... {$FRANKENPHP_WORKERS:8}` in the `Caddyfile`). Each worker owns its
own in-memory `PluginLoader`, which holds the per-plugin
[`PluginLifecycle`](../../src/Core/PluginLifecycle.php) state machine (Active,
Disabled, Failed, …). That state is **worker-local**: it lives for the lifetime
of the worker and is not shared between workers.

A request to `GET /api/plugins` is answered by whichever worker the proxy
routed it to, so the listing reflects **that worker's** view. The response
carries a typed indicator of this:

```json
{
  "data": [ /* … plugin entries … */ ],
  "meta": {
    "worker_local": true,
    "note": "Plugin lifecycle state is per-worker. Admin enable/disable/uninstall persist to disk and converge across workers on reload or worker restart; auto-fail (consecutive-error) state is per-worker until the shared store lands."
  }
}
```

## Disk is the source of truth for admin changes (WC-210)

Administrative enable / disable / uninstall now **persist to disk**, so disk is
the authoritative record and every worker converges on it independently:

- **Single-file plugins** (`plugins/Foo.php`): disabling renames the file to
  `plugins/Foo.php.disabled`. Discovery skips `*.php.disabled`, so a fresh
  worker never registers it. Re-enabling renames it back. The plugin still
  appears in `GET /api/plugins` with `enabled: false` / `status: "disabled"` —
  it does not vanish from the listing.
- **Directory plugins** (`plugins/Foo/FooPlugin.php`): renaming the entry file
  would break PSR-4 autoloading, so disabling instead writes an empty sentinel
  marker `plugins/Foo/.disabled` into the folder. Discovery honours the
  sentinel: the plugin is loaded straight into the `Disabled` lifecycle state
  with its routes **not** registered — exactly as if `disablePlugin()` had been
  called in that worker. Re-enabling removes the sentinel.

Because the signal lives on disk, any worker that performs a `load()` or
`reload()` after the change converges on the new state — it does not matter
which worker handled the original admin request.

## How and when other workers converge

| Environment | Convergence trigger |
| --- | --- |
| **Development** (`APP_ENV=development`) | The worker loop calls `reload()` at the start of every request (`public/index.php`), so added/removed plugins are picked up almost immediately. An **edit** to an already-loaded plugin cannot be redefined in-process: `reload()` invalidates the file's opcache entry and requests a worker recycle, and after the response is sent the worker breaks its loop so FrankenPHP respawns a fresh worker that recompiles the new source (WC-212). |
| **Production** | A worker re-reads disk on its next recycle. Workers recycle after `MAX_REQUESTS` (default `500`, see `docker-compose.yml`) or when the memory limit is hit. `POST /api/plugins/reload` reloads **only the worker that handles that request** — not the whole pool. |

### The immediate-propagation contract

`POST /api/plugins/reload` is not a fleet-wide broadcast; it converges a single
worker. For an administrative change to take effect across the **entire** pool
immediately, the operator contract is a **full worker-pool restart**, e.g.:

```bash
docker compose up -d --force-recreate frankenphp
```

Without a restart, the change still propagates — but lazily, as each worker
recycles. Disk remains the source of truth throughout, so there is no risk of a
worker resurrecting a disabled plugin: a recycled worker re-reads the
`.php.disabled` rename / `.disabled` sentinel and stays converged.

### The manifest cache self-invalidates (WC-213)

The on-disk plugin manifest (`plugin_manifest.json`) stores a filesystem
**fingerprint** — a `mtime:size` signature per plugin file — alongside its
`FQCN -> path` map. On a warm cache, discovery recomputes the fingerprint and
trusts the cached map only when it matches exactly. Any added, removed, or
**in-place-modified** file (a changed class, namespace, or an extra plugin
dropped beside an existing one) shifts the signature and forces a full rescan,
so a freshly-booted worker never serves a stale map left behind by a previous
worker or deploy. A manifest written before WC-213 (no `fingerprint` key) is
treated as a miss and rebuilt.

## Auto-fail is deliberately worker-local

A plugin that throws repeatedly is auto-failed by the error boundary after
`PluginLifecycle::MAX_CONSECUTIVE_ERRORS` consecutive errors (`recordError()`
flips it to `Failed`). This is **intentionally not persisted to disk**: a single
flaky worker must not be able to disable a plugin fleet-wide. Auto-fail therefore
remains per-worker and transient — a fresh worker loads the plugin as `Active`.

Sharing auto-fail (and lifecycle state generally) across the pool requires a
shared store and is deferred to the **Phase-F** work. Until then, to clear an
auto-failed plugin everywhere, either re-enable it (which converges via the disk
signal if it had also been administratively disabled) or restart the worker
pool.
