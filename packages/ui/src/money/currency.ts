/**
 * Minor units, major units, and the exponent that separates them — the client
 * half of `Whity\Core\Money\Currency`.
 *
 * WHY THERE IS A CLIENT HALF AT ALL. Amounts cross the wire as integers of
 * minor units, which is the only representation that does not lose money to
 * floating point. Turning 5000 into something a person reads therefore happens
 * here, and it cannot be done without knowing that the Jordanian dinar has
 * THREE decimal places: 5000 fils is 5.000 JOD, not 50.00. Dividing by 100 —
 * which is what every hand-rolled formatter does — shows every customer an
 * amount ten times too large.
 *
 * WHY `Intl` IS NOT THE SOURCE OF THE EXPONENT. It is the obvious idea — the
 * engine ships ISO 4217 data, so why transcribe a table? — and it is wrong.
 * `Intl.NumberFormat(...).resolvedOptions().maximumFractionDigits` reports
 * CLDR's DISPLAY convention, not the currency's official exponent, and the two
 * disagree for thirteen currencies whose minor unit is worthless in practice.
 * ICU says the Iraqi dinar has 0 decimal places; ISO 4217 says 3. Lebanese
 * pounds, Afghanis, Serbian dinars and ten others are the same story with 0
 * against 2.
 *
 * Deriving the exponent from `Intl` here would therefore have made the client
 * disagree with the server about what an IQD amount MEANS — by a factor of a
 * thousand — while every JOD and USD test passed. So the table below is the
 * authority, it is ISO's numbers, and it is identical to the PHP one by
 * construction; a test asserts the two tables entry-for-entry and separately
 * pins the ICU divergences so they cannot change unnoticed.
 *
 * `Intl` is still used for what it is actually good at: placing symbols and
 * digits for a locale. `formatMoney` forces the fraction digits to OUR
 * exponent, so the figure is ISO-correct even where CLDR would round it away.
 *
 * WHAT IS DELIBERATELY SPLIT IN TWO. `toDecimalString` is exact — integer
 * arithmetic only, correct for any amount an integer can hold. `formatMoney`
 * is the locale-aware one, and it is what screens use: `Intl` places the symbol
 * and picks the digits, which is how the Arabic build gets Arabic-Indic
 * numerals and correct RTL placement without this file knowing anything about
 * either.
 */

/** Two decimal places, which is every active ISO 4217 code not listed below. */
const DEFAULT_EXPONENT = 2;

/**
 * Every active ISO 4217 currency whose exponent is not two. THE AUTHORITY, not
 * a fallback: see the note above on why `Intl` cannot play this role. Identical
 * to the PHP table by construction — `currency-exponent-parity.test.ts` asserts
 * it entry for entry.
 */
const EXPONENT_EXCEPTIONS: Readonly<Record<string, number>> = {
  BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
  BIF: 0, CLP: 0, DJF: 0, GNF: 0, ISK: 0, JPY: 0, KMF: 0, KRW: 0, PYG: 0,
  RWF: 0, UGX: 0, UYI: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0,
  CLF: 4, UYW: 4,
};

const exponentCache = new Map<string, number>();

/** Uppercased and trimmed, or `null` when it is not a three-letter code. */
function normalize(currency: string): string | null {
  const code = currency.trim().toUpperCase();
  return /^[A-Z]{3}$/.test(code) ? code : null;
}

/**
 * The currency's ISO 4217 exponent.
 *
 * Throws on a malformed code rather than assuming two, because an amount
 * rendered against a guessed currency is wrong in a way nobody can see.
 */
export function currencyExponent(currency: string): number {
  const code = normalize(currency);
  if (code === null) {
    throw new Error(`Not an ISO 4217 currency code: '${currency}'`);
  }

  const cached = exponentCache.get(code);
  if (cached !== undefined) return cached;

  // The table, never Intl — see the module note. Asking the engine here would
  // silently make this disagree with the server for IQD, LBP and eleven others.
  const exponent = EXPONENT_EXCEPTIONS[code] ?? DEFAULT_EXPONENT;

  exponentCache.set(code, exponent);
  return exponent;
}

/** 1000 for JOD, 100 for USD, 1 for JPY. */
export function minorUnitsPerMajor(currency: string): number {
  return 10 ** currencyExponent(currency);
}

/**
 * Minor units as an exact decimal string: `5000` JOD becomes `"5.000"`.
 *
 * Integer arithmetic throughout, so this is correct for every amount a
 * JavaScript integer holds and never introduces a fractional artefact of its
 * own. No currency code is appended — that is the caller's or `Intl`'s job.
 */
