# LineChart

Change over time. Lines are 2px with round joins; the last point has an 8px dot with a 2px ring in the card colour and its value beside it. Moving the pointer (or the arrow keys once the chart has focus) snaps a crosshair to the nearest date and lists every series there, so nobody has to aim at a 2px line. A single series can carry a 10% wash (`area`).

**Props:** `data` (`[{label, [key]: number}]`), `series`, `format`, `title`, `subtitle`, `height`, `area`, `endLabels` (`false` to hide), `categoryLabel`, `labels`.

Time runs left to right in both languages, like the numbers on it. One axis only: two measures of different scale get two charts. Past four series, use small multiples.
