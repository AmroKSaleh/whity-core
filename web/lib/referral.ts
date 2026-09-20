/**
 * Remembering who sent somebody, between the link they clicked and the form
 * they eventually filled in.
 *
 * ── Why this is not just a hidden form field ───────────────────────────────
 *
 * An affiliate link lands wherever the affiliate chose to point it, and almost
 * nobody registers on the page they arrive at. They read something, open a
 * second tab, come back tomorrow. If the code only exists in the URL of the
 * page carrying the form, every referral that involved a moment's thought is
 * lost — and the affiliate is not paid for exactly the customers who took the
 * decision seriously.
 *
 * So the code is captured on arrival, kept, and attached to the registration
 * whenever it happens.
 *
 * ── The window is finite, deliberately ─────────────────────────────────────
 *
 * A stored code with no expiry credits a sale to somebody whose link was
 * clicked two years earlier and had nothing to do with it. Ninety days is the
 * ordinary commercial answer and, more importantly, it is a stated one: an
 * affiliate can be told what it is, and it is the same number for everybody.
 *
 * ── Storage can fail, and that is not an error ─────────────────────────────
 *
 * Private windows, cleared site data and browsers set to block storage all make
 * these calls throw or silently return nothing. Every one of them is wrapped,
 * because the alternative is a registration page that fails to render over a
 * marketing feature. A referral that cannot be remembered is a commission
 * nobody earns; a signup that breaks is a customer nobody gets.
 */

const STORAGE_KEY = 'whity.referral';

/** The query parameter an affiliate link carries. */
export const REFERRAL_PARAM = 'ref';

/**
 * How long a captured code keeps crediting its affiliate.
 *
 * Ninety days from the click, not from the first visit of the session: the
 * window is about how long ago somebody was persuaded, and re-reading the same
 * article does not persuade them again.
 */
export const REFERRAL_WINDOW_DAYS = 90;

/**
 * Longest code we will store or send.
 *
 * Codes are human-typed marketing strings. Anything past this is somebody
 * probing the form, and there is no reason to keep it, let alone post it.
 */
const MAX_CODE_LENGTH = 64;

interface StoredReferral {
  code: string;
  /** When the link was clicked, as epoch milliseconds. */
  capturedAt: number;
}

/**
 * Take the code out of a URL, if it carries one.
 *
 * Exported and pure so the parsing rules can be tested without a browser, a
 * router, or a rendered page.
 */
export function referralCodeFromSearch(search: string): string | null {
  let params: URLSearchParams;
  try {
    params = new URLSearchParams(search);
  } catch {
    return null;
  }

  const raw = params.get(REFERRAL_PARAM);
  if (raw === null) {
    return null;
  }

  const code = raw.trim();
  if (code === '' || code.length > MAX_CODE_LENGTH) {
    return null;
  }

  return code;
}

/**
 * Remember a code found in the current URL.
 *
 * LAST TOUCH WINS. Somebody who clicks one affiliate's link, reads on, then
 * arrives through a second affiliate's link was most recently persuaded by the
 * second — and that is the ordinary convention across referral programmes, so
 * it is the one an affiliate will already expect.
 *
 * (The server has a different rule for a different question: a workspace keeps
 * its FIRST referrer, because that is about the same workspace being claimed
 * twice, not about which link did the persuading.)
 *
 * @param search The query string, e.g. `window.location.search`.
 */
export function captureReferral(search: string, now: number = Date.now()): void {
  const code = referralCodeFromSearch(search);
  if (code === null) {
    // NO PARAMETER MEANS LEAVE WHAT IS THERE. Ordinary navigation inside the
    // app has no `?ref=`, and treating that as "no referrer" would erase the
    // code on the first click after arriving — which is every registration
    // that did not happen on the landing page.
    return;
  }

  try {
    const stored: StoredReferral = { code, capturedAt: now };
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
  } catch {
    // Storage refused. The referral is lost; the page is not.
  }
}

/**
 * The code to attach to a registration, or null if there is none to attach.
 *
 * Returns null rather than an expired code: a window that has closed has
 * closed, and sending it anyway would make the server the only thing deciding,
 * which is a rule in two places that will eventually disagree.
 */
export function readReferralCode(now: number = Date.now()): string | null {
  let raw: string | null;
  try {
    raw = window.localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }

  if (raw === null) {
    return null;
  }

  let stored: unknown;
  try {
    stored = JSON.parse(raw);
  } catch {
    // Somebody else's key, or ours from a version that stored something else.
    return null;
  }

  if (
    typeof stored !== 'object' ||
    stored === null ||
    typeof (stored as StoredReferral).code !== 'string' ||
    typeof (stored as StoredReferral).capturedAt !== 'number'
  ) {
    return null;
  }

  const { code, capturedAt } = stored as StoredReferral;

  // A capture stamped in the future is a clock that moved, not a referral from
  // tomorrow. Treated as expired: the alternative is a code that outlives every
  // window because its age is negative.
  const ageMs = now - capturedAt;
  if (ageMs < 0 || ageMs > REFERRAL_WINDOW_DAYS * 24 * 60 * 60 * 1000) {
    return null;
  }

  return code === '' || code.length > MAX_CODE_LENGTH ? null : code;
}

/**
 * Forget the code.
 *
 * Called once a workspace has been created, because the server has now recorded
 * the referral permanently and a workspace keeps its first referrer for good.
 * Leaving it would attach the same affiliate to the NEXT workspace this person
 * creates, months later, on a link they never clicked again.
 */
export function clearReferral(): void {
  try {
    window.localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Nothing to do. A stale code expires on its own.
  }
}
