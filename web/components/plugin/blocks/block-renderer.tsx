'use client';

import * as React from 'react';
import dynamic from 'next/dynamic';
import Link from 'next/link';
import * as TablerIcons from '@tabler/icons-react';
import type { Icon } from '@tabler/icons-react';
import {
  IconArrowDownRight,
  IconArrowUpRight,
  IconChevronDown,
  IconChevronUp,
  IconMinus,
  IconPlus,
  IconTrash,
  IconPointFilled,
  IconRefresh,
  IconSearch,
} from '@tabler/icons-react';
import type {
  ActionButtonBlock,
  AlertBlock,
  BadgeBlock,
  BilingualTextInputBlock,
  Block,
  ButtonBlock,
  CardBlock,
  ChartBlock,
  CheckboxBlock,
  CodeBlock,
  ColorInputBlock,
  DataListBlock,
  DataRecordBlock,
  DataStatBlock,
  DataTableBlock,
  DateInputBlock,
  DocumentViewerBlock,
  DrawerBlock,
  FieldArrayBlock,
  FileInputBlock,
  FlowBlock,
  FormBlock,
  GridBlock,
  HeadingBlock,
  IconBlock,
  InboxBlock,
  ItemAction,
  KeyValueBlock,
  ListBlock,
  LocalizedTextValue,
  MarkdownBlock,
  MathBlock,
  ModalBlock,
  NumberInputBlock,
  OuScopeKind,
  OuScopePickerBlock,
  OuScopeValue,
  RecordFact,
  RecordFieldsBlock,
  ReferenceSelectBlock,
  RichTextInputBlock,
  RowAction,
  RowBlock,
  SectionBlock,
  SelectBlock,
  SelectorBlock,
  SliderBlock,
  SourceParam,
  StatBlock,
  SubmitButtonBlock,
  TabBlock,
  TableBlock,
  TabsBlock,
  TextAreaBlock,
  TextBlock,
  TextInputBlock,
  TimelineBlock,
  VisibleWhen,
  AccessGateBlock,
} from '@/lib/plugin-features';
import { OU_SCOPE_KINDS, isOuScopeValue } from '@/lib/plugin-features';
import { buildFlowModel } from '@/components/plugin/blocks/flow-model';
import { DocumentViewer } from '@/components/plugin/blocks/document-viewer';
import { Chart } from '@amroksaleh/ui/chart';
import { DataTable as SharedDataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { BilingualInput } from '@amroksaleh/ui/bilingual-input';
import { MathText } from '@amroksaleh/ui/math-text';
import { renderMarkdown } from '@/lib/safe-markdown';
import { Pagination } from '@/components/ui/pagination';
import { Textarea } from '@amroksaleh/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@amroksaleh/ui/alert-dialog';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@amroksaleh/ui/dialog';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@amroksaleh/ui/sheet';
import { PermissionButton } from '@/components/rbac/permission-button';
import {
  FormProvider,
  FormScopeProvider,
  useFormBlockContext,
  IssuesReport,
  type FormBlockContextValue,
  type FieldArrayValue,
} from '@/components/plugin/blocks/form-context';
import { resolveContextPath } from '@/components/plugin/blocks/context-path';
import { submitPluginAction } from '@/lib/plugin-action-submit';
import type { ActionIssue } from '@/lib/plugin-action-submit';
import { useToast } from '@/lib/toast-context';
import { usePluginData } from '@/lib/use-plugin-data';
import {
  usePermittedActions,
  type PermittedActionCheck,
} from '@/lib/use-permitted-actions';
import { cn } from '@/lib/utils';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@amroksaleh/ui/card';
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@amroksaleh/ui/tabs';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * WC-227: the web renderer for `screen: 'blocks'` plugin features.
 *
 * It walks a platform-neutral tree of semantic UI blocks (the SP1 block set
 * mirrored in `@/lib/plugin-features`) and draws each node with existing
 * design-token components — never raw colors, hex, or pixels. Containers
 * recurse into `children`; leaves render their semantic props.
 *
 * Hardening (the host has already validated, but a renderer is the last line
 * of defense):
 *   - Every plugin string is passed as a React text child, so JSX escapes it.
 *     There is NO `dangerouslySetInnerHTML` and no markup parsing — a value of
 *     `<img onerror=...>` renders as literal text, never an element.
 *   - Each node is revalidated against the block contract before rendering;
 *     an unknown `type`, a missing required prop, or an out-of-set enum
 *     degrades to a quiet inline "Unsupported block" placeholder and NEVER
 *     throws.
 *   - A `button` navigates only when its `href` is an internal path (starts
 *     with `/`); any other href renders an inert, non-navigating control.
 *
 * i18n (domain `plugin`): ONLY the renderer's own chrome is keyed — loading,
 * error, empty and retry affordances, the refresh/sort/filter controls, the
 * repeatable-row controls, and the dialog Cancel/Confirm buttons. Everything
 * the block tree carries is plugin DATA and is rendered verbatim, never keyed
 * and never enumerated: every `label`, `title`, `description`, `text`,
 * `value`, `hint`, column header, option label, list item, table cell,
 * `placeholder`, `emptyText`, `confirm` copy and row-action label. Where a
 * plugin prop is absent and the renderer substitutes its OWN default
 * ("Select {label}", "No data available.", "Item"), that default is ours and
 * is keyed.
 */

const tablerIcons = TablerIcons as unknown as Record<string, Icon | undefined>;

/** Resolve a kebab/snake/Pascal icon name to a Tabler component (fallback dot). */
function resolveIcon(name: string): Icon {
  const pascal = name
    .trim()
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');
  const componentName = pascal.startsWith('Icon') ? pascal : `Icon${pascal}`;
  return tablerIcons[componentName] ?? IconPointFilled;
}

// ---- defensive prop guards (mirror the SDK BlockContract rules) ----

function isNonEmptyString(value: unknown): value is string {
  return typeof value === 'string';
}

function isStringArray(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((item) => typeof item === 'string');
}

function isKvList(
  value: unknown
): value is { label: string; value: string }[] {
  return (
    Array.isArray(value) &&
    value.every(
      (item) =>
        typeof item === 'object' &&
        item !== null &&
        typeof (item as { label?: unknown }).label === 'string' &&
        typeof (item as { value?: unknown }).value === 'string'
    )
  );
}

function isColumnList(
  value: unknown
): value is { key: string; label: string }[] {
  return (
    Array.isArray(value) &&
    value.every(
      (item) =>
        typeof item === 'object' &&
        item !== null &&
        typeof (item as { key?: unknown }).key === 'string' &&
        typeof (item as { label?: unknown }).label === 'string'
    )
  );
}

function isDataColumnList(
  value: unknown
): value is { key: string; label: string; sortable?: boolean; filterable?: boolean }[] {
  return (
    Array.isArray(value) &&
    value.every((item) => {
      if (typeof item !== 'object' || item === null) return false;
      const v = item as { key?: unknown; label?: unknown; sortable?: unknown; filterable?: unknown };
      return (
        typeof v.key === 'string' &&
        typeof v.label === 'string' &&
        (v.sortable === undefined || typeof v.sortable === 'boolean') &&
        (v.filterable === undefined || typeof v.filterable === 'boolean')
      );
    })
  );
}

function isRowList(value: unknown): value is Record<string, string>[] {
  return (
    Array.isArray(value) &&
    value.every(
      (item) =>
        typeof item === 'object' &&
        item !== null &&
        Object.values(item as Record<string, unknown>).every(
          (cell) => typeof cell === 'string'
        )
    )
  );
}

function isChartSeriesList(
  value: unknown
): value is { key: string; label: string; color: 1 | 2 | 3 | 4 | 5 }[] {
  return (
    Array.isArray(value) &&
    value.length > 0 &&
    value.every(
      (item) =>
        typeof item === 'object' &&
        item !== null &&
        typeof (item as { key?: unknown }).key === 'string' &&
        (item as { key: string }).key !== '' &&
        typeof (item as { label?: unknown }).label === 'string' &&
        isOneOfNumber((item as { color?: unknown }).color, [1, 2, 3, 4, 5] as const)
    )
  );
}

/**
 * #868: `inbox.actions` — mirrors the SDK's itemActionList rule. A malformed
 * entry costs the whole block rather than silently rendering an action whose
 * endpoint the host never shape-checked.
 */
/** #883: `dataRecord.fields` — a non-empty list of `{field, label}` facts. */
function isRecordFactList(value: unknown): value is RecordFact[] {
  return (
    Array.isArray(value) &&
    value.length > 0 &&
    value.every(
      (item) =>
        typeof item === 'object' &&
        item !== null &&
        isNonEmptyString((item as { field?: unknown }).field) &&
        typeof (item as { label?: unknown }).label === 'string'
    )
  );
}

function isItemActionList(value: unknown): value is ItemAction[] {
  if (!Array.isArray(value) || value.length === 0) return false;
  return value.every(
    (item) =>
      typeof item === 'object' &&
      item !== null &&
      isNonEmptyString((item as { key?: unknown }).key) &&
      isNonEmptyString((item as { label?: unknown }).label) &&
      isOneOf((item as { method?: unknown }).method, ['POST', 'PUT', 'PATCH', 'DELETE'] as const) &&
      isNonEmptyString((item as { endpoint?: unknown }).endpoint)
  );
}

function isOneOf<T extends string>(value: unknown, allowed: readonly T[]): value is T {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value);
}

function isOneOfNumber<T extends number>(value: unknown, allowed: readonly T[]): value is T {
  return typeof value === 'number' && (allowed as readonly number[]).includes(value);
}


function isValidSubmitSpec(value: unknown): value is { method: 'POST' | 'PUT' | 'PATCH'; endpoint: string } {
  if (typeof value !== 'object' || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    // WC-block-submit-templating: PATCH (the sync update verb) joins POST/PUT.
    (v.method === 'POST' || v.method === 'PUT' || v.method === 'PATCH') &&
    typeof v.endpoint === 'string' &&
    v.endpoint !== ''
  );
}

/** The quiet, non-throwing placeholder for any block we cannot render. */
function UnsupportedBlock({ type }: { type: string }) {
  // `type` is a DSL type identifier, never translated — it travels through the
  // sentence as a placeholder so the sentence stays one unit.
  const t = useTranslation('plugin');
  return (
    <p className="text-xs text-muted-foreground italic" data-slot="block-unsupported">
      {t('blocks.unsupported', 'Unsupported block: {type}', { type })}
    </p>
  );
}

// ---- container renderers ----

function SectionRenderer({ block }: { block: SectionBlock }) {
  return (
    <section className="space-y-3">
      {isNonEmptyString(block.title) && (
        <h2 className="font-heading text-sm font-medium">{block.title}</h2>
      )}
      <BlockList blocks={block.children} />
    </section>
  );
}

function CardRenderer({ block }: { block: CardBlock }) {
  const hasHeader =
    isNonEmptyString(block.title) || isNonEmptyString(block.description);
  return (
    <Card>
      {hasHeader && (
        <CardHeader>
          {isNonEmptyString(block.title) && <CardTitle>{block.title}</CardTitle>}
          {isNonEmptyString(block.description) && (
            <CardDescription>{block.description}</CardDescription>
          )}
        </CardHeader>
      )}
      <CardContent className="space-y-3">
        <BlockList blocks={block.children} />
      </CardContent>
    </Card>
  );
}

const GRID_COLUMN_CLASS: Record<1 | 2 | 3 | 4, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 sm:grid-cols-2',
  3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
  4: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
};

function GridRenderer({ block }: { block: GridBlock }) {
  return (
    <div className={cn('grid gap-4', GRID_COLUMN_CLASS[block.columns])}>
      {block.children.map((child, index) => (
        <BlockNode key={index} block={child} />
      ))}
    </div>
  );
}

const ROW_ALIGN_CLASS: Record<
  NonNullable<RowBlock['align']>,
  string
> = {
  start: 'justify-start',
  center: 'justify-center',
  end: 'justify-end',
  between: 'justify-between',
};

function RowRenderer({ block }: { block: RowBlock }) {
  const align = block.align ?? 'start';
  return (
    <div className={cn('flex flex-wrap items-center gap-3', ROW_ALIGN_CLASS[align])}>
      {block.children.map((child, index) => (
        <BlockNode key={index} block={child} />
      ))}
    </div>
  );
}

function TabsRenderer({ block }: { block: TabsBlock }) {
  // A `tab`'s children are rendered from here rather than through `BlockNode`,
  // so this is the one place a `visibleWhen` would otherwise be silently
  // ignored — a contract that says every block carries the facet has to mean it
  // (#909). Hiding a tab the caller may not open is also the point of carrying
  // it here at all.
  const form = useFormBlockContext();
  const md = useMasterDetail();
  const access = useAccess();
  // Keep only valid tab children; ignore anything else defensively.
  const tabs = block.children.filter(
    (child): child is TabBlock =>
      child.type === 'tab' &&
      isNonEmptyString(child.label) &&
      isBlockVisible(child, form, md, access)
  );
  if (tabs.length === 0) {
    return <UnsupportedBlock type="tabs" />;
  }
  const value = (index: number): string => `tab-${index}`;
  return (
    <Tabs defaultValue={value(0)}>
      <TabsList>
        {tabs.map((tab, index) => (
          <TabsTrigger key={index} value={value(index)}>
            {tab.label}
          </TabsTrigger>
        ))}
      </TabsList>
      {tabs.map((tab, index) => (
        <TabsContent key={index} value={value(index)} className="space-y-3">
          <BlockList blocks={tab.children} />
        </TabsContent>
      ))}
    </Tabs>
  );
}

// ---- leaf renderers ----

/**
 * #883: the value a literal leaf actually shows — the record field named by its
 * `…From` twin when that resolves, otherwise the declared literal.
 *
 * The literal is the FALLBACK rather than the alternative, which is why the
 * contract keeps it required. A record page titles itself with its record's
 * name, and it still needs a title in the two moments the reference does not
 * resolve: before the record has arrived, and on a screen where nothing
 * publishes it at all.
 */
function useBoundText(literal: string, ref: string | undefined): string {
  const md = useMasterDetail();
  if (ref === undefined || ref === '') return literal;
  const resolved = resolveContextRef(md, ref);
  return resolved !== undefined && resolved !== '' ? resolved : literal;
}

function HeadingRenderer({ block }: { block: HeadingBlock }) {
  const text = useBoundText(block.text, block.textFrom);
  const className = cn(
    'font-heading font-semibold',
    block.level === 1 && 'text-xl',
    block.level === 2 && 'text-lg',
    block.level === 3 && 'text-base',
    block.level === 4 && 'text-sm'
  );
  switch (block.level) {
    case 1:
      return <h1 className={className}>{text}</h1>;
    case 2:
      return <h2 className={className}>{text}</h2>;
    case 3:
      return <h3 className={className}>{text}</h3>;
    case 4:
      return <h4 className={className}>{text}</h4>;
  }
}

function TextRenderer({ block }: { block: TextBlock }) {
  const value = useBoundText(block.value, block.valueFrom);
  return (
    <p
      className={cn(
        'text-xs/relaxed',
        block.tone === 'muted' ? 'text-muted-foreground' : 'text-foreground'
      )}
    >
      {value}
    </p>
  );
}

const ALERT_VARIANT: Record<AlertBlock['variant'], React.ComponentProps<typeof Alert>['variant']> = {
  info: 'info',
  success: 'success',
  warning: 'warning',
  danger: 'destructive',
};

function AlertRenderer({ block }: { block: AlertBlock }) {
  return (
    <Alert variant={ALERT_VARIANT[block.variant]}>
      {isNonEmptyString(block.title) && <AlertTitle>{block.title}</AlertTitle>}
      <AlertDescription>{block.body}</AlertDescription>
    </Alert>
  );
}

const BADGE_TONE_CLASS: Record<BadgeBlock['variant'], string> = {
  neutral: '',
  info: 'bg-info/10 text-info',
  success: 'bg-success/10 text-success',
  warning: 'bg-warning/10 text-warning',
  danger: 'bg-destructive/10 text-destructive',
};

function BadgeRenderer({ block }: { block: BadgeBlock }) {
  const label = useBoundText(block.label, block.labelFrom);
  return (
    <Badge
      variant={block.variant === 'neutral' ? 'secondary' : 'outline'}
      className={BADGE_TONE_CLASS[block.variant]}
    >
      {label}
    </Badge>
  );
}

