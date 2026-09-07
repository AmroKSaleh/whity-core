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
# {"status":"ok","core_version":"0.3.0","commit":"<sha>",
#  "browser":{"version":"151.0.7922.173","source":"build", ...},
#  "capabilities":{"status":"ok", ...}}
```

The `browser` and `capabilities` halves answer a *different* drift question —
see [The browser is unpinned](#the-browser-is-unpinned-and-that-is-visible-on-purpose).

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
| `RENDER_PROBE_TIMEOUT_MS` | render | `60000` | Per-step ceiling on the boot-time capability probe. Raise it only if `/health` reports `capabilities.status: "error"` with a launch timeout. |
| `RENDER_FLOW_REQUIRE_CAPABILITIES` | render | `true` | Whether a **required** capability-probe failure refuses `POST /render/flow` with a `503`. The escape hatch, not a normal setting — see below. |
| `RENDER_FLOW_CAPABILITY_WAIT_MS` | render | `RENDER_PROBE_TIMEOUT_MS` | How long a flow request waits for a probe that has not landed yet before rendering ungated. |

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

> **Do not re-check that claim with `CSS.supports()` — it lies here.**
> `CSS.supports('content', 'target-counter(attr(href), page)')` returns **true**
> on Chromium 151, and the declaration survives in the CSSOM. It is dropped at
> computed-value time: `getComputedStyle(a, '::after').content` is `"none"` and
> nothing is printed. Verify at the rendered-output level or you will "disprove"
> this paragraph and reopen a settled decision.

Chromium 151 does support more paged media than this section once implied, which
matters if you are choosing where to draw page furniture: `@page` margin boxes,
`counter(page)` AND `counter(pages)` (so "3 of 12" is native), `@page :first`,
`@page :left` / `:right`, and named pages all work. What does not: `string-set` /
`string()` for a running section name, `position: running()`, and `counter(page)`
in ordinary body content. None of that changes the flowing mode — it prints at
`margin: 0` with pre-fragmented boxes and draws its bands in-document — but the
absence of margin-box support is not the reason.

Those facts are now **re-measured at every container start**, because the
browser they were measured against is not pinned — see
[The browser is unpinned](#the-browser-is-unpinned-and-that-is-visible-on-purpose).
If you change the table above, change the probe's expectations with it.

Three ways out were measured on the same generated 130-page document (60
tables, 90 figures, three generated front-matter lists — `npm run flow:fixture`
produces it). Times are the median of three warm runs in the real image;
correctness is the count of front-matter entries whose printed page number
matches the page the item is actually printed on, read back out of the finished
PDF by `scripts/verify-flow-pdf.js`.

| Approach | Render time | Entries correct |
|---|---|---|
| **Own paginator in the page** (what ships) | **3.7 s** (repeat sessions 3.6–4.3 s) | **294 / 294** |
| Two-pass, page estimated in the DOM from `offsetTop / pageHeight` | 4.2 s (4.0–4.3 s) | **0 / 294** |
| Two-pass, page recovered from the first-pass PDF **by text extraction** | 11.8 s (11.6–11.8 s) | 294 / 294 |
| Two-pass, page recovered from the first-pass PDF **by named destinations** | see correction below — **at parity** | 150 / 150 |
| Two-pass, first pass laid out *without* the generated list | 37.5 s | **0 / 294** |
| Paged.js polyfill | see correction below — **collapses on RTL** | n/a |

Run-to-run spread on one host is a few hundred milliseconds; the gaps in that
table are larger than the noise, which is the only reason it decides anything.

The in-page estimate is the cheap and obvious option and it is **wrong on every
single line**. `offsetTop / pageHeight` describes a continuous column;
fragmentation is exactly what departs from one, and every push a `break-inside`
or an unbreakable row causes accumulates, so the error grows down the document.
It is the dangerous kind of wrong, because the output looks typeset.

Recovering the numbers from the first-pass PDF is correct. The 11.8 s above is
the cost of recovering them **by extracting text**, and that is not the only way.

> **Correction (2026-08-31).** Chromium emits a PDF **named destination for every
> `id` targeted by an internal `<a href="#id">`** — precisely the links a contents
> list already contains. `pdfjs.getDestinations()` + `getPageIndex()` resolves all
> 150 anchors of a 110-page document in **14–27 ms**, with **no marker printed in
> the document and no text extraction**. Measured head-to-head on this repo's own
> `flow:fixture` through its own `document.js` + `html.js`, two-pass then runs at
> **0.78× (LTR) to 0.91× (RTL)** of the shipped paginator — at parity or slightly
> **cheaper**, not three times the cost. It is direction-neutral and verified
> 150/150 on a fully Arabic document.
>
> **So do not defend the shipped design on render time.** The argument that
> actually holds is below.

> **Correction (2026-08-31).** The size-truncation finding does **not**
> reproduce. Paged.js 0.4.3 under Chromium 151 paginates LTR correctly and
> linearly to at least **136 pages** (240 sections, ~35 ms/page), staying within
> one page of Chromium throughout, and its `target-counter()` rewriting prints
> real, increasing page numbers.
>
> **It collapses on RTL.** A `dir="rtl"` document of 120 sections — 69 pages of
> content — comes back as **one page**, with every cross-reference printing `1`.
> No error, no warning. The earlier investigation's fixture defaults to RTL, so
> what was diagnosed as size-dependent truncation was almost certainly this.
>
> That is a **stronger** reason to reject Paged.js, not a weaker one. Arabic and
> RTL are hard requirements here, and this is the failure mode this service is
> most careful about elsewhere: output that looks completely typeset and is
> wholly wrong.

It is not a dependency of this service and never became one.

### The argument that actually decides it

Not render time. **The paginator is plain browser JavaScript with no Puppeteer
dependency** — `html.js` inlines it into the page with a `<script>` tag. So the
same algorithm can run in an editor's browser and produce *identical* page
breaks, because it is ours and it is deterministic.

Under any two-pass scheme the breaks come from Chromium's **print**
fragmentation, which screen layout cannot reproduce; an editor would have to ask
the server where the pages fall, for every edit. Owning the algorithm is what
makes a WYSIWYG flowing editor possible at all. If that requirement is ever
dropped, two-pass via named destinations is a real, measured, RTL-safe
alternative at parity cost.

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
| `RENDER_FLOW_MAX_BYTES` | 20 MiB | Hard ceiling on the whole payload. Deliberately below express's 25 MiB `json` limit, so an oversized payload is refused by this service with its own error rather than by the body parser. (This row said 40 MiB until 2026-08-31; the code has always said 20 — see `src/flow/document.js`.) |

A render whose pagination overran a page box is **refused**, not returned: an
overrun means a unit was placed where it does not fit, so at least one recorded
page number describes a layout that did not happen, and every cross-reference
after it is suspect.

---

## The browser is unpinned, and that is visible on purpose

`render-service/Dockerfile` installs `chromium` with **no version constraint**,
so every rebuild takes whatever Debian bookworm ships that day. At the time of
writing that is **151.0.7922.173** (`151.0.7922.173-1~deb12u1`).

That matters more here than in most services. The flowing mode's paginator is
~840 lines that measure and fragment content against the browser's own layout,
guarded by a refuse-on-disagreement check rather than by a specification, and
every number its design rests on was measured against one build. A Chromium
upgrade arriving silently through a rebuild can change fragmentation — and the
failure is not a crash. It is a hundred-page document that paginates
differently, or the disagreement guard starting to refuse renders that used to
succeed, with nothing in this repository's history to explain either.

### Why it is not pinned

`chromium=151.0.7922.173-1~deb12u1` would make the build reproducible and would
**break** the day Debian rotates that version out for a security update, which
it does routinely. Trading silent behaviour drift for periodic hard build
failures on a security rebuild is a real trade, and a maintainer may yet decide
to make it. #1134 did not. It made the drift **visible and loud** instead. If
you do pin, pin in the Dockerfile *and* update the measured-facts table above,
because that table is what the probe's expectations are calibrated against.

### 1. The browser is recorded into the image at build time

`render-service/scripts/write-browser-info.js` runs in the Dockerfile's
`runtime` stage — after the `apt-get`, the only place the browser exists — and
freezes what was installed into `dist/browser-info.json`. The server reads it
once at boot, says so in the log, and reports it on `/health`:

```bash
docker logs whity_render | head -1
# [whity_render] browser: 151.0.7922.173 (apt 151.0.7922.173-1~deb12u1) at /usr/bin/chromium, recorded 2026-08-31T16:15:03.511Z

