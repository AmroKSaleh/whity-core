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
  IconPointFilled,
  IconRefresh,
  IconSearch,
} from '@tabler/icons-react';
import type {
  ActionButtonBlock,
  AlertBlock,
  BadgeBlock,
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
  FileInputBlock,
  FormBlock,
  GridBlock,
  HeadingBlock,
  IconBlock,
  KeyValueBlock,
  ListBlock,
  NumberInputBlock,
  RowBlock,
  SectionBlock,
  SelectBlock,
  SliderBlock,
  StatBlock,
  SubmitButtonBlock,
  TabBlock,
  TableBlock,
  TabsBlock,
  TextAreaBlock,
  TextBlock,
  TextInputBlock,
} from '@/lib/plugin-features';
import { Chart } from '@amroksaleh/ui/chart';
import { Input } from '@amroksaleh/ui/input';
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
  useFormBlockContext,
  IssuesReport,
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

// ---- SP2 data-bound renderers (WC-231) ----

/**
 * InteractiveDataTable (WC-241) — renders the rows already fetched by
 * `DataTableRenderer` with inline client-side sort/filter/pagination. All
 * three operate ENTIRELY on the in-memory `rows` array: there is no second
 * fetch, no route other than the block's original (already ownership
 * verified) `source` is ever touched. Sortable/filterable are per-column
 * booleans; a column with neither behaves exactly like a static `table`.
 */
function InteractiveDataTable({
  columns,
  rows,
  pageSize,
}: {
  columns: { key: string; label: string; sortable?: boolean; filterable?: boolean }[];
  rows: Record<string, string>[];
  pageSize?: number;
}) {
  const [filters, setFilters] = React.useState<Record<string, string>>({});
  const [sortKey, setSortKey] = React.useState<string | null>(null);
  const [sortDir, setSortDir] = React.useState<'asc' | 'desc'>('asc');
  const [page, setPage] = React.useState(1);

  const filterableColumns = columns.filter((c) => c.filterable === true);

  const filtered = React.useMemo(() => {
    const active = Object.entries(filters).filter(([, v]) => v.trim() !== '');
    if (active.length === 0) return rows;
    return rows.filter((row) =>
      active.every(([key, needle]) =>
        (row[key] ?? '').toLowerCase().includes(needle.trim().toLowerCase())
      )
    );
  }, [rows, filters]);

  const sorted = React.useMemo(() => {
    if (sortKey === null) return filtered;
    const copy = [...filtered];
    copy.sort((a, b) => {
      const cmp = (a[sortKey] ?? '').localeCompare(b[sortKey] ?? '');
      return sortDir === 'asc' ? cmp : -cmp;
    });
    return copy;
  }, [filtered, sortKey, sortDir]);

  const paginate = pageSize !== undefined && pageSize > 0;
  const effectivePageSize = paginate ? pageSize : Math.max(sorted.length, 1);
  const totalPages = Math.max(1, Math.ceil(sorted.length / effectivePageSize));
  const clampedPage = Math.min(page, totalPages);
  const paged = paginate
    ? sorted.slice((clampedPage - 1) * effectivePageSize, clampedPage * effectivePageSize)
    : sorted;

  const handleSort = (col: { key: string; sortable?: boolean }) => {
    if (col.sortable !== true) return;
    if (sortKey === col.key) {
      if (sortDir === 'asc') {
        setSortDir('desc');
      } else {
        setSortKey(null);
        setSortDir('asc');
      }
    } else {
      setSortKey(col.key);
      setSortDir('asc');
    }
    setPage(1);
  };

  return (
    <div className="space-y-2">
      {filterableColumns.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {filterableColumns.map((col) => (
            <div key={col.key} className="relative">
              <IconSearch
                className="pointer-events-none absolute start-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                aria-hidden
              />
              <Input
                value={filters[col.key] ?? ''}
                onChange={(e) => {
                  setFilters((f) => ({ ...f, [col.key]: e.target.value }));
                  setPage(1);
                }}
                placeholder={`Filter ${col.label}`}
                aria-label={`Filter ${col.label}`}
                className="h-8 ps-7 text-xs"
              />
            </div>
          ))}
        </div>
      )}
      <div className="overflow-x-auto rounded-lg ring-1 ring-foreground/10">
        <table className="w-full border-collapse text-xs/relaxed">
          <thead>
            <tr className="border-b border-border bg-muted/40">
              {columns.map((col) => (
                <th
                  key={col.key}
                  onClick={() => handleSort(col)}
                  className={cn(
                    'px-3 py-2 text-start font-medium text-muted-foreground',
                    col.sortable === true && 'cursor-pointer select-none hover:bg-muted/60'
                  )}
                >
                  <span className="inline-flex items-center gap-1">
                    {col.label}
                    {col.sortable === true && sortKey === col.key && (
                      sortDir === 'asc' ? (
                        <IconChevronUp className="size-3.5" aria-hidden />
                      ) : (
                        <IconChevronDown className="size-3.5" aria-hidden />
                      )
                    )}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {paged.map((row, rowIndex) => (
              <tr key={rowIndex} className="border-b border-border last:border-0">
                {columns.map((col) => (
                  <td key={col.key} className="px-3 py-2 text-foreground">
                    {row[col.key] ?? ''}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
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
 * DataTableRenderer — fetches rows from `block.source` and reuses
 * `TableRenderer` for the ready state.
 */
function DataTableRenderer({ block }: { block: DataTableBlock }) {
  type Rows = Record<string, unknown>[];
  const state = usePluginData<Rows>(block.source, (body) => {
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
      <InteractiveDataTable columns={block.columns} rows={rows} pageSize={block.pageSize} />
    </div>
  );
}

/**
 * DataStatRenderer — fetches a metric object from `block.source` and reuses
 * `StatRenderer` for the ready state.
 */
function DataStatRenderer({ block }: { block: DataStatBlock }) {
  type Metric = Record<string, unknown>;
  const state = usePluginData<Metric>(block.source, (body) => {
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
  const state = usePluginData<Rows>(block.source, (body) => {
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
  const state = usePluginData<Rows>(block.source, (body) => {
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

// ---- dispatch: validate per the contract, then render or degrade ----

/**
 * Render one block. Each branch revalidates the node's required props and enum
 * values; an invalid node falls through to the `UnsupportedBlock` placeholder
 * rather than throwing. The `default` arm catches unknown `type`s.
 */
function BlockNode({ block }: { block: Block }): React.ReactElement {
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

    case 'form':
      return Array.isArray(block.children) && isValidSubmitSpec(block.submit) ? <FormRenderer block={block} /> : <UnsupportedBlock type="form" />;
    case 'textInput':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <TextInputRenderer block={block} /> : <UnsupportedBlock type="textInput" />;
    case 'textArea':
      return isNonEmptyString(block.name) && isNonEmptyString(block.label) ? <TextAreaRenderer block={block} /> : <UnsupportedBlock type="textArea" />;
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
    <div className="space-y-4" data-slot="block-renderer">
      <BlockList blocks={blocks} />
    </div>
  );
}
