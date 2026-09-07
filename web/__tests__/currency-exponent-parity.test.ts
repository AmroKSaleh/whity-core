import { readFileSync } from 'node:fs';
import path from 'node:path';

import {
  currencyExponent,
  formatMoney,
  minorUnitsPerMajor,
  parseMoney,
  toDecimalString,
} from '@amroksaleh/ui/money/currency';

/**
 * The exponent table exists twice — once in PHP, once in TypeScript — and the
 * two must agree exactly. This reads both files and compares them entry for
 * entry.
 *
 * WHY NOT JUST ASK `Intl`? That was the original design of this test, and it
 * was wrong in a way worth recording, because the idea is very appealing: the
 * JavaScript engine ships ISO 4217 data, so let it be the oracle and stop
 * transcribing tables by hand.
 *
 * `Intl.NumberFormat(...).resolvedOptions().maximumFractionDigits` does not
 * report the currency's ISO exponent. It reports CLDR's DISPLAY convention —
 * how many decimals people actually write — and for currencies whose minor
 * unit has been inflated into irrelevance the two differ. ICU says the Iraqi
 * dinar has 0 decimal places; ISO 4217 says 3. Twelve more (the Lebanese
 * pound, the Afghani, the Serbian dinar and others) are 0 against ISO's 2.
 *
 * Had the client kept deriving its exponent from `Intl`, it would have
 * disagreed with the server about what an IQD amount MEANS by a factor of a
 * thousand, while every JOD and USD test passed. So `Intl` is pinned here as a
 * known-divergent third party rather than trusted as an authority — which also
 * means a future ICU change to any currency we care about fails this test
 * instead of silently altering what customers are shown.
 */

const REPO = path.join(__dirname, '..', '..');
const PHP_CURRENCY = path.join(REPO, 'src', 'Core', 'Money', 'Currency.php');
const TS_CURRENCY = path.join(REPO, 'packages', 'ui', 'src', 'money', 'currency.ts');

