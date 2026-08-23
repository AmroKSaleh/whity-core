# Document Render Service (`whity_render`)

The server-side PDF tier for the document/label designer: a Node + headless
Chromium container that loads **the designer's own React renderer** and returns
PDF bytes at exact millimetre size. It exists so an exported document is the
same artefact the author saw on screen, rather than the output of a second,
hand-rolled renderer that drifts from the canvas — see
[ADR 0012](../adr/0012-document-render-microservice.md).

Related: [Document & Label Designer](Document-Designer.md) (the designer
itself) · [Feature Flags](Feature-Flags.md) (`documents.render_enabled`) ·
[Core Updates](Core-Update.md) (the release/upgrade runbook).

---

## The published image

| | |
|---|---|
| Image | `ghcr.io/<repo>/render` |
| Tags | `vX.Y.Z` and `latest` — **the same tags as the app and web images** |
| Built by | `.github/workflows/release.yml`, `image` job, `render` matrix leg |
| Dockerfile | `render-service/Dockerfile`, `runtime` stage |
| Port | `8130` |

`ghcr.io/<repo>/render` sits beside `ghcr.io/<repo>` (the API) and
`ghcr.io/<repo>/web` (the UI) — three images, one release, one set of tags,
one gated job.

### Why it has to be published, not built by operators

`render-service/Dockerfile` takes the **repository root** as its build context.
It bundles `web/components/documents`, `web/lib/documents`,
`packages/ui/src` and `packages/features/src` in **from source**, so the PDF
comes out of the same code the designer draws with. That is ADR 0012's whole
premise, and it is also why the image cannot be assembled downstream:
`render-service/` ships in the **app** image, that web source ships only in the
**web** image, and neither published artefact contains both.

Before this image existed, running the render tier meant hand-assembling a
build context out of two images and repeating it on every core bump. The
failure mode when that goes wrong is not an error — it is a render tier that
keeps working while producing exports that no longer match the preview, which
is precisely the drift ADR 0012 exists to prevent.

### Version pairing — and how to check it

**Run the render tag that matches your app tag.** `render:v0.3.0` is built from
the same commit as `app:v0.3.0` and `web:v0.3.0`; the three are produced by one
matrixed job with identical gates, so a matching set is the only combination
that has been tested together.

Mixing them is not a configuration the product supports, and it does not
announce itself: a stale render container is healthy, answers every request,
and simply emits the *previous* release's layout. So the image reports what it
was built from:

```bash
curl -s http://render:8130/health
# {"status":"ok","core_version":"0.3.0","commit":"<sha>"}
```

`core_version` comes from `src/Core/CoreVersion.php` — the same constant
`GET /api/health` reports as `version` and `/web-build` reports as
`core_version`. **All three must agree.** One check for the whole deployment:

```bash
curl -s http://localhost:8000/api/health  | grep -o '"version":"[^"]*"'
curl -s http://localhost:3000/web-build   | grep -o '"core_version":"[^"]*"'
curl -s http://localhost:8130/health      | grep -o '"core_version":"[^"]*"'
```

The release pipeline asserts this on the published image before it will create
the GitHub Release (`smoke` job), so an image that cannot say what it is never
ships.

---

## Running it

The render tier is **opt-in at two independent levels**, and both must be on:

1. **Infrastructure** — the container is behind a Compose `profile`, like
   `cron`/`queue`/`scheduler`. A deployment that never exports PDFs should not
   carry a Chromium container.
2. **Setting** — `documents.render_enabled` (default `false`). With the
   container running but the setting off, `POST /api/document-templates/{id}/render`
   returns a clean `503` and never contacts the service.

```bash
# Published images (docker-compose.staging-remote.yml):
WHITY_IMAGE=ghcr.io/<repo>:v0.3.0 \
WHITY_WEB_IMAGE=ghcr.io/<repo>/web:v0.3.0 \
WHITY_RENDER_IMAGE=ghcr.io/<repo>/render:v0.3.0 \
RENDER_SHARED_SECRET=<at least 32 chars> \
  docker compose -f docker-compose.staging-remote.yml --profile render up -d

# Local build from a checkout (docker-compose.yml):
docker compose --profile render up -d --build
```

