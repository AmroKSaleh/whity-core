# DonutChart

Shares of one total, when there are few parts and the total matters. Up to six slices: past that the smallest fold into "Other" (graphite). Slices are separated by a gap in the card colour; the total sits in the middle and changes to a slice's value and share on hover or focus. The legend carries every name, value and percentage, so nothing depends on matching colours.

**Props:** `data` (`[{label, value}]`), `format`, `title`, `subtitle`, `totalLabel`, `size` (default 176), `sort` (`false` keeps your order), `labels`.

For comparing amounts across categories, a bar chart reads better; use a donut only for parts of a whole. For states (paid, overdue) prefer words and badges in the table; the donut is a summary.
