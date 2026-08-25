'use client';

/**
 * Where the document IS right now (#993, rendering #989's recipient rows).
 *
 * Separate from the trail, exactly as the two tables are separate and for the
 * same reason the routing handler gives: the trail says what happened, this says
 * where the document currently is, and neither is derivable from the other in
 * one query.
 *
 * OPEN IS THE WHOLE POINT. `document_route_recipients.closed_by_event_id` is
 * what distinguishes a row still awaiting somebody from a row somebody has
 * already dealt with — migration 112's partial unique index is defined on
 * exactly that column, so "open" is a first-class concept in the schema rather
 * than a status somebody remembered to update. The server publishes it as a
 * derived `open` boolean ALONGSIDE the pointer; this component renders the
 * boolean and keeps the pointer, because a closed row is not noise: it is a
 * count of work already done, which is the difference between "awaiting three
 * people" and "awaiting three of eleven".
 *
 * Closed rows are counted rather than listed. Listing them would restate the
 * trail one region higher with less information — the trail has the note, the
 * timestamp and the direction — and two lists of the same events on one page is
 * one list too many.
 *
 * WHY THERE ARE NO ACT BUTTONS HERE. Forwarding, acknowledging and returning are
 * the inbox's verbs (#989's follow-up), and the route that performs them —
 * `POST /{id}/routes/{routeId}/actions` — is deliberately session-gated with no
 * permission because being a recipient IS the authorization. Putting a second
 * implementation of those three verbs on this page would give the platform two
 * places to get the fan-out semantics wrong. So the record page states the FACT
 * the server resolved — this document is, or is not, awaiting you — and leaves
 * the doing to the one surface that owns it. The `recipients` region's verdict is
 * that fact: `editable` means an open row names you.
 */

import { Alert, AlertDescription, AlertTitle } from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import { RecordList, RecordListItem } from '@amroksaleh/features/record';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { useTranslation } from '@amroksaleh/features/i18n';

import { personName, unitName } from './trail';
import type { Directory, RouteRecipient } from './types';

export interface OpenRecipientsProps {
  recipients: readonly RouteRecipient[];
  directory: Directory;
  /**
   * The viewer's own profile id, or null when this page could not learn it.
   *
   * Used ONLY to mark which row is theirs. Never to decide whether they may act
   * — that is the server's `recipients` verdict, and a client comparing ids to
   * reach an authorization conclusion is the defect #910 exists to prevent.
   */
  viewerProfileId: number | null;
  /**
   * True when the SERVER said this region is editable, which for this region
   * means an open row names the caller.
   *
   * Rendered as a statement, not as a permission check of our own: the flag
   * arrives from `sectionAccessFrom`, and this component's job is to say what it
   * was told.
   */
  awaitingViewer: boolean;
}

/**
 * The open rows, with the closed ones counted.
 *
 * Renders no empty list: a document with recipient rows always has at least one
 * of the two kinds, and a document with NONE does not reach here — the screen
 * shows the region's own refusal or its "not circulated" state instead.
 */
export function OpenRecipients({
  recipients,
  directory,
  viewerProfileId,
  awaitingViewer,
}: OpenRecipientsProps) {
  const t = useTranslation('documents');
  const dates = useDateDisplay();

  const open = recipients.filter((recipient) => recipient.open);
  const closed = recipients.length - open.length;

  // A row's parent is the recipient it was forwarded FROM — migration 112's
  // fan-out edge. Resolved against the same list rather than by a second
  // request: the parent of a row on this document is always another row on this
  // document (the FK is ON DELETE CASCADE within the route).
  const byId = new Map(recipients.map((recipient) => [recipient.id, recipient]));

  return (
    <div className="space-y-3" data-testid="document-record-recipients">
      {awaitingViewer && (
        <Alert data-testid="document-record-awaiting-you">
          <AlertTitle>{t('record.recipients.youTitle', 'This document is awaiting you')}</AlertTitle>
          <AlertDescription>
            {t(
              'record.recipients.youBody',
              'It is on your list until you act on it. Forwarding, acknowledging and returning are done from your inbox, which is the one place those actions live.'
            )}
          </AlertDescription>
        </Alert>
      )}

      {open.length === 0 ? (
        // Not a blank panel and not "nothing here": every row is closed, which
        // is a real and reportable state — the circulation finished.
        <p className="text-sm text-muted-foreground" data-testid="document-record-recipients-settled">
          {t(
            'record.recipients.settled',
            'Nobody is holding this document. Everyone it reached has acted on it.'
          )}
        </p>
      ) : (
        <RecordList>
          {open.map((recipient) => {
            const parent =
              recipient.parent_recipient_id === null
                ? null
                : (byId.get(recipient.parent_recipient_id) ?? null);
            const isViewer =
              viewerProfileId !== null && recipient.profile_id === viewerProfileId;
            const when = dates.dateTime(recipient.created_at);

            return (
              <RecordListItem
                key={recipient.id}
                primary={
                  <>
                    {personName(t, directory, recipient.profile_id)}
                    {isViewer && (
                      <>
                        {' '}
                        <Badge variant="default" data-testid="document-record-recipient-you">
                          {t('record.recipients.you', 'You')}
                        </Badge>
                      </>
                    )}
                  </>
                }
                // #1068: a DATELESS sentence, not the dated one with a gap in
                // it. "In Registry since —" reads as a record that lost its
                // timestamp; "In Registry" reads as a complete statement, and
                // the fan-out edge below it — which is the part a reader
                // tracing a document actually needs — survives either way.
                secondary={
                  parent === null
                    ? when === null
                      ? t('record.recipients.in', 'In {unit}', {
                          unit: unitName(t, directory, recipient.ou_id),
                        })
                      : t('record.recipients.since', 'In {unit} since {when}', {
                          unit: unitName(t, directory, recipient.ou_id),
                          when,
                        })
                    : // The fan-out edge, said out loud: a reader tracing a
                      // document needs to know it arrived by being forwarded and
                      // by whom, which is the one thing the flat list hides.
                      when === null
                      ? t('record.recipients.inVia', 'In {unit}, forwarded by {who}', {
                          unit: unitName(t, directory, recipient.ou_id),
                          who: personName(t, directory, parent.profile_id),
                        })
                      : t('record.recipients.sinceVia', 'In {unit} since {when}, forwarded by {who}', {
                          unit: unitName(t, directory, recipient.ou_id),
                          when,
                          who: personName(t, directory, parent.profile_id),
                        })
                }
              />
            );
          })}
        </RecordList>
      )}

      {closed > 0 && (
        <p className="text-xs text-muted-foreground" data-testid="document-record-recipients-closed">
          {t(
            'record.recipients.closed',
            '{count} already acted on it. What each of them did is in the trail below.',
            { count: closed }
          )}
        </p>
      )}
    </div>
  );
}
