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
 * The tags of a numbered hole: `<0>` opening, `</0>` closing.
 *
 * WHY NOT ONE PATTERN FOR THE WHOLE HOLE
 * --------------------------------------
 * The obvious pattern is `/<(\d+)>([\s\S]*?)<\/\1>/g`. It is quadratic: the
 * lazy `[\s\S]*?` plus a backreference makes the engine rescan the tail from
 * every candidate start (CodeQL js/polynomial-redos). That is high severity
 * HERE in particular, because a translation is a TENANT-EDITABLE row — a
 * stored string could otherwise hang the browser of everyone loading a screen
 * that uses the key.
 *
 * Matching openings and then locating each closing tag with `indexOf` is NOT a
 * fix, which is worth recording because it looks like one: on input with no
 * closing tags at all, every `indexOf` scans to the end of the string, so it
 * stays quadratic. Measured, it was slower than the regex it replaced.
 *
 * So both tag kinds are collected in ONE pass each and paired by position
 * below. Neither pattern can backtrack, and the pairing only ever moves
 * forward, so the whole parse is linear in the length of the string for any
 * input at all.
 */
const HOLE_OPEN_PATTERN = /<(\d+)>/g
const HOLE_CLOSE_PATTERN = /<\/(\d+)>/g

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
 *
 * One left-to-right pass: find an opening tag, then locate its closing tag by
 * direct search. No backtracking anywhere, so the cost is linear in the length
 * of the string no matter what a translation row contains.
 *
 * Deliberately NOT nested-aware — it pairs an opening tag with the NEAREST
 * closing tag of the same index, so `<0>a <1>b</1></0>` puts the literal text
 * `a <1>b</1>` inside component 0 rather than silently dropping half the
 * sentence. A sentence needing nested emphasis is rare enough to be worth two
 * components at the call site, and a recursive parser over tenant-editable data
 * is a much larger thing to get right.
 */
export function renderRichText(
  resolved: string,
  vars?: Record<string, string | number>,
  components?: ReadonlyArray<ReactElement>
): ReactNode {
  const nodes: ReactNode[] = []
  let cursor = 0

  // Local, not module-scope: exec() on a /g regex carries lastIndex between
  // calls, and a shared one would let a previous call truncate this one.
  const openPattern = new RegExp(HOLE_OPEN_PATTERN.source, 'g')
  const closePattern = new RegExp(HOLE_CLOSE_PATTERN.source, 'g')

  // Every closing tag, by index, in ascending position — collected in a single
  // pass so pairing never rescans the string.
  const closings = new Map<string, number[]>()
  let closeMatch: RegExpExecArray | null
  while ((closeMatch = closePattern.exec(resolved)) !== null) {
    const positions = closings.get(closeMatch[1])
    if (positions === undefined) {
      closings.set(closeMatch[1], [closeMatch.index])
    } else {
      positions.push(closeMatch.index)
    }
  }

  // How far into each index's list we have already consumed. Openings are
  // visited left to right and `cursor` only moves forward, so these pointers
  // only advance — which is what keeps the pairing linear overall.
  const consumed = new Map<string, number>()
  let match: RegExpExecArray | null

  while ((match = openPattern.exec(resolved)) !== null) {
    const index = Number(match[1])
    const contentStart = match.index + match[0].length

    const positions = closings.get(match[1]) ?? []
    let at = consumed.get(match[1]) ?? 0
    while (at < positions.length && positions[at] < contentStart) {
      at++
    }
    consumed.set(match[1], at)

    if (at >= positions.length) {
      // Unclosed. Leave the opening tag as literal text and keep scanning
      // after it — losing the rest of the sentence would be far worse than
      // showing a stray tag.
      continue
    }

    const closeAt = positions[at]
    const closeTag = `</${match[1]}>`

    if (match.index > cursor) {
      nodes.push(interpolate(resolved.slice(cursor, match.index), vars))
    }

    const inner = interpolate(resolved.slice(contentStart, closeAt), vars)
    const element = components?.[index]

    if (element !== undefined && isValidElement(element)) {
      nodes.push(cloneElement(element, { key: `${index}-${match.index}` }, inner))
    } else {
      // `<7>` with nothing behind it: the sentence still reads correctly, it
      // just loses its emphasis. Preferable to throwing on a content row.
      nodes.push(inner)
    }

    cursor = closeAt + closeTag.length
    // Resume scanning past the hole, so a tag inside it is not re-read as the
    // start of another one.
    openPattern.lastIndex = cursor
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
