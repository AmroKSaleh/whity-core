/**
 * Whity document editor — the contract (design system 2.2, decisions E.1–E.43).
 *
 * The design system defines the editor; the Whity team builds it (E.1).
 * This file is the shared vocabulary between the editor, Whity Render,
 * agents (MCP tools) and plugins. Keep it framework-free: the editor maps
 * it onto TipTap/ProseMirror nodes and marks (see README.md beside it).
 */

/* ---------------------------------------------------------------- Ids */

export type BlockId = string;      // stable, unique within a document (nanoid)
export type DocId = string;
export type UserId = string;
export type AgentId = string;      // an AI principal
export type PluginId = string;     // e.g. "com.example.delivery-tracker"
export type SavedBlockId = string;
export type ISODateTime = string;  // "2026-10-29T14:02:00Z"

/** Who did something. Agents always carry the person they act for (stage 7 actor chip, E.4). */
export type Actor =
  | { kind: 'person'; id: UserId; name: string }
  | { kind: 'agent'; id: AgentId; name: string; onBehalfOf: { id: UserId; name: string }; scope: string[] }
  | { kind: 'system'; name: 'Whity' };

/* ----------------------------------------------------------- Document */

/** E.43: 'label' is a sheet of identical labels, printed one per record or CSV row; page view only. */
export type DocumentKind = 'template' | 'letter' | 'report' | 'note' | 'wiki' | 'label';

export interface WhityDocument {
  id: DocId;
  kind: DocumentKind;
  title: string;
  /** Text direction of the UI the document was created in; each block also follows its own text (E.33). */
  lang: 'en' | 'ar';
  /** E.7: templates and printed kinds open in the page view; notes and wiki continuous. */
  defaultView: 'page' | 'continuous';
  /** E.34 */
  fullWidth?: boolean;
  /** Page setup for the page view and Whity Render (stage 6). */
  page?: { size: 'A4' | 'A5' | 'Letter'; marginMm: 16; letterhead: boolean };
  /** E.43: label sheets only (A4 or Letter). Each label holds the document's blocks. */
  labels?: LabelGrid;
  /** For templates: the record type its fields come from, e.g. "invoice". */
  recordType?: string;
  /**
   * A template's own fields, for values no record type provides (a CSV column
   * on a label sheet). Each is the field `template.<key>`; with a record it prints blank.
   */
  ownFields?: OwnField[];
  blocks: Block[];
  comments: CommentThread[];
  /** Open suggestions; their text and blocks carry `insertion`/`deletion` marks or a `suggestion` attr with the same id. */
  suggestions: Suggestion[];
  /** E.9: templates have a draft and a published version. */
  status: 'draft' | 'published';
  publishedVersion?: number;
  /** E.44: the document's theme and its own overrides; absent or null follows the workspace default. */
  theme?: DocumentThemeRef | null;
  updatedAt: ISODateTime;
  updatedBy: Actor;
}

/* ------------------------------------------------------------- Themes */

/** E.44, stage 6 (6.2): a workspace line the letterhead can print under the name. */
export type LetterheadLine = 'address' | 'taxId' | 'phone' | 'email';

/** A theme's tokens (DOCUMENT-THEMES.md 3.2). Every key is optional in a theme or an override. */
export interface DocumentThemeTokens {
  /** The print colour as #rrggbb: the title, and H1-H3 with `title-and-headings` (E.45). */
  accent?: string;
  /** The title colour on screen in dark mode (E.11). Never printed. */
  accentOnDark?: string;
  accentUse?: 'title' | 'title-and-headings';
  letterhead?: {
    logo?: 'wide' | 'square' | 'none';
    /** In this order, without repeats, at most four. */
    lines?: LetterheadLine[];
  };
}

/** What a document stores: the theme it names and its overrides of the print colour and letterhead. */
export interface DocumentThemeRef {
  /** 'whity.standard' | '<plugin>.<key>' | 'tenant.<key>' */
  ref?: string;
  overrides?: Pick<DocumentThemeTokens, 'accent' | 'letterhead'>;
}

/** Every token resolved: what the editor and Whity Render use. */
export interface ResolvedThemeTokens {
  accent: string;
  accentOnDark: string;
  accentUse: 'title' | 'title-and-headings';
  letterhead: { logo: 'wide' | 'square' | 'none'; lines: LetterheadLine[] };
}

/** The server's resolution of a document's theme (`resolvedTheme`). */
export interface ResolvedDocumentTheme {
  /** The theme actually used, after falling back past one that isn't available. */
  ref: string;
  version: number;
  hash: string;
  tokens: ResolvedThemeTokens;
  /** Where each token came from, by its path. */
  sources: Record<string, string>;
  /** What changed on the way, such as a print colour darkened to read on white. */
  adjustments: { code: string; token: string; from: string; to?: string; message: string }[];
}