/** Pull `CODE => n` / `CODE: n` pairs out of a file's exception block. */
function exponentTable(file: string, marker: string): Record<string, number> {
  const source = readFileSync(file, 'utf8');

  const start = source.indexOf(marker);
  expect(start).toBeGreaterThan(-1);
  const end = source.indexOf(source[start + marker.length - 1] === '[' ? '];' : '};', start);
  expect(end).toBeGreaterThan(start);

  const block = source.slice(start, end);
  const table: Record<string, number> = {};
  for (const [, code, exponent] of block.matchAll(/\b([A-Z]{3})\b'?\s*(?:=>|:)\s*(\d+)/g)) {
    table[code] = Number(exponent);
  }
  return table;
}

const php = exponentTable(PHP_CURRENCY, 'EXPONENT_EXCEPTIONS = [');
const ts = exponentTable(TS_CURRENCY, 'EXPONENT_EXCEPTIONS: Readonly<Record<string, number>> = {');

describe('the two exponent tables', () => {
  /**
   * The precondition. Without it, a restructured source file would yield two
   * empty objects, and "they match" would be true and meaningless — which is
   * the exact shape of vacuous test this repository keeps finding.
   */
  it('were both actually found and parsed', () => {
    expect(Object.keys(php).length).toBeGreaterThanOrEqual(24);
    expect(Object.keys(ts).length).toBeGreaterThanOrEqual(24);
    expect(php.JOD).toBe(3);
    expect(ts.JOD).toBe(3);
  });

  it('agree entry for entry', () => {
    expect(ts).toEqual(php);
  });

  it('and the running code agrees with its own table', () => {
    for (const [code, exponent] of Object.entries(ts)) {
      expect(currencyExponent(code)).toBe(exponent);
    }
    // Anything absent from the table is an ordinary two-decimal currency.
    expect(currencyExponent('USD')).toBe(2);
    expect(currencyExponent('SAR')).toBe(2);
  });
});

describe('where ICU disagrees with ISO 4217', () => {
  /**
   * Every code on which CLDR's display convention differs from the ISO
   * exponent we bill in. Pinned rather than tolerated: if this set changes —
   * because ICU updated, or because someone edited our table — the test says
   * so, and somebody decides deliberately.
   */
  const EXPECTED_DIVERGENCES: Record<string, number> = {
    // ISO 3, ICU 0 — the sharpest case, and the one that would have made the
    // client and server disagree by a factor of a thousand.
    IQD: 0,
    // ISO 2, ICU 0 — minor units inflated into irrelevance.
    AFN: 0, ALL: 0, IRR: 0, KPW: 0, LAK: 0, LBP: 0,
    MGA: 0, MMK: 0, RSD: 0, SOS: 0, SYP: 0, YER: 0,
  };

  it('is exactly the set we know about', () => {
    const found: Record<string, number> = {};
    for (const code of ALL_ACTIVE_CODES) {
      const icu = new Intl.NumberFormat('en', {
        style: 'currency',
        currency: code,
      }).resolvedOptions().maximumFractionDigits;

      if (icu !== currencyExponent(code)) {
        found[code] = icu;
      }
    }
    expect(found).toEqual(EXPECTED_DIVERGENCES);
  });

  /**
   * The safety net that survives the loss of Intl as an oracle: a currency ICU
   * thinks has THREE decimals had better be in our table, because a missing
   * three-decimal currency is the failure that bills someone ten times wrong.
   */
  it('never has ICU claiming more precision than our table does', () => {
    const underCounted: string[] = [];
    for (const code of ALL_ACTIVE_CODES) {
      const icu = new Intl.NumberFormat('en', {
        style: 'currency',
        currency: code,
      }).resolvedOptions().maximumFractionDigits;
      if (icu > currencyExponent(code)) {
        underCounted.push(`${code}: ICU ${icu}, ours ${currencyExponent(code)}`);
      }
    }
    expect(underCounted).toEqual([]);
  });
});

describe('the client half behaves like the server half', () => {
  it('knows the Jordanian dinar has three decimal places', () => {
    expect(currencyExponent('JOD')).toBe(3);
    expect(minorUnitsPerMajor('JOD')).toBe(1000);
  });

  it('renders minor units at the currency’s own precision', () => {
    expect(toDecimalString(5000, 'JOD')).toBe('5.000');
    expect(toDecimalString(5000, 'USD')).toBe('50.00');
    expect(toDecimalString(5000, 'JPY')).toBe('5000');
  });

  it('pads a small fraction rather than left-aligning it', () => {
    expect(toDecimalString(5, 'JOD')).toBe('0.005');
    expect(toDecimalString(1020, 'JOD')).toBe('1.020');
  });

  it('keeps the sign outside the division', () => {
    expect(toDecimalString(-1, 'JOD')).toBe('-0.001');
    expect(toDecimalString(-5500, 'JOD')).toBe('-5.500');
  });

  it('reads a typed amount into minor units', () => {
    expect(parseMoney('5', 'JOD')).toBe(5000);
    expect(parseMoney('5.5', 'JOD')).toBe(5500);
    expect(parseMoney('5.005', 'JOD')).toBe(5005);
    expect(parseMoney('-5.5', 'JOD')).toBe(-5500);
  });

  it('refuses more precision than the currency has rather than rounding', () => {
    expect(() => parseMoney('5.0005', 'JOD')).toThrow(/Refusing to round/);
    expect(() => parseMoney('5.005', 'USD')).toThrow(/Refusing to round/);
    expect(() => parseMoney('500.5', 'JPY')).toThrow(/Refusing to round/);
  });

  it('refuses group separators rather than guessing at them', () => {
    expect(() => parseMoney('1,234.56', 'USD')).toThrow();
    expect(() => parseMoney('1.234,56', 'USD')).toThrow();
  });

  it('round-trips every amount it can render', () => {
    const cases: Array<[number, string]> = [
      [0, 'JOD'], [1, 'JOD'], [5000, 'JOD'], [1234567, 'JOD'], [-5500, 'JOD'],
      [5, 'USD'], [999999999, 'USD'], [5000, 'JPY'], [-42, 'JPY'],
    ];
    for (const [minor, code] of cases) {
      expect(parseMoney(toDecimalString(minor, code), code)).toBe(minor);
    }
  });

  it('formats for a locale at ISO precision, not CLDR’s', () => {
    // The figure, not the symbol: which glyph and where it sits varies by ICU
    // version, so asserting on it would test the engine's build rather than
    // our arithmetic.
    expect(formatMoney(5000, 'JOD', 'en')).toContain('5.000');
    expect(formatMoney(5000, 'USD', 'en')).toContain('50.00');
    expect(formatMoney(5000, 'JPY', 'en')).toContain('5,000');
    // The divergent case: ICU would render this with no decimals at all, and
    // an invoice that drops them is an invoice for a thousand times the amount.
    expect(formatMoney(5000, 'IQD', 'en')).toContain('5.000');
  });

  it('formats Arabic without this module knowing anything about Arabic', () => {
    const arabic = formatMoney(5000, 'JOD', 'ar-JO');
    expect(arabic).not.toBe('');
    // Arabic-Indic digits or Latin ones depending on ICU, but never the raw
    // minor-unit integer, which is the bug this module exists to prevent.
    expect(arabic).not.toContain('5000');
  });
});

/**
 * Every active ISO 4217 alphabetic code. Its only job is to be an input to the
 * engine for the divergence sweep above; nothing here asserts any exponent.
 */
const ALL_ACTIVE_CODES: string[] = [
  'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
  'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
  'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY',
  'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP',
  'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD',
  'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR',
  'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF',
  'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
  'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR',
  'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR',
  'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR',
  'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD',
  'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB',
  'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX',
  'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF',
  'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
];
