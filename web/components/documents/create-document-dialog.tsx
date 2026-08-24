'use client';

/**
 * Raising a document — pick a template, fill in its placeholders, create it
 * (#947 item 1, the half that was missing).
 *
 * WHY THIS IS A DIALOG ON THE ORGANIZER AND NOT A PAGE OF ITS OWN
 * ---------------------------------------------------------------
 * The organizer IS the Documents surface: it is where a person goes to find a
 * document, so it is where they expect to make one, exactly as a file manager
 * puts New beside the listing rather than on a separate screen. A page would
 * also need a nav entry, and a fourth entry in the Documents group called
 * something like "New document" would sit permanently beside "Documents" and
 * "Document Designer" advertising a one-off action as a place.
 *
 * It is a component in `web/components/documents/` rather than inline in the
 * page for the reason `route-composer.tsx` is: this surface ships to three
 * clients, and the record page is the obvious second host ("issue another like
 * this"). Data comes in as plain props; the POST is done here, mirroring
 * `RouteComposer` exactly, so a second host supplies templates and nothing else.
 *
 * THE PICKER SHOWS WHAT THE CALLER MAY SEE, AND THE SERVER DECIDES THAT
 * --------------------------------------------------------------------
 * `templates` is whatever `GET /api/v1/document-templates` returned, and that
 * list is ALREADY row-filtered by `DocumentAccessPolicy` — a template gated
 * behind a permission tag, or filed at a unit the caller has no standing in, is
 * absent from the payload rather than hidden by this component. So there is no
 * client-side scoping here on purpose: re-filtering would be a second
 * implementation of a rule the server owns, and it would drift. The create route
 * re-checks the same policy anyway, so a stale list produces a 404 rather than a
 * document nobody was entitled to raise.
 *
 * WHAT IT DOES *NOT* DO
 * ---------------------
 * It does not edit templates (that is the designer), it does not compose a
 * route, and it does not render a preview. On success it offers the ONE next
 * step that exists — send it — as a hand-off to `/admin/document-routing/{id}`,
 * where `RouteComposer` already lives. Building a step editor here would be a
 * second composer.
 *
 * ARABIC / RTL
 * ------------
 * Every value the user types carries `dir="auto"`, and so does every label that
 * came from a template. This is bidi CONTENT inside chrome whose direction is
 * the interface's, not the content's: an Arabic circular reference typed into an
 * English interface must read right-to-left in its own box while the form around
 * it stays as the reader set it. The chrome itself uses logical properties
 * throughout (`ms-`/`me-`, `text-start`), so it mirrors with the interface and
 * has no left/right of its own.
 */

import { useMemo, useState } from 'react';
import { IconExternalLink, IconFilePlus } from '@tabler/icons-react';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { EmptyState } from '@amroksaleh/ui/empty-state';
import { Spinner } from '@amroksaleh/ui/spinner';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { useTranslation } from '@amroksaleh/features/i18n';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { apiClient } from '@/lib/api-client';

/** One placeholder a template declares. Mirrors `Placeholder` in the UI kit. */
export interface TemplatePlaceholder {
  key: string;
  label: string;
  sample: string;
}

/**
 * A template as the picker needs it: its identity, and the placeholders it
 * declares. `GET /api/v1/document-templates` returns `data` verbatim, so the
 * placeholders are read out of it rather than fetched separately.
 */
export interface CreatableTemplate {
  id: number;
  name: string;
  placeholders: TemplatePlaceholder[];
}

/**
 * `POST /api/v1/documents`' render block. `reason` is a CLOSED vocabulary
 * precisely so this component can branch on it — see the route's docblock. An
 * unknown value is treated as "not stored, no explanation offered", which is
 * what a server newer than this client would produce.
 */
type RenderReason =
  | 'declined'
  | 'disabled'
  | 'persist_disabled'
  | 'rejected'
  | 'unavailable'
  | 'storage_unavailable'
  | null;