const TREND_ICON: Record<NonNullable<StatBlock['trend']>, Icon> = {
  up: IconArrowUpRight,
  down: IconArrowDownRight,
  flat: IconMinus,
};

const TREND_TONE: Record<NonNullable<StatBlock['trend']>, string> = {
  up: 'text-success',
  down: 'text-destructive',
  flat: 'text-muted-foreground',
};

function StatRenderer({ block }: { block: StatBlock }) {
  const TrendIcon = block.trend ? TREND_ICON[block.trend] : null;
  const value = useBoundText(block.value, block.valueFrom);
  const hint = useBoundText(block.hint ?? '', block.hintFrom);
  return (
    <div className="rounded-lg bg-card p-4 ring-1 ring-foreground/10">
      <div className="text-xs text-muted-foreground">{block.label}</div>
      <div className="mt-1 flex items-center gap-1.5">
        <span className="font-heading text-xl font-semibold">{value}</span>
        {TrendIcon !== null && block.trend && (
          <TrendIcon className={cn('size-4', TREND_TONE[block.trend])} aria-hidden />
        )}
      </div>
      {isNonEmptyString(hint) && (
        <div className="mt-1 text-xs text-muted-foreground">{hint}</div>
      )}
    </div>
  );
}

function KeyValueRenderer({ block }: { block: KeyValueBlock }) {
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-xs/relaxed">
      {block.items.map((item, index) => (
        <React.Fragment key={index}>
          <dt className="font-medium text-muted-foreground">{item.label}</dt>
          <dd className="text-foreground">{item.value}</dd>
        </React.Fragment>
      ))}
    </dl>
  );
}

function ListRenderer({ block }: { block: ListBlock }) {
  const className = cn(
    'space-y-1 ps-5 text-xs/relaxed text-foreground',
    block.ordered ? 'list-decimal' : 'list-disc'
  );
  if (block.ordered) {
    return (
      <ol className={className}>
        {block.items.map((item, index) => (
          <li key={index}>{item}</li>
        ))}
      </ol>
    );
  }
  return (
    <ul className={className}>
      {block.items.map((item, index) => (
        <li key={index}>{item}</li>
      ))}
    </ul>
  );
}

