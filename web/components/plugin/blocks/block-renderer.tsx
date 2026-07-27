'use client';

import * as React from 'react';
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
  DataStatBlock,
  DataTableBlock,
  DateInputBlock,
  FieldArrayBlock,
  FileInputBlock,
  FormBlock,
  GridBlock,
  HeadingBlock,
  IconBlock,
  KeyValueBlock,
  ListBlock,
  LocalizedTextValue,
  MarkdownBlock,
  MathBlock,
  NumberInputBlock,
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
  VisibleWhen,
} from '@/lib/plugin-features';
import { Chart } from '@amroksaleh/ui/chart';
import { DataTable as SharedDataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';
import { Input } from '@amroksaleh/ui/input';
import { BilingualInput } from '@amroksaleh/ui/bilingual-input';
import { MathText } from '@amroksaleh/ui/math-text';
import { renderMarkdown } from '@/lib/safe-markdown';
import { Pagination } from '@amroksaleh/ui/pagination';
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
import { PermissionButton } from '@/components/rbac/permission-button';
import {
  FormProvider,
  FormScopeProvider,
  useFormBlockContext,
  IssuesReport,
  type FormBlockContextValue,
  type FieldArrayValue,
} from '@/components/plugin/blocks/form-context';
import { submitPluginAction } from '@/lib/plugin-action-submit';
import type { ActionIssue } from '@/lib/plugin-action-submit';
import { useToast } from '@/lib/toast-context';
import { usePluginData } from '@/lib/use-plugin-data';
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

function isOneOf<T extends string>(value: unknown, allowed: readonly T[]): value is T {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value);
}

function isOneOfNumber<T extends number>(value: unknown, allowed: readonly T[]): value is T {
  return typeof value === 'number' && (allowed as readonly number[]).includes(value);
}


function isValidSubmitSpec(value: unknown): value is { method: 'POST' | 'PUT'; endpoint: string } {
  if (typeof value !== 'object' || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    (v.method === 'POST' || v.method === 'PUT') &&
    typeof v.endpoint === 'string' &&
    v.endpoint !== ''
  );
}