### Configuration

| Variable | Where | Default | Notes |
|---|---|---|---|
| `RENDER_SERVICE_URL` | app | `http://render:8130` | In-network base URL of the render container. |
| `RENDER_SHARED_SECRET` | app **and** render | — | Must match on both sides and be **≥ 32 chars**, or every render is refused (`401`). |
| `RENDER_TIMEOUT_SECONDS` | app | `30` | HTTP timeout for one render call. Large batches need this raised — see below. |
| `RENDER_PDF_TIMEOUT_MS` | render | `30000` | How long `page.pdf()` may take. **The first ceiling a large batch hits.** |
| `RENDER_READY_TIMEOUT_MS` / `RENDER_NAV_TIMEOUT_MS` | render | `20000` | Waiting for the harness to signal ready / to load. |
| `RENDER_RATE_LIMIT_MAX` / `_WINDOW_MS` | render | `30` / `60000` | Per-window cap on `POST /render`. |
| `RENDER_HARD_MAX_ROWS` / `_UNITS` / `_TEMPLATE_BYTES` | render | `2000` / `5000` / 10 MiB | Defence-in-depth ceilings inside the service; the operator-facing limits are the settings below. |

---

## Sizing the host — the memory cost of a render

**This is the part that surprises operators.** Chromium's retained memory
scales with the number of rendered pages held in the document at once, and the
service keeps one browser alive across requests (relaunching per request would
be far too slow for the bursty batch export this tier exists for).

A render's page count is `dataRows × template.pages` — one batch row of a
three-page template is three pages, and 500 rows of it is 1500.

### Measured

Numbers below are from the published image on a Docker Desktop host (12.7 GB
available to the VM), rendering an A4 template carrying text, a KaTeX
expression and a Code128 barcode on each of three pages. Peak is the
container's own cgroup usage, sampled at 200 ms through the render.

| Job | Pages | Peak container memory | Wall time | Outcome |
|---|---:|---:|---:|---|
| idle, before the first render | – | 32 MB | – | Chromium not launched yet |
| 1 row | 3 | 246 MB | < 2 s | ok |
| 100 rows | 300 | 487 MB | 6 s | ok |
| 300 rows | 900 | 713 MB | 28 s | ok |
| **500 rows** (the `render_max_rows` default) | 1500 | 813 MB | 78 s | **fails on stock settings** — see below |

Two things to take from that, and the second is the one that bites first:

**The browser baseline dominates; the marginal page is cheap.** The jump from
32 MB to ~246 MB is Chromium launching on the first render — and it stays, by
design, because relaunching per request would be far too slow for the bursty
batch export this tier exists for. Growth beyond that is well under 1 MB per
page for a template this simple. **A content-heavy page costs much more** —
embedded images in particular are not free the way text and a barcode are — so
treat the marginal figure as a floor, measure your own worst template, and size
from that rather than from this table.

**A 500-row job fails on stock settings, and not for want of memory.** It
finishes in 78 seconds and never approaches 1 GB, but *three separate
30-second timeouts* sit below it:

| Timeout | Default | Where |
|---|---|---|
| `RENDER_PDF_TIMEOUT_MS` | `30000` | render service — puppeteer's `page.pdf()` |
| `RENDER_READY_TIMEOUT_MS` | `20000` | render service — waiting for the harness to signal ready |
| `RENDER_TIMEOUT_SECONDS` | `30` | **app side** — the HTTP call to the render service |

So `documents.render_max_rows = 500` and `documents.render_max_pages = 2000`
are not reachable out of the box: the render is cut off long before the
settings consider the request large. Raising the limits without raising all
three timeouts changes nothing.

### The failure says nothing about its cause

Whichever wall it hits — an OOM kill, a `page.pdf()` timeout, the app-side HTTP
timeout — the caller sees exactly one thing:

```
503 Document rendering is temporarily unavailable
```

which is also what it sees when the render tier is switched off, misconfigured,
or not deployed at all (`DocumentRenderApiHandler` normalises every render-side
failure to that response deliberately, so nothing about the internal service
leaks). Nothing in it, and nothing in the app's log, distinguishes the cases.
Diagnose from the render side:

