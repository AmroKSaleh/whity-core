/**
 * Tests for the rich-translation renderer.
 *
 * The point of `useRichTranslation` is that ONE key covers a whole sentence
 * with rendered content inside it, so a translator can move the hole to wherever
 * their grammar needs it. Two things therefore have to hold, and both are
 * pinned here:
 *
 *   ORDER IS THE TRANSLATION'S TO CHOOSE — the renderer must follow the order
 *   in the STRING, not the order of the `components` array. If it did not, the
 *   feature would be pointless: Arabic could not move the value.
 *
 *   A BAD ROW MUST NOT BLANK THE PAGE — translations are tenant-editable data.
 *   A missing tag, an index with nothing behind it, or an unclosed tag is a
 *   content mistake someone fixes in /admin/translations; until they do, the
 *   sentence must still read.
 */

import { render, screen } from '@testing-library/react'

import { renderRichText } from '../useRichTranslation'

describe('renderRichText', () => {
  it('wraps the tagged span and leaves the rest as text', () => {
    render(
      <p data-testid="out">
        {renderRichText('We sent a link to <0>{email}</0>. Open it.', { email: 'a@b.co' }, [
          <span data-testid="hole" className="font-medium" />,
        ])}
      </p>
    )

    expect(screen.getByTestId('out')).toHaveTextContent('We sent a link to a@b.co. Open it.')
    expect(screen.getByTestId('hole')).toHaveTextContent('a@b.co')
    expect(screen.getByTestId('hole')).toHaveClass('font-medium')
  })

  it('follows the order in the string, not the order of the components array', () => {
    // This is the whole feature. A translation that puts the value first must
    // render it first, with the same components array.
    render(
      <p data-testid="out">
        {renderRichText('<1>{email}</1> :إلى <0>أرسلنا رابطًا</0>', { email: 'a@b.co' }, [
          <em data-testid="first" />,
          <strong data-testid="second" />,
        ])}
      </p>
    )

    expect(screen.getByTestId('second')).toHaveTextContent('a@b.co')
    expect(screen.getByTestId('first')).toHaveTextContent('أرسلنا رابطًا')
    expect(screen.getByTestId('out').textContent?.trimStart().startsWith('a@b.co')).toBe(true)
  })

  it('renders a sentence whose translation dropped the tags entirely', () => {
    // A translator can delete `<0>`. That loses the emphasis, not the sentence.
    render(
      <p data-testid="out">
        {renderRichText('We sent a link to {email}.', { email: 'a@b.co' }, [<span />])}
      </p>
    )

    expect(screen.getByTestId('out')).toHaveTextContent('We sent a link to a@b.co.')
  })

  it('renders the inner text when an index has no component behind it', () => {
    render(
      <p data-testid="out">{renderRichText('See <7>the terms</7> first.', undefined, [<span />])}</p>
    )

    expect(screen.getByTestId('out')).toHaveTextContent('See the terms first.')
  })

  it('renders an unclosed tag literally rather than swallowing the tail', () => {
    // Losing the rest of the sentence would be far worse than showing the tag:
    // one is a visible typo, the other is missing information.
    render(<p data-testid="out">{renderRichText('See <0>the terms first.', undefined, [<span />])}</p>)

    expect(screen.getByTestId('out')).toHaveTextContent('See <0>the terms first.')
  })

  it('does not interpret markup in the translation as HTML', () => {
    // Translations are tenant-editable rows. The stored string is data.
    render(
      <p data-testid="out">{renderRichText('<img src=x onerror=alert(1)> hello', undefined, [])}</p>
    )

    expect(screen.getByTestId('out').querySelector('img')).toBeNull()
    expect(screen.getByTestId('out')).toHaveTextContent('<img src=x onerror=alert(1)> hello')
  })

  it('is not affected by the previous call it made', () => {
    // The hole pattern is a module-scope /g regex, so a stale lastIndex would
    // make the SECOND call silently drop its opening text. Renders back to back.
    const first = renderRichText('a <0>b</0> c', undefined, [<span />])
    const second = renderRichText('a <0>b</0> c', undefined, [<span />])

    render(
      <>
        <p data-testid="first">{first}</p>
        <p data-testid="second">{second}</p>
      </>
    )

    expect(screen.getByTestId('first')).toHaveTextContent('a b c')
    expect(screen.getByTestId('second')).toHaveTextContent('a b c')
  })

  it('returns a plain string when there are no holes', () => {
    expect(renderRichText('Just a sentence.')).toBe('Just a sentence.')
  })

  it('handles two different holes in one sentence', () => {
    render(
      <p data-testid="out">
        {renderRichText('Read the <0>terms</0> and the <1>policy</1>.', undefined, [
          <a href="/terms" data-testid="terms" />,
          <a href="/policy" data-testid="policy" />,
        ])}
      </p>
    )

    expect(screen.getByTestId('terms')).toHaveTextContent('terms')
    expect(screen.getByTestId('policy')).toHaveTextContent('policy')
    expect(screen.getByTestId('out')).toHaveTextContent('Read the terms and the policy.')
  })
})
