import type { DocElement } from '@amroksaleh/ui/documents/types';
import { BLOCK_SCOPES, type BlockScope, type DocBlock } from '@amroksaleh/ui/documents/blocks';
import {
  IconChevronUp,
  IconChevronDown,
  IconTrash,
  IconComponents,
  IconLock,
  IconLockOpen,
  IconEye,
  IconEyeOff,
} from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '../i18n';

/**
 * A layer's name in the list: the element's own content when it has any, else
 * the name of what it IS. The element's content is document data and stays
 * verbatim; only the fallback names are ours to translate.
 */
function elementLabel(el: DocElement, t: TranslateFn): string {
  switch (el.type) {
    case 'text':
      return el.text || t('palette.layer.text', 'Text');
    case 'dynamicText':
      return el.template || t('palette.layer.dynamicText', 'Dynamic text');
    case 'image':
      return el.binding
        ? t('palette.layer.imageBound', 'Image {token}', { token: `{{${el.binding}}}` })
        : t('palette.layer.image', 'Image');
    case 'barcode':
      return t('palette.layer.barcode', 'Barcode {symbology}', { symbology: el.symbology });
    case 'qr':
      return t('palette.layer.qr', 'QR code');
    case 'rect':
      return t('palette.layer.rect', 'Rectangle');
    case 'line':
      return t('palette.layer.line', 'Line');
    case 'math':
      return el.expression || t('palette.layer.math', 'Math');
    case 'blockInstance':
      return t('palette.layer.block', 'Block');
    default:
      return t('palette.layer.element', 'Element');
  }
}

/**
 * The editor's left rail: the reusable-blocks library and the layers list.
 *
 * Inserting elements is NOT here — that moved to the top bar (Insert menu + the
 * toolbar's insert group) so every command lives in the chrome and the rail is
 * purely about what is already ON the page.
 */
/**
 * The display name of a block's visibility tier.
 *
 * `BLOCK_SCOPES` lives in the kit (`@amroksaleh/ui/documents/blocks`) and
 * carries English labels, which is correct — a kit constant may not reach for a
 * translator, and the English is the right default for any consumer that has
 * none. Translating is the CONSUMER's job, and until this existed nobody did
 * it: the designer's block palette rendered "SYSTEM" and "TENANT-WIDE" as
 * headings, and "System"/"Personal"/"Tenant-wide"/"Global" inside every scope
 * dropdown, in the middle of an otherwise Arabic editor.
 *
 * Literal `t()` calls per id rather than `t('palette.scope.' + id)`, because a
 * computed key is invisible to `i18n:extract` and would never reach a
 * translator. A scope the kit adds later falls back to its English label.
 */
function scopeLabel(t: ReturnType<typeof useTranslation>, scope: { id: BlockScope; label: string }): string {
  switch (scope.id) {
    case 'system':
      return t('palette.scope.system', 'System');
    case 'personal':
      return t('palette.scope.personal', 'Personal');
    case 'tenant':
      return t('palette.scope.tenant', 'Tenant-wide');
    case 'global':
      return t('palette.scope.global', 'Global');
    default:
      return scope.label;
  }
}

