---
"@whity-org/ui": minor
---

New `CopyBlock`: text the user is meant to copy somewhere else (a command, a
config file, a token) with a copy button. It renders as left-to-right monospace
code even in an RTL layout, can show a masked `display` while copying the real
`value`, takes a `caption` (a file path, a field name) and translated
`copyLabel` / `copiedLabel`, and reports each copy through `onCopy` so the
consumer can toast a failure. Also in the shadcn registry as `copy-block`.