/* ------------------------------------------------------------- Inline */

/** Rich text inside a block: a list of runs. */
export type Inline = Text | FieldRef | HardBreak | MathRef;

export interface Text { type: 'text'; text: string; marks?: Mark[] }
export interface HardBreak { type: 'break' }
/** E.42: an inline formula in TeX, rendered with KaTeX; one character for the caret. */
export interface MathRef { type: 'math'; tex: string }

/** E.26: a merge field. One character for the caret; shows its label while editing, its value in preview. */
export interface FieldRef {
  type: 'field';
  path: string;            // "customer.name", "invoice.total", "today"
  label: string;           // "Customer name"
  /** E.27: required fields block issuing; fiscal fields are always required. */
  required?: boolean;
  /** E.23: inside a synced saved block, filled per document. */
  perDocument?: boolean;
  marks?: Mark[];
}

export type Mark =
  | { type: 'bold' }              // weight 600, never 700
  | { type: 'italic' }
  | { type: 'underline' }
  | { type: 'strike' }
  | { type: 'code' }
  | { type: 'link'; href: string; recordRef?: RecordRef }
  | { type: 'highlight' }         // E.25: one colour
  | { type: 'comment'; threadId: string }
  | { type: 'insertion'; suggestionId: string }   // E.4, part 6
  | { type: 'deletion'; suggestionId: string }
  | { type: 'perDocument' };      // E.23: text filled per document in a synced block

/* ------------------------------------------------------------- Blocks */

interface BlockBase {
  id: BlockId;
  /** A block an agent or a suggesting person added: dashed until accepted (E.4). */
  suggestion?: { id: string; action: 'insert' | 'delete' | 'move' };
}

export type Block =
  | ParagraphBlock | HeadingBlock | QuoteBlock | CodeBlock
  | ListItemBlock | ChecklistBlock | CalloutBlock
  | ToggleBlock | ColumnsBlock | DividerBlock | PageBreakBlock
  | TableBlock | ImageBlock | FileBlock
  | RecordBlock | ChartBlock | SignatureBlock
  | BarcodeBlock | QrBlock | MathBlock
  | SavedBlockRef | PluginBlock;

export interface ParagraphBlock extends BlockBase { type: 'paragraph'; content: Inline[] }
export interface HeadingBlock extends BlockBase { type: 'heading'; level: 1 | 2 | 3; content: Inline[] }
export interface QuoteBlock extends BlockBase { type: 'quote'; content: Inline[] }
export interface CodeBlock extends BlockBase { type: 'code'; language?: string; text: string }
/** Lists are flat items with a level (0–2), like Notion; numbering restarts after any other block. */
export interface ListItemBlock extends BlockBase { type: 'bullet' | 'number'; level: 0 | 1 | 2; content: Inline[] }
export interface ChecklistBlock extends BlockBase { type: 'checklist'; level: 0 | 1 | 2; checked: boolean; content: Inline[] }
/** E.14: two kinds only. */
export interface CalloutBlock extends BlockBase { type: 'callout'; kind: 'note' | 'important'; content: Inline[] }
export interface ToggleBlock extends BlockBase { type: 'toggle'; open: boolean; summary: Inline[]; children: Block[] }
export interface ColumnsBlock extends BlockBase { type: 'columns'; columns: [Block[], Block[]] }
export interface DividerBlock extends BlockBase { type: 'divider' }
export interface PageBreakBlock extends BlockBase { type: 'pageBreak' }
/** E.15: document tables, not databases. */
export interface TableBlock extends BlockBase {
  type: 'table';
  headerRow: boolean;
  columns: { align: 'start' | 'end'; widthPct?: number }[];
  rows: Inline[][][];   // rows → cells → inline
}
export interface ImageBlock extends BlockBase { type: 'image'; src: string; alt: string; width: 'half' | 'wide' | 'full'; caption?: Inline[] }
export interface FileBlock extends BlockBase { type: 'file'; fileId: string; name: string; size: number; mime: string }

export interface RecordRef { type: string; id: string }   // { type: 'invoice', id: 'INV-1042' }
/** E.16: live in the editor; each PDF freezes the values at render time. */
export interface RecordBlock extends BlockBase { type: 'record'; ref: RecordRef; view: 'card' | 'summary' }
export interface ChartBlock extends BlockBase {
  type: 'chart';
  chart: 'bar' | 'line' | 'donut';
  /** A saved query or report the chart reads from; resolved within the reader's permissions. */
  source: { query: string; params?: Record<string, string> };
  title?: string;
}
/** Stage 6 signature row: signers, a date, an optional stamp. */
export interface SignatureBlock extends BlockBase {
  type: 'signature';
  signers: { name: Inline[]; role: Inline[] }[];
  date: Inline[];
  stamp: boolean;
}

