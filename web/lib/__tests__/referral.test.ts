/**
 * Remembering who sent somebody, between the click and the form.
 *
 * ── What is actually at risk here ──────────────────────────────────────────
 *
 * Everything this module does is invisible when it goes wrong. A code that is
 * not captured, or is erased on the first navigation, produces a registration
 * that looks completely normal — an account is created, the customer is happy,
 * and one person somewhere is simply never paid. Nothing errors, no screen
 * changes, and the only party positioned to notice is the affiliate comparing
 * their own numbers months later.
 *
 * So the cases pinned here are the ones a happy-path test never reaches: the
 * second page view, the reload, the expired window, the clock that moved, the
 * browser that refuses to store anything at all.
 */

import {
  captureReferral,
  clearReferral,
  readReferralCode,
  referralCodeFromSearch,
  REFERRAL_WINDOW_DAYS,
} from '../referral';

const DAY_MS = 24 * 60 * 60 * 1000;
const NOW = Date.UTC(2026, 2, 1, 12, 0, 0);

describe('reading a code out of a URL', () => {
  it('takes the code an affiliate link carries', () => {
    expect(referralCodeFromSearch('?ref=SPRING26')).toBe('SPRING26');
  });

  it('finds it beside other parameters, in any position', () => {
    expect(referralCodeFromSearch('?utm_source=x&ref=SPRING26&utm_medium=y')).toBe('SPRING26');
  });

  it('accepts a search string without the leading question mark', () => {
    expect(referralCodeFromSearch('ref=SPRING26')).toBe('SPRING26');
  });

  /**
   * A CODE IS TYPED, PASTED AND FORWARDED BY PEOPLE. Surrounding space is the
   * commonest thing that happens to one, and it must not cost the affiliate the
   * sale — the server matches case-insensitively for the same reason.
   */
  it('trims what somebody pasted', () => {
    expect(referralCodeFromSearch('?ref=%20SPRING26%20')).toBe('SPRING26');
  });

  it('keeps the case it arrived in, and lets the server decide', () => {
    expect(referralCodeFromSearch('?ref=Spring26')).toBe('Spring26');
  });

  it.each([
    ['no parameter at all', '?utm_source=x'],
    ['an empty string', ''],
    ['present but empty', '?ref='],
    ['nothing but space', '?ref=%20%20'],
  ])('produces nothing for %s', (_case, search) => {
    expect(referralCodeFromSearch(search)).toBeNull();
  });

  /**
   * A LONG CODE IS SOMEBODY PROBING THE FORM, not a campaign. Refused here so
   * it is never stored and never posted — the server would reject it as
   * unknown, but there is no reason to carry a kilobyte that far.
   */
  it('refuses a code longer than any real one', () => {
    expect(referralCodeFromSearch('?ref=' + 'A'.repeat(65))).toBeNull();
  });

  it('accepts one exactly at the limit', () => {
    expect(referralCodeFromSearch('?ref=' + 'A'.repeat(64))).toBe('A'.repeat(64));
  });
});

