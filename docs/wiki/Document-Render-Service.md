# Document Render Service (`whity_render`)

The server-side PDF tier for the document/label designer: a Node + headless
Chromium container that loads **the designer's own React renderer** and returns
PDF bytes at exact millimetre size, **and** a flowing mode that paginates a
content tree into as many pages as it takes (see [Two render modes](#two-render-modes)).
The first exists so an exported design is the
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

## Sizing the host, and what a large export actually costs

**This is the part that surprises operators**, and it surprises them twice: the
memory is not where you would look for it, and the thing that actually stops a
large export is not memory at all.

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

## Two render modes

The service has two endpoints, and they take genuinely different documents.

| | `POST /render` — **fixed canvas** | `POST /render/flow` — **flowing** |
|---|---|---|
| Input | a designer **template**: pages of absolutely-placed, millimetre-positioned elements | a **content tree**: headings, paragraphs, tables, figures, no positions at all |
| Page count | known before rendering — one per template page, times the data rows | **not knowable** before rendering; the renderer decides |
| Margins | none (`margin: 0`); the design owns the whole sheet | configurable, real, and the running header/footer are drawn in them |
| Renderer | the designer's own React bundle (`dist/harness/`), for pixel parity with the on-screen preview | plain server-generated HTML plus a paginator that runs in the page |
| Who uses it | the document designer, and verification-code stamping composing into it | anything that assembles content and needs a document out of it |

They share exactly one thing: the Chromium instance. Not the harness, not the
stylesheet, not the readiness signal, not the page geometry. That separation is
deliberate — the fixed-canvas mode's output is expected to be unchanged
forever, and the cheapest way to guarantee that is for the flowing mode to have
no way to reach it. `test/fixed-canvas-geometry.test.js` pins the fixed mode's
`@page` rule and `page.pdf()` options so a future change to either is a test
failure rather than a surprise in someone's certificate.

### Why the flowing mode paginates itself

Chrome paginates flowing content perfectly well. What it will not do is say
where it put anything: it implements no CSS `target-counter()`, and no DOM API
reports which printed page an element landed on. So `"Table 34 …… 78"` cannot
be produced from a document Chrome has paginated, because the 78 does not exist
anywhere the document can read.

Three ways out were measured on the same generated 130-page document (60
tables, 90 figures, three generated front-matter lists — `npm run flow:fixture`
produces it). Times are the median of three warm runs in the real image;
correctness is the count of front-matter entries whose printed page number
matches the page the item is actually printed on, read back out of the finished
PDF by `scripts/verify-flow-pdf.js`.

| Approach | Render time | Entries correct |
|---|---|---|
| **Own paginator in the page** (what ships) | **3.7 s** | **294 / 294** |
| Two-pass, page estimated in the DOM from `offsetTop / pageHeight` | 4.2 s | **0 / 294** |
| Two-pass, page recovered from the first-pass PDF | 11.8 s | 294 / 294 |
| Paged.js polyfill | 18.8 s, and it truncated the document | n/a |

The in-page estimate is the cheap and obvious option and it is **wrong on every
single line**. `offsetTop / pageHeight` describes a continuous column;
fragmentation is exactly what departs from one, and every push a `break-inside`
or an unbreakable row causes accumulates, so the error grows down the document.
It is the dangerous kind of wrong, because the output looks typeset.

Recovering the numbers from the first-pass PDF is correct, but it costs two
full `page.pdf()` calls plus a text-extraction pass — three times the render
time — and it needs a PDF parser as a production dependency of the render tier
plus a machine-readable marker printed beside every anchor so the extractor can
find it. Paginating in the page instead makes the answer known before any PDF
exists, and makes `page.pdf()` **faster**, because Chromium is handed page boxes
it does not have to fragment.

Paged.js paginated a 14-page document correctly in 1.3 s, and silently
truncated anything larger in this harness — a 45-page document came back as
four pages, with no error. Even at the size where it worked its pagination rate
was ~93 ms/page against the shipped paginator's ~11 ms/page. It is not a
dependency of this service and never became one.

### The trap in generated front matter

A contents list changes the page numbers it prints, because the list itself
occupies pages. Get this wrong and every number is off by the length of the
list — which is what happens if the first pass is laid out without the list and
the numbers are injected afterwards (measured: 294/294 entries wrong, each
exactly 10 pages early, the length of the front matter).

The paginator handles it by fragmenting the body once — the body's own
pagination cannot depend on the front matter — then iterating the front matter
to a fixed point, and only then shifting the recorded anchor pages by the
front matter's final length. Because a contents entry is a fixed-height,
non-wrapping row, the list's length in pages does not depend on the numbers it
prints, so the loop converges on the second pass; a guard rebuilds at the final
length if it ever did not.

### Exercising it

`documents.render_enabled` defaults to `false`, so a fresh install renders
nothing and a change here has no natural way to be tested. Everything below
needs no database, tenant, template or setting:

```bash
cd render-service && npm ci

# A synthetic ~130-page document: 60 tables, 90 figures, three front-matter
# lists, Arabic with Latin identifiers throughout. Generated from a seed, so
# two runs are comparable. No real content of any kind.
npm run flow:fixture -- --direction rtl --out /tmp/flow.json

curl -sS -X POST http://127.0.0.1:8130/render/flow \
  -H 'Content-Type: application/json' -H "X-Render-Secret: ${RENDER_SHARED_SECRET}" \
  --data @/tmp/flow.json -D /tmp/h -o /tmp/flow.pdf
grep -i x-render-page-count /tmp/h        # the renderer's own count

# The check that matters: open the PDF, find the page each table and figure is
# actually printed on, read the number printed beside the matching entry, and
# compare. Also checks the running footer on physical page N says N.
npm run flow:verify -- --pdf /tmp/flow.pdf --direction rtl

# Bidi as geometry, in a real browser: the page number must land at the LEFT
# edge of a right-to-left entry and the label at the right, and a Latin
# identifier inside an Arabic label must keep its own order.
npm run flow:geometry
```

All four run in CI, in the `Render microservice` job, against the real image.

### Arabic and RTL

A contents entry is a **flex row**, not a line of mixed text, and every run
whose script disagrees with the document's direction is wrapped in `<bdi>`
(`src/flow/bidi.js`, used both in Node and in the page). Both are load-bearing.

Written as running text, a right-to-left entry ending in a Latin page number
has only bidi-neutral characters — spaces, dots, an em dash — between that
number and the previous Latin run in the label. The Unicode algorithm resolves a
neutral run between two left-to-right runs as itself left-to-right, so
identifier, leader and page number merge into one run and print backwards.
Measured in Chromium on the string `وصف العنصر — TBL-034 … 78`: as running
text the page number lands at x 677, to the **right** of the identifier at
x 566–627, i.e. the line reads "78 … TBL-034". Isolating the number fixes the
order; as a flex row the number is pinned at the content box's left edge
regardless. `scripts/check-flow-rtl-geometry.js` asserts this in both
directions, including the worst case of a caption that *ends* in a Latin run.

### Why `displayHeaderFooter` is not used

Puppeteer's own running header/footer is left switched off in both modes, and
the flowing mode draws its bands in the document instead. Chromium renders
those templates in a separate document with its own default stylesheet and no
access to the page's CSS; it can pass them only `pageNumber`, `totalPages`,
`title`, `url` and `date`, so a running head cannot name the **section** a page
belongs to, which is the main reason to have one; and it reserves the bands out
of the PDF margin, which fights a mode that prints at `margin: 0` with the page
box drawn in CSS. Drawing them in the document costs nothing, and gives them the
document's own font, direction and bidi isolation. The requirement — real
margins, and a running header and footer on every page — is met; the mechanism
the issue guessed at is not the one that meets it.

### Flowing-mode configuration

| Variable | Default | Notes |
|---|---|---|
| `RENDER_FLOW_READY_TIMEOUT_MS` | `180000` | How long pagination may take. It scales with the document, not the request; the fixed mode's 20 s was sized for one designed page. |
| `RENDER_FLOW_PDF_TIMEOUT_MS` | `180000` | How long `page.pdf()` may take for a flowing document. |
| `RENDER_FLOW_NAV_TIMEOUT_MS` | `30000` | Loading the generated page. |
| `RENDER_FLOW_MAX_BLOCKS` | `20000` | Hard ceiling on content blocks. |
| `RENDER_FLOW_MAX_TABLE_ROWS` | `5000` | Hard ceiling on rows in one table. |
| `RENDER_FLOW_MAX_BYTES` | 40 MiB | Hard ceiling on the whole payload. |

A render whose pagination overran a page box is **refused**, not returned: an
overrun means a unit was placed where it does not fit, so at least one recorded
page number describes a layout that did not happen, and every cross-reference
after it is suspect.

---

## References

- [ADR 0012 — Document render as a dedicated microservice](../adr/0012-document-render-microservice.md)
- `render-service/Dockerfile`, `render-service/Dockerfile.dockerignore`
- `render-service/scripts/build-harness.js` (what gets bundled and why)
- `render-service/src/flow/` (the flowing mode: payload, HTML, bidi, paginator)
- `render-service/scripts/generate-flow-fixture.js`, `verify-flow-pdf.js`, `check-flow-rtl-geometry.js`
- `render-service/scripts/write-build-info.js` (how the image identifies itself)
- `src/Api/DocumentRenderApiHandler.php`, `src/Core/Document/Render/RenderServiceClient.php`
- `src/Core/Settings/SettingsRegistry.php` (`documents.render_*`)
