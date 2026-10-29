# Doc (Whity Render)

The building block for every printed document: `Doc` lays out A4, A5 or US Letter pages to the stage 6 rules and splits long line tables over continuation pages.

- `lead`: `'en'` or `'ar'`. The lead language is the content; the other gives small labels. `'ar'` mirrors the layout and isolates Latin values with `bdi`.
- `title`, `number`, `date`, `tenant` ({ name, initial or logo, lines }), `parties` ([{ label, value, lines, mono }]).
- `columns` ([{ key, label, type: 'money', align: 'end', mono, width }]) and `rows`. Money is JOD to 3 decimals, tabular, end-aligned.
- `totals`, `grand` (the only bold line, 1.5 pt rule above) and the amount in words under it in both languages (`words: false` to hide).
- `stamp` (true for "Issued through JoFotara"), `reference`, `qr` ({ src, label }), page numbers on every page.
- Continuation: `carry` (the column to sum), `rowsFirst` 14, `rowsNext` 20, `tailRows` 6.
- `intro` goes above the table (the receipt's amount); children go after the totals (`DocNote`, `DocSign`).
- `aside` sits at the start side beside the totals on the last page (the payslip's leave balance).

Related parts:
- `DocPages` gives free-form pages (letters, reports) the same letterhead, running header and foot: pass `pages` (one body per page) and optional `parties`. Without a `title`, the header's end carries only the reference and date (letters).
- `DocTable` is the same rules-only table for secondary tables (ageing, approval trail, report tables); `compact` tightens rows; a row with `_strong: true` is a bold total row. Column `type: 'percent'` formats percentages. A text column after a number column gets 6 mm more space.
- `DocKpis` prints key numbers in ink with a rule above each.
- `DocBars` is a bar chart for paper: grey bars, the one that matters in ink (`highlight`, the last by default), values on the bars, light gridlines (`ticks`), no legend. Paper keeps the tenant colour on the title only, so charts on documents never use colour.

Labels: pass a key from `Whity.docLabels` (for example `'billTo'`) or { en, ar }. `Whity.amountInWords(amount, 'en' | 'ar')` writes JOD in words; the Arabic follows accounting usage (tafqeet). Have an Arabic-speaking accountant review it before shipping.

Print: each page is a `section.wy-doc` sized in mm; in print the shadow goes and each page breaks.
