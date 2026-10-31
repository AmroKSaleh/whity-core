/**
 * Decision 2.8: the workspace main colour reaches the page as CSS custom
 * properties, light on :root and dark on .dark. Everything the server sends is
 * re-validated before it reaches a <style> tag.
 */

import { brandThemeCss } from '@/lib/brand-theme-css';

describe('brandThemeCss', () => {
  it('writes the light set on :root and the dark set on .dark, dark last so it wins', () => {
    const css = brandThemeCss({
      light: { primary: '#b74c05', 'plane-rest': '-3px 3px 0 0 #b74c05' },
      dark: { primary: '#e97c47' },
    });
    expect(css).toBe(':root{--primary:#b74c05;--plane-rest:-3px 3px 0 0 #b74c05;}.dark{--primary:#e97c47;}');
  });

  it('is empty when the workspace has no main colour', () => {
    expect(brandThemeCss(null)).toBe('');
    expect(brandThemeCss(undefined)).toBe('');
    expect(brandThemeCss({ light: {}, dark: {} })).toBe('');
  });

  it('drops any name or value that could break out of the style tag', () => {
    const css = brandThemeCss({
      light: {
        primary: '#b74c05',
        'primary;}body{': '#000000',
        ring: 'red;}body{display:none',
        brand: 'url(https://example.com)',
        'chart-1': '#B74C05',
      },
      dark: 'not an object',
    });
    expect(css).toBe(':root{--primary:#b74c05;}');
  });
});
