# BarChart

Compares amounts across a few categories: months, branches, products. Bars are at most 24px thick with a 4px rounded end, square on the baseline, grouped with a 2px gap. One value is labelled (the highest, or `highlight`); every bar shows its values on hover and keyboard focus, and "Show as table" gives them all.

**Props:** `data` (`[{label, [key]: number}]`), `series` (`[{key, label}]`, default one `value` series), `format`, `title`, `subtitle`, `height`, `highlight` (index), `label` (`false` for none), `categoryLabel`, `labels`.

Colours come from the 2.7 palette in fixed order: series 1 is always the tenant's main colour, so a tenant's charts are in its brand. A legend appears for two or more series. One axis only; text is never in a series colour. On paper (`DocBars`) bars are grey with the one that matters in ink.
