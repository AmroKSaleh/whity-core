---
"@amroksaleh/ui": minor
---

`DataTable` gains `sorting` and `search` props, the two halves of server-side
list handling it was missing. Both are optional and nothing changes without
them: sorting and global search stay client-side, which is what every existing
caller is using.

The table has supported server PAGINATION for a while but always sorted and
filtered the rows itself, and those two facts do not compose. `getSortedRowModel`
sorts the rows it was handed — the twenty five on screen — so clicking a header
reorders one page and presents the result as a sorted list; page 2 then re-sorts
a different twenty five. A global filter over the same table reports "no
results" for a term whose match is sitting on page 4. Neither throws and neither
looks wrong, so the only way to notice is to already know.

Screens had started routing around it. The document library made its columns
non-sortable on purpose and moved sort into its own toolbar, with a comment
explaining that the alternative was shipping the untruth; that workaround is now
a choice rather than the only option.

`search` debounces before calling back — typing "engineering" is otherwise
twelve requests — with the delay overridable per table via `debounceMs` and the
default exported as `DATA_TABLE_SEARCH_DEBOUNCE_MS`. The input keeps its own
draft so typing stays responsive while a request is in flight, and adopts an
external change to `value` (a "clear search" control elsewhere on the page)
without clobbering characters typed in the meantime.

Per-column filters are unaffected in both modes. `manualFiltering` would have
been the obvious way to stop the component filtering by the search term, but
table-core has a single flag covering global AND column filtering, so setting it
would have silently disabled the per-column inputs that around eighteen admin
columns rely on. Keeping the term out of `state.globalFilter` does the same job
with no collateral.
