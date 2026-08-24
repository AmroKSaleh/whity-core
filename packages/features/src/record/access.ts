/**
 * Resolving what a caller may do to a record — and, since #910, to each REGION
 * of one.
 *
 * Every record page on this platform has MORE THAN ONE gate. The roles page has
 * two — the caller's `roles:write` capability and the role's server-computed
 * manageability — and the user page has three. Each screen was otherwise going
 * to re-write the same nested ternary, and the ternary is where the answer stops
 * being "the first refusal" and starts being "whichever branch the author put
 * last".
 *
 * WHERE THE ANSWER COMES FROM, which #910 is really about. Nothing in this file
 * DECIDES anything: every input is already an answer the server gave. A gate's
 * `allowed` is a server-computed flag off the record or a slug the server put in
 * `/me/capabilities`; a verdict is the server's per-region ruling. The functions
 * here fold answers into a rendering decision, and a fold is not a policy. The
 * moment a browser combines two permissions into a third the deployment never
 * granted, the client holds an opinion about authorization — and an opinion the
 * server does not share is either a control that is decoration or a refusal
 * nobody asked for.
 */

import type {
  RecordAccess,
  RecordGate,
  RecordSectionAccess,
  RecordSectionGate,
  RecordSectionVerdicts,
} from './types';

/** The `hidden` state, which has nothing to say and no reason to carry. */
const HIDDEN: RecordSectionAccess = { state: 'hidden', readOnlyReason: null };

/** The `editable` state. */
const EDITABLE: RecordSectionAccess = {
  state: 'editable',
  readOnlyReason: null,
};

/**
 * Fold an ordered list of region gates into a {@link RecordSectionAccess}.
 *
 * The FIRST refusal wins, so the order is the screen's editorial decision: a
 * caller who both lacks the capability AND is looking at a record their tenant
 * cannot manage is told the more fundamental of the two, once. A gate whose
 * answer is not yet known should be passed as refusing with the honest reason —
 * fail-closed, matching the way `can()` answers while capabilities are in
 * flight.
 *
 * A `hide` refusal short-circuits with no reason, and that asymmetry is the
 * point of having three states: you cannot be told WHY you may not see
 * something you may not see, because the telling is itself the disclosure. It
 * also means a hide gate listed after a read-only gate never renders the
 * read-only one's sentence about a region that is about to vanish — so screens
 * should list hide gates first, and get the same answer either way if they do
 * not, since a hidden region shows nothing regardless.
 *
 * An empty list means editable: a region with no gates is one nobody is
 * stopping you from changing, and inventing a refusal for it would be the shell
 * deciding policy it has no basis for.
 */
export function resolveSectionAccess(gates: readonly RecordSectionGate[]): RecordSectionAccess {
  let refusal: RecordSectionAccess | null = null;
  for (const gate of gates) {
    if (gate.allowed) continue;
    // A hide beats a read-only wherever it appears in the list: rendering a
    // region read-only because an earlier gate got there first would show a
    // caller a region a later gate says they may not see at all. "First refusal
    // wins" decides which SENTENCE is shown, never whether the region exists.
    if (gate.effect === 'hide') return HIDDEN;
    refusal ??= { state: 'read-only', readOnlyReason: gate.reason };
  }
  return refusal ?? EDITABLE;
}

/**
 * Read one region's state out of the SERVER'S verdicts.
 *
 * This is the whole "who decides" answer in a dozen lines. The server resolves
 * the region against the same `RoleChecker` its middleware enforces with, ships
 * a verdict per region it is willing to show, and OMITS the rest. So:
 *
 *  - key absent  ⇒ hidden. Not a flag saying hidden — an absence. The response
 *    that leaves this key out is the same response that leaves the region's data
 *    out, so there is nothing to render and nothing to leak. A viewer is never
 *    shipped the LABELS of things they may not see.
 *  - `read-only` ⇒ read-only, with a sentence composed from the denial below.
 *  - `editable`  ⇒ editable.
 *
 * `verdicts` undefined resolves everything to hidden. A screen that asked to be
 * told and was told nothing has not been told yes.
 *
 * HOW THE SENTENCE IS COMPOSED, once here rather than per screen — the two
 * rules #968 established for a denied crud control, applied to a region:
 *
 *  1. **The screen's localized string, falling back to the server's.** A screen
 *     keys its own copy off `denial.code`; returning null (a code this build has
 *     never heard of) falls through to `denial.reason`, so a newer server leaves
 *     the region correctly read-only with a vague explanation rather than
 *     correctly read-only with a blank space where the explanation goes.
 *  2. **The operator-grade `detail` appended when the server sent one**, which
 *     it does only for a caller it decided may read it. The client never
 *     re-decides that audience.
 *
 * @param verdicts The `sections` map from the record payload, if it carried one.
 * @param key      The region's key — the same string the server keyed it by.
 * @param localize This screen's copy for a denial code, or null to fall back.
 */
export function sectionAccessFrom(
  verdicts: RecordSectionVerdicts | undefined,
  key: string,
  localize: (code: string) => string | null
): RecordSectionAccess {
  const verdict = verdicts?.[key];
  if (verdict === undefined) return HIDDEN;
  if (verdict.state === 'editable' || verdict.denial === null) return EDITABLE;

  const denial = verdict.denial;
  const reason = localize(denial.code) ?? denial.reason;
  return {
    state: 'read-only',
    readOnlyReason: denial.detail !== null ? `${reason} (${denial.detail})` : reason,
  };
}

/**
 * Fold an ordered list of gates into a page-level {@link RecordAccess}.
 *
 * The page-level binary, unchanged since #897 and still correct for a record
 * governed as one thing. Expressed through {@link resolveSectionAccess} rather
 * than beside it: a page is a region whose refusals can only ever be read-only,
 * and two folds of the same shape are two folds that drift.
 */
export function resolveAccess(gates: readonly RecordGate[]): RecordAccess {
  const access = resolveSectionAccess(
    gates.map((gate) => ({
      allowed: gate.allowed,
      effect: 'read-only' as const,
      reason: gate.reason,
    }))
  );
  return {
    editable: access.state === 'editable',
    readOnlyReason: access.readOnlyReason,
  };
}
