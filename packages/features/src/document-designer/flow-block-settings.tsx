import { useEffect, useState } from 'react';
import {
  MAX_BLOCK_SPACE_MM,
  MIN_BLOCK_WIDTH_PERCENT,
  blockAcceptsLayout,
  type FlowBlock,
} from '@amroksaleh/ui/documents/flow';
import { Input } from './ui-i18n';
import { useTranslation } from '../i18n';

/**
 * BLOCK SETTINGS: the parameters of the selected block, beside the document.
 *
 * Document mode edits a block AS ITSELF — a heading is a line at heading size,
 * a paragraph is a text area — which is most of why it feels like writing. That
 * leaves nowhere to put the things that are not the content: the space around a
 * block, whether a page break may fall inside it, how wide it is. This is that
 * place, and it lives in the rail's existing properties slot rather than a new
 * tab, because "the properties of the thing I have selected" is the question
 * that slot already answers in canvas mode.
 *
 * EVERY CONTROL HERE IS READ BY THE RENDERER. `render-service/src/flow` honours
 * all six keys — the stylesheet applies the spacing and the width, the
 * paginator reads the break hints — and the bounds below are the ones it
 * validates. A control for something the printer ignores is the defect this
 * whole line of work keeps turning up (#1190, #1191, #1192); the way not to add
 * another is to build the renderer support first and the control second.
 *
 * DEFAULTS ARE STORED AS ABSENT. Clearing a space clears the key rather than
 * writing 0, and full width clears rather than writing 100. The renderer treats
 * absent and default identically, so only one of them puts a key in every
 * document that a later reader has to learn is redundant.
 */

/**
 * A number field that lets you FINISH TYPING.
 *
 * Bound straight to the stored value, these controls could not be used. Width
 * has a floor of 20, so typing "50" offered `5` first — below the floor, stored
 * as "no setting", pushed back into the field as empty — and the `0` then
 * arrived on its own. The same shape of bug snapped a cleared spacer height to
 * its minimum, so "12" became "112". Both are invisible to a test that renders
 * once with a fixed value and only appear when the component is driven the way
 * a person drives it.
 *
 * So the field keeps the TEXT while it is being edited and reports the parsed
 * value alongside it. The draft follows the stored value when that changes from
 * outside — selecting a different block — but never fights what is being typed.
 */
