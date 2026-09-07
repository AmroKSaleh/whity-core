import type { ComponentProps, ReactNode } from 'react';
import {
  IconStack2,
  IconAdjustments,
  IconFile,
  IconDatabase,
  IconListNumbers,
  IconGridDots,
  IconLayoutSidebarRightCollapse,
} from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '../i18n';

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

const TABS: ReadonlyArray<{ id: RailTab; icon: React.ReactNode }> = [
  { id: 'layers', icon: <IconStack2 /> },
  { id: 'element', icon: <IconAdjustments /> },
  { id: 'page', icon: <IconFile /> },
  { id: 'data', icon: <IconDatabase /> },
  { id: 'batch', icon: <IconListNumbers /> },
  { id: 'sheet', icon: <IconGridDots /> },
];

/**
 * The tabs' names. The strip is icon-only, so this is the whole accessible name
 * of each tab as well as the heading under the strip — which is why it is
 * resolved through `t()` rather than sitting as English in the table above.
 */
function tabLabels(t: TranslateFn): Record<RailTab, string> {
  return {
    layers: t('sideRail.tab.layers', 'Layers'),
    element: t('sideRail.tab.element', 'Element'),
    page: t('sideRail.tab.page', 'Page'),
    data: t('sideRail.tab.data', 'Data'),
    batch: t('sideRail.tab.batch', 'Batch'),
    sheet: t('sideRail.tab.sheet', 'Sheet'),
  };
}

export function SideRail({
  tab,
  onTabChange,
  onCollapse,
  palette,
  inspector,
  blockSettings,
}: {
  tab: RailTab;
  onTabChange: (tab: RailTab) => void;
  onCollapse: () => void;
  /** Props for the Layers tab. */
  palette: ComponentProps<typeof Palette>;
  /** Props for every other tab; its own `tab` is supplied from `tab` here. */
  inspector: Omit<ComponentProps<typeof Inspector>, 'tab'>;
  /**
   * Shown in the properties slot INSTEAD of the inspector when supplied — the
   * document-mode block settings (#1186).
   *
   * Optional, so the canvas passes nothing and behaves exactly as before. The
   * alternative was a seventh tab, which would then sit in the strip doing
   * nothing whenever the canvas was open.
   */
  blockSettings?: ReactNode;
}) {
  const t = useTranslation('documents');
  const labels = tabLabels(t);
  const active = TABS.find((entry) => entry.id === tab) ?? TABS[0];

  return (
    <aside
      data-testid="doc-side-rail"
      className="flex min-h-0 w-72 flex-col border-s border-border bg-card"
    >
      <div className="flex items-center gap-0.5 border-b border-border px-1.5 py-1">
        {TABS.map((entry) => (
          <button
            key={entry.id}
            type="button"
            data-testid={`doc-tab-${entry.id}`}
            aria-label={labels[entry.id]}
            aria-current={tab === entry.id}
            title={labels[entry.id]}
            onClick={() => onTabChange(entry.id)}
            className={`flex size-7 items-center justify-center rounded-md outline-hidden focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4 ${
              tab === entry.id
                ? 'bg-accent text-accent-foreground'
                : 'text-muted-foreground hover:bg-accent/60 hover:text-accent-foreground'
            }`}
          >
            {entry.icon}
          </button>
        ))}
        <button
          type="button"
          data-testid="doc-rail-collapse"
          aria-label={t('sideRail.collapse', 'Hide side panel')}
          title={t('sideRail.collapse', 'Hide side panel')}
          onClick={onCollapse}
          className="ms-auto flex size-7 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4"
        >
          <IconLayoutSidebarRightCollapse className="rtl:rotate-180" />
        </button>
      </div>

      <h2 className="px-3 pt-2 text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground">
        {labels[active.id]}
      </h2>

      <div className="min-h-0 flex-1 overflow-hidden p-3">
        {tab === 'layers' ? (
          <Palette {...palette} />
        ) : tab === 'element' && blockSettings !== undefined ? (
          // Document mode puts BLOCK settings in the properties slot. Same
          // question the slot answers on the canvas — "the properties of what I
          // have selected" — asked of a different kind of thing, which is why
          // it is this slot rather than a seventh tab that would sit there
          // doing nothing in canvas mode.
          blockSettings
        ) : (
          <Inspector {...inspector} tab={tab} />
        )}
      </div>
    </aside>
  );
}