```bash
docker logs <render container> | tail -40                              # TimeoutError names the wall
docker inspect --format '{{.State.OOMKilled}}' <render container>      # true = memory, nothing else
```

### Practical guidance

- **Give the container a memory limit you chose** (`mem_limit`), so an
  over-large job kills the render container rather than whatever else shares
  the host — and so `docker inspect` can tell you that is what happened.
- **Set the limits to what your deployment actually supports** rather than
  leaving the 500 / 2000 ceilings in place. They are tenant-overridable (unlike
  `render_enabled`), so hold a low instance-wide ceiling and raise it for the
  one tenant that legitimately exports large batches.
- **If you raise the limits, raise all three timeouts too**, and raise
  `RENDER_TIMEOUT_SECONDS` at least as high as the render-side ones — the app
  gives up first otherwise, and the render container goes on burning a
  browser on a result nobody is waiting for.
- **Split large exports into several requests.** The render tier is stateless
  and independently scalable by design; one enormous request is the shape that
  hurts, and it is also the shape that holds a single browser hostage for the
  duration.

---

## Settings: what is per-tenant and what is not

`documents.render_enabled` is **global-only** (`SettingsRegistry::GLOBAL_ONLY_KEYS`).
It answers an infrastructure question — *is a Chromium container deployed on
this instance* — not a tenant preference, so a per-tenant value would be inert
by construction.

That is enforced, not merely intended: the key is excluded from the per-tenant
registry (`GET /api/v1/settings` never lists it), and `PATCH /api/v1/settings`
rejects it with

```
422  documents.render_enabled is a global instance setting and cannot be set per-tenant.
```

Set it on the global surface (`PATCH /api/v1/settings/global`) or in the admin
**Feature Flags** tab.

The three render **limits** are the opposite: ordinary tenant-overridable
settings, resolved per-tenant → global → registry default, exactly so an
operator can hold a low instance-wide ceiling while raising it for the one
tenant that needs it.

---

## Verifying a deployment end to end

```bash
# 1. The service is up and is the right build.
curl -s http://localhost:8130/health

# 2. It can actually render (the check that matters — a healthy container that
#    cannot render is the failure mode this tier has).
curl -fsS -X POST http://localhost:8130/render \
  -H 'Content-Type: application/json' \
  -H "X-Render-Secret: ${RENDER_SHARED_SECRET}" \
  -d '{"template":{"version":2,"page":{"widthMm":100,"heightMm":60,"marginMm":2,"background":"#ffffff"},"placeholders":[],"pages":[{"id":"p1","elements":[{"id":"t1","type":"text","x":2,"y":2,"w":96,"h":12,"rotation":0,"z":1,"text":"مرحبا","style":{"fontSize":18,"fontWeight":"normal","fontStyle":"normal","align":"center","vAlign":"middle","color":"#000000","direction":"rtl"}}]}]},"dataRows":[{}]}' \
  -o /tmp/render-check.pdf
head -c 5 /tmp/render-check.pdf   # %PDF-
```

Arabic is in that payload on purpose: the Noto Naskh / Noto Sans Arabic fonts
are baked into this image, and a build that lost them still renders — as tofu.

The release pipeline runs the same shape of check against the published image
(`smoke` job in `.github/workflows/release.yml`), and every PR that touches
`render-service/`, `web/components/documents`, `web/lib/documents` or
`packages/{ui,features}/src` runs it against a locally built one
(`render-service` job in `.github/workflows/automated-tests.yml`).

---

## References

- [ADR 0012 — Document render as a dedicated microservice](../adr/0012-document-render-microservice.md)
- `render-service/Dockerfile`, `render-service/Dockerfile.dockerignore`
- `render-service/scripts/build-harness.js` (what gets bundled and why)
- `render-service/scripts/write-build-info.js` (how the image identifies itself)
- `src/Api/DocumentRenderApiHandler.php`, `src/Core/Document/Render/RenderServiceClient.php`
- `src/Core/Settings/SettingsRegistry.php` (`documents.render_*`)
