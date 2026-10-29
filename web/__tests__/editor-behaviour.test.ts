/**
 * Editor milestone M2: the keyboard paths in the EditorInsert and
 * EditorBlockChrome cards (Markdown shortcuts with one-step undo, list
 * levels, Enter in lists, moving blocks) and list numbering.
 */
import { Editor } from '@tiptap/core';

import { documentExtensions } from '@whity-org/ui/editor/extensions';
import { listMarkers, moveBlock } from '@whity-org/ui/editor/behaviour';
import { fromEditorContent, toEditorContent } from '@whity-org/ui/editor/convert';
import type { Block } from '@whity-org/ui/editor/contract';

const text = (t: string) => ({ type: 'text' as const, text: t });

function make(blocks: Block[]) {
  const element = document.createElement('div');
  document.body.append(element);
  return new Editor({ element, extensions: documentExtensions(), content: toEditorContent(blocks) });
}

/** Type text at the caret the way the browser does, so input rules run. */
function type(editor: Editor, chars: string) {
  for (const ch of chars) {
    const { from, to } = editor.state.selection;
    const handled = editor.view.someProp('handleTextInput', (f) => f(editor.view, from, to, ch, () => editor.state.tr.insertText(ch, from, to)));
    if (!handled) editor.view.dispatch(editor.state.tr.insertText(ch, from, to));
  }
}

function key(editor: Editor, name: string, mods: { mod?: boolean; shift?: boolean; alt?: boolean } = {}) {
  const isMac = /Mac/.test(navigator.platform);
  const event = new KeyboardEvent('keydown', {
    key: name,
    metaKey: !!mods.mod && isMac,
    ctrlKey: !!mods.mod && !isMac,
    shiftKey: !!mods.shift,
    altKey: !!mods.alt,
    bubbles: true,
    cancelable: true,
  });
  editor.view.someProp('handleKeyDown', (f) => f(editor.view, event));
}

const blocks = (editor: Editor) => fromEditorContent(editor.getJSON());

describe('Markdown shortcuts (E.28)', () => {
  it.each([
    ['# ', { type: 'heading', level: 1 }],
    ['## ', { type: 'heading', level: 2 }],
    ['### ', { type: 'heading', level: 3 }],
    ['- ', { type: 'bullet', level: 0 }],
    ['* ', { type: 'bullet', level: 0 }],
    ['1. ', { type: 'number', level: 0 }],
    ['[] ', { type: 'checklist', checked: false }],
    ['> ', { type: 'quote' }],
    ['``` ', { type: 'code' }],
  ])('"%s" at the start of a line makes the block', (shortcut, expected) => {
    const editor = make([{ id: 'p', type: 'paragraph', content: [] }]);
    editor.commands.focus('start');
    type(editor, shortcut);
    expect(blocks(editor)[0]).toMatchObject({ id: 'p', ...expected });
    editor.destroy();
  });

  it('⌘Z straight after turns the shortcut back into what was typed', () => {
    const editor = make([{ id: 'p', type: 'paragraph', content: [] }]);
    editor.commands.focus('start');
    type(editor, '## ');
    expect(blocks(editor)[0]).toMatchObject({ type: 'heading', level: 2 });
    key(editor, 'z', { mod: true });
    expect(blocks(editor)[0]).toMatchObject({ type: 'paragraph', content: [text('## ')] });
    editor.destroy();
  });

  it('--- makes a divider', () => {
    const editor = make([{ id: 'p', type: 'paragraph', content: [] }]);
    editor.commands.focus('start');
    type(editor, '---');
    expect(blocks(editor).map((b) => b.type)).toContain('divider');
    editor.destroy();
  });

  it('**text** and `text` become bold and code', () => {
    const editor = make([{ id: 'p', type: 'paragraph', content: [] }]);
    editor.commands.focus('start');
    type(editor, 'Pay **now** or `later`');
    const content = (blocks(editor)[0] as Extract<Block, { type: 'paragraph' }>).content;
    expect(content).toEqual([
      text('Pay '),
      { type: 'text', text: 'now', marks: [{ type: 'bold' }] },
      text(' or '),
      { type: 'text', text: 'later', marks: [{ type: 'code' }] },
    ]);
    editor.destroy();
  });
});

describe('lists', () => {
  it('Tab and Shift-Tab change the level, up to 3 levels', () => {
    const editor = make([{ id: 'a', type: 'bullet', level: 0, content: [text('Item')] }]);
    editor.commands.focus('end');
    key(editor, 'Tab');
    key(editor, 'Tab');
    key(editor, 'Tab');
    expect(blocks(editor)[0]).toMatchObject({ level: 2 });
    key(editor, 'Tab', { shift: true });
    expect(blocks(editor)[0]).toMatchObject({ level: 1 });
    editor.destroy();
  });

  it('Enter continues the list at the same level; Enter on an empty item steps out', () => {
    const editor = make([{ id: 'a', type: 'number', level: 1, content: [text('First')] }]);
    editor.commands.focus('end');
    key(editor, 'Enter');
    expect(blocks(editor).map((b) => [b.type, (b as { level?: number }).level])).toEqual([
      ['number', 1],
      ['number', 1],
    ]);
    key(editor, 'Enter');
    expect(blocks(editor)[1]).toMatchObject({ type: 'number', level: 0 });
    key(editor, 'Enter');
    expect(blocks(editor)[1]).toMatchObject({ type: 'paragraph' });
    editor.destroy();
  });

  it('numbers restart after any other block and count per level', () => {
    const editor = make([
      { id: 'n1', type: 'number', level: 0, content: [text('a')] },
      { id: 'n2', type: 'number', level: 1, content: [text('b')] },
      { id: 'n3', type: 'number', level: 1, content: [text('c')] },
      { id: 'n4', type: 'number', level: 0, content: [text('d')] },
      { id: 'p', type: 'paragraph', content: [text('break')] },
      { id: 'n5', type: 'number', level: 0, content: [text('e')] },
      { id: 'b1', type: 'bullet', level: 1, content: [text('f')] },
    ]);
    expect([...listMarkers(editor.state.doc).values()]).toEqual(['1.', 'a.', 'b.', '2.', '1.', '◦']);
    editor.destroy();
  });
});

describe('moving blocks (⌥⇧↑ / ⌥⇧↓)', () => {
  it('moves the current block and keeps its id', () => {
    const editor = make([
      { id: 'one', type: 'paragraph', content: [text('one')] },
      { id: 'two', type: 'paragraph', content: [text('two')] },
      { id: 'three', type: 'paragraph', content: [text('three')] },
    ]);
    editor.commands.focus('end');
    key(editor, 'ArrowUp', { alt: true, shift: true });
    expect(blocks(editor).map((b) => b.id)).toEqual(['one', 'three', 'two']);
    expect(moveBlock(editor, -1)).toBe(true);
    expect(blocks(editor).map((b) => b.id)).toEqual(['three', 'one', 'two']);
    expect(moveBlock(editor, -1)).toBe(false);
    editor.destroy();
  });
});

describe('checklist', () => {
  it('the box is a checkbox that toggles `checked`', () => {
    const editor = make([{ id: 'c', type: 'checklist', level: 0, checked: false, content: [text('Send it')] }]);
    const box = editor.view.dom.querySelector('[role=checkbox]') as HTMLButtonElement;
    expect(box.getAttribute('aria-checked')).toBe('false');
    box.click();
    expect(blocks(editor)[0]).toMatchObject({ checked: true });
    expect(box.getAttribute('aria-checked')).toBe('true');
    editor.destroy();
  });
});
