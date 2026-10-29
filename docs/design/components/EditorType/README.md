# Editor typography

Two scales for the same blocks (part 8): the screen scale in the continuous sheet, and the print scale (stage 6) in the page view. A heading is a heading in both; only the size changes.

| | On screen (continuous) | On paper (page view) |
| --- | --- | --- |
| Title | Readex Pro 600, 31px, −1% (a document title, 3.1) | Readex Pro 600, 22pt, the tenant's print colour |
| Heading 1 · 2 · 3 | Noto Sans 600, 24 · 20 · 18px | Noto Sans 600, 14 · 12 · 10.5pt |
| Body | Noto Sans 400, 16px, line height 1.5 | Noto Sans 400, 9.5pt, line height 1.5 |
| Arabic | Noto Sans Arabic at the same sizes, line height 1.7 | the same |
| Measure | 616px of text (at most 68 characters, 4.5) in a 760px sheet | 178mm on A4 |

Rules: no 700 (bold is 600); nothing below 11px on screen; Latin inside Arabic is isolated (`bdi`); each block's direction follows its own text, so an Arabic paragraph in an English document runs right to left.