interface CreateResponse {
  data?: { id?: number; title?: string };
  render?: { attempted?: boolean; stored?: boolean; reason?: RenderReason };
  error?: string;
}

/** What the dialog reports when a document has been raised. */
export interface RaisedDocument {
  id: number;
  title: string;
  /** Whether an artifact was rendered and stored for it. */
  rendered: boolean;
  /** Why not, when it was not. Null when it was, or when nothing was offered. */
  renderReason: RenderReason;
}

export interface CreateDocumentDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * The templates this caller may raise from — already filtered server-side.
   * An empty array is rendered as an empty state, never as an empty dropdown
   * (#756: never invented content, and never a control that cannot work).
   */
  templates: CreatableTemplate[];
  templatesLoading: boolean;
  /**
   * Why `templates` could not be read, when that is the reason it is empty.
   * Distinguishes "you have no templates" from "we could not ask", which look
   * identical on screen and are not the same problem.
   */
  templatesError: string | null;
  /** Called after a successful create, so the host can refresh its listing. */
  onCreated: (document: RaisedDocument) => void;
  /**
   * Whether to offer the send hand-off. Resolved by the host from
   * `documents:route` — being offered a Send that 403s is worse than not being
   * offered one (#951 argues the inverse case; both come down to not lying).
   */
  canRoute: boolean;
  /** Take the user to the routing composer for a document. */
  onSend: (documentId: number) => void;
}

