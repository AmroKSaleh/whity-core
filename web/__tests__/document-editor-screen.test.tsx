/**
 * Editor milestone M2: the screen saves the draft a moment after typing
 * stops, with the version it edited; a conflict stops saving and offers the
 * newer version instead of overwriting it; a failed save is retried.
 */
import { act, render, screen } from '@testing-library/react';

import { DocumentEditorScreen, type DocumentEditorAdapter, type SaveResult } from '@whity-org/features/document-editor';
import { SAMPLE_DOCUMENT } from '@whity-org/ui/editor/sample';
import type { WhityDocument } from '@whity-org/ui/editor/contract';

let lastOnChange: ((doc: WhityDocument) => void) | null = null;

// The editor itself is covered by editor-*.test.ts; here only its onChange matters.
jest.mock('@whity-org/ui/editor/doc-editor', () => ({
  DocEditor: (props: { document: WhityDocument; onChange: (d: WhityDocument) => void; save?: { state: string } }) => {
    lastOnChange = props.onChange;
    return <div data-testid="editor" data-save={props.save?.state ?? ''}>{props.document.title}</div>;
  },
}));

function adapter(saves: Array<SaveResult | Error>) {
  const calls: Array<{ title: string; version: number }> = [];
  const a: DocumentEditorAdapter = {
    load: jest.fn(async () => ({ document: SAMPLE_DOCUMENT, version: 4 })),
    save: jest.fn(async (_id, patch, version) => {
      calls.push({ title: patch.title, version });
      const next = saves.shift();
      if (next instanceof Error) throw next;
      return next ?? { ok: true, version: version + 1, updatedAt: '2026-10-29T14:05:00Z' };
    }),
  };
  return { a, calls };
}

async function mount(a: DocumentEditorAdapter) {
  render(<DocumentEditorScreen id="7" adapter={a} canEdit />);
  await act(async () => {});
  expect(screen.getByTestId('editor')).toBeTruthy();
}

const edit = (title: string) => act(() => lastOnChange?.({ ...SAMPLE_DOCUMENT, title }));

beforeEach(() => jest.useFakeTimers());
afterEach(() => jest.useRealTimers());

it('saves once typing pauses, with the version it edited, then the next version', async () => {
  const { a, calls } = adapter([]);
  await mount(a);

  edit('Pay');
  edit('Payment');
  expect(a.save).not.toHaveBeenCalled();
  await act(async () => {
    jest.advanceTimersByTime(800);
  });
  expect(calls).toEqual([{ title: 'Payment', version: 4 }]);

  edit('Payment reminder');
  await act(async () => {
    jest.advanceTimersByTime(800);
  });
  expect(calls[1]).toEqual({ title: 'Payment reminder', version: 5 });
  expect(screen.getByTestId('editor').dataset.save).toBe('saved');
});

it('on a conflict, stops saving and offers the newer version', async () => {
  const { a } = adapter([{ ok: false, conflict: true, currentVersion: 9 }]);
  await mount(a);

  edit('Mine');
  await act(async () => {
    jest.advanceTimersByTime(800);
  });
  expect(screen.getByRole('alert').textContent).toContain('Someone else saved this document');
  expect(screen.getByRole('button', { name: 'Load the newer version' })).toBeTruthy();

  edit('Still mine');
  await act(async () => {
    jest.advanceTimersByTime(5000);
  });
  expect(a.save).toHaveBeenCalledTimes(1);
});

it('retries a failed save, later each time', async () => {
  const { a, calls } = adapter([new Error('network'), new Error('network')]);
  await mount(a);

  edit('Draft');
  await act(async () => {
    jest.advanceTimersByTime(800);
  });
  expect(screen.getByTestId('editor').dataset.save).toBe('error');

  await act(async () => {
    jest.advanceTimersByTime(2000);
  });
  expect(calls).toHaveLength(2);

  await act(async () => {
    jest.advanceTimersByTime(3999);
  });
  expect(calls).toHaveLength(2);
  await act(async () => {
    jest.advanceTimersByTime(1);
  });
  expect(calls).toHaveLength(3);
  expect(calls[2]).toEqual({ title: 'Draft', version: 4 });
  expect(screen.getByTestId('editor').dataset.save).toBe('saved');
});