export function toDecimalString(minorUnits: number, currency: string): string {
  if (!Number.isInteger(minorUnits)) {
    throw new Error(
      `Expected an integer of minor units, got ${minorUnits}. A fractional ` +
        'minor unit is not an amount of money.',
    );
  }

  const exponent = currencyExponent(currency);
  if (exponent === 0) return String(minorUnits);

  // The sign is taken off first: -1 fil must render "-0.001", and leaving the
  // minus in the arithmetic produces "0.-01".
  const sign = minorUnits < 0 ? '-' : '';
  const magnitude = Math.abs(minorUnits);
  const divisor = 10 ** exponent;

  const major = Math.floor(magnitude / divisor);
  const minor = magnitude % divisor;

  return `${sign}${major}.${String(minor).padStart(exponent, '0')}`;
}

/**
 * The amount as a person in this locale reads it — symbol, grouping, digits and
 * direction all decided by `Intl`.
 *
 * Pass the UI's active locale so the Arabic build renders Arabic-Indic numerals
 * and places the currency correctly for RTL; this file must not try to do
 * either itself.
 *
 * PRECISION NOTE: the exact decimal string is handed to `Intl` directly where
 * the engine accepts one (ES2023), so no precision is lost. Where it does not,
 * this falls back to a `Number`, which is exact below 2^53 — about nine
 * quadrillion fils, or nine trillion dinars. `toDecimalString` has no such
 * bound if an exact rendering is what is needed.
 */
export function formatMoney(
  minorUnits: number,
  currency: string,
  locale?: string,
): string {
  const code = normalize(currency);
  if (code === null) {
    throw new Error(`Not an ISO 4217 currency code: '${currency}'`);
  }

  const decimal = toDecimalString(minorUnits, code);
  const exponent = currencyExponent(code);

  let formatter: Intl.NumberFormat;
  try {
    formatter = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: code,
      minimumFractionDigits: exponent,
      maximumFractionDigits: exponent,
    });
  } catch {
    // Unknown to this engine's ICU data: show the exact figure and the code
    // rather than nothing.
    return `${decimal} ${code}`;
  }

  try {
    // Intl.NumberFormat v3 accepts a decimal string, which keeps full
    // precision. Older engines throw or coerce, hence the fallback.
    return formatter.format(decimal as unknown as number);
  } catch {
    return formatter.format(Number(decimal));
  }
}

/**
 * Read a human-entered major-unit amount into minor units.
 *
 * Mirrors the PHP `Currency::parse` exactly, and that exactness is the point:
 * if the client accepted what the server refuses, the user would see a
 * validation error on an amount the form told them was fine; if it accepted
 * what the server silently rounds, they would be billed something they did not
 * type.
 *
 * So: more precision than the currency has is REFUSED, not rounded, and group
 * separators are refused rather than guessed at — "1.234,56" and "1,234.56"
 * are the same string to different halves of the world and mean amounts a
 * thousand times apart.
 */
export function parseMoney(input: string, currency: string): number {
  const code = normalize(currency);
  if (code === null) {
    throw new Error(`Not an ISO 4217 currency code: '${currency}'`);
  }

  const exponent = currencyExponent(code);
  const trimmed = input.trim();

  if (trimmed === '') {
    throw new Error(`Cannot read an empty amount as ${code}.`);
  }

  const match = /^(-?)(\d+)(?:\.(\d*))?$/.exec(trimmed);
  if (match === null) {
    throw new Error(
      `Cannot read "${input}" as ${code}: expected digits with at most one "." ` +
        'and an optional leading "-". Group and decimal separators are ambiguous ' +
        'between locales and are refused rather than guessed at.',
    );
  }

  const [, sign, whole, fraction = ''] = match;

  if (fraction.length > exponent) {
    throw new Error(
      `Cannot read "${input}" as ${code}: ${code} has ${exponent} decimal ` +
        `place${exponent === 1 ? '' : 's'}, and this has ${fraction.length}. ` +
        'Refusing to round an amount somebody typed.',
    );
  }

  const digits = whole + fraction.padEnd(exponent, '0');
  const value = Number(`${sign}${digits}`);

  if (!Number.isSafeInteger(value)) {
    throw new Error(
      `Cannot read "${input}" as ${code}: ${digits} minor units is beyond the ` +
        'range in which an integer is exact.',
    );
  }

  return value;
}