curl -s http://localhost:8130/health | jq .browser
```

```json
{
  "version": "151.0.7922.173",
  "package_version": "151.0.7922.173-1~deb12u1",
  "banner": "Chromium 151.0.7922.173 built on Debian GNU/Linux 12 (bookworm)",
  "executable": "/usr/bin/chromium",
  "recorded_at": "2026-08-31T16:15:03.511Z",
  "source": "build",
  "running_version": "151.0.7922.173",
  "running_banner": "Chrome/151.0.7922.173",
  "running_matches_build": true
}
```

Same shape, and the same rules, as `BuildIdentity` / `GET /api/build` on the PHP
side (#1049): captured at build time because it cannot be recovered afterwards;
a `source` field saying **where** the answer came from; `unknown` with nulls as
a first-class answer, because a plausible-looking wrong version is worse than no
version. A checkout that never ran `npm run build:browser-info` reports
`source: "unknown"` — an image build always writes the file and **fails** if it
cannot.

`running_version` is a *second* reading of the same fact: what the browser
answered when the boot probe launched it. `running_matches_build: false` means
the binary under the recorded path is not the one this image installed;
`null` means one of the two sides could not answer, which is a different
finding and is reported as one.

**This is the drift signal.** Two images, two `/health` responses, one diff.

### 2. A boot-time capability probe

`render-service/src/capability-probe.js` asserts, once at container start, the
behaviours the paginator actually relies on. Twelve probes, measured at a level
where the answer is real — computed style, and the bytes of a rendered PDF —
never `CSS.supports()`.

| Probe | Level | Expected | Fatal? |
|---|---|---|:--:|
| `range-client-rects-per-line` — `Range.getClientRects()` returns one rect per line box | DOM layout | present | **yes** |
| `range-prefix-bottom-monotonic` — a prefix `Range`'s bottom grows monotonically and reaches the last line | DOM layout | present | **yes** |
| `range-extract-contents-moves-text` — `extractContents()` moves the prefix out | DOM | present | **yes** |
| `print-one-page-per-forced-break` — `break-after: page` yields one PDF page per page box | rendered PDF | present | **yes** |
| `print-honours-css-page-size` — `preferCSSPageSize` prints at the `@page` size in exact mm | rendered PDF | present | **yes** |
| `css-mm-at-96dpi` — a CSS millimetre lays out at the 96dpi ratio | DOM layout | present | no |
| `css-target-counter` | computed style | **absent** | no |
| `css-string-set` | computed style | **absent** | no |
| `css-string-function` | computed style | **absent** | no |
| `css-position-running` | computed style | **absent** | no |
| `css-page-first-pseudo` — `@page :first` gives page 1 its own size | rendered PDF | present | no |
| `css-named-pages` — a named `@page` gives a marked element its own size | rendered PDF | present | no |

**The rule: a probe is fatal only when the paginator's arithmetic is wrong
without it.** Three consequences are worth stating, because in each case the
obvious choice is the wrong one:

- **The missing features are not required to stay missing.** Their absence is
  the entire reason this service paginates for itself. A Chromium that
  implements `target-counter()` is an *opportunity* — a much simpler design
  becomes available — and it must never take the render tier down. It is logged
  loudly as `notable` and named against #1134.
- **The paged-media features that work are not required either.** `@page :first`
  and named pages work today; **neither render mode uses them**. Failing a boot
  over a feature nothing depends on would make this diagnostic into a new
  outage source, which is the one thing it must not be.
- **"Could not determine" is never a failure, and never a success.** An
  unreadable PDF or a browser that would not launch reports `unknown`; a
  required probe that came back `unknown` makes the verdict `inconclusive`, not
  `ok`. Ignorance is not evidence that anything changed, and it is not evidence
  that nothing did.

### What the verdict does

```bash
curl -s http://localhost:8130/health | jq '.capabilities | {status, ms, required_failures, notable, unknown}'
```

| `capabilities.status` | Meaning | Effect on traffic |
|---|---|---|
| `ok` | every probe answered and matched | none |
| `notable` | an informational behaviour changed, in either direction | none — logged loudly |
| `inconclusive` | a required probe could not be measured | none |
| `degraded` | a **required** probe disagreed | `POST /render/flow` → `503` naming the probe |
| `error` | the probe could not run at all | none |
| `pending` / `not_run` | still running / never started | none |

Only `degraded` refuses anything, and it refuses only the **flowing** mode —
the one whose correctness rests on how this browser fragments content, and
whose own paginator already refuses rather than emit page numbers it cannot
vouch for. `POST /render` (fixed canvas) is deliberately **not** gated: #1134's
constraint was not to touch that path, and its failure mode — a PDF at the
wrong physical size — is visible in the first document anyone opens.

Set `RENDER_FLOW_REQUIRE_CAPABILITIES=false` to lift the gate while keeping the
report. That escape hatch exists because a gate with no way round it is itself
a new way to be down.

**If the probe itself cannot run, the service starts anyway** and gates
nothing. A detector that can stop the service converts a diagnostic into an
outage, and a crash loop is strictly harder to diagnose than a running
container whose `/health` says the probe could not run. A browser that
genuinely cannot launch already fails every render with a logged stack and a
`500`; killing the container adds no information and removes the endpoint you
would use to find out.

### Cost, and what it does not cost

The probe never blocks `listen()` — the server accepts requests immediately and
`/health` reports `pending` until the probe lands. Only `POST /render/flow`
waits for it, and that wait is bounded (`RENDER_FLOW_CAPABILITY_WAIT_MS`).

Its own work is three page loads and two `page.pdf()` calls on trivial
documents. Measured in the real image on a Docker Desktop host:

```json
"ms": 5550, "phases": { "launch_ms": 3791, "layout_ms": 504, "geometry_ms": 949, "paged_media_ms": 275 }
```

**The browser launch dominates and is entirely environmental.** The same image
on a cold VM — Chromium waiting out dbus timeouts that do not exist on a Linux
host — took **59 s**, essentially all of it the launch. That is why
`capabilities.phases` reports the launch separately: before concluding anything
about this code, look at which part took the time. The probe's own measuring is
~1.7 s and does not vary.

It launches a browser **of its own** and closes it, rather than warming the
shared instance in `src/renderer.js`. Two reasons, both load-bearing: a detector
must not be able to wedge the browser every subsequent render depends on; and
the idle-memory row in [Measured](#measured) (~32 MB, "Chromium not launched
yet") is what operators size hosts from, so a deployed-but-unused render
container must not quietly start carrying ~250 MB.

### What is deliberately not probed

`@page` margin boxes, `counter(page)` and `counter(pages)`. All three manifest
only as **text** in printed output, and reading that text back honestly needs a
font-aware PDF parser — `pdfjs-dist` is a devDependency and the runtime image
installs `--omit=dev`, so a probe built on it would only work outside the image
it is supposed to check. The available shortcut is asking the CSSOM whether the
at-rule parsed, which is exactly the class of answer `CSS.supports()` gives, and
exactly the class of answer this probe refuses to take. Nothing in either render
mode uses them, so they stay measured by hand and recorded in the table above.

---

## References

- [ADR 0012 — Document render as a dedicated microservice](../adr/0012-document-render-microservice.md)
- `render-service/Dockerfile`, `render-service/Dockerfile.dockerignore`
- `render-service/scripts/build-harness.js` (what gets bundled and why)
- `render-service/src/flow/` (the flowing mode: payload, HTML, bidi, paginator)
- `render-service/scripts/generate-flow-fixture.js`, `verify-flow-pdf.js`, `check-flow-rtl-geometry.js`
- `render-service/scripts/write-build-info.js` (how the image identifies itself)
- `render-service/scripts/write-browser-info.js`, `render-service/src/browser-info.js` (which browser it was built around)
- `render-service/src/capability-probe.js` (the boot-time probe, and which probes are fatal)
- `src/Api/DocumentRenderApiHandler.php`, `src/Core/Document/Render/RenderServiceClient.php`
- `src/Core/Settings/SettingsRegistry.php` (`documents.render_*`)