export function Palette({
  elements,
  selectedIds,
  blocks,
  onSelect,
  onReorder,
  onToggleLock,
  onToggleHidden,
  onDelete,
  onInsertBlock,
  onDeleteBlock,
  onSetBlockScope,
}: {
  elements: DocElement[];
  selectedIds: string[];
  blocks: DocBlock[];
  onSelect: (id: string, additive?: boolean) => void;
  onReorder: (id: string, dir: 'up' | 'down') => void;
  onToggleLock: (id: string) => void;
  onToggleHidden: (id: string) => void;
  onDelete: (id: string) => void;
  onInsertBlock: (blockId: string) => void;
  onDeleteBlock: (blockId: string) => void;
  onSetBlockScope: (blockId: string, scope: BlockScope) => void;
}) {
  const t = useTranslation('documents');
  const frontToBack = [...elements].sort((a, b) => b.z - a.z);
  const selectedSet = new Set(selectedIds);

  return (
    <div className="flex h-full flex-col gap-4">
      {blocks.length > 0 && (
        <div className="space-y-2">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {t('palette.blocks.heading', 'Blocks')}
          </h3>
          {BLOCK_SCOPES.filter((s) => blocks.some((b) => b.scope === s.id)).map((s) => (
            <div key={s.id} className="space-y-1">
              <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground/70">{scopeLabel(t, s)}</div>
              {blocks
                .filter((b) => b.scope === s.id)
                .map((b) => (
                  <div
                    key={b.id}
                    className="flex items-center gap-1 rounded-md border border-border bg-card px-2 py-1 text-xs"
                  >
                    <button
                      type="button"
                      data-testid={`doc-block-insert-${b.id}`}
                      className="flex min-w-0 flex-1 items-center gap-1.5 truncate text-start"
                      title={t('palette.blocks.insert', 'Insert “{name}”', { name: b.name })}
                      onClick={() => onInsertBlock(b.id)}
                    >
                      <IconComponents className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span className="truncate">{b.name}</span>
                    </button>
                    <select
                      data-testid={`doc-block-scope-${b.id}`}
                      aria-label={t('palette.blocks.scope', 'Scope for {name}', { name: b.name })}
                      className="h-6 rounded border border-input bg-input/20 px-1 text-[10px] outline-none focus-visible:ring-1 focus-visible:ring-ring/40"
                      value={b.scope}
                      onChange={(e) => onSetBlockScope(b.id, e.target.value as BlockScope)}
                    >
                      {BLOCK_SCOPES.map((sc) => (
                        <option key={sc.id} value={sc.id}>
                          {scopeLabel(t, sc)}
                        </option>
                      ))}
                    </select>
                    <button
                      type="button"
                      aria-label={t('palette.blocks.delete', 'Delete block')}
                      data-testid={`doc-block-delete-${b.id}`}
                      onClick={() => onDeleteBlock(b.id)}
                    >
                      <IconTrash className="h-3.5 w-3.5 text-destructive/80 hover:text-destructive" />
                    </button>
                  </div>
                ))}
            </div>
          ))}
        </div>
      )}

      <div className="min-h-0 flex-1">
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {t('palette.layers.heading', 'Layers ({count})', { count: elements.length })}
        </h3>
        <div className="space-y-1 overflow-y-auto">
          {frontToBack.length === 0 && (
            <p className="text-xs text-muted-foreground">
              {t('palette.layers.empty', 'No elements yet — add one from the Insert menu.')}
            </p>
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
                title={elementLabel(el, t)}
              >
                {elementLabel(el, t)}
              </button>
              <button
                type="button"
                data-testid={`doc-layer-lock-${el.id}`}
                aria-label={
                  el.locked
                    ? t('palette.layer.unlock', 'Unlock element')
                    : t('palette.layer.lock', 'Lock element')
                }
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
                aria-label={
                  el.hidden
                    ? t('palette.layer.show', 'Show element')
                    : t('palette.layer.hide', 'Hide element')
                }
                aria-pressed={!!el.hidden}
                onClick={() => onToggleHidden(el.id)}
              >
                {el.hidden ? (
                  <IconEyeOff className="h-3.5 w-3.5 text-primary" />
                ) : (
                  <IconEye className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                )}
              </button>
              <button
                type="button"
                aria-label={t('palette.layer.bringForward', 'Bring forward')}
                onClick={() => onReorder(el.id, 'up')}
              >
                <IconChevronUp className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
              </button>
              <button
                type="button"
                aria-label={t('palette.layer.sendBackward', 'Send backward')}
                onClick={() => onReorder(el.id, 'down')}
              >
                <IconChevronDown className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
              </button>
              <button
                type="button"
                aria-label={t('palette.layer.delete', 'Delete element')}
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