function TableRenderer({ block }: { block: TableBlock }) {
  return (
    <div className="overflow-x-auto rounded-lg ring-1 ring-foreground/10">
      <table className="w-full border-collapse text-xs/relaxed">
        <thead>
          <tr className="border-b border-border bg-muted/40">
            {block.columns.map((column) => (
              <th
                key={column.key}
                className="px-3 py-2 text-start font-medium text-muted-foreground"
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {block.rows.map((row, rowIndex) => (
            <tr key={rowIndex} className="border-b border-border last:border-0">
              {block.columns.map((column) => (
                <td key={column.key} className="px-3 py-2 text-foreground">
                  {row[column.key] ?? ''}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const BUTTON_VARIANT: Record<
  NonNullable<ButtonBlock['variant']>,
  React.ComponentProps<typeof Button>['variant']
> = {
  primary: 'default',
  secondary: 'secondary',
  outline: 'outline',
  ghost: 'ghost',
  destructive: 'destructive',
};

function ButtonRenderer({ block }: { block: ButtonBlock }) {
  const variant = block.variant ? BUTTON_VARIANT[block.variant] : 'default';
  // Navigate only for internal, same-origin paths; any other href is inert (no
  // navigation). A protocol-relative URL ("//evil.com", or "/\evil.com" which
  // browsers normalize to "//") also starts with "/" but points off-site, so it
  // must be excluded — otherwise a plugin could smuggle an open-redirect.
  const isInternal =
    block.href.startsWith('/') &&
    !block.href.startsWith('//') &&
    !block.href.startsWith('/\\');
  if (isInternal) {
    return (
      <Button asChild variant={variant}>
        <Link href={block.href}>{block.label}</Link>
      </Button>
    );
  }
  return (
    <Button type="button" variant={variant} disabled aria-disabled>
      {block.label}
    </Button>
  );
}

function IconRenderer({ block }: { block: IconBlock }) {
  // Resolve the Tabler component and render via createElement: the dynamic tag
  // is a stable module export looked up by name, not a component defined here
  // (which `react-hooks/static-components` would otherwise flag).
  return React.createElement(resolveIcon(block.name), {
    className: cn(
      'size-4',
      block.tone === 'muted' ? 'text-muted-foreground' : 'text-foreground'
    ),
    'aria-hidden': true,
  });
}

function CodeRenderer({ block }: { block: CodeBlock }) {
  return (
    <pre className="overflow-x-auto rounded-lg bg-muted p-3 font-mono text-xs/relaxed text-foreground">
      <code>{block.content}</code>
    </pre>
  );
}

// WC-532 A5: a LaTeX expression via the KaTeX MathText atom (trust:false).
function MathRenderer({ block }: { block: MathBlock }) {
  return (
    <div className="overflow-x-auto" data-slot="math-block">
      <MathText expression={block.expression} block={block.block === true} />
    </div>
  );
}

// WC-532 A5: Markdown rendered by the dependency-free, XSS-safe renderer.
function MarkdownRenderer({ block }: { block: MarkdownBlock }) {
  return <div data-slot="markdown-block">{renderMarkdown(block.content)}</div>;
}

// ---- WC-532 A7: master-detail (selector → data-bound source params) ----

interface MasterDetail {
  selections: Record<string, string>;
  setSelection: (name: string, value: string) => void;
  // WC-block-modal-drawer: rows published by `open` row actions, keyed by the
  // opened overlay's id; and which overlays are currently open.
  rows: Record<string, Record<string, unknown>>;
  openTargets: Record<string, boolean>;
  openTarget: (id: string, row?: Record<string, unknown>) => void;
  // #883: a `dataRecord` publishes its fetched record under its own id, into
  // the SAME `rows` map an `open` row action writes — so `{id}.{field}` means
  // one thing whether the record came from a route, a selector, or a clicked
  // row. Separate from `openTarget` only because publishing a record must not
  // also mark an overlay open; the addressing is deliberately identical.
  publishRecord: (id: string, fields: Record<string, unknown>, facts: RecordFact[]) => void;
  // The DECLARATION behind each published record: which fields it names and
  // under which labels. Held beside `rows` rather than inside it because
  // `rows` is the addressing surface every `{id}.{field}` reference resolves
  // against, and a parallel label map in there would make `{rec.label}` mean
  // something. Provider-level rather than a context around the `dataRecord`'s
  // subtree, so a `recordFields` that is its SIBLING resolves too — `from` names
  // a record, not a position in the tree.
  recordFacts: Record<string, RecordFact[]>;
  // `options.refresh` bumps `refreshSignal` (passed ONLY from a form's
  // submit-success path) so a plain dismiss/cancel never triggers a refetch.
  closeTarget: (id: string, options?: { refresh?: boolean }) => void;
  refreshSignal: number;
}

const MasterDetailContext = React.createContext<MasterDetail | null>(null);

function useMasterDetail(): MasterDetail | null {
  return React.useContext(MasterDetailContext);
}

// WC-block-modal-drawer: the id of the nearest enclosing modal/drawer, or null
// at the top level — a form inside an overlay reads this to close it (and
// refetch) on submit-success.
const ModalScopeContext = React.createContext<string | null>(null);

function useModalScope(): string | null {
  return React.useContext(ModalScopeContext);
}

/**
 * WC-block-modal-drawer: resolve a master-detail context reference. A dotted
 * `{targetId}.{field}` reads the row published under `targetId` by an `open`
 * row action; a bare name reads the current selection of the selector so named.
 * Returns undefined when unresolved — an unresolvable reference is a no-op,
 * matching the SDK validator's "no cross-reference validation" stance.
 */
export function resolveContextRef(md: MasterDetail | null, ref: string): string | undefined {
  if (md === null || ref === '') return undefined;
  const dot = ref.indexOf('.');
  if (dot === -1) {
    const v = md.selections[ref];
    return v !== undefined && v !== '' ? v : undefined;
  }
  const row = md.rows[ref.slice(0, dot)];
  if (row === undefined) return undefined;
  const v = row[ref.slice(dot + 1)];
  return v === undefined || v === null ? undefined : String(v);
}

/**
 * Provides the shared selection/row state that `selector` and `open` row actions
 * write and data-bound blocks' `params` / form `defaultFrom` read. Rendered once
 * at the BlockRenderer root, so state is visible to every block on the screen.
 */
/**
 * The reserved binding a host seeds with the record its ROUTE is about (#883).
 * Kept in step with the SDK's `BlockValidator::PAGE_RECORD_BINDING`, which
 * refuses a `selector` that would shadow it.
 */
export const PAGE_RECORD_BINDING = 'record';

function MasterDetailProvider({
  children,
  record,
}: {
  children: React.ReactNode;
  /**
   * #883 gap 2: the record a record-page route is about, published under
   * {@link PAGE_RECORD_BINDING} so a `dataRecord` can name it in its `source`
   * (`/api/v1/things/{record}`). Undefined for an ordinary feature screen, in
   * which case nothing binds it and a tree that names it simply never resolves
   * — the contract's standing no-op stance for an unresolvable reference.
   */
  record?: string;
}) {
  const [selections, setSelections] = React.useState<Record<string, string>>(
    record !== undefined && record !== '' ? { [PAGE_RECORD_BINDING]: record } : {}
  );
  const [rows, setRows] = React.useState<Record<string, Record<string, unknown>>>({});
  const [recordFacts, setRecordFacts] = React.useState<Record<string, RecordFact[]>>({});
  const [openTargets, setOpenTargets] = React.useState<Record<string, boolean>>({});
  const [refreshSignal, setRefreshSignal] = React.useState(0);

  const setSelection = React.useCallback(
    (name: string, value: string) => setSelections((prev) => ({ ...prev, [name]: value })),
    []
  );
  const openTarget = React.useCallback((id: string, row?: Record<string, unknown>) => {
    setOpenTargets((prev) => ({ ...prev, [id]: true }));
    if (row !== undefined) setRows((prev) => ({ ...prev, [id]: row }));
  }, []);
  const closeTarget = React.useCallback((id: string, options?: { refresh?: boolean }) => {
    setOpenTargets((prev) => ({ ...prev, [id]: false }));
    if (options?.refresh === true) setRefreshSignal((s) => s + 1);
  }, []);
  // Bails out when nothing changed. `dataRecord` publishes from a render-phase
  // effect on every settled fetch, and an unconditional setState there would
  // re-render the whole feature tree forever.
  const publishRecord = React.useCallback(
    (id: string, fields: Record<string, unknown>, facts: RecordFact[]) => {
      setRows((prev) => {
        const current = prev[id];
        if (current !== undefined && shallowEqualRecords(current, fields)) return prev;
        return { ...prev, [id]: fields };
      });
      setRecordFacts((prev) => (prev[id] === facts ? prev : { ...prev, [id]: facts }));
    },
    []
  );

  // The route's record is a PROP, so it has to survive a re-render that changes
  // it (one record page navigating to another) without discarding selections
  // the user has made on the screen.
  const seededRecord = React.useRef(record);
  React.useEffect(() => {
    if (seededRecord.current === record) return;
    seededRecord.current = record;
    setSelections((prev) => ({ ...prev, [PAGE_RECORD_BINDING]: record ?? '' }));
  }, [record]);

  const value = React.useMemo<MasterDetail>(
    () => ({ selections, setSelection, rows, recordFacts, openTargets, openTarget, closeTarget, publishRecord, refreshSignal }),
    [selections, setSelection, rows, recordFacts, openTargets, openTarget, closeTarget, publishRecord, refreshSignal]
  );
  return <MasterDetailContext.Provider value={value}>{children}</MasterDetailContext.Provider>;
}

/**
 * Whether two published records hold the same values, compared one level deep.
 *
 * `dataRecord` republishes on every settled fetch, and the projection builds a
 * fresh object each time, so identity comparison would loop forever. One level
 * is enough because the projection's values are whatever the payload held for
 * the declared fields — and a nested object that changed identity but not
 * content costs one extra render, not a loop, since the next comparison sees
 * the same reference again.
 */
function shallowEqualRecords(
  a: Record<string, unknown>,
  b: Record<string, unknown>
): boolean {
  const aKeys = Object.keys(a);
  if (aKeys.length !== Object.keys(b).length) return false;
  return aKeys.every((key) => Object.is(a[key], b[key]));
}

// ---- #909: caller access, resolved by the HOST -----------------------------

/**
 * What a gate's question currently answers.
 *
 * NEITHER unsettled state is a synonym for refused. A gate that has not been
 * answered renders NEITHER branch, because showing the read-only rendering for a
 * frame and then replacing it with the editor is a worse lie than showing
 * nothing for a frame; and a `visibleWhen` reading an unsettled gate hides its
 * block whichever polarity it asked for (see {@link isBlockVisible}).
 *
 * They are told apart because they LOOK different. `'pending'` is in flight and
 * gets a skeleton — something is coming. `'unasked'` is a gate whose endpoint
 * still has an unresolved token (nothing has said which record) or an id nothing
 * declared, and it gets NOTHING: a skeleton that never resolves is a spinner
 * promising an answer no one is fetching.
 */
type AccessAnswer = 'unasked' | 'pending' | 'allowed' | 'refused';

/**
 * The access namespace: gate id -> the host's answer.
 *
 * SEPARATE FROM THE MASTER-DETAIL CONTEXT ON PURPOSE, and this separation is the
 * #895 property restated for #909. A record's published fields live in
 * `MasterDetail.rows`, which is what `resolveContextRef` reads — and
 * `resolveContextRef` is the single resolver behind every fact binding
 * (`textFrom`/`valueFrom`/`labelFrom`/`hintFrom`) and every plumbing binding
 * (`defaultFrom`, `params.from`, a `{token}` in a source). It does not read this
 * map and has no reason to: a gate answer is not a field of anything.
 *
 * So a page can ACT on what the caller may do — that is `visibleWhen.access`,
 * the only prop in the contract that names a gate — and still cannot SAY it
 * about the record. #895 was a page stating "your tenant's role" because a
 * permission flag was reachable as a fact; nothing here makes an answer
 * reachable as a fact, whatever the gate is called.
 */
interface AccessScope {
  answer: (gateId: string) => AccessAnswer;
}

const AccessContext = React.createContext<AccessScope | null>(null);

function useAccess(): AccessScope | null {
  return React.useContext(AccessContext);
}

/** One gate's declaration, as collected from the tree. */
interface CollectedGate {
  id: string;
  method: string;
  endpoint: string;
}

/**
 * Collect every `accessGate` in a tree, in document order.
 *
 * The batch is derived from the TREE rather than registered by the gates as
 * they mount, and that is what makes one request enough. A registration pass
 * would need the gates to render before their own answer could be asked for,
 * which is a second render and a frame of every gated region being absent. The
 * declarations are static, so they can simply be read.
 *
 * Descends through both child slots — `otherwise` holds real blocks and can hold
 * nested gates.
 */
function collectAccessGates(blocks: Block[] | undefined, into: CollectedGate[] = []): CollectedGate[] {
  if (!Array.isArray(blocks)) return into;
  for (const block of blocks) {
    if (block === null || typeof block !== 'object') continue;
    if (
      block.type === 'accessGate' &&
      isNonEmptyString(block.id) &&
      typeof block.check === 'object' &&
      block.check !== null &&
      isNonEmptyString(block.check.method) &&
      isNonEmptyString(block.check.endpoint)
    ) {
      // First declaration wins, matching how the router resolves a duplicate
      // route: two gates under one id is a declaration bug, and picking the
      // later one would make the answer depend on document order in a way
      // nothing else in this contract does.
      if (!into.some((gate) => gate.id === block.id)) {
        into.push({ id: block.id, method: block.check.method, endpoint: block.check.endpoint });
      }
    }
    const node = block as { children?: Block[]; otherwise?: Block[] };
    collectAccessGates(node.children, into);
    collectAccessGates(node.otherwise, into);
  }
  return into;
}

/**
 * Substitute a gate endpoint's `{token}` segments from the master-detail
 * context, or return null when any is unresolved.
 *
 * Null means NOT ASKED, exactly as it does for a `dataRecord.source`, and for
 * the same reason: `/api/v1/roles/{record}` with nothing bound is
 * `/api/v1/roles/`, a different route with a different gate. Being told whether
 * you may write the collection, and rendering an editor for one record on the
 * strength of it, is worse than being told nothing.
 *
 * The substitution itself is {@link resolveContextPath} — shared with
 * `dataRecord.source` and a form's `dataSource.path` so the three cannot drift.
 */
function resolveGateEndpoint(md: MasterDetail | null, endpoint: string): string | null {
  return resolveContextPath(endpoint, (ref) => resolveContextRef(md, ref));
}

/** The methods the host will resolve. Mirrors `BlockValidator::ACCESS_CHECK_METHODS`. */
const ACCESS_CHECK_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const;

function isAccessCheckMethod(value: string): value is PermittedActionCheck['method'] {
  return (ACCESS_CHECK_METHODS as readonly string[]).includes(value);
}

/**
 * Resolves every gate on the screen in ONE batch, through the host's own
 * authority (`POST /api/v1/me/permitted-actions`).
 *
 * ONE batch, not one per gate. The endpoint was built for exactly this shape
 * (#868): a page's worth of questions answered together, so the answers arrive
 * at one moment rather than making the page assemble itself region by region.
 *
 * Fail-closed all the way down. `usePermittedActions` denies every ref while
 * loading, on error, and whenever the batch has moved on from the answer in
 * hand; a gate whose endpoint has an unresolved token is never in the batch and
 * stays pending; and a gate id nothing declared is pending forever. The answers
 * are UI hints regardless — every request is re-gated when it is actually made.
 */
function AccessProvider({ blocks, children }: { blocks: Block[]; children: React.ReactNode }) {
  const md = useMasterDetail();

  const gates = React.useMemo(() => collectAccessGates(blocks), [blocks]);

  // A gate is only asked once its endpoint fully resolves. `resolvable` is the
  // set of ids that made it into the batch, so a gate that dropped out can be
  // told apart from one the host refused.
  const { checks, resolvable } = React.useMemo(() => {
    const list: PermittedActionCheck[] = [];
    const ids = new Set<string>();
    for (const gate of gates) {
      const method = gate.method.toUpperCase();
      if (!isAccessCheckMethod(method)) continue;
      const path = resolveGateEndpoint(md, gate.endpoint);
      if (path === null) continue;
      list.push({ ref: gate.id, method, path });
      ids.add(gate.id);
    }
    return { checks: list, resolvable: ids };
  }, [gates, md]);

  const batchKey = React.useMemo(() => JSON.stringify(checks), [checks]);
  const permitted = usePermittedActions(checks, batchKey);
  const status = permitted.status;
  const isAllowed = permitted.isAllowed;

  const value = React.useMemo<AccessScope>(
    () => ({
      answer: (gateId: string): AccessAnswer => {
        if (!resolvable.has(gateId)) return 'unasked';
        if (status === 'loading') return 'pending';
        // An error is a REFUSAL, not a pending state. The alternative is a
        // region that never resolves, and for the read-only pair that means a
        // record page with no body at all when the resolver is down.
        return status === 'ready' && isAllowed(gateId) ? 'allowed' : 'refused';
      },
    }),
    [resolvable, status, isAllowed]
  );

  return <AccessContext.Provider value={value}>{children}</AccessContext.Provider>;
}

/**
 * AccessGateRenderer (#909) — the two renderings of a gated region, declared
 * together so they cannot drift apart.
 *
 * Renders `children` when the host permits the gate's request and `otherwise`
 * when it refuses. While the answer is pending it renders a skeleton and NOT the
 * refused branch: "you may not edit this" is a statement, and stating it before
 * the answer arrives is stating something not yet known.
 *
 * A gate with neither slot renders nothing at all and exists purely so
 * `visibleWhen: {access: id}` elsewhere on the page has something to name.
 */
function AccessGateRenderer({ block }: { block: AccessGateBlock }) {
  const access = useAccess();
  const answer = access?.answer(block.id) ?? 'unasked';
  const permitted = Array.isArray(block.children) ? block.children : [];
  const refused = Array.isArray(block.otherwise) ? block.otherwise : [];

  if (permitted.length === 0 && refused.length === 0) return null;
  if (answer === 'unasked') return null;

  if (answer === 'pending') {
    return (
      <div className="space-y-2" data-slot="block-access-pending">
        <Skeleton className="h-16 w-full" />
      </div>
    );
  }

  const shown = answer === 'allowed' ? permitted : refused;
  if (shown.length === 0) return null;

  return (
    <div
      className="space-y-4"
      data-slot={answer === 'allowed' ? 'block-access-permitted' : 'block-access-refused'}
    >
      <BlockList blocks={shown} />
    </div>
  );
}

/**
 * Compute a `dataRecord`'s EFFECTIVE source by substituting its `{token}`
 * segments from the master-detail context, or `null` when any token is still
 * unresolved.
 *
 * `null` MATTERS, and it is the one place this differs from how a form's
 * `submit.endpoint` is interpolated. That one substitutes `''` for an
 * unresolved token, which is right for a submit the user explicitly triggered.
 * Here it would be a silent bug of the worst kind: `/api/v1/things/{record}`
 * with nothing bound becomes `/api/v1/things/`, which is very often the
 * COLLECTION endpoint — so the block would fetch every record the caller can
 * see, take whatever the envelope held, and render it as "the record this page
 * is about". Not fetching is the only honest answer to "which record?" when
 * nothing has said.
 *
 * A form's `dataSource.path` resolves through the same {@link resolveContextPath}
 * for exactly that reason (#949) — it is a read, and reads that guess are the
 * ones that go wrong quietly.
 */
function useResolvedRecordSource(
  baseSource: string,
  params?: SourceParam[]
): string | null {
  const md = useMasterDetail();
  const substituted = resolveContextPath(baseSource, (ref) => resolveContextRef(md, ref));
  if (substituted === null) return null;
  if (!params || params.length === 0 || md === null) return substituted;
  const qs = params
    .map((p) => {
      const v = resolveContextRef(md, p.from);
      return v !== undefined && v !== '' ? `${encodeURIComponent(p.param)}=${encodeURIComponent(v)}` : null;
    })
    .filter((x): x is string => x !== null)
    .join('&');
  if (qs === '') return substituted;
  return substituted + (substituted.includes('?') ? '&' : '?') + qs;
}

/**
 * Project a fetched payload down to the facts the declaration NAMED (#883).
 *
 * This is the structural half of the #895 guard, and it is deliberately the
 * only path by which a record reaches the master-detail context. A payload's
 * `manageable`, `canEdit` or `mayModify` is not filtered out here so much as
 * never picked up: the projection reads the declared field names and nothing
 * else, so a caller-permission flag is unreachable from the tree whatever it is
 * called and whether or not the author thought about it. The SDK validator
 * refuses the eleven names #897 knows by name; this refuses everything that was
 * not asked for.
 *
 * A declared field the payload does not carry is published as `undefined`, so
 * `resolveContextRef` reports it unresolved and every binding falls back — the
 * same no-op an unresolvable `params.from` already is.
 */
function projectRecordFacts(
  payload: Record<string, unknown>,
  fields: RecordFact[]
): Record<string, unknown> {
  const facts: Record<string, unknown> = {};
  for (const fact of fields) {
    if (typeof fact?.field === 'string' && fact.field !== '') {
      facts[fact.field] = payload[fact.field];
    }
  }
  return facts;
}

/**
 * Compute a data-bound block's EFFECTIVE source: its base `source` plus any
 * `params` whose reference currently resolves, appended as URL-encoded query
 * params. Returns the base source unchanged when there are no params or nothing
 * resolves yet. usePluginData keys on this string, so a selection/row change
 * re-fetches the block.
 */
function useEffectiveSource(baseSource: string, params?: SourceParam[]): string {
  const md = useMasterDetail();
  if (!params || params.length === 0 || md === null) return baseSource;
  const qs = params
    .map((p) => {
      const v = resolveContextRef(md, p.from);
      return v !== undefined && v !== '' ? `${encodeURIComponent(p.param)}=${encodeURIComponent(v)}` : null;
    })
    .filter((x): x is string => x !== null)
    .join('&');
  if (qs === '') return baseSource;
  return baseSource + (baseSource.includes('?') ? '&' : '?') + qs;
}

/**
 * WC-block-modal-drawer: refetch a data-bound block when the master-detail
 * `refreshSignal` bumps — i.e. after an overlay form submits successfully.
 * Wired into every data-bound renderer (dataTable/dataList/dataStat/chart) so an
 * edit through an overlay is reflected in the whole feature tree, not just the
 * opener. Skips the initial render; `refetch` is undefined only while loading.
 */
function useRefetchOnSignal(refetch: (() => void) | undefined): void {
  const md = useMasterDetail();
  const signal = md?.refreshSignal ?? 0;
  const seen = React.useRef(signal);
  React.useEffect(() => {
    if (seen.current !== signal) {
      seen.current = signal;
      refetch?.();
    }
  }, [signal, refetch]);
}

// WC-532 A7: the master selector — a dropdown fed from an owned collection
// whose selection is published into the shared master-detail context.
function SelectorRenderer({ block }: { block: SelectorBlock }) {
  const t = useTranslation('plugin');
  const md = useMasterDetail();
  const state = usePluginData<Array<Record<string, unknown>>>(
    block.source,
    (body) => (Array.isArray(body) ? (body as Array<Record<string, unknown>>) : null)
  );
  const current = md?.selections[block.name] ?? '';
  const options =
    state.status === 'ready'
      ? state.data.flatMap((row) => {
          const rawValue = row[block.valueField];
          const rawLabel = row[block.labelField];
          if (rawValue === undefined || rawValue === null) return [];
          return [{
            value: String(rawValue),
            label: rawLabel === undefined || rawLabel === null ? String(rawValue) : String(rawLabel),
          }];
        })
      : [];

  return (
    <div className="space-y-1.5" data-slot="selector">
      <label className="text-sm font-medium">{block.label}</label>
      {state.status === 'error' ? (
        <div className="flex items-center gap-3 rounded-lg border border-border bg-card p-2 text-xs text-muted-foreground" data-slot="selector-error">
          <span>{t('blocks.options.loadError', 'Failed to load options.')}</span>
          <Button type="button" variant="outline" size="sm" onClick={state.retry}>{t('blocks.retry', 'Retry')}</Button>
        </div>
      ) : (
        <Select value={current} onValueChange={(v) => md?.setSelection(block.name, v)} disabled={state.status === 'loading'}>
          <SelectTrigger aria-label={block.label} data-slot="selector-trigger">
            {/* `block.placeholder` is the plugin's own copy — only our
                substitute for it is keyed. */}
            <SelectValue placeholder={state.status === 'loading' ? t('blocks.loading', 'Loading…') : (block.placeholder ?? t('blocks.select.placeholder', 'Select {label}', { label: block.label }))} />
          </SelectTrigger>
          <SelectContent>
            {options.map((opt) => <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>)}
          </SelectContent>
        </Select>
      )}
    </div>
  );
}

// ---- SP2 data-bound renderers (WC-231) ----

/**
 * InteractiveDataTable (WC-241) — renders the rows already fetched by
 * `DataTableRenderer` with sort/filter/pagination, now delegated to the
 * shared `@amroksaleh/ui` DataTable engine instead of a bespoke
 * implementation. All three still operate ENTIRELY on the in-memory `rows`
 * array: there is no second fetch, no route other than the block's original
 * (already ownership-verified) `source` is ever touched. Sortable/filterable
 * are per-column booleans; a column with neither behaves exactly like a
 * static `table`. The plugin-facing schema (`block.columns`/`block.pageSize`)
 * is unchanged — only the rendering engine underneath it is.
 */
// WC-532 A1: substitute `{field}` placeholders in a row-action href/endpoint
// with the row's values, URL-encoded. A missing field becomes ''. Only the
// {…} tokens are touched — the surrounding path (already shape-validated by the
// SDK) is left intact.
function applyRowTemplate(template: string, row: Record<string, string>): string {
  return template.replace(/\{([^}]+)\}/g, (_m, key: string) =>
    encodeURIComponent(row[key] ?? '')
  );
}

// WC-532 A1: a single mutating row action (a `{method, endpoint}` RowAction),
// with an optional confirm dialog. On success it toasts and asks the table to
// refresh so the mutated row set reflects the change.
function RowActionButton({
  action,
  row,
  onMutated,
}: {
  action: Extract<RowAction, { endpoint: string }>;
  row: Record<string, string>;
  onMutated?: () => void;
}) {
  const { addToast } = useToast();
  const t = useTranslation('plugin');
  const [open, setOpen] = React.useState(false);
  const [busy, setBusy] = React.useState(false);

  const run = React.useCallback(() => {
    setBusy(true);
    void submitPluginAction(applyRowTemplate(action.endpoint, row), action.method, {}).then((result) => {
      setBusy(false);
      setOpen(false);
      if (result.ok) {
        addToast(t('action.toast.completed', 'Completed successfully'), 'success');
        onMutated?.();
      } else {
        // `result.error` is the server's own message — never keyed.
        addToast(result.error ?? t('action.toast.requestFailed', 'Request failed'), 'error');
      }
    });
  }, [action, row, addToast, onMutated, t]);

  // `action.label` and `action.confirm` are the plugin's copy — verbatim.
  if (typeof action.confirm === 'string' && action.confirm !== '') {
    return (
      <AlertDialog open={open} onOpenChange={setOpen}>
        <AlertDialogTrigger asChild>
          <Button type="button" variant="ghost" size="sm" disabled={busy}>{action.label}</Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{action.label}</AlertDialogTitle>
            <AlertDialogDescription>{action.confirm}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('blocks.dialog.cancel', 'Cancel')}</AlertDialogCancel>
            <AlertDialogAction onClick={(e) => { e.preventDefault(); run(); }}>{action.label}</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    );
  }

  return (
    <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={run}>{action.label}</Button>
  );
}

// WC-block-modal-drawer: an `open` row action — publishes the row into the
// master-detail context under the target overlay's id and opens it. The overlay
// (a modal/drawer elsewhere in the tree) reads the row via `defaultFrom` /
// `params.from`. A no-op if rendered outside a MasterDetailProvider.
function RowOpenButton({
  action,
  row,
}: {
  action: Extract<RowAction, { open: string }>;
  row: Record<string, string>;
}) {
  const md = useMasterDetail();
  return (
    <Button type="button" variant="ghost" size="sm" onClick={() => md?.openTarget(action.open, row)}>
      {action.label}
    </Button>
  );
}

function InteractiveDataTable({
  columns,
  rows,
  pageSize,
  rowActions,
  onMutated,
}: {
  columns: { key: string; label: string; sortable?: boolean; filterable?: boolean }[];
  rows: Record<string, string>[];
  pageSize?: number;
  rowActions?: RowAction[];
  onMutated?: () => void;
}) {
  const dataTableColumns: DataTableColumn<Record<string, string>>[] = columns.map((col) => ({
    id: col.key,
    accessorKey: col.key,
    header: col.label,
    enableSorting: col.sortable === true,
    enableColumnFilter: col.filterable === true,
  }));

  const renderRowActions =
    rowActions && rowActions.length > 0
      ? (row: Record<string, string>) => (
          <div className="flex flex-wrap gap-1">
            {rowActions.map((action, i) =>
              'href' in action ? (
                <Button key={i} asChild variant="ghost" size="sm">
                  <Link href={applyRowTemplate(action.href, row)}>{action.label}</Link>
                </Button>
              ) : 'open' in action ? (
                <RowOpenButton key={i} action={action} row={row} />
              ) : (
                <RowActionButton key={i} action={action} row={row} onMutated={onMutated} />
              )
            )}
          </div>
        )
      : undefined;

  return (
    <SharedDataTable
      columns={dataTableColumns}
      data={rows}
      getRowId={(_row, index) => String(index)}
      pagination={pageSize !== undefined && pageSize > 0 ? { pageSize } : undefined}
      rowActions={renderRowActions}
    />
  );
}

/**
 * DataTableRenderer — fetches rows from `block.source` and reuses
 * `TableRenderer` for the ready state.
 */
function DataTableRenderer({ block }: { block: DataTableBlock }) {
  type Rows = Record<string, unknown>[];
  const t = useTranslation('plugin');
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });

  useRefetchOnSignal(
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined
  );

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-data-loading">
        <Skeleton className="h-8 w-full" />
        <Skeleton className="h-8 w-full" />
        <Skeleton className="h-8 w-3/4" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-error"
      >
        <span>{t('blocks.data.loadError', 'Failed to load data.')}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={state.retry}
        >
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-empty"
      >
        {/* The plugin's own `emptyText` wins; only our default is keyed. */}
        <span>{block.emptyText ?? t('blocks.data.empty', 'No data available.')}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
    );
  }

  // ready
  const rows: Record<string, string>[] = state.data.map((row) =>
    Object.fromEntries(
      block.columns.map((col) => [col.key, String(row[col.key] ?? '')])
    )
  );

  return (
    <div className="space-y-1" data-slot="block-data-refresh">
      <div className="flex justify-end">
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      <InteractiveDataTable
        columns={block.columns}
        rows={rows}
        pageSize={block.pageSize}
        rowActions={block.rowActions}
        onMutated={state.refresh}
      />
    </div>
  );
}

/**
 * DataStatRenderer — fetches a metric object from `block.source` and reuses
 * `StatRenderer` for the ready state.
 */
function DataStatRenderer({ block }: { block: DataStatBlock }) {
  type Metric = Record<string, unknown>;
  const t = useTranslation('plugin');
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Metric>(source, (body) => {
    if (typeof body !== 'object' || body === null) return null;
    const obj = body as Record<string, unknown>;
    if (!(block.valueField in obj)) return null;
    return obj;
  });

  useRefetchOnSignal(
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined
  );

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-data-loading">
        <Skeleton className="h-16 w-full" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-error"
      >
        <span>{t('blocks.data.loadError', 'Failed to load data.')}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={state.retry}
        >
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-empty"
      >
        {/* The plugin's own `emptyText` wins; only our default is keyed. */}
        <span>{block.emptyText ?? t('blocks.data.empty', 'No data available.')}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
    );
  }

  // ready
  const obj = state.data;
  const trendRaw = block.trendField ? obj[block.trendField] : undefined;
  const trend = isOneOf(trendRaw, ['up', 'down', 'flat'] as const)
    ? trendRaw
    : undefined;

  return (
    <div className="space-y-1" data-slot="block-data-refresh">
      <div className="flex justify-end">
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      <StatRenderer
        block={{
          type: 'stat',
          label: block.label,
          value: String(obj[block.valueField] ?? ''),
          hint: block.hintField ? String(obj[block.hintField] ?? '') : undefined,
          trend,
        }}
      />
    </div>
  );
}

/**
 * DataRecordRenderer (#883) — the record-bound primitive.
 *
 * Fetches ONE resource, publishes the fields its declaration NAMES into the
 * master-detail context under `block.id`, and renders its children beneath. It
 * owns loading and failure for the whole subtree, which is the reason it is a
 * container: a record page assembled from a dozen leaves that each own their
 * own skeleton shows a dozen skeletons resolving in an arbitrary order, and a
 * record that failed to load renders as a page of empty fields rather than as a
 * page that could not be loaded.
 */
function DataRecordRenderer({ block }: { block: DataRecordBlock }) {
  const t = useTranslation('plugin');
  const md = useMasterDetail();
  const source = useResolvedRecordSource(block.source, block.params);
  // `usePluginData` is a hook, so it cannot be skipped when the source has not
  // resolved. An empty source string is never fetched (the effect bails), which
  // keeps the hook order stable without issuing a request for a record nobody
  // has named yet.
  const state = usePluginData<Record<string, unknown>>(source ?? '', (body) =>
    typeof body === 'object' && body !== null && !Array.isArray(body)
      ? (body as Record<string, unknown>)
      : null
  );

  useRefetchOnSignal(
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined
  );

  const publishRecord = md?.publishRecord;
  // Keyed on the fetched payload rather than on `state`, which is a fresh object
  // every render — memoizing on it would rebuild the projection each time and
  // re-fire the publish effect on every render. It would still settle (the
  // publisher bails when nothing changed), but "settles" is a weaker property
  // than "does not fire", and this block sits at the root of a whole page.
  //
  // `source === null` forces it back to null: a record whose token stopped
  // resolving (a selection cleared) must stop being published, or a sibling
  // `recordFields` keeps rendering the record the page is no longer about.
  const fetched = state.status === 'ready' && source !== null ? state.data : null;
  const facts = React.useMemo(
    () => (fetched === null ? null : projectRecordFacts(fetched, block.fields)),
    [fetched, block.fields]
  );

  React.useEffect(() => {
    if (facts === null || publishRecord === undefined) return;
    publishRecord(block.id, facts, block.fields);
  }, [facts, publishRecord, block.id, block.fields]);

  if (source === null) {
    // Nothing has named a record yet — a record page before its route resolves,
    // or a detail pane before the user has picked a master row. Deliberately the
    // same shape as `empty` rather than an error: no record chosen is a state,
    // not a failure.
    return (
      <div
        className="rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-record-unbound"
      >
        {block.emptyText ?? t('blocks.record.unbound', 'No record selected.')}
      </div>
    );
  }

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-record-loading">
        <Skeleton className="h-24 w-full" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-record-error"
      >
        <span>{t('blocks.record.loadError', 'Failed to load this record.')}</span>
        <Button type="button" variant="outline" size="sm" onClick={state.retry}>
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-record-empty"
      >
        <span>{block.emptyText ?? t('blocks.record.empty', 'This record is not available.')}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
    );
  }

  // The record is fetched but not yet IN CONTEXT. `publishRecord` runs from an
  // effect, which commits after this render — so rendering the children now
  // mounts them against an empty context, and everything that reads the record
  // AT MOUNT rather than on every render silently gets nothing. A form's
  // `defaultFrom` is exactly that: seeded once, when the input mounts. Holding
  // the loading state for the one extra frame is what makes "a record page is a
  // form WITH its record" true instead of nearly true.
  if (md?.rows[block.id] === undefined) {
    return (
      <div className="space-y-2" data-slot="block-record-loading">
        <Skeleton className="h-24 w-full" />
      </div>
    );
  }

  return (
    <div className="space-y-4" data-slot="block-record">
      <BlockList blocks={block.children} />
    </div>
  );
}

/**
 * RecordFieldsRenderer (#883) — the data-bound `keyValue`.
 *
 * Reads the record published under `block.from` and renders its declared facts
 * as a description list, reusing `KeyValueRenderer` so a literal key/value list
 * and a record's own fields are the same thing on screen. `fields` picks a
 * subset in the order given; omitted, every declared fact is shown.
 */
function RecordFieldsRenderer({ block }: { block: RecordFieldsBlock }) {
  const t = useTranslation('plugin');
  const md = useMasterDetail();
  const row = md?.rows[block.from];
  const declared = md?.recordFacts[block.from];

  if (row === undefined || declared === undefined) {
    // The named record has not published yet (or nothing in this tree declares
    // it). An unresolvable reference is a no-op everywhere else in this
    // contract, and it is a no-op here.
    return null;
  }

  const wanted =
    Array.isArray(block.fields) && block.fields.length > 0
      ? block.fields
          .map((name) => declared.find((fact) => fact.field === name))
          .filter((fact): fact is RecordFact => fact !== undefined)
      : declared;

  if (wanted.length === 0) {
    return (
      <div
        className="rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-record-fields-empty"
      >
        {block.emptyText ?? t('blocks.record.noFields', 'No fields to show.')}
      </div>
    );
  }

  return (
    <div data-slot="block-record-fields">
      <KeyValueRenderer
        block={{
          type: 'keyValue',
          items: wanted.map((fact) => ({
            label: fact.label,
            value: formatFactValue(row[fact.field]),
          })),
        }}
      />
    </div>
  );
}

/**
 * A published fact as display text.
 *
 * `null`/`undefined` become an EM DASH rather than an empty cell or the string
 * "null" — the record-page shell's answer for a value the server has not
 * stated, kept identical here so a described record page and a hand-built one
 * do not disagree about what "no value" looks like. Booleans render as words,
 * because a record's own boolean facts read as sentences on a record page
 * ("Archived: Yes") and "true" reads as a serialization leaking through.
 */
function formatFactValue(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

/**
 * InteractiveList (WC-241) — renders the items already fetched by
 * `DataListRenderer` with an optional inline search box, an alphabetical
 * asc/desc sort toggle, and client-side pagination. All three operate
 * entirely on the in-memory `items` array — no second fetch.
 */
function InteractiveList({
  items,
  ordered,
  sortable,
  filterable,
  pageSize,
}: {
  items: string[];
  ordered?: boolean;
  sortable?: boolean;
  filterable?: boolean;
  pageSize?: number;
}) {
  const t = useTranslation('plugin');
  const [filterText, setFilterText] = React.useState('');
  const [sortDir, setSortDir] = React.useState<'asc' | 'desc' | null>(null);
  const [page, setPage] = React.useState(1);

  const filtered = React.useMemo(() => {
    if (filterable !== true || filterText.trim() === '') return items;
    const needle = filterText.trim().toLowerCase();
    return items.filter((item) => item.toLowerCase().includes(needle));
  }, [items, filterable, filterText]);

  const sorted = React.useMemo(() => {
    if (sortDir === null) return filtered;
    const copy = [...filtered].sort((a, b) => a.localeCompare(b));
    return sortDir === 'asc' ? copy : copy.reverse();
  }, [filtered, sortDir]);

  const paginate = pageSize !== undefined && pageSize > 0;
  const effectivePageSize = paginate ? pageSize : Math.max(sorted.length, 1);
  const totalPages = Math.max(1, Math.ceil(sorted.length / effectivePageSize));
  const clampedPage = Math.min(page, totalPages);
  const paged = paginate
    ? sorted.slice((clampedPage - 1) * effectivePageSize, clampedPage * effectivePageSize)
    : sorted;

  const toggleSort = () => {
    setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    setPage(1);
  };

  return (
    <div className="space-y-2">
      {(filterable === true || sortable === true) && (
        <div className="flex flex-wrap items-center gap-2">
          {filterable === true && (
            <div className="relative">
              <IconSearch
                className="pointer-events-none absolute start-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                aria-hidden
              />
              <Input
                value={filterText}
                onChange={(e) => {
                  setFilterText(e.target.value);
                  setPage(1);
                }}
                placeholder={t('blocks.list.filter', 'Filter items')}
                aria-label={t('blocks.list.filter', 'Filter items')}
                className="h-8 ps-7 text-xs"
              />
            </div>
          )}
          {sortable === true && (
            <Button type="button" variant="outline" size="sm" onClick={toggleSort}>
              {sortDir === 'desc' ? (
                <IconChevronDown className="size-3.5" aria-hidden />
              ) : (
                <IconChevronUp className="size-3.5" aria-hidden />
              )}
              {t('blocks.list.sort', 'Sort')}
            </Button>
          )}
        </div>
      )}
      <ListRenderer block={{ type: 'list', ordered, items: paged }} />
      {paginate && (
        <Pagination
          page={clampedPage}
          perPage={effectivePageSize}
          total={sorted.length}
          onPageChange={setPage}
        />
      )}
    </div>
  );
}

/**
 * DataListRenderer — fetches rows from `block.source` and reuses
 * `ListRenderer` for the ready state.
 */
function DataListRenderer({ block }: { block: DataListBlock }) {
  type Rows = Record<string, unknown>[];
  const t = useTranslation('plugin');
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });

  useRefetchOnSignal(
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined
  );

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-data-loading">
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-4 w-2/3" />
        <Skeleton className="h-4 w-1/2" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-error"
      >
        <span>{t('blocks.data.loadError', 'Failed to load data.')}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={state.retry}
        >
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-empty"
      >
        {/* The plugin's own `emptyText` wins; only our default is keyed. */}
        <span>{block.emptyText ?? t('blocks.data.empty', 'No data available.')}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
    );
  }

  // ready
  const items = state.data.map((row) => String(row[block.itemField] ?? ''));

  return (
    <div className="space-y-1" data-slot="block-data-refresh">
      <div className="flex justify-end">
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      <InteractiveList
        items={items}
        ordered={block.ordered}
        sortable={block.sortable}
        filterable={block.filterable}
        pageSize={block.pageSize}
      />
    </div>
  );
}


