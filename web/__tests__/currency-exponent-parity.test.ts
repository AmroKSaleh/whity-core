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
  function icuExponent(code: string): number {
    return new Intl.NumberFormat('en', { style: 'currency', currency: code })
      .resolvedOptions().maximumFractionDigits;
  }

  const divergences = (): Record<string, number> => {
    const found: Record<string, number> = {};
    for (const code of ALL_ACTIVE_CODES) {
      const icu = icuExponent(code);
      if (icu !== currencyExponent(code)) found[code] = icu;
    }
    return found;
  };

  /**
   * The phenomenon itself, which is the reason this module transcribes a table
   * instead of asking the engine.
   *
   * The EXACT set is deliberately not pinned. An earlier draft of this test did
   * pin it and failed in CI for a reason worth keeping: the divergence list is
   * ICU-VERSION-DEPENDENT. The Node this was written on reports the Serbian
   * dinar as 0-decimal and the Pakistani rupee as 2; CI's Node reports the
   * opposite. Neither currency's ISO exponent changed — CLDR's opinion about
   * how many decimals people write did.
   *
   * That makes ICU an even worse authority than the first finding suggested:
   * not merely different from ISO, but different in ways that shift underneath
   * a deployment when the runtime is upgraded. Pinning the set would only have
   * bought a test that fails on Node upgrades and teaches nobody anything.
   */
  it('exists at all, which is why we do not ask the engine', () => {
    const found = divergences();

    expect(Object.keys(found).length).toBeGreaterThan(0);
    // Every known divergence is a currency whose minor unit has been inflated
    // into irrelevance, so CLDR stopped printing it.
    for (const icu of Object.values(found)) {
      expect(icu).toBe(0);
    }
  });

  /**
   * THE INVARIANT THAT ACTUALLY MATTERS, and it holds on every ICU version: ICU
   * may claim LESS precision than our table (a display choice, harmless because
   * we force the digits when formatting), but never MORE.
   *
   * More would mean a currency with real sub-units we are treating as having
   * fewer — the failure that bills someone ten or a thousand times wrong — and
   * it is the one thing a missing table entry would look like.
   */
  it('never has ICU claiming more precision than our table does', () => {
    const underCounted: string[] = [];
    for (const code of ALL_ACTIVE_CODES) {
      if (icuExponent(code) > currencyExponent(code)) {
        underCounted.push(`${code}: ICU ${icuExponent(code)}, ours ${currencyExponent(code)}`);
      }
    }
    expect(underCounted).toEqual([]);
  });

  /**
   * The headline case, asserted against OUR table rather than against ICU —
   * ISO says the Iraqi dinar has three decimal places, and that is what we
   * bill in regardless of what any ICU build prints.
   */
  it('still bills IQD at its ISO precision', () => {
    expect(currencyExponent('IQD')).toBe(3);
    expect(formatMoney(5000, 'IQD', 'en')).toContain('5.000');
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
