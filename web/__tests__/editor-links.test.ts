/**
 * Editor milestone M2, links (EditorInline): a bare domain gets https://, an
 * email address becomes mailto:, and only web, mail and in-app addresses are
 * ever put in a link.
 */
import { isSafeHref, normaliseHref } from '@whity-org/ui/editor/link-tools';

describe('normaliseHref', () => {
  it.each([
    ['example.jo/pay', 'https://example.jo/pay'],
    ['  example.jo  ', 'https://example.jo'],
    ['https://example.jo/pay/INV-1042', 'https://example.jo/pay/INV-1042'],
    ['mailto:billing@example.jo', 'mailto:billing@example.jo'],
    ['billing@example.jo', 'mailto:billing@example.jo'],
    ['/admin/document-sources/7', '/admin/document-sources/7'],
    ['#block-b2', '#block-b2'],
    ['', ''],
  ])('%s → %s', (input, expected) => {
    expect(normaliseHref(input)).toBe(expected);
  });

  it('is not an email without a name, a dot in the domain, or with two @', () => {
    expect(normaliseHref('@example.jo')).toBe('https://@example.jo');
    expect(normaliseHref('billing@example')).toBe('https://billing@example');
    expect(normaliseHref('a@b@example.jo')).toBe('https://a@b@example.jo');
  });

  it('stays fast on long hostile input', () => {
    const hostile = '!@!.' + '!.'.repeat(50000);
    const start = Date.now();
    normaliseHref(hostile);
    expect(Date.now() - start).toBeLessThan(50);
  });
});

describe('isSafeHref', () => {
  it.each(['https://example.jo', 'http://example.jo', 'mailto:a@example.jo', 'tel:+96260000000', '/admin', '#b1'])('accepts %s', (href) => {
    expect(isSafeHref(href)).toBe(true);
  });

  it.each(['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,<b>x</b>', 'vbscript:x'])('refuses %s', (href) => {
    expect(isSafeHref(href)).toBe(false);
  });
});