// ---- SP4 chart renderer (WC-240) ----

/**
 * ChartRenderer — fetches rows from `block.source` (the SAME verified-route
 * trust boundary as `dataTable`/`dataStat`/`dataList`, enforced generically
 * in `PluginLoader` by the block's `source: apiPath` prop rule) and hands
 * them to the shared `Chart` primitive. Series values are coerced to numbers
 * and the `xField` category to a string; malformed rows degrade the row's
 * value to `0` rather than throwing.
 */
function ChartRenderer({ block }: { block: ChartBlock }) {
  type Rows = Record<string, unknown>[];
  const t = useTranslation('plugin');
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });

  useRefetchOnSignal(
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined
  );

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-data-loading">
        <Skeleton className="h-48 w-full" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-error"
      >
        <span>{t('blocks.data.loadError', 'Failed to load data.')}</span>
        <Button type="button" variant="outline" size="sm" onClick={state.retry}>
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-empty"
      >
        {/* The plugin's own `emptyText` wins; only our default is keyed. */}
        <span>{block.emptyText ?? t('blocks.data.empty', 'No data available.')}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
    );
  }

  // ready
  const data = state.data.map((row) => {
    const mapped: Record<string, string | number> = {};
    if (block.xField !== undefined) {
      mapped[block.xField] = String(row[block.xField] ?? '');
    }
    for (const s of block.series) {
      const raw = row[s.key];
      const num = typeof raw === 'number' ? raw : Number(raw);
      mapped[s.key] = Number.isFinite(num) ? num : 0;
    }
    return mapped;
  });

  return (
    <div className="space-y-1" data-slot="block-data-refresh">
      <div className="flex justify-end">
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      <Chart type={block.chartType} data={data} series={block.series} xKey={block.xField} />
    </div>
  );
}

// ---- workflow renderers (#868) ----

/**
 * TimelineRenderer — an ordered, append-only event list: actor, action,
 * timestamp, an optional note, and an optional from → to pair.
 *
 * Read-only by construction. There is no endpoint, no verb and no affordance in
 * the contract, so there is nothing here to submit — the type cannot grow a
 * write without a contract change a reviewer would see.
 *
 * Data-bound exactly like `dataStat`: one already-verified `source`, then
 * per-field mappings over each row. `pageSize` is the SAME client-side facet
 * `dataTable`/`dataList` carry — it slices rows already fetched and never
 * issues a second request.
 */
function TimelineRenderer({ block }: { block: TimelineBlock }) {
  type Rows = Record<string, unknown>[];
  const t = useTranslation('plugin');
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });
  const [page, setPage] = React.useState(1);

  useRefetchOnSignal(
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined
  );

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-data-loading">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-2/3" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-error"
      >
        <span>{t('blocks.data.loadError', 'Failed to load data.')}</span>
        <Button type="button" variant="outline" size="sm" onClick={state.retry}>
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-empty"
      >
        {/* The plugin's own `emptyText` wins; only our default is keyed. */}
        <span>{block.emptyText ?? t('blocks.timeline.empty', 'No events recorded.')}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
    );
  }

  const events = state.data.map((row) => ({
    actor: String(row[block.actorField] ?? ''),
    action: String(row[block.actionField] ?? ''),
    timestamp: String(row[block.timestampField] ?? ''),
    note: block.noteField !== undefined ? String(row[block.noteField] ?? '') : '',
    from: block.fromField !== undefined ? String(row[block.fromField] ?? '') : '',
    to: block.toField !== undefined ? String(row[block.toField] ?? '') : '',
  }));

  const paginate = block.pageSize !== undefined && block.pageSize > 0;
  const effectivePageSize = paginate ? (block.pageSize as number) : Math.max(events.length, 1);
  const totalPages = Math.max(1, Math.ceil(events.length / effectivePageSize));
  const clampedPage = Math.min(page, totalPages);
  const paged = paginate
    ? events.slice((clampedPage - 1) * effectivePageSize, clampedPage * effectivePageSize)
    : events;

  return (
    <div className="space-y-2" data-slot="block-timeline">
      <div className="flex justify-end">
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      {/* An ordered list, semantically: the order IS the information. */}
      <ol className="relative space-y-4 border-s border-border ps-5">
        {paged.map((event, index) => (
          <li key={index} className="relative" data-slot="block-timeline-event">
            <IconPointFilled
              className="absolute -start-[1.4rem] top-1 size-3 text-muted-foreground"
              aria-hidden
            />
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              <span className="text-sm font-medium text-foreground">{event.actor}</span>
              <span className="text-sm text-foreground">{event.action}</span>
              <span className="text-xs text-muted-foreground">{event.timestamp}</span>
            </div>
            {(event.from !== '' || event.to !== '') && (
              <div className="mt-1 flex flex-wrap items-center gap-1.5">
                {event.from !== '' && <Badge variant="outline">{event.from}</Badge>}
                <span className="text-xs text-muted-foreground" aria-hidden>
                  &rarr;
                </span>
                {event.to !== '' && <Badge variant="secondary">{event.to}</Badge>}
              </div>
            )}
            {event.note !== '' && (
              <p className="mt-1 text-xs text-muted-foreground">{event.note}</p>
            )}
          </li>
        ))}
      </ol>
      {paginate && (
        <Pagination
          page={clampedPage}
          perPage={effectivePageSize}
          total={events.length}
          onPageChange={setPage}
        />
      )}
    </div>
  );
}

