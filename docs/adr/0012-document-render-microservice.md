# ADR 0012: Document render as a dedicated microservice

- **Status:** Accepted
- **Date:** 2026-07-12
- **Task / Issue:** WC-docdesigner (58cdd88a) — Track 2
- **Deciders:** Amro Saleh

## Context

The document/label designer (`web/app/(protected)/admin/documents`,
`web/components/documents/*`) authors templates that must render to PDF/PNG at exact
physical (mm) size — device serial-number labels, multi-page documents, N-up
Avery-style label sheets, variable-data batches (CSV / sequence generators). Today
output goes through the browser print dialog: fine for authoring, unreliable at
volume and for exact sizing.

The web already contains a faithful renderer —
`web/components/documents/print-document.tsx` — that lays out every page/row/cell at
mm sizes and rasterises barcodes (bwip-js). The core question is **what produces the
server-side PDF**, and the dominant constraint is **parity**: for a WYSIWYG tool the
classic failure is drift between on-screen preview and printed output. Reusing the
one renderer avoids a second source of truth.

Adding a browser engine touches the third-party-dependency policy (architect
approval) and the sovereign-deployment footprint concern (Chromium is heavy). A
further operational fact drove the shape: **export is seasonal and bursty** — a
whole cohort's exams/reports/QA files can be exported at once — so render load does
not track steady request load.

## Decision

We will render with **headless Chromium reusing the existing React renderer**, run
as a **separate, dedicated, dockerized render microservice** that whity-core calls
over internal HTTP — **not** Chromium embedded in the FrankenPHP image.

- **`whity_render` service** (own Docker image + compose service): Node +
  Puppeteer/headless Chromium that loads `print-document.tsx` with `{template JSON +
  dataset rows + sheet}` and returns PDF/PNG at exact mm size, N-up tiled →
  pixel-parity with the on-screen preview. **Arabic fonts (Noto Naskh / Noto Sans
  Arabic) are baked into this image** with correct shaping, or Arabic prints as tofu
  (hard cross-cutting RTL requirement).
- **Core endpoint** `POST /api/v1/document-templates/{id}/render` (permission
  `documents:render`): resolves the template **tenant-scoped + RBAC**, assembles the
  payload, calls the render service over internal HTTP, and streams the PDF back.
  **Shared-secret auth** between core↔render (≥32 chars). On the service being
  down/disabled it fails gracefully (503), never a raw exception.
- **The render tier is optional and independently scalable.** A minimal sovereign
  deploy can omit it (compose profile); high-volume render is entitlement-gateable,
  and the payment wall (ADR 0011) already returns 402 without ever blocking the
  system tenant.

This supersedes the relay's open question ("is Chromium in the deployment image
acceptable for sovereign footprints") — the engine is isolated in its own container,
so the core image stays lean and the render tier scales horizontally on its own.

## Alternatives Considered

- **Headless Chromium embedded in the FrankenPHP image** — parity, but bloats every
  core container with Chromium + fonts and couples bursty render load to the request
  tier (can't scale independently). Rejected in favour of a separate service.
- **PHP PDF lib (Dompdf / mPDF)** — no browser, stays in PHP, light. But cannot reuse
  the React renderer; someone re-implements mm-absolute layout + barcode raster
  server-side → a second renderer that drifts from the canvas. Rejected as primary;
  acceptable only with golden-image drift tests if Chromium is ever untenable.
- **JS PDF lib (pdf-lib / pdfkit)** — same second-renderer problem as Dompdf.
  Rejected.

## Consequences

### Positive

- Single renderer → printed output is pixel-identical to preview and to what authors
  designed; barcodes and N-up tiling already handled by `print-document.tsx`.
- Core image stays lean; the render tier scales horizontally for seasonal bursts
  without touching core.
- Optional for minimal deploys; entitlement-gateable for volume.

### Negative / Trade-offs

- A new service to build, deploy, and secure (shared secret, network boundary).
- Chromium's memory/CPU footprint, now isolated but still real for operators running
  the render profile.
- Core must degrade gracefully when the render tier is absent/disabled.

### Impact on existing conventions

- New Docker image + compose service; **new dependency (Puppeteer)** — approved here
  under the third-party policy for the render service only, not for core PHP.
- The render endpoint is tenant-scoped + RBAC (`documents:render`) like every other
  route; it must never render another tenant's template.
- Render limits (max batch rows / page count / template size) are **settings**, not
  hardcoded (per-tenant ?? global default ?? registry default).

## References

- Task 58cdd88a (Document/label designer backend) — canonical brief; Track 1
  (persistence/RBAC/CRUD/seed) shipped in #514/#515 and is independent of this.
- `web/components/documents/print-document.tsx` (the renderer to reuse).
- [Arabic / RTL requirement], [doc-designer scope / starters / blocks notes].