/** The quiet, non-throwing placeholder for any block we cannot render. */
function UnsupportedBlock({ type }: { type: string }) {
  return (
    <p className="text-xs text-muted-foreground italic" data-slot="block-unsupported">
      Unsupported block: {type}
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
  // Keep only valid tab children; ignore anything else defensively.
  const tabs = block.children.filter(
    (child): child is TabBlock =>
      child.type === 'tab' && isNonEmptyString(child.label)
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

function HeadingRenderer({ block }: { block: HeadingBlock }) {
  const className = cn(
    'font-heading font-semibold',
    block.level === 1 && 'text-xl',
    block.level === 2 && 'text-lg',
    block.level === 3 && 'text-base',
    block.level === 4 && 'text-sm'
  );
  switch (block.level) {
    case 1:
      return <h1 className={className}>{block.text}</h1>;
    case 2:
      return <h2 className={className}>{block.text}</h2>;
    case 3:
      return <h3 className={className}>{block.text}</h3>;
    case 4:
      return <h4 className={className}>{block.text}</h4>;
  }
}

function TextRenderer({ block }: { block: TextBlock }) {
  return (
    <p
      className={cn(
        'text-xs/relaxed',
        block.tone === 'muted' ? 'text-muted-foreground' : 'text-foreground'
      )}
    >
      {block.value}
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
  return (
    <Badge
      variant={block.variant === 'neutral' ? 'secondary' : 'outline'}
      className={BADGE_TONE_CLASS[block.variant]}
    >
      {block.label}
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
  return (
    <div className="rounded-lg bg-card p-4 ring-1 ring-foreground/10">
      <div className="text-xs text-muted-foreground">{block.label}</div>
      <div className="mt-1 flex items-center gap-1.5">
        <span className="font-heading text-xl font-semibold">{block.value}</span>
        {TrendIcon !== null && block.trend && (
          <TrendIcon className={cn('size-4', TREND_TONE[block.trend])} aria-hidden />
        )}
      </div>
      {isNonEmptyString(block.hint) && (
        <div className="mt-1 text-xs text-muted-foreground">{block.hint}</div>
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
}

const MasterDetailContext = React.createContext<MasterDetail | null>(null);

function useMasterDetail(): MasterDetail | null {
  return React.useContext(MasterDetailContext);
}

/**
 * Provides the shared selection state that `selector` blocks write and
 * data-bound blocks' `params` read. Rendered once at the BlockRenderer root, so
 * a selection is visible to every sibling block on the screen.
 */
function MasterDetailProvider({ children }: { children: React.ReactNode }) {
  const [selections, setSelections] = React.useState<Record<string, string>>({});
  const setSelection = React.useCallback(
    (name: string, value: string) => setSelections((prev) => ({ ...prev, [name]: value })),
    []
  );
  const value = React.useMemo<MasterDetail>(() => ({ selections, setSelection }), [selections, setSelection]);
  return <MasterDetailContext.Provider value={value}>{children}</MasterDetailContext.Provider>;
}

/**
 * Compute a data-bound block's EFFECTIVE source: its base `source` plus any
 * `params` whose named selector currently has a value, appended as URL-encoded
 * query params. Returns the base source unchanged when there are no params or
 * no selections yet. usePluginData keys on this string, so a selection change
 * re-fetches the block.
 */
function useEffectiveSource(baseSource: string, params?: SourceParam[]): string {
  const md = useMasterDetail();
  if (!params || params.length === 0 || md === null) return baseSource;
  const qs = params
    .map((p) => {
      const v = md.selections[p.from];
      return v !== undefined && v !== '' ? `${encodeURIComponent(p.param)}=${encodeURIComponent(v)}` : null;
    })
    .filter((x): x is string => x !== null)
    .join('&');
  if (qs === '') return baseSource;
  return baseSource + (baseSource.includes('?') ? '&' : '?') + qs;
}

// WC-532 A7: the master selector — a dropdown fed from an owned collection
// whose selection is published into the shared master-detail context.
function SelectorRenderer({ block }: { block: SelectorBlock }) {
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
          <span>Failed to load options.</span>
          <Button type="button" variant="outline" size="sm" onClick={state.retry}>Retry</Button>
        </div>
      ) : (
        <Select value={current} onValueChange={(v) => md?.setSelection(block.name, v)} disabled={state.status === 'loading'}>
          <SelectTrigger aria-label={block.label} data-slot="selector-trigger">
            <SelectValue placeholder={state.status === 'loading' ? 'Loading…' : (block.placeholder ?? `Select ${block.label}`)} />
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
  const [open, setOpen] = React.useState(false);
  const [busy, setBusy] = React.useState(false);

  const run = React.useCallback(() => {
    setBusy(true);
    void submitPluginAction(applyRowTemplate(action.endpoint, row), action.method, {}).then((result) => {
      setBusy(false);
      setOpen(false);
      if (result.ok) {
        addToast('Completed successfully', 'success');
        onMutated?.();
      } else {
        addToast(result.error ?? 'Request failed', 'error');
      }
    });
  }, [action, row, addToast, onMutated]);

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
            <AlertDialogCancel>Cancel</AlertDialogCancel>
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
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });

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
        <span>Failed to load data.</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={state.retry}
        >
          Retry
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
        <span>{block.emptyText ?? 'No data available.'}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label="Refresh"
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
          aria-label="Refresh"
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
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Metric>(source, (body) => {
    if (typeof body !== 'object' || body === null) return null;
    const obj = body as Record<string, unknown>;
    if (!(block.valueField in obj)) return null;
    return obj;
  });

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
        <span>Failed to load data.</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={state.retry}
        >
          Retry
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
        <span>{block.emptyText ?? 'No data available.'}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label="Refresh"
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
          aria-label="Refresh"
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
                placeholder="Filter items"
                aria-label="Filter items"
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
              Sort
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
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });

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
        <span>Failed to load data.</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={state.retry}
        >
          Retry
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
        <span>{block.emptyText ?? 'No data available.'}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label="Refresh"
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
          aria-label="Refresh"
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
  const source = useEffectiveSource(block.source, block.params);
  const state = usePluginData<Rows>(source, (body) => {
    if (!Array.isArray(body) || body.length === 0) return null;
    return body as Rows;
  });

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
        <span>Failed to load data.</span>
        <Button type="button" variant="outline" size="sm" onClick={state.retry}>
          Retry
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
        <span>{block.emptyText ?? 'No data available.'}</span>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label="Refresh"
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
          aria-label="Refresh"
          onClick={state.refresh}
        >
          <IconRefresh className="size-3.5" aria-hidden />
        </Button>
      </div>
      <Chart type={block.chartType} data={data} series={block.series} xKey={block.xField} />
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
  return (
    <FormProvider block={block}>
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
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="fieldArray" />;

  const raw = ctx.values[block.name];
  const rows: FieldArrayValue = Array.isArray(raw) ? raw : [];
  const min = typeof block.min === 'number' && block.min > 0 ? block.min : 0;
  const max = typeof block.max === 'number' && block.max > 0 ? block.max : Infinity;
  const itemLabel = block.itemLabel ?? 'Item';

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
              <span className="text-xs font-medium text-muted-foreground">{itemLabel} {i + 1}</span>
              <div className="flex gap-1">
                <Button type="button" variant="ghost" size="icon-sm" aria-label={`Move ${itemLabel} ${i + 1} up`} disabled={i === 0} onClick={() => move(i, -1)}>
                  <IconChevronUp className="size-3.5" aria-hidden />
                </Button>
                <Button type="button" variant="ghost" size="icon-sm" aria-label={`Move ${itemLabel} ${i + 1} down`} disabled={i === rows.length - 1} onClick={() => move(i, 1)}>
                  <IconChevronDown className="size-3.5" aria-hidden />
                </Button>
                <Button type="button" variant="ghost" size="icon-sm" aria-label={`Remove ${itemLabel} ${i + 1}`} disabled={rows.length <= min} onClick={() => remove(i)}>
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
        <IconPlus className="me-1 size-4" aria-hidden />Add {itemLabel.toLowerCase()}
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
          <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Preview</p>
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
        <SelectTrigger aria-label={block.label}><SelectValue placeholder={`Select ${block.label}`} /></SelectTrigger>
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
  const value = raw !== null && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
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
          <span>Failed to load options.</span>
          <Button type="button" variant="outline" size="sm" onClick={state.retry}>Retry</Button>
        </div>
      ) : (
        <Select value={strValue} onValueChange={(v) => ctx.setValue(block.name, v)} disabled={state.status === 'loading'}>
          <SelectTrigger aria-label={block.label} data-slot="reference-select-trigger">
            <SelectValue placeholder={state.status === 'loading' ? 'Loading…' : (block.placeholder ?? `Select ${block.label}`)} />
          </SelectTrigger>
          <SelectContent>
            {options.map((opt) => <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>)}
          </SelectContent>
        </Select>
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
  const ctx = useFormBlockContext();
  if (ctx === null) return <UnsupportedBlock type="submitButton" />;
  const variant = block.variant ? INTERACTIVE_BUTTON_VARIANT[block.variant] : "default";
  const label = ctx.isSubmitting ? "Working…" : block.label;
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
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [serverIssues, setServerIssues] = React.useState<ActionIssue[] | null>(null);
  const variant = block.variant ? INTERACTIVE_BUTTON_VARIANT[block.variant] : "default";

  const handleAction = React.useCallback(() => {
    setIsSubmitting(true);
    setServerIssues(null);
    void submitPluginAction(block.action.endpoint, block.action.method, {}).then((result) => {
      setIsSubmitting(false);
      if (result.ok) {
        addToast("Completed successfully", "success");
      } else if (result.issues && result.issues.length > 0) {
        setServerIssues(result.issues);
        addToast(`${result.issues.length} issue(s) — see the report below`, "error");
      } else {
        addToast(result.error ?? "Request failed", "error");
      }
    });
  }, [block.action, addToast]);

  const triggerLabel = isSubmitting ? "Working…" : block.label;

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
              <AlertDialogCancel>Cancel</AlertDialogCancel>
              <AlertDialogAction onClick={() => handleAction()}>Confirm</AlertDialogAction>
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
  value: string | number | boolean | LocalizedTextValue | FieldArrayValue | undefined
): string {
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  if (value !== null && typeof value === 'object') {
    // A bilingualText field (WC-532 A4) holds a {ar,en} object — never a
    // meaningful scalar equals/in target; normalize to a sentinel that matches
    // no operand rather than '[object Object]'.
    return ' object';
  }
  return value === undefined ? '' : String(value);
}

/**
 * Evaluate a block's optional `visibleWhen` facet against the enclosing form's
 * live values. Returns true (visible) when there is no facet, when the block is
 * outside any form (no sibling field to test), or when the rule is malformed —
 * i.e. it FAILS OPEN so content is never permanently hidden by a missing
 * context or a bad rule. The SDK validator already rejects malformed rules at
 * publish time; this is the render-time counterpart.
 */
function isBlockVisible(block: Block, form: FormBlockContextValue | null): boolean {
  const rule = (block as { visibleWhen?: VisibleWhen }).visibleWhen;
  if (!rule || typeof rule.field !== 'string' || rule.field === '') {
    return true;
  }
  if (form === null) {
    return true;
  }
  const current = normalizeVisibilityOperand(form.values[rule.field]);
  if (rule.equals !== undefined) {
    return current === normalizeVisibilityOperand(rule.equals);
  }
  if (Array.isArray(rule.in)) {
    return rule.in.some((v) => current === normalizeVisibilityOperand(v));
  }
  return true;
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
function BlockNode({ block }: { block: Block }): React.ReactElement | null {
  const form = useFormBlockContext();
  if (!isBlockVisible(block, form)) {
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
    case 'submitButton':
      return isNonEmptyString(block.label) ? <SubmitButtonRenderer block={block} /> : <UnsupportedBlock type="submitButton" />;
    case 'actionButton':
      return isNonEmptyString(block.label) && isValidSubmitSpec(block.action) ? <ActionButtonRenderer block={block} /> : <UnsupportedBlock type="actionButton" />;
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
export function BlockRenderer({ blocks }: { blocks: Block[] }) {
  return (
    <MasterDetailProvider>
      <div className="space-y-4" data-slot="block-renderer">
        <BlockList blocks={blocks} />
      </div>
    </MasterDetailProvider>
  );
}