/** A stable empty row set, so the memoized item list is not rebuilt every render. */
const EMPTY_ROWS: Record<string, unknown>[] = [];

/**
 * The `ref` under which one (item, action) pair is resolved and looked up.
 * Item ids and action keys are both plugin-supplied, so the separator has to be
 * one the action key cannot contain — the SDK forbids whitespace in `key`, so a
 * space is unambiguous.
 */
function actionRef(itemId: string, actionKey: string): string {
  return `${itemId} ${actionKey}`;
}

/** Stringify a row for `{field}` templating: every value as its display string. */
function templateRowOf(raw: Record<string, unknown>): Record<string, string> {
  return Object.fromEntries(Object.entries(raw).map(([k, v]) => [k, String(v ?? '')]));
}

/**
 * One resolved action button on an inbox item. Rendered ONLY when core answered
 * that this caller may make this exact request, so a refused or unresolved
 * action is absent rather than present-and-disabled: a greyed-out button still
 * advertises work the user cannot do, and a task list of them is noise.
 */
function InboxActionButton({
  action,
  path,
  onMutated,
}: {
  action: ItemAction;
  path: string;
  onMutated: () => void;
}) {
  const { addToast } = useToast();
  const t = useTranslation('plugin');
  const [open, setOpen] = React.useState(false);
  const [busy, setBusy] = React.useState(false);

  const run = React.useCallback(() => {
    setBusy(true);
    void submitPluginAction(path, action.method, {}).then((result) => {
      setBusy(false);
      setOpen(false);
      if (result.ok) {
        addToast(t('action.toast.completed', 'Completed successfully'), 'success');
        onMutated();
      } else {
        // `result.error` is the server's own message — never keyed.
        addToast(result.error ?? t('action.toast.requestFailed', 'Request failed'), 'error');
      }
    });
  }, [action.method, path, addToast, onMutated, t]);

  const variant = action.variant === 'primary' ? 'default' : (action.variant ?? 'outline');

  if (typeof action.confirm === 'string' && action.confirm !== '') {
    return (
      <AlertDialog open={open} onOpenChange={setOpen}>
        <AlertDialogTrigger asChild>
          <Button type="button" variant={variant} size="sm" disabled={busy}>
            {action.label}
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{action.label}</AlertDialogTitle>
            <AlertDialogDescription>{action.confirm}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('blocks.dialog.cancel', 'Cancel')}</AlertDialogCancel>
            <AlertDialogAction onClick={(e) => { e.preventDefault(); run(); }}>
              {action.label}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    );
  }

  return (
    <Button type="button" variant={variant} size="sm" disabled={busy} onClick={run}>
      {action.label}
    </Button>
  );
}

/**
 * InboxRenderer — the items awaiting the current user, each carrying the actions
 * that user may actually take on it.
 *
 * The seam, and the reason the type is in core rather than in each product:
 *
 *   - the PLUGIN supplies the items. Core has no notion of a task queue, so
 *     `source` is an ordinary ownership-checked apiPath, fetched exactly as a
 *     `dataTable`'s is.
 *   - CORE resolves which of the declared `actions` this caller may take on each
 *     item, through `POST /api/v1/me/permitted-actions`. That endpoint answers
 *     from the live route table with the same RoleChecker calls RbacMiddleware
 *     makes, so what is shown here and what the middleware admits are one
 *     computation rather than two that happen to agree.
 *
 * Fail-closed while resolving: `usePermittedActions` denies every ref until the
 * real answer lands, so the action row fills in rather than emptying out.
 *
 * Pagination is the same client-side `pageSize` facet `dataTable`/`dataList`
 * carry, over the rows one fetch of `source` returned — deliberately NOT a
 * second pagination mechanism (the fetch side is #867).
 */
function InboxRenderer({ block }: { block: InboxBlock }) {
  type Rows = Record<string, unknown>[];
  const t = useTranslation('plugin');
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });
  const [page, setPage] = React.useState(1);

  const rows = state.status === 'ready' ? state.data : EMPTY_ROWS;

  const items = React.useMemo(
    () =>
      rows.map((row) => ({
        id: String(row[block.idField] ?? ''),
        title: String(row[block.titleField] ?? ''),
        subtitle: block.subtitleField !== undefined ? String(row[block.subtitleField] ?? '') : '',
        timestamp:
          block.timestampField !== undefined ? String(row[block.timestampField] ?? '') : '',
        status: block.statusField !== undefined ? String(row[block.statusField] ?? '') : '',
        raw: row,
      })),
    [
      rows,
      block.idField,
      block.titleField,
      block.subtitleField,
      block.timestampField,
      block.statusField,
    ]
  );

  // One check per (item, action): the CONCRETE request the button would make,
  // templated from the item exactly as it will be at click time. Asking about
  // the same string the button will send is what makes the answer binding.
  const checks = React.useMemo<PermittedActionCheck[]>(() => {
    const out: PermittedActionCheck[] = [];
    for (const item of items) {
      const row = templateRowOf(item.raw);
      for (const action of block.actions) {
        out.push({
          ref: actionRef(item.id, action.key),
          method: action.method,
          path: applyRowTemplate(action.endpoint, row),
          ...(block.resourceType !== undefined
            ? { resourceType: block.resourceType, resourceId: item.id }
            : {}),
          ...(action.scopedPermission !== undefined
            ? { scopedPermission: action.scopedPermission }
            : {}),
        });
      }
    }
    return out;
  }, [items, block.actions, block.resourceType]);

  // The batch changes only when the resolved requests do. Derived from the
  // checks themselves, so a re-render with identical content does not re-POST.
  const batchKey = React.useMemo(
    () => checks.map((c) => `${c.method} ${c.path} ${c.scopedPermission ?? ''}`).join('|'),
    [checks]
  );

  const permitted = usePermittedActions(checks, batchKey);

  const refresh =
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined;
  useRefetchOnSignal(refresh);

  // After a mutation BOTH halves are stale: the queue (the item may have left
  // it) and the permission answer (approving something can change what is next
  // permitted on it).
  const permittedRefresh =
    permitted.status === 'ready' ? permitted.refresh
      : permitted.status === 'error' ? permitted.retry : undefined;
  const onMutated = React.useCallback(() => {
    refresh?.();
    permittedRefresh?.();
  }, [refresh, permittedRefresh]);

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-data-loading">
        <Skeleton className="h-14 w-full" />
        <Skeleton className="h-14 w-full" />
        <Skeleton className="h-14 w-2/3" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-error"
      >
        <span>{t('blocks.data.loadError', 'Failed to load data.')}</span>
        <Button type="button" variant="outline" size="sm" onClick={state.retry}>
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-empty"
      >
        {/* The plugin's own `emptyText` wins; only our default is keyed. */}
        <span>{block.emptyText ?? t('blocks.inbox.empty', 'Nothing awaiting you.')}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
    );
  }

  const paginate = block.pageSize !== undefined && block.pageSize > 0;
  const effectivePageSize = paginate ? (block.pageSize as number) : Math.max(items.length, 1);
  const totalPages = Math.max(1, Math.ceil(items.length / effectivePageSize));
  const clampedPage = Math.min(page, totalPages);
  const paged = paginate
    ? items.slice((clampedPage - 1) * effectivePageSize, clampedPage * effectivePageSize)
    : items;

  return (
    <div className="space-y-2" data-slot="block-inbox">
      <div className="flex items-center justify-end gap-2">
        {permitted.status === 'error' && (
          <span className="text-xs text-muted-foreground" data-slot="block-inbox-actions-error">
            {t(
              'blocks.inbox.actionsUnavailable',
              'Actions unavailable — permissions could not be resolved.'
            )}
          </span>
        )}
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={onMutated}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      <ul className="space-y-2">
        {paged.map((item, index) => {
          const row = templateRowOf(item.raw);
          const allowedActions = block.actions.filter((action) =>
            permitted.isAllowed(actionRef(item.id, action.key))
          );
          return (
            <li
              key={`${item.id}-${index}`}
              className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-3"
              data-slot="block-inbox-item"
            >
              <div className="min-w-0 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium text-foreground">{item.title}</span>
                  {item.status !== '' && <Badge variant="secondary">{item.status}</Badge>}
                </div>
                {item.subtitle !== '' && (
                  <p className="text-xs text-muted-foreground">{item.subtitle}</p>
                )}
                {item.timestamp !== '' && (
                  <p className="text-xs text-muted-foreground">{item.timestamp}</p>
                )}
              </div>
              {allowedActions.length > 0 && (
                <div className="flex flex-wrap gap-1.5" data-slot="block-inbox-actions">
                  {allowedActions.map((action) => (
                    <InboxActionButton
                      key={action.key}
                      action={action}
                      path={applyRowTemplate(action.endpoint, row)}
                      onMutated={onMutated}
                    />
                  ))}
                </div>
              )}
            </li>
          );
        })}
      </ul>
      {paginate && (
        <Pagination
          page={clampedPage}
          perPage={effectivePageSize}
          total={items.length}
          onPageChange={setPage}
        />
      )}
    </div>
  );
}

// ---- graph renderer (#950) ----

/**
 * react-flow is heavy, touches browser-only APIs, and belongs to exactly one
 * block type — so the canvas is loaded on demand and never server-rendered,
 * the same arrangement the OU hub and the relations hub use for their graphs.
 * A static import would put the graph library in the bundle of every plugin
 * screen, including the great majority that draw no graph.
 */
const FlowCanvas = dynamic(() => import('@/components/plugin/blocks/flow-canvas'), {
  ssr: false,
  loading: () => <Skeleton className="h-[28rem] w-full rounded-lg" />,
});

/**
 * FlowRenderer — a set of nodes and the edges between them.
 *
 * Data-bound exactly like `dataTable`: one already-verified `source`, one fetch,
 * then per-field mappings over each row. The mapping itself lives in
 * {@link buildFlowModel} rather than here, because which rows become nodes and
 * which references become edges is contract behaviour every platform renderer
 * has to agree on.
 *
 * Read-only by construction: the contract carries no endpoint and no verb, so
 * there is nothing on this block to submit. The affordances come from
 * `nodeActions`, which is the SAME `RowAction` list a `dataTable` row carries
 * and is rendered by the same three controls — so an `open` from a node and an
 * `open` from a table row publish the clicked record identically, and an
 * overlay cannot tell which one opened it.
 *
 * TRUNCATION IS ANNOUNCED, never silent (#950, inheriting #192). Above the
 * ceiling the canvas draws the first N nodes in payload order and this renderer
 * says so, with the numbers, above the diagram. A partial graph that looks
 * complete is a worse failure than an unreadable one: a reader can see a tangle
 * and stop trusting it, and cannot see an absence at all.
 */
