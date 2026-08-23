/**
 * The ONE substitution behind every `{token}` a declaration can put in a path —
 * desktop twin of `web/components/plugin/blocks/context-path.ts`.
 *
 * `dataRecord.source`, an `accessGate`'s `check.endpoint` and a form's
 * `dataSource.path` all spell the same thing the same way, and #949/#957 were
 * what happens when they each spell it themselves. This renderer held two
 * hand-written copies of the syntax and no third one at all: the form's path
 * went to `usePluginData` verbatim, so an edit form on a record pane requested
 * `/things/%7Brecord%7D` and pre-populated with nothing. Everything that
 * substitutes a context token in a READ path now goes through here, so there is
 * nothing left for the three of them to disagree about.
 *
 * WHY THIS IS A COPY OF WEB'S AND NOT AN IMPORT OF IT. This template ships to
 * consumers who get `@amroksaleh/*` from the registry and no `web/` at all, and
 * web's copy lives in the unpublished Next app — `templates/tauri-desktop` is
 * its own Vite bundle with its own tsconfig, and nothing in `src/` can reach
 * across into it. Hoisting the helper into `@amroksaleh/features` would make it
 * importable from both, at the cost of a packaging change (a published surface
 * for a private helper, and a version bump the template would have to take) —
 * the same trade already recorded in `fetch-all-pages.ts`, and answered the same
 * way. `block-renderer-payload-parity.test.tsx` runs both renderers over one
 * tree and is what keeps the copy honest; #957 exists because that test did not
 * yet cover form preloads.
 *
 * Kept deliberately diffable against web's, one difference aside: web's context
 * refs resolve to `string | undefined`, this renderer's `resolveFromContext`
 * returns `unknown` (a row field can arrive as a number, or as a JSON `null`).
 * The normalisation below is the one both of this file's callers already did.
 */

/**
 * Substitute a path's `{token}` segments from the master-detail context, or
 * return `null` when ANY of them is unresolved.
 *
 * `null` IS THE POINT, and it is why this is not a `String.replace` with `""`
 * for the misses. `/api/v1/things/{record}` with nothing bound becomes
 * `/api/v1/things/` — which is very often the COLLECTION endpoint, so the
 * truncated request does not fail. It succeeds and returns the wrong thing.
 * There is no honest answer to "which record?" before something has said, and
 * `null` is how a caller is told to ask nothing at all.
 *
 * That matters more here than it does on the web. A device runs against its own
 * local host with nobody watching, and what it writes syncs later: a form
 * pre-populated from the wrong record — or from nothing — is submitted, the
 * blanked row replicates as a legitimate edit, and the sync engine cannot tell
 * it from an intentional clear.
 *
 * `resolveRef` is optional so that "no resolver in scope" and "the resolver
 * knows nothing about this ref" reach the same answer: a path with a token in
 * it is unresolved, and a path without one is returned as it was written.
 *
 * @param path       The declared path, possibly carrying `{token}` segments.
 * @param resolveRef Resolves one ref (the text between the braces) to its
 *                   current value. `undefined`, `null` and `""` all mean
 *                   nothing has bound it.
 * @returns The substituted path, or `null` while any token is unbound.
 */
export function resolveContextPath(path: string, resolveRef?: (ref: string) => unknown): string | null {
  // Split on the tokens rather than replacing through a callback: a callback
  // that recorded "something did not resolve" in a closure variable is a
  // reassignment during render, which the React compiler refuses (and is right
  // to — the same expression would read differently on a re-render). Splitting
  // on a CAPTURING pattern puts every token at an odd index, so the whole thing
  // becomes a map and a join with nothing mutable in it.
  const parts = path.split(/(\{[^{}]*\})/)
  const resolved = parts.map((part, index) => {
    if (index % 2 === 0) return part
    const value = resolveRef?.(part.slice(1, -1))
    return value === undefined || value === null || value === "" ? null : encodeURIComponent(String(value))
  })
  return resolved.some((part) => part === null) ? null : resolved.join("")
}
