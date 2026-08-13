'use client'

/**
 * useRichTranslation — one translatable sentence that renders something INSIDE
 * itself.
 *
 * THE PROBLEM THIS EXISTS FOR
 * ---------------------------
 * `t()` returns a string, which is fine for a label and wrong for a sentence
 * with a bolded address, a link, or a count in a <span> in the middle of it.
 * The only way to express that with `t()` is to split the sentence:
 *
 *     t('register.pending.before', 'We’ve sent a verification link to')
 *     {email}
 *     t('register.pending.after', '. Open it to confirm your address…')
 *
 * which is worse than leaving it in English. A translator sees two rows in
 * /admin/translations and never sees them together; the second one OPENS ON A
 * FULL STOP, which is not a well-formed string in any language; and the split
 * hard-codes English word order, so a translator who needs the address earlier
 * in the sentence physically cannot move it. That last point is the RTL
 * requirement biting, not a cosmetic preference.
 *
 * So the whole sentence stays ONE key with numbered holes in it:
 *
 *     const rt = useRichTranslation('auth')
 *     rt(
 *       'register.pending.body',
 *       'Thanks for signing up! We’ve sent a verification link to <0>{email}</0>. ' +
 *         'Open it to confirm your address, then sign in.',
 *       { email },
 *       [<span className="font-medium" />]
 *     )
 *
 * The translator receives the whole sentence with `<0>` in it and puts `<0>`
 * wherever their grammar wants it.
 *
 * WHY A HOOK AND NOT A <Trans> COMPONENT
 * --------------------------------------
 * Because this shape is already understood end to end. The extractor binds
 * `rt` exactly as it binds `t` (see TranslationKeyExtractor::TRANSLATE_HOOKS),
 * so these keys land in the catalogue, the drift guard covers them, a computed
 * key is still refused, and an ambiguous domain is still an error — with no
 * second scanner for JSX attributes and no second set of rules to keep in sync.
 * A component API would have had to re-earn all of that.
 *
 * WHAT IT REFUSES TO TRUST
 * ------------------------
 * Translations are tenant-editable rows, so the stored string is DATA, not
 * markup. It is never injected as HTML — `components` supplies the elements and
 * the string only says where they go. A translation that drops a tag, invents
 * `<7>` with no component behind it, or leaves one unclosed renders as readable
 * text rather than throwing: a bad row is a content problem someone fixes in
 * the admin screen, and it must not blank a page in the meantime.
 */

import { cloneElement, isValidElement, useCallback, useContext, useEffect } from 'react'
import type { ReactElement, ReactNode } from 'react'

import { LanguageContext } from './LanguageProvider'
import { interpolate } from './useTranslation'

/**
 * `<0>…</0>` — a numbered hole naming its index in `components`.
 *
 * Deliberately NOT nested-aware. The pattern is non-greedy and matches the
 * nearest closing tag with the same index, so `<0>a <1>b</1></0>` renders the
 * inner tag's text literally instead of silently dropping half the sentence.
 * A sentence needing nested emphasis is rare enough to be worth splitting into
 * two components at the call site, and a recursive parser over tenant-editable
 * data is a much larger thing to get right.
 */
const HOLE_PATTERN = /<(\d+)>([\s\S]*?)<\/\1>/g

/** The rich translation function a screen calls. */
export type RichTranslateFn = (
  key: string,
  fallback?: string,
  vars?: Record<string, string | number>,
  components?: ReadonlyArray<ReactElement>
) => ReactNode

/**
 * Split a resolved string into text and component segments.
 *
 * Everything outside a hole is interpolated text. Everything inside one is
 * interpolated text wrapped in the matching component. An index with no
 * component behind it degrades to its text, unwrapped.
 */
export function renderRichText(
  resolved: string,
  vars?: Record<string, string | number>,
  components?: ReadonlyArray<ReactElement>
): ReactNode {
  const nodes: ReactNode[] = []
  let cursor = 0
  let match: RegExpExecArray | null

  // exec() on a /g regex is stateful; this one is module-scope, so reset it
  // before use or a previous call's lastIndex silently truncates this one.
  HOLE_PATTERN.lastIndex = 0

  while ((match = HOLE_PATTERN.exec(resolved)) !== null) {
    if (match.index > cursor) {
      nodes.push(interpolate(resolved.slice(cursor, match.index), vars))
    }

    const index = Number(match[1])
    const inner = interpolate(match[2], vars)
    const element = components?.[index]

    if (element !== undefined && isValidElement(element)) {
      nodes.push(cloneElement(element, { key: `${index}-${match.index}` }, inner))
    } else {
      // `<7>` with nothing behind it: the sentence still reads correctly, it
      // just loses its emphasis. Preferable to throwing on a content row.
      nodes.push(inner)
    }

    cursor = match.index + match[0].length
  }

  if (cursor < resolved.length) {
    nodes.push(interpolate(resolved.slice(cursor), vars))
  }

  // No holes at all — a translator removed them, or the string never had any.
  // Return the plain string so the common case does not allocate a fragment.
  if (nodes.length === 1 && typeof nodes[0] === 'string') {
    return nodes[0]
  }

  return nodes
}

/**
 * Hook to translate a sentence that wraps rendered content.
 *
 * Mirrors {@link useTranslation} exactly — same domain rules, same fallback
 * chain (translation → supplied English → key), same non-throwing behaviour
 * with no <LanguageProvider> mounted so a component stays renderable in a unit
 * test or a Storybook story.
 *
 * @param domain The translation domain — bare for core ('auth', 'common'),
 *               namespaced for a plugin ('acme:catalog').
 */
export function useRichTranslation(domain: string): RichTranslateFn {
  const context = useContext(LanguageContext)
  const ensureDomain = context?.ensureDomain
  const getTranslation = context?.getTranslation

  useEffect(() => {
    ensureDomain?.(domain)
  }, [ensureDomain, domain])

  return useCallback(
    (
      key: string,
      fallback?: string,
      vars?: Record<string, string | number>,
      components?: ReadonlyArray<ReactElement>
    ): ReactNode => {
      const resolved = getTranslation
        ? getTranslation(domain, key, fallback ?? key)
        : fallback ?? key
      return renderRichText(resolved, vars, components)
    },
    [getTranslation, domain]
  )
}
