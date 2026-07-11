'use client';

import type { DocElement, ElementType } from '@/lib/documents/types';
import type { DocBlock } from '@/lib/documents/blocks';
import { Button } from '@amroksaleh/ui/button';
import {
  IconTypography,
  IconVariable,
  IconPhoto,
  IconBarcode,
  IconQrcode,
  IconSquare,
  IconLine,
  IconChevronUp,
  IconChevronDown,
  IconTrash,
  IconComponents,
  IconLock,
  IconLockOpen,
  IconEye,
  IconEyeOff,
} from '@tabler/icons-react';

const ADD_ITEMS: ReadonlyArray<{ type: ElementType; label: string; Icon: typeof IconTypography }> = [
  { type: 'text', label: 'Text', Icon: IconTypography },
  { type: 'dynamicText', label: 'Dynamic text', Icon: IconVariable },
  { type: 'image', label: 'Image / logo', Icon: IconPhoto },
  { type: 'barcode', label: 'Barcode', Icon: IconBarcode },
  { type: 'qr', label: 'QR code', Icon: IconQrcode },
  { type: 'rect', label: 'Rectangle', Icon: IconSquare },
  { type: 'line', label: 'Line', Icon: IconLine },
];

function elementLabel(el: DocElement): string {
  switch (el.type) {
    case 'text':
      return el.text || 'Text';
    case 'dynamicText':
      return el.template || 'Dynamic text';
    case 'image':
      return el.binding ? `Image {{${el.binding}}}` : 'Image';
    case 'barcode':
      return `Barcode ${el.symbology}`;
    case 'qr':
      return 'QR code';
    case 'rect':
      return 'Rectangle';
    case 'line':
      return 'Line';
    case 'blockInstance':
      return 'Block';
    default:
      return 'Element';
  }
}

export function Palette({
  elements,
  selectedIds,
  blocks,
  onAdd,
  onSelect,
  onReorder,
  onToggleLock,
  onToggleHidden,
  onDelete,
  onInsertBlock,
  onDeleteBlock,
}: {
  elements: DocElement[];
  selectedIds: string[];
  blocks: DocBlock[];
  onAdd: (type: ElementType) => void;
  onSelect: (id: string, additive?: boolean) => void;
  onReorder: (id: string, dir: 'up' | 'down') => void;
  onToggleLock: (id: string) => void;
  onToggleHidden: (id: string) => void;
  onDelete: (id: string) => void;
  onInsertBlock: (blockId: string) => void;
  onDeleteBlock: (blockId: string) => void;
}) {
  const frontToBack = [...elements].sort((a, b) => b.z - a.z);
  const selectedSet = new Set(selectedIds);

  return (
    <div className="flex h-full flex-col gap-4">
      <div>
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Add element</h3>
        <div className="grid grid-cols-2 gap-1.5">
          {ADD_ITEMS.map(({ type, label, Icon }) => (
            <Button
              key={type}
              variant="outline"
              size="sm"
              className="justify-start gap-1.5"
              data-testid={`doc-add-${type}`}
              onClick={() => onAdd(type)}
            >
              <Icon className="h-3.5 w-3.5" />
              {label}
            </Button>
          ))}
        </div>
      </div>

      {blocks.length > 0 && (
        <div>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Blocks</h3>
          <div className="space-y-1">
            {blocks.map((b) => (
              <div
                key={b.id}
                className="flex items-center gap-1 rounded-md border border-border bg-card px-2 py-1 text-xs"
              >
                <button
                  type="button"
                  data-testid={`doc-block-insert-${b.id}`}
                  className="flex min-w-0 flex-1 items-center gap-1.5 truncate text-start"
                  title={`Insert “${b.name}”`}
                  onClick={() => onInsertBlock(b.id)}
                >
                  <IconComponents className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="truncate">{b.name}</span>
                </button>
                <button
                  type="button"
                  aria-label="Delete block"
                  data-testid={`doc-block-delete-${b.id}`}
                  onClick={() => onDeleteBlock(b.id)}
                >
                  <IconTrash className="h-3.5 w-3.5 text-destructive/80 hover:text-destructive" />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="min-h-0 flex-1">
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Layers ({elements.length})
        </h3>
        <div className="space-y-1 overflow-y-auto">
          {frontToBack.length === 0 && (
            <p className="text-xs text-muted-foreground">No elements yet. Add one above.</p>
          )}
          {frontToBack.map((el) => (
            <div
              key={el.id}
              className={`flex items-center gap-1 rounded-md border px-2 py-1 text-xs ${
                selectedSet.has(el.id) ? 'border-primary bg-primary/10' : 'border-border bg-card'
              }`}
            >
              <button
                type="button"
                data-testid={`doc-layer-select-${el.id}`}
                className={`min-w-0 flex-1 truncate text-start ${el.hidden ? 'text-muted-foreground line-through' : ''}`}
                onClick={(e) => onSelect(el.id, e.shiftKey || e.metaKey || e.ctrlKey)}
                title={elementLabel(el)}
              >
                {elementLabel(el)}
              </button>
              <button
                type="button"
                data-testid={`doc-layer-lock-${el.id}`}
                aria-label={el.locked ? 'Unlock element' : 'Lock element'}
                aria-pressed={!!el.locked}
                onClick={() => onToggleLock(el.id)}
              >
                {el.locked ? (
                  <IconLock className="h-3.5 w-3.5 text-primary" />
                ) : (
                  <IconLockOpen className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                )}
              </button>
              <button
                type="button"
                data-testid={`doc-layer-hide-${el.id}`}
                aria-label={el.hidden ? 'Show element' : 'Hide element'}
                aria-pressed={!!el.hidden}
                onClick={() => onToggleHidden(el.id)}
              >
                {el.hidden ? (
                  <IconEyeOff className="h-3.5 w-3.5 text-primary" />
                ) : (
                  <IconEye className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                )}
              </button>
              <button type="button" aria-label="Bring forward" onClick={() => onReorder(el.id, 'up')}>
                <IconChevronUp className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
              </button>
              <button type="button" aria-label="Send backward" onClick={() => onReorder(el.id, 'down')}>
                <IconChevronDown className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
              </button>
              <button
                type="button"
                aria-label="Delete element"
                disabled={el.locked}
                className="disabled:opacity-30"
                onClick={() => onDelete(el.id)}
              >
                <IconTrash className="h-3.5 w-3.5 text-destructive/80 hover:text-destructive" />
              </button>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