export function CreateDocumentDialog({
  open,
  onOpenChange,
  templates,
  templatesLoading,
  templatesError,
  onCreated,
  canRoute,
  onSend,
}: CreateDocumentDialogProps) {
  const t = useTranslation('documents');

  const [templateId, setTemplateId] = useState<string>('');
  const [title, setTitle] = useState('');
  const [values, setValues] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [raised, setRaised] = useState<RaisedDocument | null>(null);

  const selected = useMemo(
    () => templates.find((tpl) => String(tpl.id) === templateId) ?? null,
    [templates, templateId]
  );

  /**
   * Reset on every OPEN, adjusted DURING RENDER rather than in an effect.
   *
   * The reset itself is not optional: a dialog that reopens still holding the
   * last document's reference number is a dialog that issues the wrong document
   * to somebody in a hurry. Doing it in an effect would render the stale form
   * once, then re-render — which is both a cascading render and a real frame in
   * which the previous values are on screen and clickable. React's documented
   * pattern for "reset state when a prop changes" is this: compare against the
   * previous value in render and adjust immediately, so nothing stale is ever
   * committed.
   */
  const [openedAs, setOpenedAs] = useState(open);
  if (open !== openedAs) {
    setOpenedAs(open);
    if (open) {
      setTemplateId('');
      setTitle('');
      setValues({});
      setRefusal(null);
      setRaised(null);
      setBusy(false);
    }
  }

  /**
   * Switching template abandons the values keyed to the old one, in the SAME
   * event that changes the template rather than in an effect watching it.
   *
   * Carrying them over would send keys the new template does not declare, and
   * the server refuses those by name — correct, and a baffling way to find out.
   */
  function chooseTemplate(id: string) {
    setTemplateId(id);
    setValues({});
    setRefusal(null);
  }

  async function submit() {
    if (selected === null) {
      return;
    }
    setBusy(true);
    setRefusal(null);
    try {
      const response = await apiClient('/api/v1/documents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          document_template_id: selected.id,
          // Omitted when blank so the server falls back to the template's name,
          // which is what it does and what it says it does. Sending an empty
          // string would be a title of "".
          ...(title.trim() === '' ? {} : { title: title.trim() }),
          // EXACTLY one row, carrying EVERY placeholder the template declares —
          // including the ones left blank, as empty strings.
          //
          // Omitting the field entirely would be a different request: the server
          // reads an absent `dataRows` as "use the template's SAMPLES", which
          // are demonstration text ("DEMO-0001"). A document issued with sample
          // text where its reference number belongs looks finished and is wrong,
          // and the person who left the field blank is the last one who would
          // notice. A blank field renders blank, visibly.
          dataRows: [
            Object.fromEntries(
              selected.placeholders.map((p) => [p.key, values[p.key] ?? ''])
            ),
          ],
          // `render` is deliberately NOT sent. Absent means "render if this
          // instance can", which is the honest request: this dialog does not
          // know whether the render tier is running and must not claim to
          // require it — `render: true` would turn a perfectly good document
          // into a 503 on every install where `documents.render_enabled` is
          // false, which is the default.
        }),
      });

      const body = (await response.json().catch(() => null)) as CreateResponse | null;

      if (!response.ok) {
        // Verbatim. The 422s name the offending field ("These fields are not
        // placeholders on this template: refrence") and the 503 says which
        // switch is off — both are more useful than anything this component
        // could paraphrase.
        setRefusal(
          body?.error ??
            t('create.error.generic', 'The document could not be created.')
        );
        return;
      }

      const id = body?.data?.id;
      if (typeof id !== 'number') {
        // A 201 whose body does not identify the document is not a success this
        // component can act on: there is nothing to send and nothing to link to.
        setRefusal(
          t('create.error.noId', 'The document was created but the server did not identify it.')
        );
        return;
      }

      const document: RaisedDocument = {
        id,
        title: typeof body?.data?.title === 'string' ? body.data.title : selected.name,
        rendered: body?.render?.stored === true,
        renderReason: body?.render?.reason ?? null,
      };
      setRaised(document);
      onCreated(document);
    } catch {
      setRefusal(
        t('create.error.network', 'The document could not be created. Check your connection and try again.')
      );
    } finally {
      setBusy(false);
    }
  }

  /**
   * What to say about a document that exists but has no PDF.
   *
   * Never "failed": the document is real, routable and auditable, and on a
   * default install (`documents.render_enabled` is false) it is the ONLY outcome
   * available. Each reason gets its own sentence because the action differs —
   * ask the operator, try again, or nothing at all.
   */
  function renderNote(document: RaisedDocument): string | null {
    if (document.rendered) {
      return null;
    }
    switch (document.renderReason) {
      case 'disabled':
      case 'persist_disabled':
        return t(
          'create.render.disabled',
          'No PDF was produced: server-side rendering is switched off on this instance. The document is complete and can be circulated; an operator can enable rendering to produce the file later.'
        );
      case 'unavailable':
      case 'storage_unavailable':
        return t(
          'create.render.unavailable',
          'The document was saved, but the rendering service could not be reached. You can produce the PDF later from the document itself.'
        );
      case 'rejected':
        return t(
          'create.render.rejected',
          'The document was saved, but it was too large to render under this instance limits. An operator can raise them.'
        );
      case 'declined':
        return null;
      default:
        return t('create.render.none', 'The document was saved without a PDF.');
    }
  }

  const canSubmit = selected !== null && !busy;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('create.title', 'New document')}</DialogTitle>
          <DialogDescription>
            {raised === null
              ? t(
                  'create.description',
                  'Choose a template and fill in its fields. The document gets an identity you can circulate.'
                )
              : t('create.done.description', 'The document has been created.')}
          </DialogDescription>
        </DialogHeader>

        {raised !== null ? (
          // ── created ──────────────────────────────────────────────────────
          <div className="space-y-4">
            <Alert variant="success">
              <AlertDescription>
                <span dir="auto" className="font-medium">
                  {raised.title}
                </span>
              </AlertDescription>
            </Alert>

            {renderNote(raised) !== null && (
              <p className="text-sm text-muted-foreground">{renderNote(raised)}</p>
            )}

            <DialogFooter>
              <Button variant="outline" onClick={() => onOpenChange(false)}>
                {t('create.done.close', 'Done')}
              </Button>
              {canRoute && (
                <Button onClick={() => onSend(raised.id)}>
                  <IconExternalLink className="me-2 size-4" aria-hidden />
                  {t('create.done.send', 'Send it')}
                </Button>
              )}
            </DialogFooter>
          </div>
        ) : templatesLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : templatesError !== null ? (
          <EmptyState
            variant="error"
            title={t('create.templates.errorTitle', 'Templates could not be loaded')}
            description={templatesError}
          />
        ) : templates.length === 0 ? (
          // No dropdown at all, rather than an empty one. The reason is
          // actionable and is not the same as an error.
          <EmptyState
            title={t('create.templates.emptyTitle', 'No templates available')}
            description={t(
              'create.templates.emptyDescription',
              'A document is raised from a template, and none are available to you. Ask whoever manages the document templates, or design one in the Document Designer.'
            )}
          />
        ) : (
          // ── the form ─────────────────────────────────────────────────────
          <div className="space-y-4">
            <div className="space-y-2">
              <label htmlFor="new-document-template" className="text-sm font-medium leading-none">
                {t('create.template.label', 'Template')}
              </label>
              <Select value={templateId} onValueChange={chooseTemplate}>
                <SelectTrigger id="new-document-template" className="w-full">
                  <SelectValue placeholder={t('create.template.placeholder', 'Choose a template')} />
                </SelectTrigger>
                <SelectContent>
                  {templates.map((tpl) => (
                    <SelectItem
                      key={tpl.id}
                      value={String(tpl.id)}
                      // Declared rather than inferred from the children. Radix
                      // derives an item's text value from its rendered subtree
                      // when this is absent, which the `dir="auto"` wrapper below
                      // turns into a post-mount state update; naming it here
                      // keeps the bidi wrapper AND leaves the item's identity a
                      // plain string.
                      textValue={tpl.name}
                    >
                      <span dir="auto">{tpl.name}</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <label htmlFor="new-document-title" className="text-sm font-medium leading-none">
                {t('create.titleField.label', 'Title')}
              </label>
              <Input
                id="new-document-title"
                dir="auto"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder={selected?.name ?? t('create.titleField.placeholder', 'Named after the template')}
              />
              <p className="text-xs text-muted-foreground">
                {t(
                  'create.titleField.hint',
                  'What this document is recognised by in a list or an inbox. Left blank, it takes the template name.'
                )}
              </p>
            </div>

            {selected !== null && selected.placeholders.length > 0 && (
              <div className="space-y-3 rounded-lg border border-border p-3">
                <p className="text-sm font-medium">{t('create.fields.label', 'Fields')}</p>
                {selected.placeholders.map((placeholder) => (
                  <div key={placeholder.key} className="space-y-1">
                    <label
                      htmlFor={`new-document-field-${placeholder.key}`}
                      className="text-sm text-muted-foreground"
                      dir="auto"
                    >
                      {placeholder.label === '' ? placeholder.key : placeholder.label}
                    </label>
                    <Input
                      id={`new-document-field-${placeholder.key}`}
                      // The user's own content, whose direction is its own and
                      // not the interface's.
                      dir="auto"
                      value={values[placeholder.key] ?? ''}
                      onChange={(e) =>
                        setValues((prev) => ({ ...prev, [placeholder.key]: e.target.value }))
                      }
                      // The template's SAMPLE, as a hint and never as a value: a
                      // pre-filled sample is the one that gets issued.
                      placeholder={placeholder.sample}
                    />
                  </div>
                ))}
              </div>
            )}

            {selected !== null && selected.placeholders.length === 0 && (
              <p className="text-sm text-muted-foreground">
                {t('create.fields.none', 'This template has no fields to fill in.')}
              </p>
            )}

            {refusal !== null && (
              <Alert variant="destructive">
                <AlertDescription>{refusal}</AlertDescription>
              </Alert>
            )}

            <DialogFooter>
              <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
                {t('create.cancel', 'Cancel')}
              </Button>
              <Button onClick={submit} disabled={!canSubmit}>
                {busy ? (
                  <Spinner size="sm" className="me-2" />
                ) : (
                  <IconFilePlus className="me-2 size-4" aria-hidden />
                )}
                {t('create.submit', 'Create document')}
              </Button>
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
