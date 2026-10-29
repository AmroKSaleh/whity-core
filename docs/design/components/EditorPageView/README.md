# Page view

The A4 view for anything Whity Render prints (decision E.7). It shows the page as it will print: 794 × 1123px at 100% (A4 at 96 dpi), 16mm margins (stage 6), the tenant's letterhead on page 1 (logo, address and tax number at the start side; the title, number and date at the end side, stage 6), a small running header on the pages after it, and "Page x of y" with the reference in the footer. The title is the tenant's print colour (`--doc-accent`); everything else is ink.

- **Pages stay white in dark mode**, like paper; only the ground around them darkens (E.11).
- **Zoom:** Fit width, 50, 75, 100, 125%. **Show margins** draws the content box dashed.
- **Page breaks** come from the content flow, and a Page break block forces one. Whity Render paginates the final PDF (it measures rows); the editor's breaks are a close preview.
- **Preview with a record:** a bar above the pages names the record ("INV-1042 · Example Clinic"); merge fields show its values with a dotted underline instead of the field chips, and the pages show what would print now: suggestions not applied, comment highlights off, the rail emptied with a note. Read-only until "Show fields". Change record picks another.
- Margin rail and outline work as in the continuous view; the gutter sits in the page margin.
