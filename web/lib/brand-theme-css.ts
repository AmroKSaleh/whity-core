const TOKEN_NAME_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const HEX_COLOR_RE = /^#[0-9a-f]{6}$/;
const PLANE_SHADOW_RE = /^-?\d+px -?\d+px 0 0 #[0-9a-f]{6}$/;

/** One mode's token declarations, keeping only well-formed names and values. */
function declarations(raw: unknown): string {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return '';
  return Object.entries(raw as Record<string, unknown>)
    .filter(
      ([key, value]) =>
        TOKEN_NAME_RE.test(key) &&
        typeof value === 'string' &&
        (HEX_COLOR_RE.test(value) || PLANE_SHADOW_RE.test(value))
    )
    .map(([key, value]) => `--${key}:${value as string};`)
    .join('');
}

/**
 * The workspace main colour (decision 2.8) as CSS: the light set on `:root`
 * and the dark set on `.dark`, which comes second so it wins in dark mode.
 * Empty when the workspace hasn't set a main colour, so Whity Blue stays.
 *
 * The server derives these (TenantTheme); this re-validates every name and
 * value before it reaches a `<style>` tag, so plain concatenation is safe:
 * nothing that passes the patterns can hold a quote, brace or semicolon.
 */
export function brandThemeCss(theme: unknown): string {
  if (typeof theme !== 'object' || theme === null) return '';
  const { light, dark } = theme as { light?: unknown; dark?: unknown };
  const lightCss = declarations(light);
  const darkCss = declarations(dark);
  return (lightCss !== '' ? `:root{${lightCss}}` : '') + (darkCss !== '' ? `.dark{${darkCss}}` : '');
}
