/**
 * The ONE substitution behind every `{token}` a declaration can put in a path.
 *
 * `dataRecord.source`, an `accessGate`'s `check.endpoint` and a form's
 * `dataSource.path` all spell the same thing the same way, and #949 was what
 * happens when they each spell it themselves: two interpolated and one did not,
 * so the same `/things/{record}` meant three different requests depending on
 * which field an author wrote it in. This module exists so there is nothing
 * left to disagree about.
 *
 * It lives in its own file rather than in `block-renderer`, which imports
 * `FormProvider` from `form-context` — the form could not reach a helper there
 * without closing the cycle.
 */

/**
 * Substitute a path's `{token}` segments from the master-detail context, or
 * return `null` when ANY of them is unresolved.
 *
 * `null` IS THE POINT, and it is why this is not a `String.replace` with `''`
 * for the misses. `/api/v1/things/{record}` with nothing bound becomes
 * `/api/v1/things/` — which is very often the COLLECTION endpoint, so the
 * truncated request does not fail. It succeeds and returns the wrong thing.
 * There is no honest answer to "which record?" before something has said, and
 * `null` is how a caller is told to ask nothing at all.
 *
 * `resolveRef` is optional so that "no resolver in scope" and "the resolver
 * knows nothing about this ref" reach the same answer: a path with a token in
 * it is unresolved, and a path without one is returned as it was written.
 *
 * @param path      The declared path, possibly carrying `{token}` segments.
 * @param resolveRef Resolves one ref (the text between the braces) to its
 *                   current value, or `undefined` when nothing has bound it.
 * @returns The substituted path, or `null` while any token is unbound.
 */
export function resolveContextPath(
  path: string,
  resolveRef?: (ref: string) => string | undefined
): string | null {
  // Split on the tokens rather than replacing through a callback: a callback
  // that recorded "something did not resolve" in a closure variable is a
  // reassignment during render, which the React compiler refuses (and is right
  // to — the same expression would read differently on a re-render). Splitting
  // on a CAPTURING pattern puts every token at an odd index, so the whole thing
  // becomes a map and a join with nothing mutable in it.
  const parts = path.split(/(\{[^{}]*\})/);
  const resolved = parts.map((part, index) => {
    if (index % 2 === 0) return part;
    const value = resolveRef?.(part.slice(1, -1));
    return value === undefined || value === '' ? null : encodeURIComponent(value);
  });
  return resolved.some((part) => part === null) ? null : resolved.join('');
}