describe('keeping the code until the form is filled in', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('attaches a captured code to a registration', () => {
    captureReferral('?ref=SPRING26', NOW);

    expect(readReferralCode(NOW)).toBe('SPRING26');
  });

  /**
   * THE CODE SURVIVES THE NEXT PAGE, which is the whole reason this exists.
   * Almost nobody registers on the page they land on; a capture that only
   * looked at the current URL would lose every referral that involved reading
   * anything first.
   */
  it('survives navigating on to a page with no parameter', () => {
    captureReferral('?ref=SPRING26', NOW);
    captureReferral('', NOW + 60_000);
    captureReferral('?utm_source=newsletter', NOW + 120_000);

    expect(readReferralCode(NOW + 120_000)).toBe('SPRING26');
  });

  /**
   * LAST TOUCH WINS. Somebody who arrives again through a different affiliate's
   * link was most recently persuaded by that one, which is the convention every
   * referral programme uses and therefore the one an affiliate expects.
   */
  it('replaces the code when a different link is used later', () => {
    captureReferral('?ref=FIRST', NOW);
    captureReferral('?ref=SECOND', NOW + DAY_MS);

    expect(readReferralCode(NOW + DAY_MS)).toBe('SECOND');
  });

  it('still credits an affiliate late in the window', () => {
    captureReferral('?ref=SPRING26', NOW);

    expect(readReferralCode(NOW + (REFERRAL_WINDOW_DAYS - 1) * DAY_MS)).toBe('SPRING26');
  });

  /**
   * A WINDOW THAT NEVER CLOSES CREDITS A SALE TO A LINK CLICKED TWO YEARS AGO.
   * The expiry is what makes the promise to an affiliate a statable one.
   */
  it('stops crediting once the window has closed', () => {
    captureReferral('?ref=SPRING26', NOW);

    expect(readReferralCode(NOW + (REFERRAL_WINDOW_DAYS + 1) * DAY_MS)).toBeNull();
  });

  /**
   * A CLOCK THAT MOVED BACKWARDS MUST NOT MINT AN IMMORTAL CODE. A capture
   * stamped in the future has a negative age, and an age compared only against
   * the upper bound would pass forever.
   */
  it('refuses a code stamped in the future', () => {
    captureReferral('?ref=SPRING26', NOW + 10 * DAY_MS);

    expect(readReferralCode(NOW)).toBeNull();
  });

  it('has nothing to attach when no link was ever clicked', () => {
    expect(readReferralCode(NOW)).toBeNull();
  });

  /**
   * FORGOTTEN ONCE THE WORKSPACE EXISTS. The server records the referral
   * permanently and a workspace keeps its first referrer, so a code left behind
   * would attach the same affiliate to the NEXT workspace this person creates —
   * on a link they never clicked again.
   */
  it('is forgotten after a workspace has been created', () => {
    captureReferral('?ref=SPRING26', NOW);
    clearReferral();

    expect(readReferralCode(NOW)).toBeNull();
  });
});

describe('when the browser will not cooperate', () => {
  beforeEach(() => {
    window.localStorage.clear();
    jest.restoreAllMocks();
  });

  /**
   * A PRIVATE WINDOW MUST NOT BREAK THE REGISTRATION PAGE. Storage throws
   * outright in some browsers rather than failing quietly, and a marketing
   * feature that can take the signup form down with it is a far worse trade
   * than a referral nobody gets paid for.
   */
  it('gives up quietly when storage refuses to write', () => {
    jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('The operation is insecure.');
    });

    expect(() => captureReferral('?ref=SPRING26', NOW)).not.toThrow();
  });

  it('gives up quietly when storage refuses to read', () => {
    jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('The operation is insecure.');
    });

    expect(readReferralCode(NOW)).toBeNull();
  });

  it('gives up quietly when storage refuses to clear', () => {
    jest.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new DOMException('The operation is insecure.');
    });

    expect(() => clearReferral()).not.toThrow();
  });

  /**
   * SOMEBODY ELSE'S DATA UNDER OUR KEY IS NOT A CODE. A stored value from an
   * older shape, or written by hand, must not be posted as a referral —
   * `JSON.parse` on it either throws or yields something that is not ours.
   */
  it.each([
    ['not JSON at all', 'SPRING26'],
    ['a bare string', '"SPRING26"'],
    ['an object without a code', '{"capturedAt":1}'],
    ['an object without a timestamp', '{"code":"SPRING26"}'],
    ['a code that is not a string', '{"code":123,"capturedAt":1}'],
    ['null', 'null'],
  ])('ignores %s left under its key', (_case, raw) => {
    window.localStorage.setItem('whity.referral', raw);

    expect(readReferralCode(NOW)).toBeNull();
  });
});