function FlowRenderer({ block }: { block: FlowBlock }) {
  type Rows = Record<string, unknown>[];
  const t = useTranslation('plugin');
  const md = useMasterDetail();
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });

  useRefetchOnSignal(
    state.status === 'ready' || state.status === 'empty' ? state.refresh
      : state.status === 'error' ? state.retry : undefined
  );

  // Everything below the early returns is a hook, so it is computed here — over
  // an empty row set in every state but `ready`, which costs nothing and keeps
  // the hook order identical across all four.
  const rows = state.status === 'ready' ? state.data : EMPTY_ROWS;
  const model = React.useMemo(() => buildFlowModel(rows, block), [rows, block]);

  const refresh =
    state.status === 'ready' || state.status === 'empty' ? state.refresh : undefined;
  const nodeActions = block.nodeActions;

  // The node's own click runs its FIRST `open` action. A diagram whose only
  // affordance is a control inside the box is a diagram nobody clicks, and
  // "click the node, get the detail" is what both existing graph views already
  // do. It is the first `open` rather than a separate prop so there is exactly
  // one place a node's affordances are declared: adding a second prop for the
  // click would let the two disagree about what a node does.
  //
  // That action still renders as a LABELLED control as well, and the overlap is
  // the point — the button is what makes the affordance discoverable, and the
  // node click is the shortcut for someone who has already discovered it.
  // Dropping the button would leave a graph whose only way in is a gesture
  // nothing on screen suggests.
  const primaryOpen = nodeActions?.find(
    (action): action is Extract<RowAction, { open: string }> => 'open' in action
  );

  const activate = React.useCallback(
    (row: Record<string, string>) => {
      if (primaryOpen === undefined) return;
      md?.openTarget(primaryOpen.open, row);
    },
    [primaryOpen, md]
  );

  const renderNodeActions = React.useCallback(
    (row: Record<string, string>) => (
      <>
        {nodeActions?.map((action, i) =>
          'href' in action ? (
            <Button key={i} asChild variant="ghost" size="sm">
              <Link href={applyRowTemplate(action.href, row)}>{action.label}</Link>
            </Button>
          ) : 'open' in action ? (
            <RowOpenButton key={i} action={action} row={row} />
          ) : (
            <RowActionButton key={i} action={action} row={row} onMutated={refresh} />
          )
        )}
      </>
    ),
    [nodeActions, refresh]
  );

  if (state.status === 'loading') {
    return (
      <div className="space-y-2" data-slot="block-data-loading">
        <Skeleton className="h-[28rem] w-full rounded-lg" />
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-error"
      >
        <span>{t('blocks.data.loadError', 'Failed to load data.')}</span>
        <Button type="button" variant="outline" size="sm" onClick={state.retry}>
          {t('blocks.retry', 'Retry')}
        </Button>
      </div>
    );
  }

  if (state.status === 'empty' || model.nodes.length === 0) {
    // A payload of rows that carry no usable id yields no nodes, and an empty
    // canvas with a working refresh button is indistinguishable from a graph
    // that has not arrived. Both go to the same empty state.
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-dashed border-border bg-card p-3 text-xs text-muted-foreground"
        data-slot="block-data-empty"
      >
        {/* The plugin's own `emptyText` wins; only our default is keyed. */}
        <span>{block.emptyText ?? t('blocks.flow.empty', 'Nothing to diagram yet.')}</span>
        {refresh !== undefined && (
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={t('blocks.data.refresh', 'Refresh')}
            onClick={refresh}
          >
            <IconRefresh className="size-3.5" aria-hidden />
          </Button>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-1" data-slot="block-flow">
      <div className="flex items-center justify-between gap-3">
        {/* Stated in the flow of the page, not as a tooltip or a console
            warning: the reader has to be able to see that what is drawn is
            part of the graph without going looking for the fact. */}
        {model.truncated ? (
          <p className="text-xs text-muted-foreground" data-slot="block-flow-truncated">
            {t(
              'blocks.flow.truncated',
              'Showing the first {shown} of {total} nodes — the rest are not drawn.',
              { shown: String(model.nodes.length), total: String(model.total) }
            )}
          </p>
        ) : (
          <span />
        )}
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={t('blocks.data.refresh', 'Refresh')}
          onClick={refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      <FlowCanvas
        model={model}
        orientation={block.orientation}
        hasSubtitle={block.nodeSubtitleField !== undefined}
        onActivate={primaryOpen !== undefined ? activate : undefined}
        renderNodeActions={
          nodeActions !== undefined && nodeActions.length > 0 ? renderNodeActions : undefined
        }
      />
    </div>
  );
}

// ---- SP3 interactive renderers (WC-235) ----

function InputLabel({ inputId, label, required, error }: { inputId: string; label: string; required?: boolean; error?: string }) {
  return (
    <>
      <label htmlFor={inputId} className="text-sm font-medium">
        {label}
        {required === true && <span className="text-destructive" aria-hidden> *</span>}
      </label>
      {error !== undefined && <p className="text-xs text-destructive" role="alert">{error}</p>}
    </>
  );
}

function FormRenderer({ block }: { block: FormBlock }) {
  const md = useMasterDetail();
  const scopeId = useModalScope();
  // WC-block-modal-drawer: seed inputs' `defaultFrom` from the master-detail
  // context (a row published by an `open` action, or a selector's value), and —
  // when this form lives inside an overlay — close it + refetch on success.
  const resolveRef = React.useCallback(
    (ref: string) => resolveContextRef(md, ref),
    [md]
  );
  const onSubmitSuccess = React.useCallback(() => {
    if (scopeId !== null) md?.closeTarget(scopeId, { refresh: true });
  }, [scopeId, md]);
  return (
    <FormProvider block={block} resolveRef={resolveRef} onSubmitSuccess={onSubmitSuccess}>
      <BlockList blocks={block.children} />
    </FormProvider>
  );
}

// WC-532 A2: a repeatable field-group. Owns an array of row-records under
// block.name in the enclosing form; each row renders the template children
// through a row-SCOPED FormScopeProvider so the ordinary input renderers work
// unchanged (their names resolve against the row, not the outer form). The
// user can add / remove / reorder rows within [min, max].
function FieldArrayRenderer({ block }: { block: FieldArrayBlock }) {
  // Before the early return: a hook may not run conditionally.
  const t = useTranslation('plugin');
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="fieldArray" />;

  const raw = ctx.values[block.name];
  const rows: FieldArrayValue = Array.isArray(raw) ? raw : [];
  const min = typeof block.min === 'number' && block.min > 0 ? block.min : 0;
  const max = typeof block.max === 'number' && block.max > 0 ? block.max : Infinity;
  // The plugin's own noun for a row wins; only our default is keyed. Every
  // control below therefore takes it as a {item} placeholder rather than
  // splicing it onto a translated fragment.
  const itemLabel = block.itemLabel ?? t('blocks.fieldArray.itemLabel', 'Item');

  const write = (next: FieldArrayValue) => ctx.setValue(block.name, next);
  const add = () => { if (rows.length < max) write([...rows, {}]); };
  const remove = (i: number) => { if (rows.length > min) write(rows.filter((_, j) => j !== i)); };
  const move = (i: number, dir: -1 | 1) => {
    const j = i + dir;
    if (j < 0 || j >= rows.length) return;
    const next = rows.slice();
    const tmp = next[i]; next[i] = next[j]; next[j] = tmp;
    write(next);
  };

  return (
    <div className="space-y-2" data-slot="field-array">
      <div className="flex items-center justify-between gap-2">
        <label className="text-sm font-medium">{block.label}</label>
        {ctx.errors[block.name] !== undefined && (
          <p className="text-xs text-destructive" role="alert">{ctx.errors[block.name]}</p>
        )}
      </div>

      {rows.map((row, i) => {
        const rowCtx: FormBlockContextValue = {
          values: row,
          setValue: (childName, v) => {
            // A row holds only scalar/bilingual values — nested arrays (a
            // fieldArray inside a row) are out of scope and ignored.
            if (Array.isArray(v)) return;
            const next = rows.slice();
            next[i] = { ...next[i], [childName]: v };
            write(next);
          },
          errors: {},
          isSubmitting: ctx.isSubmitting,
          submit: ctx.submit,
        };
        return (
          <div key={i} className="space-y-2 rounded-md border border-border p-3" data-slot="field-array-row">
            <div className="flex items-center justify-between gap-2">
              {/* Not keyed: the visible text is the plugin's noun followed by
                  the row number — there is no English prose here to translate,
                  only the (already translated) itemLabel and a numeral. */}
              <span className="text-xs font-medium text-muted-foreground">{itemLabel} {i + 1}</span>
              <div className="flex gap-1">
                <Button type="button" variant="ghost" size="icon-sm" aria-label={t('blocks.fieldArray.moveUp', 'Move {item} {index} up', { item: itemLabel, index: i + 1 })} disabled={i === 0} onClick={() => move(i, -1)}>
                  <IconChevronUp className="size-3.5" aria-hidden />
                </Button>
                <Button type="button" variant="ghost" size="icon-sm" aria-label={t('blocks.fieldArray.moveDown', 'Move {item} {index} down', { item: itemLabel, index: i + 1 })} disabled={i === rows.length - 1} onClick={() => move(i, 1)}>
                  <IconChevronDown className="size-3.5" aria-hidden />
                </Button>
                <Button type="button" variant="ghost" size="icon-sm" aria-label={t('blocks.fieldArray.remove', 'Remove {item} {index}', { item: itemLabel, index: i + 1 })} disabled={rows.length <= min} onClick={() => remove(i)}>
                  <IconTrash className="size-3.5" aria-hidden />
                </Button>
              </div>
            </div>
            <FormScopeProvider value={rowCtx}>
              <BlockList blocks={block.children} />
            </FormScopeProvider>
          </div>
        );
      })}

      <Button type="button" variant="outline" size="sm" disabled={rows.length >= max} onClick={add}>
        <IconPlus className="me-1 size-4" aria-hidden />{t('blocks.fieldArray.add', 'Add {item}', { item: itemLabel.toLowerCase() })}
      </Button>
    </div>
  );
}

function TextInputRenderer({ block }: { block: TextInputBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="textInput" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : '';
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      <Input id={inputId} type={block.sensitive === true ? 'password' : 'text'} value={strValue} placeholder={block.placeholder} onChange={(e) => ctx.setValue(block.name, e.target.value)} aria-label={block.label} />
    </div>
  );
}

function TextAreaRenderer({ block }: { block: TextAreaBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="textArea" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : '';
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      <Textarea id={inputId} value={strValue} rows={block.rows} onChange={(e) => ctx.setValue(block.name, e.target.value)} aria-label={block.label} />
    </div>
  );
}

// WC-532 A5: a Markdown-aware textarea that submits Markdown source and shows
// a live preview (rendered via the same XSS-safe renderer as the markdown block).
function RichTextInputRenderer({ block }: { block: RichTextInputBlock }) {
  // Before the early return: a hook may not run conditionally.
  const t = useTranslation('plugin');
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="richTextInput" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : '';
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      <Textarea id={inputId} value={strValue} rows={block.rows ?? 6} onChange={(e) => ctx.setValue(block.name, e.target.value)} aria-label={block.label} />
      {strValue.trim() !== '' && (
        <div className="rounded-md border border-border bg-muted/30 p-3" data-slot="richtext-preview">
          <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">{t('blocks.richTextInput.preview', 'Preview')}</p>
          {renderMarkdown(strValue)}
        </div>
      )}
    </div>
  );
}

function NumberInputRenderer({ block }: { block: NumberInputBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="numberInput" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : '';
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      <Input id={inputId} type="number" value={strValue} min={block.min} max={block.max} step={block.step} onChange={(e) => ctx.setValue(block.name, e.target.value)} aria-label={block.label} />
    </div>
  );
}

function SelectRenderer({ block }: { block: SelectBlock }) {
  // Before the early return: a hook may not run conditionally.
  const t = useTranslation('plugin');
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="select" />;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : (block.default ?? '');
  return (
    <div className="space-y-1.5">
      <label className="text-sm font-medium">
        {block.label}
        {block.required === true && <span className="text-destructive" aria-hidden> *</span>}
      </label>
      {ctx.errors[block.name] !== undefined && <p className="text-xs text-destructive" role="alert">{ctx.errors[block.name]}</p>}
      <Select value={strValue} onValueChange={(v) => ctx.setValue(block.name, v)}>
        <SelectTrigger aria-label={block.label}><SelectValue placeholder={t('blocks.select.placeholder', 'Select {label}', { label: block.label })} /></SelectTrigger>
        <SelectContent>
          {block.options.map((opt) => <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>)}
        </SelectContent>
      </Select>
    </div>
  );
}

function CheckboxRenderer({ block }: { block: CheckboxBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="checkbox" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const checked = typeof value === 'boolean' ? value : (block.default ?? false);
  return (
    <div className="flex items-center gap-2">
      <input id={inputId} type="checkbox" checked={checked} onChange={(e) => ctx.setValue(block.name, e.target.checked)} className="h-4 w-4 rounded border-input accent-primary" aria-label={block.label} />
      <label htmlFor={inputId} className="text-sm font-medium">{block.label}</label>
    </div>
  );
}

function SliderRenderer({ block }: { block: SliderBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="slider" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : (block.default ?? String(block.min));
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} error={ctx.errors[block.name]} />
      <input id={inputId} type="range" min={block.min} max={block.max} step={block.step ?? 1} value={strValue} onChange={(e) => ctx.setValue(block.name, e.target.value)} className="w-full accent-primary" aria-label={block.label} />
    </div>
  );
}

function DateInputRenderer({ block }: { block: DateInputBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="dateInput" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : '';
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      <Input id={inputId} type="date" value={strValue} onChange={(e) => ctx.setValue(block.name, e.target.value)} aria-label={block.label} />
    </div>
  );
}

function FileInputRenderer({ block }: { block: FileInputBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="fileInput" />;
  const inputId = `block-input-${block.name}`;
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      <Input id={inputId} type="file" accept={block.accept} onChange={(e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (block.encoding === 'base64') {
          const reader = new FileReader();
          reader.onload = (evt) => {
            ctx.setValue(block.name, (evt.target?.result as string) ?? '');
          };
          reader.readAsDataURL(file);
        } else {
          void file.text().then((text) => ctx.setValue(block.name, text));
        }
      }} aria-label={block.label} />
    </div>
  );
}

function ColorInputRenderer({ block }: { block: ColorInputBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="colorInput" />;
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : (block.default ?? '#000000');
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} error={ctx.errors[block.name]} />
      <Input id={inputId} type="color" value={strValue} onChange={(e) => ctx.setValue(block.name, e.target.value)} aria-label={block.label} className="h-9 w-16 cursor-pointer p-0.5" />
    </div>
  );
}

// WC-532 A4: paired AR/EN bilingual text input — reads/writes a {ar?, en?}
// object in the form value map via the shared BilingualInput.
function BilingualTextRenderer({ block }: { block: BilingualTextInputBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="bilingualText" />;
  const inputId = `block-input-${block.name}`;
  const raw = ctx.values[block.name];
  // #868 widened FormValue with the OU scope rule, which is also a plain object
  // — narrow it out explicitly rather than letting an unrelated shape reach the
  // bilingual input as if it were `{ar, en}`.
  const value =
    raw !== null && typeof raw === 'object' && !Array.isArray(raw) && !isOuScopeValue(raw) ? raw : {};
  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      <BilingualInput
        id={inputId}
        value={value}
        onChange={(next) => ctx.setValue(block.name, next)}
        arLabel={block.arLabel}
        enLabel={block.enLabel}
        required={block.required}
      />
    </div>
  );
}

