# Tax invoice (continuation pages)

A long invoice (26 lines) that runs to a second page. Page 1 keeps the full letterhead, parties, stamp and QR and ends with "Carried forward" and the running sum. Page 2 has a small running header (logo, number, "Tax invoice · continued"), repeats the table header, opens with "Brought forward", and carries the totals and the amount in words, because totals go on the last page only. Every page shows "Page x of y".

`Doc` splits rows by count (`rowsFirst` 14, `rowsNext` 20, `tailRows` 6 kept free for the totals) and never leaves the totals alone on a page. Whity Render should measure row heights when it prints; the counts are a safe default for single-line rows.
