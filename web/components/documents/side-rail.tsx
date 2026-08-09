'use client';

import type { ComponentProps } from 'react';
import {
  IconStack2,
  IconAdjustments,
  IconFile,
  IconDatabase,
  IconListNumbers,
  IconGridDots,
  IconLayoutSidebarRightCollapse,
} from '@tabler/icons-react';

import { Palette } from './palette';
import { Inspector, type InspectorTab } from './inspector';

/**
 * The editor's ONE side rail — deliberately one, on the inline-END side (right
 * in LTR, left in RTL, handled by grid flow + logical properties, not by a
 * left/right switch).
 *
 * It used to be two rails: layers on the left, properties on the right, with the
 * page squeezed between them. That was the wrong trade for a canvas app — most
 * of those panels are consulted occasionally while the page is looked at
 * constantly. So Layers folds in here as one more tab, and the whole rail
 * collapses away, leaving the document the full width.
 *
 * The tab strip lives here rather than inside `Inspector` precisely so Layers
 * can sit alongside the property tabs in a single strip. Tabs are icon-only
 * (named via `aria-label`/`title`, with the active one's name spelled out
 * underneath) so six of them fit a narrow rail without wrapping.
 */

export type RailTab = 'layers' | InspectorTab;

const TABS: ReadonlyArray<{ id: RailTab; label: string; icon: React.ReactNode }> = [
  { id: 'layers', label: 'Layers', icon: <IconStack2 /> },
  { id: 'element', label: 'Element', icon: <IconAdjustments /> },
  { id: 'page', label: 'Page', icon: <IconFile /> },
  { id: 'data', label: 'Data', icon: <IconDatabase /> },
  { id: 'batch', label: 'Batch', icon: <IconListNumbers /> },
  { id: 'sheet', label: 'Sheet', icon: <IconGridDots /> },
];

export function SideRail({
  tab,
  onTabChange,
  onCollapse,
  palette,
  inspector,
}: {
  tab: RailTab;
  onTabChange: (tab: RailTab) => void;
  onCollapse: () => void;
  /** Props for the Layers tab. */
  palette: ComponentProps<typeof Palette>;
  /** Props for every other tab; its own `tab` is supplied from `tab` here. */
  inspector: Omit<ComponentProps<typeof Inspector>, 'tab'>;
}) {
  const active = TABS.find((t) => t.id === tab) ?? TABS[0];

  return (
    <aside
      data-testid="doc-side-rail"
      className="flex min-h-0 w-72 flex-col border-s border-border bg-card"
    >
      <div className="flex items-center gap-0.5 border-b border-border px-1.5 py-1">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            data-testid={`doc-tab-${t.id}`}
            aria-label={t.label}
            aria-current={tab === t.id}
            title={t.label}
            onClick={() => onTabChange(t.id)}
            className={`flex size-7 items-center justify-center rounded-md outline-hidden focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4 ${
              tab === t.id
                ? 'bg-accent text-accent-foreground'
                : 'text-muted-foreground hover:bg-accent/60 hover:text-accent-foreground'
            }`}
          >
            {t.icon}
          </button>
        ))}
        <button
          type="button"
          data-testid="doc-rail-collapse"
          aria-label="Hide side panel"
          title="Hide side panel"
          onClick={onCollapse}
          className="ms-auto flex size-7 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4"
        >
          <IconLayoutSidebarRightCollapse className="rtl:rotate-180" />
        </button>
      </div>

      <h2 className="px-3 pt-2 text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground">
        {active.label}
      </h2>

      <div className="min-h-0 flex-1 overflow-hidden p-3">
        {tab === 'layers' ? <Palette {...palette} /> : <Inspector {...inspector} tab={tab} />}
      </div>
    </aside>
  );
}
