# Arabic and right-to-left

Whity has **no Arabic name, logo, tagline or brand copy**. The product ships a full Arabic interface, and documents can lead in Arabic, because the platform is built for companies in the Arab world.

## Layout

- Set `dir="rtl"` and `lang="ar"` on the page. Components use logical properties, so the shell, rows, menus, drawers, breadcrumbs and tabs mirror on their own. The drawer and the mobile sidebar slide in from the right.
- Direction icons mirror (chevrons, `arrow-right`, `send`, `undo`, `external`, `logout`). **The mark, the lockup and the plane never mirror**: the lockup stays left to right with the mark on the left, and the plane always sits down-left.
- Numbers, money inputs, one-time codes, IBANs and codes stay left to right inside Arabic lines, isolated with `<bdi>` or Unicode isolates.

## Type

- **Noto Sans Arabic at the same size as Latin** (100%), line height 1.7 for body and 1.5 for labels and titles; no letter spacing, no uppercase.
- **Document titles in Readex Pro's Arabic design** (E.35), as Latin titles are; product screens stay in Noto Sans Arabic.
- **Western digits 0–9** everywhere. Inputs accept Arabic-Indic digits and the Arabic decimal mark as typed and store Western digits.
- The name stays "Whity" in Latin letters, isolated with `<bdi>`. Never a transliteration.

## Dates and calendars

- Levantine month names in Arabic: كانون الثاني، شباط، آذار، نيسان، أيار، حزيران، تموز، آب، أيلول، تشرين الأول، تشرين الثاني، كانون الأول. Dates read "27 تشرين الأول 2026".
- Calendars start on Saturday and grey Friday and Saturday as the weekend; working days run Sunday to Thursday. Both can be changed per tenant.

## Documents

Either language can lead, chosen per customer: Arabic content with small English labels (the layout mirrors), or English with small Arabic labels. Amounts are written in words in both languages; the Arabic follows accounting usage (tafqeet) and must be reviewed by an Arabic-speaking accountant before shipping. See section 16.

## Words and review

- Each language is named in its own script: "English", "العربية".
- Interface strings are written by a native speaker in clear Modern Standard Arabic, in short sentences, and reviewed before shipping. Every component takes `labels` for its Arabic strings.
- whity-core's catalogues (`database/i18n/ar/`) hold the shipped strings.