function NumberField({
  value,
  onCommit,
  ...rest
}: {
  value: number | undefined;
  onCommit: (parsed: number | undefined) => void;
} & Omit<React.ComponentProps<typeof Input>, 'value' | 'onChange'>) {
  const [draft, setDraft] = useState(value === undefined ? '' : String(value));

  useEffect(() => {
    // Only when the OUTSIDE moved. Comparing the parsed draft rather than the
    // text keeps "50." and "50" from resetting each other mid-edit.
    const parsed = draft === '' ? undefined : Number(draft);
    if (parsed !== value) setDraft(value === undefined ? '' : String(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps -- driven by `value` alone on purpose.
  }, [value]);

  return (
    <Input
      {...rest}
      value={draft}
      onChange={(e) => {
        setDraft(e.target.value);
        const n = Number(e.target.value);
        onCommit(e.target.value === '' || !Number.isFinite(n) ? undefined : n);
      }}
    />
  );
}

export interface FlowBlockSettingsProps {
  /** The selected block, or null when nothing is selected. */
  block: FlowBlock | null;
  /**
   * The block after it, for the one interaction worth showing: a block cannot
   * be kept with a successor that has been told to start its own page.
   */
  nextBlock?: FlowBlock | null;
  onChange: (block: FlowBlock) => void;
}

export function FlowBlockSettings({ block, nextBlock, onChange }: FlowBlockSettingsProps) {
  const t = useTranslation('documents');

  if (block === null) {
    return (
      <p className="p-3 text-xs text-muted-foreground" data-testid="flow-settings-empty">
        {t('flow.settings.none', 'Select a block to change its spacing, width and page behaviour.')}
      </p>
    );
  }

  // A page break is a boundary rather than a thing on the page, and the
  // renderer REFUSES layout keys on it. Saying so is better than showing six
  // controls that would each be rejected.
  if (!blockAcceptsLayout(block)) {
    return (
      <div className="flex flex-col gap-2 p-3">
        {block.type === 'spacer' ? (
          <label className="flex flex-col gap-1 text-xs">
            {t('flow.settings.spacerHeight', 'Height (mm)')}
            <NumberField
              type="number"
              min={1}
              max={MAX_BLOCK_SPACE_MM}
              value={block.heightMm}
              data-testid="flow-settings-spacer-height"
              // An empty or nonsense field is left alone rather than snapped to
              // the minimum: snapping is what made a cleared height read "1"
              // and turned the next two keystrokes into 112.
              onCommit={(mm) => {
                if (mm === undefined || mm <= 0) return;
                onChange({ ...block, heightMm: Math.min(mm, MAX_BLOCK_SPACE_MM) });
              }}
            />
          </label>
        ) : null}
        <p className="text-xs text-muted-foreground" data-testid="flow-settings-boxless">
          {t(
            'flow.settings.boxless',
            'A {type} has no box of its own, so it takes no spacing, width or page settings.',
            { type: block.type }
          )}
        </p>
      </div>
    );
  }

  /** Store a default as ABSENT rather than as a value that means the same. */
  /** Store a default as ABSENT, and never above what the renderer accepts. */
  const setSpace = (key: 'spaceBeforeMm' | 'spaceAfterMm', mm: number | undefined) => {
    const usable = mm !== undefined && mm > 0;
    onChange({ ...block, [key]: usable ? Math.min(mm, MAX_BLOCK_SPACE_MM) : undefined });
  };

  const setFlag = (key: 'breakBefore' | 'keepWithNext' | 'keepTogether', on: boolean) => {
    onChange({ ...block, [key]: on ? true : undefined });
  };

  // The one interaction worth surfacing. `keepWithNext` asks the paginator to
  // move this block rather than let a break fall after it — which a successor
  // that has been told to start its own page makes impossible. Left live it
  // would be a setting that silently does nothing.
  const nextStartsAPage =
    nextBlock !== null && nextBlock !== undefined &&
    (nextBlock.type === 'pageBreak' || (blockAcceptsLayout(nextBlock) && nextBlock.breakBefore === true));

  return (
    <div className="flex flex-col gap-3 p-3" data-testid="flow-settings">
      <section className="flex flex-col gap-2">
        <h3 className="text-xs font-medium">{t('flow.settings.spacing', 'Spacing')}</h3>
        <div className="flex gap-2">
          <label className="flex flex-1 flex-col gap-1 text-xs text-muted-foreground">
            {t('flow.settings.spaceBefore', 'Above (mm)')}
            <NumberField
              type="number"
              min={0}
              max={MAX_BLOCK_SPACE_MM}
              value={block.spaceBeforeMm}
              placeholder={t('flow.settings.spaceDefault', 'Default')}
              data-testid="flow-settings-space-before"
              onCommit={(mm) => setSpace('spaceBeforeMm', mm)}
            />
          </label>
          <label className="flex flex-1 flex-col gap-1 text-xs text-muted-foreground">
            {t('flow.settings.spaceAfter', 'Below (mm)')}
            <NumberField
              type="number"
              min={0}
              max={MAX_BLOCK_SPACE_MM}
              value={block.spaceAfterMm}
              placeholder={t('flow.settings.spaceDefault', 'Default')}
              data-testid="flow-settings-space-after"
              onCommit={(mm) => setSpace('spaceAfterMm', mm)}
            />
          </label>
        </div>
      </section>

      <section className="flex flex-col gap-2">
        <h3 className="text-xs font-medium">{t('flow.settings.pages', 'Page behaviour')}</h3>
        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          <input
            type="checkbox"
            checked={block.breakBefore === true}
            data-testid="flow-settings-break-before"
            onChange={(e) => setFlag('breakBefore', e.target.checked)}
          />
          {t('flow.settings.breakBefore', 'Start on a new page')}
        </label>
        <label
          className="flex items-center gap-2 text-xs text-muted-foreground"
          title={
            nextStartsAPage
              ? t(
                  'flow.settings.keepWithNextBlocked',
                  'The next block starts its own page, so nothing can be kept with it.'
                )
              : undefined
          }
        >
          <input
            type="checkbox"
            disabled={nextStartsAPage}
            checked={block.keepWithNext === true && !nextStartsAPage}
            data-testid="flow-settings-keep-with-next"
            onChange={(e) => setFlag('keepWithNext', e.target.checked)}
          />
          {t('flow.settings.keepWithNext', 'Keep with the next block')}
        </label>
        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          <input
            type="checkbox"
            checked={block.keepTogether === true}
            data-testid="flow-settings-keep-together"
            onChange={(e) => setFlag('keepTogether', e.target.checked)}
          />
          {t('flow.settings.keepTogether', 'Do not split across pages')}
        </label>
      </section>

      <section className="flex flex-col gap-2">
        <h3 className="text-xs font-medium">{t('flow.settings.width', 'Width')}</h3>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          {t('flow.settings.widthPercent', 'Percentage of the text column')}
          <NumberField
            type="number"
            min={MIN_BLOCK_WIDTH_PERCENT}
            max={100}
            value={block.widthPercent}
            placeholder={t('flow.settings.widthFull', 'Full width')}
            data-testid="flow-settings-width"
            // Anything outside the readable range — including the `5` that
            // exists for a moment while somebody types `50` — is stored as no
            // setting. The field keeps the text either way, so the rest of the
            // number can still be typed.
            onCommit={(pct) => {
              const usable =
                pct !== undefined && pct >= MIN_BLOCK_WIDTH_PERCENT && pct < 100;
              onChange({ ...block, widthPercent: usable ? pct : undefined });
            }}
          />
        </label>
        {/* Said once, here, because it is the reason this is a percentage and
            not a measurement — and the reason nothing needs re-authoring when
            the same document is printed on a different paper size. */}
        <p className="text-[0.625rem] text-muted-foreground">
          {t(
            'flow.settings.widthHint',
            'A share of the text column, so it stays right on smaller paper without being set again.'
          )}
        </p>
      </section>
    </div>
  );
}
