# Toggle group

Buttons that stay pressed: a view switch (one at a time) or filters (any number). A pressed toggle is the tint with text in the main colour, never a solid fill (7.3). For a short either/or setting inside a form, use `Segmented`; for choices that need a label and a description, use `RadioGroup`.

**Props:** `options` (`[{value, label, icon?, iconOnly?, shortcut?, disabled?}]`), `type` (`single` or `multiple`), `defaultValue` / `value`, `required` (a single group can't be emptied), `size` (`sm` 28px for toolbars), `label`, `onChange`.

Every button reports `aria-pressed`. Icon-only toggles carry a tooltip with their name.