/** E.40: ink only; the value is typed text or a merge field. Sized in mm in the page view. */
export interface BarcodeBlock extends BlockBase {
  type: 'barcode';
  symbology: 'code128' | 'ean13' | 'code39';
  value: (Text | FieldRef)[];
  showText: boolean;
  widthMm?: number;
  heightMm?: number;
}
/** E.41: a value or field as a QR. Not the document's verification QR (6.7), which stays automatic. */
export interface QrBlock extends BlockBase {
  type: 'qr';
  value: (Text | FieldRef)[];
  caption?: Inline[];
  sizeMm?: number;
}
/** E.42: a display formula in TeX, rendered with KaTeX on screen and in print. */
export interface MathBlock extends BlockBase { type: 'math'; tex: string }

/** A field a template declares itself: `key` is lowercase letters, digits and _. */
export interface OwnField {
  key: string;
  label: string;
  sample?: string;
}

/** E.43: the grid of a label sheet. A certificate is a one-label sheet (columns 1, rows 1). */
export interface LabelGrid {
  columns: number;
  rows: number;
  size: { widthMm: number; heightMm: number };
  /** Between columns, and between rows unless `gapYMm` is set. */
  gapMm: number;
  /** Between rows, when stock differs from its column gap (Avery L7160: 2.5 and 0). */
  gapYMm?: number;
}
/**
 * E.43: what Print fills the labels from: one label per record, or per row of a CSV.
 * The CSV travels with the print request and is never stored: a mailing list is
 * personal data, and documents' file store holds only PDFs and images.
 */
export type LabelPrintSource =
  | { kind: 'records'; records: RecordRef[] }
  | { kind: 'csv'; file: File; columns: Record<string, string> /* CSV column -> field path */ };

/** E.21: a saved block in a document. Synced ones point at the library; copies are ordinary blocks. */
export interface SavedBlockRef extends BlockBase {
  type: 'savedBlock';
  savedBlockId: SavedBlockId;
  /** The library version this document shows; published templates pick up changes at their next publish. */
  version: number;
  /** E.23: values for the parts marked "filled per document", by key. */
  overrides: Record<string, Inline[]>;
}

/** E.17–E.19: a block from a plugin. The data is the plugin's own, validated against its schema. */
export interface PluginBlock extends BlockBase {
  type: 'plugin';
  plugin: PluginId;
  blockType: string;          // the plugin's block name, e.g. "deliveryStatus"
  schemaVersion: number;
  data: Record<string, unknown>;
}

/* ------------------------------------------- Comments and suggestions */

export interface CommentThread {
  id: string;
  /** Anchored by the `comment` mark; a thread whose text was deleted keeps its quote. */
  quote: string;
  messages: { id: string; by: Actor; at: ISODateTime; body: Inline[]; mentions: Actor[] }[];
  resolved?: { by: Actor; at: ISODateTime };
}

export interface Suggestion {
  id: string;
  by: Actor;                  // a person in Suggesting mode, or an agent (E.4, E.10)
  at: ISODateTime;
  /** What the rail card says: "Replace 30 days with 14 days". Derived, but stored for the audit log. */
  summary: string;
  kind: 'text' | 'format' | 'block';
  request?: string;           // for agents: what they were asked
  /** The one permission the change used, e.g. "document:edit" (shown on the card and in the audit log). */
  permission?: string;
  /** E.32: set when an owner's routine started it, not a person in the moment. */
  routine?: { id: string; name: string; setUpBy: Actor & { kind: 'person' } };
}

/** E.29: anyone who can edit decides; every decision is logged. */
export interface SuggestionDecision { suggestionId: string; decision: 'accepted' | 'rejected'; by: Actor; at: ISODateTime }

/* --------------------------------------------- Block registry (plugins) */

/** What every block type declares, Whity's own and plugins' (E.17–E.20). */
export interface BlockDefinition {
  /** Unique name; plugin blocks are namespaced: "com.example.delivery-tracker/deliveryStatus". */
  name: string;
  title: string;                       // "Delivery status"
  description: string;                 // one line for the / menu
  icon: string;                        // a Whity icon name or an SVG from the plugin
  keywords?: string[];
  /** The / menu group: Whity's groups, or the plugin's own name (E.20). */
  group: 'Basic' | 'Lists' | 'Media' | 'Structure' | 'Whity' | string;
  markdown?: string;                   // shortcut, e.g. "##"
  /** JSON Schema for `data`; the editor validates before saving. */
  schema: object;
  defaultData: Record<string, unknown>;
  /** Permissions the block reads with, granted at install and shown like ScopeGrant. */
  scopes: string[];
  /** E.17: how it renders. Verified and first-party: native; others: sandboxed frame. */
  render: { native?: string /* module export using the Whity SDK */; sandbox?: { url: string; minHeight: number } };
  /** E.18: needed to be used in templates or anything that prints. */
  print?: { renderer: string /* Whity Render template id */ };
  /** Controls for the block toolbar shown when the block is selected (E.8). */
  toolbar?: { id: string; label: string; icon?: string; kind: 'toggle' | 'menu' | 'button' }[];
  /** Where it may be inserted. */
  allowedIn: DocumentKind[];
}

