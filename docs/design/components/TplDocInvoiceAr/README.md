# Tax invoice (Arabic leads)

The same tax invoice for a customer who reads Arabic first: Arabic content with small English labels, and the layout mirrors (tenant at the right, title block at the left, totals at the left). Codes, dates and Latin values are isolated with `bdi` so they read correctly inside Arabic lines. Digits stay Western (0–9). The tenant chooses the lead language per customer.

Built from `Doc` with `lead: 'ar'`; every standard label comes from `Whity.docLabels`.
