---
"@amroksaleh/features": minor
"@amroksaleh/ui": minor
---

Add `@amroksaleh/features/datetime`: `DateDisplayProvider` and
`useDateDisplay()`, the one path a date takes to a screen. The hook carries both
things a call site should never have to remember — the reader's resolved
language, and whether this tenant has asked for dates to be off the screen
entirely (`ui.hide_dates`, #1068). It exposes `date`, `dateTime`, `age`,
`relative` and `dateColumns`, all of which return nothing when dates are hidden.

**Breaking (`@amroksaleh/features`).** `formatRecordDate` and
`formatRecordDateTime` are no longer exported from `@amroksaleh/features/record`,
and the `./record/format` subpath is gone. They were one of six ways a date
reached a screen, and a gated one of six is a setting that is 90% true. Use
`useDateDisplay()` instead; it needs no locale argument, which is what eight of
the twenty former call sites had forgotten to pass.

Two behaviours changed in the consolidation. A wire timestamp is now read as the
UTC instant the server meant — PostgreSQL returns `2026-08-25 14:02:11` with no
offset, which `new Date()` reads in the *local* zone — and the formatters return
`null` rather than the raw wire string for a value they cannot parse, because
`formatter(x) ?? x` prints the timestamp the formatter has just declined to.

**Breaking (`@amroksaleh/ui`).** `PluginVersion.releasedAt` becomes
`releasedLabel`, and is optional. It was always display text in practice
("Current (Installed)", "2 weeks ago") printed verbatim, and a name that says
"timestamp" over a field that is really a label is how an unformatted
`2026-08-25 14:02:11` reaches a reader. This package cannot format a date — it
has access to neither the reader's language nor the tenant preference — so the
caller formats and omits.

`RegistrySettingControl` now renders its inherited/overridden badge on `bool`
controls too, when a caller passes `status`.
