'use strict';

/**
 * The browser-identity reader (#1134).
 *
 * The point of this module is that an operator can trust what /health says
 * about the browser. So the cases that matter here are the DISHONEST ones: a
 * file that exists but says nothing, a version that is not a version, a
 * runtime reading that cannot be compared. Every one of them must collapse to
 * a null or an `unknown`, never to something that parses like an answer — the
 * same rule BuildIdentity applies on the PHP side, and for the same reason.
 */

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { readRecordedBrowser, versionFromUserAgent, versionsAgree, SOURCE_BUILD, SOURCE_UNKNOWN } =
  require('../src/browser-info');

let dir;

beforeEach(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'whity-browser-info-'));
});

afterEach(() => {
  fs.rmSync(dir, { recursive: true, force: true });
});

function write(contents) {
  const file = path.join(dir, 'browser-info.json');
  fs.writeFileSync(file, typeof contents === 'string' ? contents : JSON.stringify(contents), 'utf8');
  return file;
}

describe('readRecordedBrowser', () => {
  test('reads a well-formed file written by the image build', () => {
    const file = write({
      version: '151.0.7922.173',
      package_version: '151.0.7922.173-1~deb12u1',
      banner: 'Chromium 151.0.7922.173 built on Debian GNU/Linux 12 (bookworm)',
      executable: '/usr/bin/chromium',
      recorded_at: '2026-08-31T12:00:00.000Z',
    });

    expect(readRecordedBrowser(file)).toEqual({
      version: '151.0.7922.173',
      package_version: '151.0.7922.173-1~deb12u1',
      banner: 'Chromium 151.0.7922.173 built on Debian GNU/Linux 12 (bookworm)',
      executable: '/usr/bin/chromium',
      recorded_at: '2026-08-31T12:00:00.000Z',
      source: SOURCE_BUILD,
    });
  });

  test('a checkout with no baked file is `unknown` with every field null', () => {
    const identity = readRecordedBrowser(path.join(dir, 'nope.json'));
    expect(identity.source).toBe(SOURCE_UNKNOWN);
    expect(identity.version).toBeNull();
    expect(identity.package_version).toBeNull();
  });

  test('malformed JSON is `unknown`, not a crash', () => {
    expect(readRecordedBrowser(write('{ not json')).source).toBe(SOURCE_UNKNOWN);
  });

  // The v0.2.2 /web-build failure mode: a response that answers with every
  // identifying field empty and reads as "working" to whatever is checking it.
  test('a file that exists but cannot name a version is `unknown`, not a build identity', () => {
    expect(readRecordedBrowser(write({ version: '', package_version: 'x' })).source).toBe(SOURCE_UNKNOWN);
    expect(readRecordedBrowser(write({ package_version: 'x' })).source).toBe(SOURCE_UNKNOWN);
    expect(readRecordedBrowser(write({ version: 'unknown' })).source).toBe(SOURCE_UNKNOWN);
    expect(readRecordedBrowser(write({ version: 'Chromium 151' })).source).toBe(SOURCE_UNKNOWN);
    expect(readRecordedBrowser(write({ version: 151 })).source).toBe(SOURCE_UNKNOWN);
  });

  test('optional fields that are absent or empty are null rather than empty strings', () => {
    const identity = readRecordedBrowser(write({ version: '151.0.7922.173', package_version: '   ' }));
    expect(identity.version).toBe('151.0.7922.173');
    expect(identity.package_version).toBeNull();
    expect(identity.banner).toBeNull();
  });
});

describe('versionFromUserAgent', () => {
  // Same binary, two launch modes, two product names. Comparing the raw
  // strings would report drift where there is none.
  test.each([
    ['HeadlessChrome/151.0.7922.173', '151.0.7922.173'],
    ['Chrome/151.0.7922.173', '151.0.7922.173'],
    ['Chromium 151.0.7922.173 built on Debian GNU/Linux 12 (bookworm)', '151.0.7922.173'],
  ])('%s -> %s', (input, expected) => {
    expect(versionFromUserAgent(input)).toBe(expected);
  });

  test.each([[null], [''], ['HeadlessChrome'], [undefined], [42]])('%p has no version in it', (input) => {
    expect(versionFromUserAgent(input)).toBeNull();
  });
});

describe('versionsAgree', () => {
  test('true and false when both sides are known', () => {
    expect(versionsAgree('151.0.7922.173', '151.0.7922.173')).toBe(true);
    expect(versionsAgree('151.0.7922.173', '152.0.1.1')).toBe(false);
  });

  // "we cannot tell" is not "they disagree", and only one of those is worth
  // waking someone up for.
  test('null — not false — when either side is unknown', () => {
    expect(versionsAgree(null, '151.0.7922.173')).toBeNull();
    expect(versionsAgree('151.0.7922.173', null)).toBeNull();
    expect(versionsAgree(null, null)).toBeNull();
  });
});