// WC-532 A6: a select whose options are fetched from a plugin-owned collection
// endpoint (usePluginData over `source`), each row mapped {value: valueField,
// label: labelField}. The value submitted is the chosen valueField string —
// identical to a static `select` once loaded. Split so the data fetch only
// runs INSIDE a form: outside one the block degrades and never hits `source`.
function ReferenceSelectRenderer({ block }: { block: ReferenceSelectBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="referenceSelect" />;
  return <ReferenceSelectField block={block} ctx={ctx} />;
}

function ReferenceSelectField({ block, ctx }: { block: ReferenceSelectBlock; ctx: FormBlockContextValue }) {
  const t = useTranslation('plugin');
  const state = usePluginData<Array<Record<string, unknown>>>(
    block.source,
    (body) => (Array.isArray(body) ? (body as Array<Record<string, unknown>>) : null)
  );
  const inputId = `block-input-${block.name}`;
  const value = ctx.values[block.name];
  const strValue = typeof value === 'string' ? value : (block.default ?? '');

  const options =
    state.status === 'ready'
      ? state.data.flatMap((row) => {
          const rawValue = row[block.valueField];
          const rawLabel = row[block.labelField];
          if (rawValue === undefined || rawValue === null) return [];
          return [{
            value: String(rawValue),
            label: rawLabel === undefined || rawLabel === null ? String(rawValue) : String(rawLabel),
          }];
        })
      : [];

  return (
    <div className="space-y-1.5">
      <InputLabel inputId={inputId} label={block.label} required={block.required} error={ctx.errors[block.name]} />
      {state.status === 'error' ? (
        <div className="flex items-center gap-3 rounded-lg border border-border bg-card p-2 text-xs text-muted-foreground" data-slot="reference-select-error">
          <span>{t('blocks.options.loadError', 'Failed to load options.')}</span>
          <Button type="button" variant="outline" size="sm" onClick={state.retry}>{t('blocks.retry', 'Retry')}</Button>
        </div>
      ) : (
        <Select value={strValue} onValueChange={(v) => ctx.setValue(block.name, v)} disabled={state.status === 'loading'}>
          <SelectTrigger aria-label={block.label} data-slot="reference-select-trigger">
            {/* `block.placeholder` is the plugin's own copy — only our
                substitute for it is keyed. */}
            <SelectValue placeholder={state.status === 'loading' ? t('blocks.loading', 'Loading…') : (block.placeholder ?? t('blocks.select.placeholder', 'Select {label}', { label: block.label }))} />
          </SelectTrigger>
          <SelectContent>
            {options.map((opt) => <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>)}
          </SelectContent>
        </Select>
      )}
    </div>
  );
}

// ---- organizational-unit scope picker (#868) ----

/**
 * Core's own OU endpoints. This block is the one leaf in the contract that
 * fetches without a `source`, and these two constants are the whole reason:
 * the hierarchy and its type vocabulary belong to the PLATFORM, so the renderer
 * reads them from the platform under the caller's own session and `ous:read`
 * gate. A plugin has no prop with which to redirect either one — a rule built
 * here is a rule over the units core actually knows about.
 */
const OU_SOURCE = '/api/v1/ous';
const OU_TYPES_SOURCE = '/api/v1/ou-types';

/** One row of `GET /api/v1/ous`, reduced to what the picker reads. */
interface OuRow {
  id: number;
  name: string;
  parent_id: number | null;
}

/** One row of `GET /api/v1/ou-types`. */
interface OuTypeRow {
  key: string;
  label: string;
}

/** The permitted scopes for a block, defaulting to all three in canonical order. */
function effectiveScopes(block: OuScopePickerBlock): OuScopeKind[] {
  const declared = block.scopes;
  if (!Array.isArray(declared) || declared.length === 0) return [...OU_SCOPE_KINDS];
  const valid = declared.filter((s): s is OuScopeKind => OU_SCOPE_KINDS.includes(s));
  return valid.length > 0 ? valid : [...OU_SCOPE_KINDS];
}

/**
 * The rule the control shows before the user has touched it: no anchor, the
 * first permitted scope that means anything without one, and the pinned
 * `memberType` (or no kind filter).
 *
 * This value is NOT seeded into the form — an untouched picker contributes
 * nothing to the payload, exactly as an untouched `referenceSelect` does, so a
 * form that edits a stored rule never overwrites it with a blank one.
 */
function emptyRule(block: OuScopePickerBlock): OuScopeValue {
  const scopes = effectiveScopes(block);
  const anchorless = scopes.find((s) => s !== 'unit');
  return {
    unit: null,
    scope: anchorless ?? scopes[0],
    type: block.memberType ?? null,
  };
}

/**
 * Order the flat OU list as a depth-first walk of the tree, carrying each row's
 * depth, so the dropdown reads as a hierarchy rather than an arbitrary id order.
 *
 * Cycle-safe and loss-free by construction, matching `buildOuTree`: a row whose
 * parent is missing from the list (filtered out by `anchorType`, or simply not
 * present) is promoted to a root rather than dropped, because a unit the user
 * can see in the admin tree but not in this picker reads as missing data.
 */
function orderOuRows(rows: OuRow[]): { row: OuRow; depth: number }[] {
  const childrenOf = new Map<number | null, OuRow[]>();
  const present = new Set(rows.map((r) => r.id));
  for (const row of rows) {
    const key = row.parent_id !== null && present.has(row.parent_id) ? row.parent_id : null;
    const bucket = childrenOf.get(key);
    if (bucket) bucket.push(row);
    else childrenOf.set(key, [row]);
  }
  for (const bucket of childrenOf.values()) {
    bucket.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
  }

  const out: { row: OuRow; depth: number }[] = [];
  const visited = new Set<number>();
  const walk = (parent: number | null, depth: number): void => {
    for (const row of childrenOf.get(parent) ?? []) {
      if (visited.has(row.id)) continue;
      visited.add(row.id);
      out.push({ row, depth });
      walk(row.id, depth + 1);
    }
  };
  walk(null, 0);
  // Corrupt cyclic data leaves rows unreachable from any root; append them flat
  // rather than losing them.
  for (const row of rows) {
    if (!visited.has(row.id)) {
      visited.add(row.id);
      out.push({ row, depth: 0 });
    }
  }
  return out;
}

/** Coerce one `/api/v1/ous` row into the three fields the picker reads. */
function toOuRow(raw: Record<string, unknown>): OuRow | null {
  const id = Number(raw.id);
  if (!Number.isFinite(id)) return null;
  const parent = raw.parent_id;
  return {
    id,
    name: raw.name === undefined || raw.name === null ? String(id) : String(raw.name),
    parent_id: parent === undefined || parent === null ? null : Number(parent),
  };
}

/** The sentinel option value for "no anchor" / "any kind" — Radix refuses ''. */
const OU_ANY = '__any__';

/** U+2007 FIGURE SPACE: a fixed-width blank that survives HTML whitespace collapsing. */
const FIGURE_SPACE = '\u2007';

/**
 * The kind filter, split into its own component so its fetch is CONDITIONAL on
 * being rendered: a block with a pinned `memberType` shows no kind control and
 * must therefore cost no vocabulary request. Calling `usePluginData` in the
 * parent and ignoring the result would fetch either way — a hook cannot be run
 * conditionally, which is exactly why this is a component and not a branch.
 */
function OuKindSelect({
  value,
  onChange,
  ariaLabel,
  anyLabel,
}: {
  value: string | null;
  onChange: (next: string | null) => void;
  ariaLabel: string;
  anyLabel: string;
}) {
  const types = usePluginData<Array<Record<string, unknown>>>(OU_TYPES_SOURCE, (body) =>
    Array.isArray(body) ? (body as Array<Record<string, unknown>>) : null
  );

  const options: OuTypeRow[] =
    types.status === 'ready'
      ? types.data.flatMap((raw) => {
          const key = raw.key;
          if (typeof key !== 'string' || key === '') return [];
          return [
            { key, label: raw.label === undefined || raw.label === null ? key : String(raw.label) },
          ];
        })
      : [];

  return (
    <Select
      value={value ?? OU_ANY}
      onValueChange={(v) => onChange(v === OU_ANY ? null : v)}
      disabled={types.status === 'loading'}
    >
      <SelectTrigger aria-label={ariaLabel} data-slot="ou-scope-picker-kind">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {/* "Any kind" is always offered: a tenant that has adopted no vocabulary
            still has a usable picker, and the rule it writes (`type: null`) is
            the correct one for "every unit, whatever it is". */}
        <SelectItem value={OU_ANY}>{anyLabel}</SelectItem>
        {options.map((option) => (
          <SelectItem key={option.key} value={option.key}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

function OuScopePickerRenderer({ block }: { block: OuScopePickerBlock }) {
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="ouScopePicker" />;
  return <OuScopePickerField block={block} ctx={ctx} />;
}

/**
 * The picker proper: three controls over one value.
 *
 * The invariant that makes the value shape trustworthy is here — every control
 * writes the WHOLE rule (`{unit, scope, type}`), composed from the current one,
 * never a partial patch. There is therefore no code path that can persist a rule
 * without its `scope`, which is the one field a consumer cannot recover by
 * guessing.
 *
 * A `unit` scope and a kind filter are mutually exclusive by construction: the
 * kind control disappears when the scope resolves to the single unit the user
 * just picked, and the rule written in that moment carries `type: null`.
 */
function OuScopePickerField({ block, ctx }: { block: OuScopePickerBlock; ctx: FormBlockContextValue }) {
  const t = useTranslation('plugin');

  // `anchorType` narrows the ANCHOR list at the source rather than client-side:
  // core answers `?type=` itself, so a tenant with ten thousand units does not
  // ship them all to filter nine thousand of them away in the browser.
  const unitsSource =
    block.anchorType !== undefined && block.anchorType !== ''
      ? `${OU_SOURCE}?type=${encodeURIComponent(block.anchorType)}`
      : OU_SOURCE;

  // usePluginData exhausts pagination (#870). A truncated unit list is exactly
  // what made this block unbuildable before — a picker missing unit 26 reads as
  // "the department was never created" (#824).
  const units = usePluginData<Array<Record<string, unknown>>>(unitsSource, (body) =>
    Array.isArray(body) ? (body as Array<Record<string, unknown>>) : null
  );
  // A pinned `memberType` shows no kind control at all — see `OuKindSelect`,
  // which owns that fetch precisely so a pinned block never issues it.
  const kindsPinned = block.memberType !== undefined && block.memberType !== '';

  const scopes = effectiveScopes(block);
  const stored = ctx.values[block.name];
  const rule: OuScopeValue = isOuScopeValue(stored) ? stored : emptyRule(block);

  /**
   * Write a complete rule, normalising the two combinations the contract says
   * cannot exist:
   *   - dropping the anchor while the scope is `unit` moves to the next
   *     permitted scope ("this unit" with no unit is not a rule);
   *   - a `unit` scope carries no kind filter (it could only ever subtract the
   *     unit the user just chose).
   */
  const write = (next: OuScopeValue): void => {
    let scope = next.scope;
    if (next.unit === null && scope === 'unit') {
      scope = scopes.find((s) => s !== 'unit') ?? 'unit';
    }
    const type = scope === 'unit' ? null : (block.memberType ?? next.type);
    ctx.setValue(block.name, { unit: next.unit, scope, type });
  };

  const orderedUnits =
    units.status === 'ready'
      ? orderOuRows(
          units.data.flatMap((raw) => {
            const row = toOuRow(raw);
            return row === null ? [] : [row];
          })
        )
      : [];

  const scopeLabels: Record<OuScopeKind, string> = {
    // The disambiguation lives in the option text, where the choice is made:
    // "this unit" and "this subtree" are the two answers a stored rule must
    // never be ambiguous between, so the control says which is which in words.
    unit: t('blocks.ouScopePicker.scope.unit', 'This unit only'),
    subtree: t('blocks.ouScopePicker.scope.subtree', 'This unit and everything below it'),
    children: t('blocks.ouScopePicker.scope.children', 'Direct children only'),
  };

  if (units.status === 'error') {
    return (
      <div className="space-y-1.5" data-slot="ou-scope-picker">
        <span className="text-sm font-medium">{block.label}</span>
        <div
          className="flex items-center gap-3 rounded-lg border border-border bg-card p-2 text-xs text-muted-foreground"
          data-slot="ou-scope-picker-error"
        >
          <span>{t('blocks.ouScopePicker.loadError', 'Failed to load organizational units.')}</span>
          <Button type="button" variant="outline" size="sm" onClick={units.retry}>
            {t('blocks.retry', 'Retry')}
          </Button>
        </div>
      </div>
    );
  }

  const loading = units.status === 'loading';
  const unitValue = rule.unit === null ? OU_ANY : String(rule.unit);
  // `scope: 'unit'` is only offerable once an anchor exists — see the contract's
  // resolution table, where (null, unit) is the row that is never produced.
  const offerableScopes = rule.unit === null ? scopes.filter((s) => s !== 'unit') : scopes;

  // A GROUP, not a single labelled input: the rule is built from up to three
  // controls, so there is no one element for a `<label for>` to point at.
  // `aria-labelledby` names the whole group, and each control carries its own
  // aria-label underneath it.
  const groupLabelId = `block-input-${block.name}-label`;

  return (
    <div className="space-y-2" data-slot="ou-scope-picker" role="group" aria-labelledby={groupLabelId}>
      <div className="flex items-center justify-between gap-2">
        <span id={groupLabelId} className="text-sm font-medium">
          {block.label}
          {block.required === true && (
            <span className="ms-0.5 text-destructive" aria-hidden>
              *
            </span>
          )}
        </span>
        {ctx.errors[block.name] !== undefined && (
          <p className="text-xs text-destructive" role="alert">
            {ctx.errors[block.name]}
          </p>
        )}
      </div>

      <Select
        value={unitValue}
        onValueChange={(v) => write({ ...rule, unit: v === OU_ANY ? null : Number(v) })}
        disabled={loading}
      >
        <SelectTrigger aria-label={block.label} data-slot="ou-scope-picker-unit">
          {/* `block.placeholder` is the plugin's own copy — only our substitute is keyed. */}
          <SelectValue
            placeholder={
              loading
                ? t('blocks.loading', 'Loading…')
                : (block.placeholder ??
                  t('blocks.select.placeholder', 'Select {label}', { label: block.label }))
            }
          />
        </SelectTrigger>
        <SelectContent>
          {block.required !== true && (
            <SelectItem value={OU_ANY}>
              {t('blocks.ouScopePicker.wholeTenant', 'All organizational units')}
            </SelectItem>
          )}
          {orderedUnits.map(({ row, depth }) => (
            <SelectItem key={row.id} value={String(row.id)}>
              {/* Figure-space indentation, not a nested menu: the list is one
                  flat set of options and the depth is a reading aid. */}
              {FIGURE_SPACE.repeat(depth * 2)}
              {row.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {offerableScopes.length > 1 && (
        <Select value={rule.scope} onValueChange={(v) => write({ ...rule, scope: v as OuScopeKind })}>
          <SelectTrigger
            aria-label={t('blocks.ouScopePicker.scopeLabel', 'Scope')}
            data-slot="ou-scope-picker-scope"
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {offerableScopes.map((scope) => (
              <SelectItem key={scope} value={scope}>
                {scopeLabels[scope]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}

      {!kindsPinned && rule.scope !== 'unit' && (
        <OuKindSelect
          value={rule.type}
          onChange={(next) => write({ ...rule, type: next })}
          ariaLabel={t('blocks.ouScopePicker.kindLabel', 'Kind')}
          anyLabel={t('blocks.ouScopePicker.anyKind', 'Any kind')}
        />
      )}
    </div>
  );
}

const INTERACTIVE_BUTTON_VARIANT: Record<NonNullable<SubmitButtonBlock["variant"]>, React.ComponentProps<typeof Button>["variant"]> = {
  primary: "default",
  secondary: "secondary",
  outline: "outline",
  ghost: "ghost",
  destructive: "destructive",
};

function SubmitButtonRenderer({ block }: { block: SubmitButtonBlock }) {
  // Before the early return: a hook may not run conditionally.
  const t = useTranslation('plugin');
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="submitButton" />;
  const variant = block.variant ? INTERACTIVE_BUTTON_VARIANT[block.variant] : "default";
  // The idle label is the plugin's; only the busy state is ours.
  const label = ctx.isSubmitting ? t('action.submit.pending', 'Working…') : block.label;
  if (isNonEmptyString(block.requiredPermission)) {
    return (
      <PermissionButton permission={block.requiredPermission} variant={variant} disabled={ctx.isSubmitting} onClick={() => ctx.submit()}>
        {label}
      </PermissionButton>
    );
  }
  return (
    <Button type="button" variant={variant} disabled={ctx.isSubmitting} onClick={() => ctx.submit()}>
      {label}
    </Button>
  );
}

function ActionButtonRenderer({ block }: { block: ActionButtonBlock }) {
  const { addToast } = useToast();
  const t = useTranslation('plugin');
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [serverIssues, setServerIssues] = React.useState<ActionIssue[] | null>(null);
  const variant = block.variant ? INTERACTIVE_BUTTON_VARIANT[block.variant] : "default";

  const handleAction = React.useCallback(() => {
    setIsSubmitting(true);
    setServerIssues(null);
    void submitPluginAction(block.action.endpoint, block.action.method, {}).then((result) => {
      setIsSubmitting(false);
      if (result.ok) {
        addToast(t('action.toast.completed', 'Completed successfully'), "success");
      } else if (result.issues && result.issues.length > 0) {
        setServerIssues(result.issues);
        addToast(
          t('action.issues.summary', '{count} issue(s) — see the report below', {
            count: result.issues.length,
          }),
          "error"
        );
      } else {
        // `result.error` is the server's own message — never keyed.
        addToast(result.error ?? t('action.toast.requestFailed', 'Request failed'), "error");
      }
    });
  }, [block.action, addToast, t]);

  // The idle label is the plugin's; only the busy state is ours.
  const triggerLabel = isSubmitting ? t('action.submit.pending', 'Working…') : block.label;

  const renderTrigger = (onClick?: () => void) => {
    if (isNonEmptyString(block.requiredPermission)) {
      return (
        <PermissionButton permission={block.requiredPermission} variant={variant} disabled={isSubmitting} onClick={onClick}>
          {triggerLabel}
        </PermissionButton>
      );
    }
    return (
      <Button type="button" variant={variant} disabled={isSubmitting} onClick={onClick}>
        {triggerLabel}
      </Button>
    );
  };

  return (
    <div className="space-y-3" data-slot="action-button-block">
      {block.confirm ? (
        <AlertDialog>
          <AlertDialogTrigger asChild>{renderTrigger(undefined)}</AlertDialogTrigger>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{block.label}</AlertDialogTitle>
              <AlertDialogDescription>{block.confirm}</AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{t('blocks.dialog.cancel', 'Cancel')}</AlertDialogCancel>
              <AlertDialogAction onClick={() => handleAction()}>
                {t('blocks.dialog.confirm', 'Confirm')}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      ) : (
        renderTrigger(() => handleAction())
      )}
      {serverIssues !== null && serverIssues.length > 0 && <IssuesReport issues={serverIssues} />}
    </div>
  );
}

// ---- WC-532 A3: conditional visibility ----

/**
 * Normalize a form value / rule operand to a comparable string. Booleans
 * become `'true'`/`'false'` so a checkbox (`true`) matches `equals: true` and
 * `equals: 'true'` alike; everything else is `String()`-coerced so a numeric
 * `equals: 5` matches a form field holding the string `'5'`. Missing → `''`.
 */
function normalizeVisibilityOperand(
  value: string | number | boolean | LocalizedTextValue | FieldArrayValue | OuScopeValue | undefined
): string {
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  if (value !== null && typeof value === 'object') {
    // A bilingualText field (WC-532 A4) holds a {ar,en} object and an
    // ouScopePicker (#868) an {unit,scope,type} rule — neither is a meaningful
    // scalar equals/in target; normalize to a sentinel that matches no operand
    // rather than '[object Object]'.
    return ' object';
  }
  return value === undefined ? '' : String(value);
}

/**
 * Evaluate a block's optional `visibleWhen` facet (WC-532 A3, widened by #909).
 *
 * The rule names one of three subjects and this reads the matching one:
 *   - `field`  the enclosing form's live values;
 *   - `from`   the master-detail context — the record the page is about;
 *   - `access` the host's answer for the named `accessGate`.
 *
 * FACTS FAIL OPEN, AUTHORITY FAILS CLOSED, and the asymmetry is deliberate. A
 * `field`/`from` rule that cannot be evaluated — no form around it, an
 * unresolved reference, a malformed rule — leaves the block VISIBLE, so content
 * is never permanently hidden by a missing context and the SDK validator stays
 * the place malformed rules are caught. An `access` rule that cannot be
 * evaluated hides the block, whichever polarity it asked for: a control drawn
 * before its permission is known is a control drawn for somebody who may not
 * have it, and the read-only half declaring `equals: false` must not flash on
 * screen before the answer says it should.
 */
function isBlockVisible(
  block: Block,
  form: FormBlockContextValue | null,
  md: MasterDetail | null,
  access: AccessScope | null
): boolean {
  const rule = (block as { visibleWhen?: VisibleWhen }).visibleWhen;
  if (!rule) return true;

  if (isNonEmptyString(rule.access)) {
    const answer = access?.answer(rule.access) ?? 'unasked';
    if (answer === 'unasked' || answer === 'pending') return false;
    // Anything other than a boolean equality is a rule the validator refuses;
    // hide, because the safe reading of an unintelligible authority rule is "no".
    if (typeof rule.equals !== 'boolean' || rule.in !== undefined) return false;
    return (answer === 'allowed') === rule.equals;
  }

  const current = isNonEmptyString(rule.from)
    ? resolveContextRef(md, rule.from)
    : typeof rule.field === 'string' && rule.field !== '' && form !== null
      ? normalizeVisibilityOperand(form.values[rule.field])
      : undefined;

  if (current === undefined) return true;

  if (rule.equals !== undefined) {
    return current === normalizeVisibilityOperand(rule.equals);
  }
  if (Array.isArray(rule.in)) {
    return rule.in.some((v) => current === normalizeVisibilityOperand(v));
  }
  return true;
}

/**
 * DocumentViewerRenderer — an issued document (#947 item 4).
 *
 * The block declares no path and this renderer takes none from it: the ids come
 * out of the master-detail context and the fetching is the host's, against
 * core's own `/api/v1/documents/*` under the caller's session. See the SDK
 * contract for why that is structural rather than stylistic.
 *
 * `resolveContextRef` returning `undefined` means "nothing has said which
 * document yet", which is a resting state and not a failure — it is passed
 * through as `null` and the viewer renders the author's `emptyText`. The same
 * miss on `artifactIdFrom` means the pin is not resolvable YET; it is also
 * passed as null, so the viewer opens on the current artifact rather than
 * refusing. A pin that resolves to an artifact the record does not have is the
 * different case, and the viewer refuses that one.
 */
function DocumentViewerRenderer({ block }: { block: DocumentViewerBlock }) {
  const md = useMasterDetail();
  const documentId = resolveContextRef(md, block.documentIdFrom) ?? null;
  const pinnedArtifactId =
    isNonEmptyString(block.artifactIdFrom) ? resolveContextRef(md, block.artifactIdFrom) ?? null : null;

  return (
    <DocumentViewer
      documentId={documentId}
      pinnedArtifactId={pinnedArtifactId}
      emptyText={block.emptyText}
    />
  );
}

// ---- dispatch: validate per the contract, then render or degrade ----

/**
 * Render one block. Each branch revalidates the node's required props and enum
 * values; an invalid node falls through to the `UnsupportedBlock` placeholder
 * rather than throwing. The `default` arm catches unknown `type`s.
 *
 * WC-532 A3: before rendering, a `visibleWhen` facet is evaluated against the
 * enclosing form — an unmet predicate renders nothing (the block and its
 * subtree are hidden). This is presentational only; hidden inputs still exist
 * in the form's value map, and the server remains authoritative on validation.
 */
// WC-block-modal-drawer: modal size → the DialogContent max-width utility.
const MODAL_SIZE: Record<'sm' | 'md' | 'lg', string> = {
  sm: 'sm:max-w-sm',
  md: 'sm:max-w-lg',
  lg: 'sm:max-w-2xl',
};

/**
 * An overlay container (→ Dialog). Open state lives in the shared master-detail
 * context (`openTargets[id]`), so a row action's `openTarget()` and the optional
 * self-`trigger` button drive the same Dialog. A form nested inside closes it
 * (and triggers a refetch) on submit-success via `ModalScopeContext` — see
 * `FormRenderer`. A plain dismiss/cancel closes WITHOUT refetching.
 */
function ModalRenderer({ block }: { block: ModalBlock }) {
  const md = useMasterDetail();
  const open = md?.openTargets[block.id] ?? false;
  const triggerVariant = block.variant ? BUTTON_VARIANT[block.variant] : 'default';
  return (
    <Dialog open={open} onOpenChange={(next) => (next ? md?.openTarget(block.id) : md?.closeTarget(block.id))}>
      {isNonEmptyString(block.trigger) && (
        <DialogTrigger asChild>
          <Button type="button" variant={triggerVariant}>{block.trigger}</Button>
        </DialogTrigger>
      )}
      <DialogContent className={MODAL_SIZE[block.size ?? 'md']}>
        <DialogHeader>
          <DialogTitle>{block.title}</DialogTitle>
        </DialogHeader>
        <ModalScopeContext.Provider value={block.id}>
          <div className="space-y-3">
            <BlockList blocks={block.children} />
          </div>
        </ModalScopeContext.Provider>
      </DialogContent>
    </Dialog>
  );
}

/** An overlay container (→ Sheet). Same open/close/refetch model as {@link ModalRenderer}. */
function DrawerRenderer({ block }: { block: DrawerBlock }) {
  const md = useMasterDetail();
  const open = md?.openTargets[block.id] ?? false;
  return (
    <Sheet open={open} onOpenChange={(next) => (next ? md?.openTarget(block.id) : md?.closeTarget(block.id))}>
      {isNonEmptyString(block.trigger) && (
        <SheetTrigger asChild>
          <Button type="button" variant="outline">{block.trigger}</Button>
        </SheetTrigger>
      )}
      <SheetContent side={block.side ?? 'right'}>
        <SheetHeader>
          <SheetTitle>{block.title}</SheetTitle>
        </SheetHeader>
        <ModalScopeContext.Provider value={block.id}>
          <div className="space-y-3 px-4 pb-4">
            <BlockList blocks={block.children} />
          </div>
        </ModalScopeContext.Provider>
      </SheetContent>
    </Sheet>
  );
}

function BlockNode({ block }: { block: Block }): React.ReactElement | null {
  const form = useFormBlockContext();
  // Read at the top, before the switch: a `visibleWhen` is carried by EVERY
  // block type now, so the contexts it may consult have to be in scope for
  // every branch, and a switch body cannot call a hook.
  const md = useMasterDetail();
  const access = useAccess();
  if (!isBlockVisible(block, form, md, access)) {
    return null;
  }
  switch (block.type) {
    case 'section':
      return Array.isArray(block.children) ? (
        <SectionRenderer block={block} />
      ) : (
        <UnsupportedBlock type="section" />
      );
    case 'card':
      return Array.isArray(block.children) ? (
        <CardRenderer block={block} />
      ) : (
        <UnsupportedBlock type="card" />
      );
    case 'grid':
      return Array.isArray(block.children) &&
        isOneOfNumber(block.columns, [1, 2, 3, 4]) ? (
        <GridRenderer block={block} />
      ) : (
        <UnsupportedBlock type="grid" />
      );
    case 'row':
      return Array.isArray(block.children) ? (
        <RowRenderer block={block} />
      ) : (
        <UnsupportedBlock type="row" />
      );
    case 'tabs':
      return Array.isArray(block.children) ? (
        <TabsRenderer block={block} />
      ) : (
        <UnsupportedBlock type="tabs" />
      );
    case 'tab':
      // A bare `tab` outside `tabs` is not a valid root/standalone node.
      return <UnsupportedBlock type="tab" />;
    case 'divider':
      return <hr className="border-border" />;
    case 'heading':
      return isOneOfNumber(block.level, [1, 2, 3, 4]) &&
        isNonEmptyString(block.text) ? (
        <HeadingRenderer block={block} />
      ) : (
        <UnsupportedBlock type="heading" />
      );
    case 'text':
      return isNonEmptyString(block.value) &&
        (block.tone === undefined ||
          isOneOf(block.tone, ['default', 'muted'])) ? (
        <TextRenderer block={block} />
      ) : (
        <UnsupportedBlock type="text" />
      );
    case 'alert':
      return isOneOf(block.variant, ['info', 'success', 'warning', 'danger']) &&
        isNonEmptyString(block.body) ? (
        <AlertRenderer block={block} />
      ) : (
        <UnsupportedBlock type="alert" />
      );
    case 'badge':
      return isOneOf(block.variant, [
        'neutral',
        'info',
        'success',
        'warning',
        'danger',
      ]) && isNonEmptyString(block.label) ? (
        <BadgeRenderer block={block} />
      ) : (
        <UnsupportedBlock type="badge" />
      );
    case 'stat':
      return isNonEmptyString(block.label) &&
        isNonEmptyString(block.value) &&
        (block.trend === undefined ||
          isOneOf(block.trend, ['up', 'down', 'flat'])) ? (
        <StatRenderer block={block} />
      ) : (
        <UnsupportedBlock type="stat" />
      );
    case 'keyValue':
      return isKvList(block.items) ? (
        <KeyValueRenderer block={block} />
      ) : (
        <UnsupportedBlock type="keyValue" />
      );
    case 'list':
      return isStringArray(block.items) ? (
        <ListRenderer block={block} />
      ) : (
        <UnsupportedBlock type="list" />
      );
    case 'table':
      return isColumnList(block.columns) && isRowList(block.rows) ? (
        <TableRenderer block={block} />
      ) : (
        <UnsupportedBlock type="table" />
      );
    case 'button':
      return isNonEmptyString(block.label) &&
        isNonEmptyString(block.href) &&
        (block.variant === undefined ||
          isOneOf(block.variant, [
            'primary',
            'secondary',
            'outline',
            'ghost',
            'destructive',
          ])) ? (
        <ButtonRenderer block={block} />
      ) : (
        <UnsupportedBlock type="button" />
      );
    case 'icon':
      return isNonEmptyString(block.name) &&
        (block.tone === undefined ||
          isOneOf(block.tone, ['default', 'muted'])) ? (
        <IconRenderer block={block} />
      ) : (
        <UnsupportedBlock type="icon" />
      );
    case 'code':
      return isNonEmptyString(block.content) ? (
        <CodeRenderer block={block} />
      ) : (
        <UnsupportedBlock type="code" />
      );
    case 'math':
      return isNonEmptyString(block.expression) ? <MathRenderer block={block} /> : <UnsupportedBlock type="math" />;
    case 'markdown':
      return isNonEmptyString(block.content) ? <MarkdownRenderer block={block} /> : <UnsupportedBlock type="markdown" />;
    case 'dataTable':
      return isNonEmptyString(block.source) && isDataColumnList(block.columns) ? (
        <DataTableRenderer block={block} />
      ) : (
        <UnsupportedBlock type="dataTable" />
      );
    case 'dataStat':
      return isNonEmptyString(block.source) &&
        isNonEmptyString(block.label) &&
        isNonEmptyString(block.valueField) ? (
        <DataStatRenderer block={block} />
      ) : (
        <UnsupportedBlock type="dataStat" />
      );
    case 'dataList':
      return isNonEmptyString(block.source) && isNonEmptyString(block.itemField) ? (
        <DataListRenderer block={block} />
      ) : (
        <UnsupportedBlock type="dataList" />
      );
    case 'chart':
      return isNonEmptyString(block.source) &&
        isOneOf(block.chartType, ['bar', 'line', 'area', 'pie']) &&
        isChartSeriesList(block.series) ? (
        <ChartRenderer block={block} />
      ) : (
        <UnsupportedBlock type="chart" />
      );
    case 'selector':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) && isNonEmptyString(block.source) && isNonEmptyString(block.valueField) && isNonEmptyString(block.labelField) ? <SelectorRenderer block={block} /> : <UnsupportedBlock type="selector" />;
    case 'timeline':
      return isNonEmptyString(block.source) &&
        isNonEmptyString(block.actorField) &&
        isNonEmptyString(block.actionField) &&
        isNonEmptyString(block.timestampField) ? (
        <TimelineRenderer block={block} />
      ) : (
        <UnsupportedBlock type="timeline" />
      );
    case 'inbox':
      return isNonEmptyString(block.source) &&
        isNonEmptyString(block.idField) &&
        isNonEmptyString(block.titleField) &&
        isItemActionList(block.actions) ? (
        <InboxRenderer block={block} />
      ) : (
        <UnsupportedBlock type="inbox" />
      );
    case 'flow':
      return isNonEmptyString(block.source) &&
        isNonEmptyString(block.nodeIdField) &&
        isNonEmptyString(block.nodeLabelField) ? (
        <FlowRenderer block={block} />
      ) : (
        <UnsupportedBlock type="flow" />
      );

    case 'form':
      return Array.isArray(block.children) && isValidSubmitSpec(block.submit) ? <FormRenderer block={block} /> : <UnsupportedBlock type="form" />;
    case 'fieldArray':
      return Array.isArray(block.children) && isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <FieldArrayRenderer block={block} /> : <UnsupportedBlock type="fieldArray" />;
    case 'textInput':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <TextInputRenderer block={block} /> : <UnsupportedBlock type="textInput" />;
    case 'textArea':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <TextAreaRenderer block={block} /> : <UnsupportedBlock type="textArea" />;
    case 'richTextInput':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <RichTextInputRenderer block={block} /> : <UnsupportedBlock type="richTextInput" />;
    case 'numberInput':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <NumberInputRenderer block={block} /> : <UnsupportedBlock type="numberInput" />;
    case 'select':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) && isKvList(block.options) ? <SelectRenderer block={block} /> : <UnsupportedBlock type="select" />;
    case 'checkbox':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <CheckboxRenderer block={block} /> : <UnsupportedBlock type="checkbox" />;
    case 'slider':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) && typeof block.min === 'number' && typeof block.max === 'number' ? <SliderRenderer block={block} /> : <UnsupportedBlock type="slider" />;
    case 'dateInput':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <DateInputRenderer block={block} /> : <UnsupportedBlock type="dateInput" />;
    case 'fileInput':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <FileInputRenderer block={block} /> : <UnsupportedBlock type="fileInput" />;
    case 'colorInput':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <ColorInputRenderer block={block} /> : <UnsupportedBlock type="colorInput" />;
    case 'bilingualText':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <BilingualTextRenderer block={block} /> : <UnsupportedBlock type="bilingualText" />;
    case 'referenceSelect':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) && isNonEmptyString(block.source) && isNonEmptyString(block.valueField) && isNonEmptyString(block.labelField) ? <ReferenceSelectRenderer block={block} /> : <UnsupportedBlock type="referenceSelect" />;
    case 'documentViewer':
      return isNonEmptyString(block.documentIdFrom) ? (
        <DocumentViewerRenderer block={block} />
      ) : (
        <UnsupportedBlock type="documentViewer" />
      );
    case 'ouScopePicker':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <OuScopePickerRenderer block={block} /> : <UnsupportedBlock type="ouScopePicker" />;
    case 'submitButton':
      return isNonEmptyString(block.label) ? <SubmitButtonRenderer block={block} /> : <UnsupportedBlock type="submitButton" />;
    case 'actionButton':
      return isNonEmptyString(block.label) && isValidSubmitSpec(block.action) ? <ActionButtonRenderer block={block} /> : <UnsupportedBlock type="actionButton" />;
    case 'dataRecord':
      return isNonEmptyString(block.id) &&
        isNonEmptyString(block.source) &&
        isRecordFactList(block.fields) &&
        Array.isArray(block.children) ? (
        <DataRecordRenderer block={block} />
      ) : (
        <UnsupportedBlock type="dataRecord" />
      );
    case 'recordFields':
      return isNonEmptyString(block.from) ? (
        <RecordFieldsRenderer block={block} />
      ) : (
        <UnsupportedBlock type="recordFields" />
      );
    case 'accessGate':
      return isNonEmptyString(block.id) &&
        typeof block.check === 'object' &&
        block.check !== null &&
        isNonEmptyString(block.check.endpoint) ? (
        <AccessGateRenderer block={block} />
      ) : (
        <UnsupportedBlock type="accessGate" />
      );
    case 'modal':
      return isNonEmptyString(block.id) && isNonEmptyString(block.title) && Array.isArray(block.children) ? <ModalRenderer block={block} /> : <UnsupportedBlock type="modal" />;
    case 'drawer':
      return isNonEmptyString(block.id) && isNonEmptyString(block.title) && Array.isArray(block.children) ? <DrawerRenderer block={block} /> : <UnsupportedBlock type="drawer" />;
    default: {
      // Unknown type: TypeScript narrows `block` to `never`, but a malformed
      // payload at runtime still reaches here — degrade quietly.
      const unknownType =
        typeof (block as { type?: unknown }).type === 'string'
          ? (block as { type: string }).type
          : 'unknown';
      return <UnsupportedBlock type={unknownType} />;
    }
  }
}

/** Render a list of sibling blocks in document order. */
function BlockList({ blocks }: { blocks: Block[] }) {
  return (
    <>
      {blocks.map((block, index) => (
        <BlockNode key={index} block={block} />
      ))}
    </>
  );
}

/**
 * Render a plugin's `screen: 'blocks'` tree using design-token components.
 *
 * The top level stacks its blocks vertically; containers manage their own
 * inner layout. Every node is revalidated and degrades to an inline
 * placeholder rather than throwing, and no plugin string is ever interpreted
 * as HTML.
 */
export function BlockRenderer({ blocks, record }: { blocks: Block[]; record?: string }) {
  return (
    <MasterDetailProvider record={record}>
      {/* Inside the master-detail provider, because a gate's endpoint may carry
          `{record}` and resolves through the same context every source does. */}
      <AccessProvider blocks={blocks}>
        <div className="space-y-4" data-slot="block-renderer">
          <BlockList blocks={blocks} />
        </div>
      </AccessProvider>
    </MasterDetailProvider>
  );
}
