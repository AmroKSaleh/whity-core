---
"@amroksaleh/ui": minor
---

Add two primitives for the offline-sync UI: `LockedScreen` (a full-page locked
state, sibling of `AccessDenied`, for an offline-TTL-expired session) and
`RadioGroup` / `RadioGroupItem` (accessible single-select on Radix, used by the
field-level conflict resolver's per-field picker). Additive — new subpath exports
(`@amroksaleh/ui/locked-screen`, `@amroksaleh/ui/radio-group`) + barrel re-exports.
