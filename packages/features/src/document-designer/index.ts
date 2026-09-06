/**
 * The Document & Label Designer — the shared, client-agnostic slice.
 *
 * Mounted by web (`web/components/documents/document-designer.tsx`, which
 * injects the app's toast runtime and its typed-api-client adapter) and by the
 * Tauri desktop template (`templates/tauri-desktop/src/documents-page.tsx`,
 * over the `remote_request` transport).
 *
 * NOT re-exported from `../index.ts`. That barrel is `export *`, and this
 * slice and the roles slice both export `Transport`/`TransportResponse`, which
 * would collide. Consume it by subpath: `@amroksaleh/features/document-designer`.
 * `print-document` additionally has its OWN subpath export so the render
 * service can pull just the renderer without dragging the editor's dependency
 * graph (radix, icons, canvas) into a production PDF bundle.
 */

export { DocumentDesignerScreen } from './document-designer-screen';
export { createDocumentDesignerAdapter } from './adapter';

export type {
  DocumentDesignerAdapter,
  DocumentDesignerScreenProps,
  DocumentsNotify,
  DocumentsNotifyType,
  SavedTemplate,
  Transport,
  TransportResponse,
} from './types';
export { noopNotify } from './types';

// The pure model — web's storage.ts/blocks.ts re-export these so every
// existing `@/lib/documents/*` import keeps working unchanged.
export {
  exportTemplateJson,
  isDocTemplate,
  migrateTemplate,
  newElement,
  repointBlockInstances,
  sampleDataOf,
  toSavedTemplate,
  interpolate,
  resolveBound,
  type DocumentTemplateRow,
} from './template-model';

export {
  toDocBlock,
  BLOCK_SCOPES,
  blocksById,
  makeBlockFromElements,
  resolveInstance,
  // #1186 slice 3: blocks nested inside blocks.
  MAX_BLOCK_DEPTH,
  blockChildIds,
  flattenBlock,
  wouldCycle,
  type BlockScope,
  type DocBlock,
  type DocumentBlockRow,
  type FlattenDiagnostics,
  type FlattenResult,
} from './block-model';

export { PrintDocument } from './print-document';
export { Canvas } from './canvas';
export { Palette } from './palette';
export { SideRail, type RailTab } from './side-rail';
export { Inspector, type InspectorTab, type BatchState } from './inspector';
export { EditorTopBar, ShortcutsDialog, useModLabel } from './editor-top-bar';
export {
  buildEditorMenus,
  buildEditorToolbar,
  listEditorShortcuts,
  useEditorChrome,
  type AlignKind,
  type EditorCommandContext,
  type ToolbarButton,
  type ToolbarGroup,
  type ZoomAction,
} from './editor-commands';

export { DEFAULT_TEXT_STYLE, PAGE_PRESETS, blankTemplate, newPageId } from './presets';
export { STARTER_BLOCKS, STARTER_TEMPLATES, type StarterTemplate } from './starters';
export {
  DEFAULT_SEQUENCE,
  MAX_BATCH_ROWS,
  generateSequence,
  rowsFromRecords,
  rowsFromValues,
  type SequenceConfig,
} from './batch';
export { parseDelimited, parseJsonRows, type ParsedRows } from './csv';
export { applyPlainTextEdit, normalizeRuns, runsToPlainText, toggleRunFormat } from './rich-text';
// #1186 slice 1: document mode.
export { FlowEditor, type FlowEditorProps } from './flow-editor';
// #1186: the selected block's spacing, page behaviour and width.
export { FlowBlockSettings, type FlowBlockSettingsProps } from './flow-block-settings';
// #1186 slice 2: switching a template between the two modes.
export { canvasToFlow, describeSwitch, flowToCanvas, type SwitchCost } from './mode-switch';
