import { render, screen } from '@testing-library/react';
import { Inspector } from '@amroksaleh/features/document-designer';
import { safeImageSrc } from '@amroksaleh/ui/documents/element-content';

/**
 * WHY THE IMAGE BOX IS EMPTY.
 *
 * `safeImageSrc` accepts only absolute http(s) URLs. It is an XSS-safe sink and
 * should stay strict — a `data:` URI (including a script-carrying SVG one) or a
 * `javascript:` URL must never reach the `<img>`.
 *
 * But the refusal was silent. Paste a `data:` URI, an `ftp://` address or a
 * typo, and the element stayed blank on the canvas and in the PDF alike, with
 * nothing anywhere to explain it. The rule was right and unannounced.
 *
 * The field consults the SAME function the renderer does, so it cannot approve
 * something that is then dropped downstream — the "two spellings" failure
 * `DocumentRenderer` already argues against for `VariableData`.
 */

const IMAGE = {
  id: 'e1',
  type: 'image',
  x: 0,
  y: 0,
  w: 30,
  h: 30,
  rotation: 0,
  z: 1,
  src: '',
  binding: undefined,
  fit: 'contain',
};

const TEMPLATE = {
  version: 2,
  name: 'T',
  page: { widthMm: 210, heightMm: 297, marginMm: 10, background: '#ffffff' },
  placeholders: [],
  pages: [{ id: 'p1', elements: [IMAGE] }],
};

function renderInspector(src: string) {
  return render(
    <Inspector
      template={TEMPLATE as never}
      selected={{ ...IMAGE, src } as never}
      selectedCount={1}
      batch={{ active: false, index: 0, total: 0 } as never}
      sheet={{ enabled: false } as never}
      sequence={{} as never}
      tab="element"
      onChangeSelected={() => {}}
      onChangePage={() => {}}
      onChangePlaceholders={() => {}}
      onGenerateBatch={() => {}}
      onLoadBatchRecords={() => {}}
      onClearBatch={() => {}}
      onBatchIndex={() => {}}
      onChangeSheet={() => {}}
      onChangeSequence={() => {}}
    />
  );
}

describe('the image URL field', () => {
  it('says nothing while the field is empty — that is not an error, just unset', () => {
    renderInspector('');
    expect(screen.queryByTestId('doc-image-src-refused')).toBeNull();
  });

  it('says nothing for a URL that will actually render', () => {
    renderInspector('https://example.com/logo.png');
    expect(screen.queryByTestId('doc-image-src-refused')).toBeNull();
  });

  it.each([
    ['a data: URI', 'data:image/svg+xml,<svg/>'],
    ['a javascript: URL', 'javascript:alert(1)'],
    ['a non-web protocol', 'ftp://example.com/logo.png'],
    ['a relative path', '/logo.png'],
    ['a typo', 'htp://example.com/logo.png'],
  ])('explains the empty box for %s', (_label, src) => {
    // Guard the fixture itself: if safeImageSrc ever started accepting one of
    // these, this test would otherwise silently stop testing anything.
    expect(safeImageSrc(src)).toBe('');

    renderInspector(src);
    expect(screen.getByTestId('doc-image-src-refused')).toBeVisible();
  });
});
