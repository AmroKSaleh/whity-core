/**
 * Resolving "may this caller edit this record, and if not, which gate refused".
 *
 * Every record page on this platform has MORE THAN ONE gate. The roles page has
 * two — the caller's `roles:write` capability and the role's server-computed
 * manageability — and the user page has three. Each screen was otherwise going
 * to re-write the same nested ternary, and the ternary is where the answer stops
 * being "the first refusal" and starts being "whichever branch the author put
 * last".
 */

import type { RecordAccess, RecordGate } from './types';

/**
 * Fold an ordered list of gates into a {@link RecordAccess}.
 *
 * The FIRST refusal wins, so the order is the screen's editorial decision: a
 * caller who both lacks the capability AND is looking at a record their tenant
 * cannot manage is told the more fundamental of the two, once. A gate whose
 * answer is not yet known should be passed as refusing with the honest reason —
 * fail-closed, matching the way `can()` answers while capabilities are in
 * flight.
 *
 * An empty list means editable: a record with no gates is one nobody is stopping
 * you from changing, and inventing a refusal for it would be the shell deciding
 * policy it has no basis for.
 */
export function resolveAccess(gates: readonly RecordGate[]): RecordAccess {
  for (const gate of gates) {
    if (!gate.allowed) {
      return { editable: false, readOnlyReason: gate.reason };
    }
  }
  return { editable: true, readOnlyReason: null };
}