export interface PluginManifestBlocks {
  plugin: PluginId;
  name: string;
  logo: string;
  verified: boolean;                   // set by Whity, not the plugin
  firstParty: boolean;                 // made by Whity; renders natively like verified ones (E.17)
  blocks: BlockDefinition[];
}

/** E.19: the editor shows this when a block's plugin is off; the data is kept. */
export interface MissingPluginState { plugin: PluginId; pluginName: string; canEnable: boolean; blocksPublish: true }

/* -------------------------------------------------------- Saved blocks */

/** E.21–E.24 */
export interface SavedBlock {
  id: SavedBlockId;
  name: string;
  description?: string;
  mode: 'synced' | 'copy';
  sharing: 'me' | 'workspace';
  owner: Actor;
  blocks: Block[];
  /** Keys of the parts filled per document (marked with `perDocument`). */
  perDocumentKeys: string[];
  version: number;
  usedIn?: number;                     // documents using a synced block
}

/* ---------------------------------------------------- The editor's API */

export type EditorMode = 'editing' | 'suggesting' | 'viewing';   // E.10

export interface AgentRequest {
  agent: AgentId;
  request: string;                     // what the person typed
  /** E.31: the document and the records its fields and blocks point to. */
  context: { document: DocId; records: RecordRef[]; selection?: { blockIds: BlockId[]; text: string } };
  kind: 'write' | 'rewrite' | 'comment-reply';
}
export interface AgentResult {
  by: Actor & { kind: 'agent' };
  /** Always suggestions (E.4): inserted blocks, or insertion/deletion marks for a rewrite. */
  blocks?: Block[];
  rewrite?: { deleteText: string; insert: Inline[] };
  comment?: Inline[];
  summary: string;
}

export interface DocEditorProps {
  document: WhityDocument;
  me: Actor & { kind: 'person' };
  canEdit: boolean;                    // false → Suggesting (E.10)
  mode?: EditorMode;                   // defaults to 'editing', or 'suggesting' when canEdit is false
  onModeChange?(mode: EditorMode): void;
  view?: 'continuous' | 'page';
  /** Field groups offered by the picker for this template's record type. */
  fields: { group: string; items: { path: string; label: string; required?: boolean; sample?: string }[] }[];
  /** E.12: preview with a record — read-only, comments and suggestions hidden, until showFields. */
  preview?: { record: RecordRef; label: string; values: Record<string, string | null>; showFields?: boolean };
  onPreviewChange?(preview: DocEditorProps['preview'] | null): void;
  blockDefinitions: BlockDefinition[]; // Whity's and installed plugins'
  savedBlocks: SavedBlock[];
  agents: { id: AgentId; name: string; scope: string[] }[];
  letterhead?: { logo?: string; name: string; lines: string[] };
  onChange(doc: WhityDocument): void;  // debounced; drafts autosave
  onPublish?(doc: WhityDocument): Promise<void>;          // E.9, templates only
  onExportPdf?(doc: WhityDocument): Promise<void>;        // Whity Render
  onPrintLabels?(doc: WhityDocument, source: LabelPrintSource): Promise<void>;  // E.43, label sheets only
  onAskAgent?(req: AgentRequest): AsyncIterable<AgentResult>;  // streams (part 7)
  onDecide?(d: SuggestionDecision): void;
  onSaveBlock?(b: Omit<SavedBlock, 'id' | 'version' | 'usedIn'>): Promise<SavedBlock>;
  labels?: Record<string, string>;     // every string has an English default; Arabic via labels
}

/* ------------------------------------------------------ Publishing rules */

/** What blocks Publish (E.9, E.37) — the editor lists these in a dialog. */
export type PublishBlocker =
  | { kind: 'open-suggestions'; count: number }
  | { kind: 'missing-plugin'; plugin: PluginId }             // E.19
  | { kind: 'block-without-print-view'; blockId: BlockId }   // E.18
  | { kind: 'unknown-field'; path: string }
  /** E.44: the document names a theme the workspace doesn't have. */
  | { kind: 'theme-unavailable'; ref: string };
