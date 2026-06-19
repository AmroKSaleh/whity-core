'use client';

import * as React from 'react';
import Link from 'next/link';
import * as TablerIcons from '@tabler/icons-react';
import type { Icon } from '@tabler/icons-react';
import {
  IconArrowDownRight,
  IconArrowUpRight,
  IconMinus,
  IconPointFilled,
  IconRefresh,
} from '@tabler/icons-react';
import type {
  AlertBlock,
  BadgeBlock,
  Block,
  ButtonBlock,
  CardBlock,
  CodeBlock,
  DataListBlock,
  DataStatBlock,
  DataTableBlock,
  GridBlock,
  HeadingBlock,
  IconBlock,
  KeyValueBlock,
  ListBlock,
  RowBlock,
  SectionBlock,
  StatBlock,
  TabBlock,
  TableBlock,
  TabsBlock,
  TextBlock,
} from '@/lib/plugin-features';
import { usePluginData } from '@/lib/use-plugin-data';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs';

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

function isOneOf<T extends string>(value: unknown, allowed: readonly T[]): value is T {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value);
}

function isOneOfNumber<T extends number>(value: unknown, allowed: readonly T[]): value is T {
  return typeof value === 'number' && (allowed as readonly number[]).includes(value);
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

const ALERT_TONE_CLASS: Record<AlertBlock['variant'], string> = {
  info: 'border-info/40 text-info',
  success: 'border-success/40 text-success',
  warning: 'border-warning/40 text-warning',
  danger: 'border-destructive/40 text-destructive',
};

function AlertRenderer({ block }: { block: AlertBlock }) {
  return (
    <Alert
      variant={block.variant === 'danger' ? 'destructive' : 'default'}
      className={ALERT_TONE_CLASS[block.variant]}
    >
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
  // Navigate only for internal paths; any other href is inert (no navigation).
  const isInternal = block.href.startsWith('/');
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
      <TableRenderer block={{ type: 'table', columns: block.columns, rows }} />
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
      <ListRenderer block={{ type: 'list', ordered: block.ordered, items }} />
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
      return isNonEmptyString(block.source) && isColumnList(block.columns) ? (
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
