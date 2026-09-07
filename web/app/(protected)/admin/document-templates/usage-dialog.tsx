'use client';

import { useState } from 'react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Alert, AlertDescription, AlertTitle } from '@amroksaleh/ui/alert';
import { Button } from '@amroksaleh/ui/button';
import { EmptyState } from '@amroksaleh/ui/empty-state';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@amroksaleh/ui/table';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { PlacementText, ScopeBadge } from './audience';
import type { BlockRow, BlockUsage } from './types';

/**
 * How many referencing templates to show before collapsing the rest.
 *
 * Not a tunable — a tunable is something an operator's situation changes the
 * right answer to, and this is a fixed property of the sentence being made:
 * enough rows to see the shape of the answer without the dialog becoming the
 * list it is summarising. The COUNT is always exact and always shown; only the
 * enumeration is trimmed, and a control expands it in place, so nothing is
 * withheld from anybody at any inventory size.
 */
const INITIAL_ROWS = 8;

/**
 * "What uses this block?" — answered before anything destructive is offered.
 *
 * A block is pointer-referenced with Gutenberg synced-pattern semantics: editing
 * it rewrites everything that instances it, and unlike delete that is never
 * refused. So this dialog is the safeguard, and its job is to be believed:
 *
 *  - the TOTAL is the server's unfiltered count, and when some of those users
 *    are outside the caller's visibility that is stated as a number rather than
 *    quietly dropped. A count of only the visible ones would understate the blast
 *    radius for exactly the people whose reach is narrowest.
 *  - a user the caller may not see is counted but never NAMED. The hidden
 *    count is a number about their own tenant; the identities are not theirs.
 *  - an unreadable count is shown as unreadable. A blank is not a zero, and a
 *    zero is what would license the delete.
 *  - there are TWO kinds of user, because a block may contain another block
 *    (#1186). They are listed separately rather than merged: a template is a
 *    document, a nesting block is another block that a delete would leave
 *    pointing at nothing, and the person deciding needs to know which is which.
 */
export function UsageDialog({
  block,
  usage,
  ouName,
  onClose,
}: {
  block: BlockRow;
  usage: BlockUsage | null;
  ouName: (ouId: number | null) => string | null;
  onClose: () => void;
}) {
  const t = useTranslation('admin');
  const [expanded, setExpanded] = useState(false);

  const rows = usage?.templates ?? [];
  const shown = expanded ? rows : rows.slice(0, INITIAL_ROWS);
  const collapsed = rows.length - shown.length;

  // Blocks that NEST this one (#1186). Listed separately rather than merged
  // into the table above, because the two are not the same kind of thing and a
  // person deciding whether to delete needs to know which is which: a template
  // is a document, a block is another block that would be left pointing at
  // nothing.
  const nesting = usage?.blocks ?? [];

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {t('documentTemplates.usage.title', 'What uses “{name}”', { name: block.name })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'documentTemplates.usage.description',
              'A block is referenced by pointer, so editing it changes everything listed below. Nothing here is a copy.'
            )}
          </DialogDescription>
        </DialogHeader>

        {usage === null ? (
          <Alert variant="warning">
            <AlertTitle>{t('documentTemplates.usage.unknownTitle', 'Could not be read')}</AlertTitle>
            <AlertDescription>
              {t(
                'documentTemplates.usage.unknownBody',
                'The usage count is unavailable, so the effect of editing or deleting this block is unknown. A blank is not a zero.'
              )}
            </AlertDescription>
          </Alert>
        ) : usage.total === 0 ? (
          <EmptyState
            title={t('documentTemplates.usage.emptyTitle', 'Nothing uses this block')}
            description={t(
              'documentTemplates.usage.emptyBody',
              'Nothing in this tenant holds a pointer at it — no template and no other block, including ones you cannot see, which is why this is safe to act on.'
            )}
          />
        ) : (
          <>
            <p className="text-sm">
              {t('documentTemplates.usage.count', '{total} things in this tenant use this block.', {
                total: usage.total,
              })}
            </p>

            {usage.hidden > 0 && (
              <Alert variant="warning">
                <AlertTitle>
                  {t('documentTemplates.usage.hiddenTitle', '{count} are not listed', {
                    count: usage.hidden,
                  })}
                </AlertTitle>
                <AlertDescription>
                  {t(
                    'documentTemplates.usage.hiddenBody',
                    'They are filed where you do not reach, or gated on something you do not hold. They are counted because an edit reaches them, and unnamed because their identity is not yours to see.'
                  )}
                </AlertDescription>
              </Alert>
            )}

            {rows.length > 0 && (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('documentTemplates.table.name', 'Name')}</TableHead>
                    <TableHead>{t('documentTemplates.table.visibility', 'Visible to')}</TableHead>
                    <TableHead>{t('documentTemplates.table.placement', 'Filed at')}</TableHead>
                    <TableHead>{t('documentTemplates.table.permission', 'Requires')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {shown.map((template) => (
                    <TableRow key={template.id}>
                      <TableCell className="font-medium">{template.name}</TableCell>
                      <TableCell>
                        <ScopeBadge
                          row={{ ...template, created_by: null }}
                          ouName={ouName(template.owner_ou_id)}
                          viewerProfileId={null}
                        />
                      </TableCell>
                      <TableCell>
                        <PlacementText row={template} ouName={ouName(template.owner_ou_id)} />
                      </TableCell>
                      <TableCell>
                        {template.required_permission ?? (
                          <span className="text-muted-foreground">
                            {t('documentTemplates.table.noPermission', 'None')}
                          </span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            )}

            {collapsed > 0 && (
              <Button variant="ghost" size="sm" onClick={() => setExpanded(true)}>
                {t('documentTemplates.usage.showAll', 'Show {count} more', { count: collapsed })}
              </Button>
            )}

            {nesting.length > 0 && (
              <div data-testid="usage-nesting-blocks">
                <p className="text-sm font-medium">
                  {t(
                    'documentTemplates.usage.nestedTitle',
                    'Blocks that contain this one'
                  )}
                </p>
                <p className="text-sm text-muted-foreground">
                  {t(
                    'documentTemplates.usage.nestedBody',
                    'Deleting this block is refused while any of these hold it. Editing it changes them too.'
                  )}
                </p>
                <ul className="mt-2 space-y-1 text-sm">
                  {nesting.map((b) => (
                    <li key={b.id} className="flex items-center gap-2">
                      <span className="font-medium">{b.name}</span>
                      <ScopeBadge
                        row={{ ...b, created_by: null }}
                        ouName={ouName(b.owner_ou_id)}
                        viewerProfileId={null}
                      />
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('documentTemplates.close', 'Close')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
