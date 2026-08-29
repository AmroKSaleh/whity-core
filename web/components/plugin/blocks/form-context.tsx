'use client';

/**
 * WC-235: FormContext for interactive `form` blocks.
 *
 * Provides per-form state (values, errors, isSubmitting) to all descendant
 * input and submit-button renderers. A `textInput` / `checkbox` / etc.
 * rendered outside a `FormProvider` receives `null` from `useFormBlockContext`
 * and degrades to `UnsupportedBlock`.
 *
 * i18n: the chrome this file authors goes through `t()` (domain `plugin`).
 * Every `child.label`, the server's issue `message`/`column`, and a plugin's
 * own validation copy are DATA and are rendered verbatim.
 */

import * as React from 'react';
import type { Block, FormBlock, LocalizedTextValue, OuScopeValue } from '@/lib/plugin-features';
import { isOuScopeValue } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import { resolveContextPath } from '@/components/plugin/blocks/context-path';
import { submitPluginAction, type ActionIssue } from '@/lib/plugin-action-submit';
import { useToast } from '@/lib/toast-context';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';

/** Sentinel: when a sensitive field holds this value, it is omitted from the submit payload. */
export const SENSITIVE_SENTINEL = '••••••';

/**
 * One cell of a `fieldArray` row.
 *
 * The first five members are what a template input WRITES. The last two exist
 * for what a seeded row CARRIES: a `fieldArray` with a `source` copies each
 * fetched row whole and lets the template overlay the keys it names, so a fact
 * the editor does not render — a `select` question's `options`, a field's
 * `validation` rules — rides back out on the submit exactly as it arrived.
 *
 * That passthrough is not a convenience. The submit behind a sourced array is a
 * REPLACEMENT, so a key the editor dropped on the way in is a key the server is
 * being told to forget. Carrying the row whole means the editor can only ever
 * change what it actually shows somebody.
 */
export type FieldArrayCell =
  | string
  | boolean
  | LocalizedTextValue
  | OuScopeValue
  | number
  | null
  | unknown[]
  | Record<string, unknown>;

/** A `fieldArray` (WC-532 A2) value: an ordered list of per-row sub-records. */
export type FieldArrayValue = Record<string, FieldArrayCell>[];

/**
 * A single form field's value. Most inputs are `string | boolean`; a
 * `bilingualText` input (WC-532 A4) holds a `{ar?, en?}` object; a `fieldArray`
 * (WC-532 A2) holds an array of row records; an `ouScopePicker` (#868) holds a
 * `{unit, scope, type}` rule — the picker always writes the WHOLE object, so a
 * value in this map can never be a rule missing its `scope`.
 */
export type FormValue = string | boolean | LocalizedTextValue | FieldArrayValue | OuScopeValue;

/** The value shape exposed to all form descendants via context. */
export interface FormBlockContextValue {
  values: Record<string, FormValue>;
  /**
   * Write a field's value. `undefined` REMOVES the key from the value map
   * rather than storing an empty one, and the difference is load-bearing for a
   * sourced `fieldArray`: an absent key is omitted from the submit payload
   * entirely, so a replace endpoint handed no list at all refuses the request,
   * where one handed `[]` would empty the record and report success.
   */
  setValue(name: string, value: FormValue | undefined): void;
  errors: Record<string, string>;
  isSubmitting: boolean;
  submit(): void;
  /**
   * A descendant declares that the form MUST NOT be submitted yet, and says
   * why; `null` releases the hold. `submit()` refuses while any hold stands and
   * shows each reason under the field that raised it.
   *
   * This exists because of one specific way a form can lie. An input renders
   * its own emptiness, and for most inputs an empty render is just an empty
   * value — the server sees a blank and decides. But a `fieldArray` bound to a
   * `source` submits a REPLACEMENT set, so "I have no rows" is not a blank, it
   * is an instruction to delete every stored row. Until its fetch has landed, an
   * empty render means "I do not know yet", and the two are indistinguishable
   * from outside the block. So the block that knows the difference is the one
   * that gets to say so, rather than the provider guessing from the value.
   *
   * Deliberately NOT the same mechanism as `errors`: an error is the outcome of
   * a submit the user asked for, and a hold is a state the form is in before
   * they ask. Holds therefore also disable the submit button, so the ordinary
   * case is a control that is visibly not ready rather than one that refuses
   * after the fact.
   */
  holdSubmit(name: string, reason: string | null): void;
  /** Whether any descendant currently holds the submit. */
  submitHeld: boolean;
}

const FormBlockContext = React.createContext<FormBlockContextValue | null>(null);

/**
 * Returns the nearest `FormProvider`'s context value, or `null` when the
 * calling component is rendered outside any form.
 */
export function useFormBlockContext(): FormBlockContextValue | null {
  return React.useContext(FormBlockContext);
}

/**
 * WC-532 A2: provide a scoped form context to a subtree. A `fieldArray` uses
 * this to give each row its own `{values, setValue}` (backed by that row's
 * slice of the array) so the ORDINARY input renderers work unchanged inside a
 * row — their `name`s resolve against the row record, not the outer form.
 */
export function FormScopeProvider({
  value,
  children,
}: {
  value: FormBlockContextValue;
  children: React.ReactNode;
}) {
  return <FormBlockContext.Provider value={value}>{children}</FormBlockContext.Provider>;
}

// ---- helpers ----

/**
 * Render the issues report UI (mirrors action-screen's report section) so the
 * form can surface server-side validation feedback inline.
 */
export function IssuesReport({ issues }: { issues: ActionIssue[] }) {
  // Before the early return: a hook may not run conditionally.
  const t = useTranslation('plugin');
  if (issues.length === 0) {
    return null;
  }
  return (
    <div
      className="space-y-2 rounded-lg border border-border bg-card p-4"
      data-slot="form-issues-report"
    >
      <div className="flex items-center gap-2">
        <IconAlertTriangle size={16} className="text-destructive" aria-hidden />
        <h3 className="font-heading text-sm font-medium">
          {t('action.issues.title', 'Validation report')}
        </h3>
      </div>
      <ul className="space-y-1.5">
        {issues.map((issue, index) => {
          // `issue.column` and `issue.message` are the plugin's own report
          // text — rendered verbatim. Only the "Item n" locator is ours.
          const where: string[] = [];
          if (typeof issue.item === 'number') {
            where.push(t('action.issues.item', 'Item {index}', { index: issue.item }));
          }
          if (typeof issue.column === 'string' && issue.column !== '') {
            where.push(issue.column);
          }
          const isError = issue.severity !== 'warning';
          return (
            <li
              key={index}
              className={`rounded-md border-s-4 bg-muted/40 px-3 py-2 text-sm ${
                isError ? 'border-destructive' : 'border-warning'
              }`}
            >
              <span className="me-2 text-xs font-semibold uppercase tracking-wide">
                {isError
                  ? t('action.issues.severity.error', 'error')
                  : t('action.issues.severity.warning', 'warning')}
              </span>
              {where.length > 0 && (
                <span className="font-medium text-muted-foreground">{where.join(' / ')}: </span>
              )}
              {issue.message ?? ''}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

// ---- collect inputs (any depth) ----

/** The input-leaf block types that participate in a form's value map. */
const FORM_INPUT_TYPES = [
  'textInput',
  'textArea',
  'numberInput',
  'select',
  'checkbox',
  'slider',
  'dateInput',
  'fileInput',
  'colorInput',
  'bilingualText',
  'referenceSelect',
  'richTextInput',
  'ouScopePicker',
] as const;

/**
 * Flatten every input-leaf descendant of a form's children at ANY depth —
 * inputs nested inside layout containers (section, card, grid, row, tabs) are
 * included. This mirrors the SDK `BlockValidator`, which permits inputs
 * anywhere inside a `form` (the `inForm` ancestor rule), so default-seeding and
 * required-validation must reach them too. A nested `form` owns its own
 * inputs, so we never descend into one.
 *
 * Exported for `FieldArrayRenderer`, which needs the same walk over a ROW
 * TEMPLATE in order to map a fetched row onto the inputs that will edit it.
 * Deriving that from the one walk rather than a second list is what keeps
 * "which children are inputs" a single answer: a template input the seeder did
 * not know about would render blank over a stored value and then save the blank.
 */
export function collectFormInputs(blocks: Block[]): Block[] {
  const inputs: Block[] = [];
  for (const block of blocks) {
    if ((FORM_INPUT_TYPES as readonly string[]).includes(block.type)) {
      inputs.push(block);
      continue;
    }
    if (block.type === 'form') {
      continue;
    }
    if (block.type === 'fieldArray') {
      // WC-532 A2: a fieldArray owns ONE flat value (its row array) keyed by
      // its own name — include it, but never flatten its per-row template
      // inputs into the outer form (their names are row-scoped).
      inputs.push(block);
      continue;
    }
    // #909: BOTH child lists. An `accessGate` carries two, and a walk that knew
    // only `children` would seed defaults for the permitted rendering and not
    // for the refused one — so the same field name would be in the value map or
    // absent from it depending on which branch the author put it in, which is
    // not a distinction anyone declared. Hidden inputs staying in the value map
    // is the standing convention for `visibleWhen` (the server re-validates and
    // is authoritative over what it accepts); this keeps the two slots equal to
    // each other rather than inventing a third rule for one of them.
    for (const slot of ['children', 'otherwise'] as const) {
      const nested = (block as { children?: unknown; otherwise?: unknown })[slot];
      if (Array.isArray(nested)) {
        inputs.push(...collectFormInputs(nested as Block[]));
      }
    }
  }
  return inputs;
}

// ---- seed defaults ----

/**
 * Collect each input's `default` value across ALL descendant inputs of a form
 * (any depth — see {@link collectFormInputs}).
 */
function collectDefaults(
  children: FormBlock['children'],
  resolveRef?: (ref: string) => string | undefined
): Record<string, FormValue> {
  const defaults: Record<string, FormValue> = {};
  for (const input of collectFormInputs(children)) {
    if (input.type === 'fieldArray') {
      // A SOURCED array is left OUT of the value map entirely — not seeded with
      // `[]`. Its rows are the stored ones, and until the fetch lands nobody
      // knows what they are; `[]` would be a confident answer to that question,
      // and the wrong one, submitted to an endpoint that reads it as "delete
      // them all". Absent is the honest state, and it is also a second line of
      // defence: a submit that somehow escaped the hold below would omit the key
      // rather than send an empty list, and a replace endpoint that is handed no
      // list at all refuses the request instead of emptying the record.
      if (input.source !== undefined && input.source !== '') {
        continue;
      }
      // Seed `min` empty rows (each with the template's own defaults) so a
      // required-min array starts populated; 0 min → an empty array.
      const min = typeof input.min === 'number' && input.min > 0 ? input.min : 0;
      const rowDefault = collectDefaults(input.children, resolveRef) as FieldArrayValue[number];
      defaults[input.name] = Array.from({ length: min }, () => ({ ...rowDefault }));
    } else if (input.type === 'checkbox') {
      // WC-block-modal-drawer: `defaultFrom` (a master-detail context ref) wins
      // over the literal `default`. A row's boolean serialises as 'true'/'1'.
      const seeded = input.defaultFrom !== undefined ? resolveRef?.(input.defaultFrom) : undefined;
      if (seeded !== undefined) {
        defaults[input.name] = seeded === 'true' || seeded === '1';
      } else if (typeof input.default === 'boolean') {
        defaults[input.name] = input.default;
      }
    } else if (input.type === 'bilingualText') {
      // Seed an empty {ar, en} so the field exists in the value map and the
      // renderer/required-check have a stable object to read.
      defaults[input.name] = {};
    } else if (
      input.type === 'textInput' ||
      input.type === 'textArea' ||
      input.type === 'numberInput' ||
      input.type === 'select' ||
      input.type === 'slider' ||
      input.type === 'dateInput' ||
      input.type === 'colorInput' ||
      input.type === 'referenceSelect' ||
      input.type === 'richTextInput'
    ) {
      // WC-block-modal-drawer: `defaultFrom` resolved from context seeds the
      // input (e.g. an edit form opened from a table row) BEFORE the literal
      // `default`; an unresolved ref falls through to `default`.
      const seeded = input.defaultFrom !== undefined ? resolveRef?.(input.defaultFrom) : undefined;
      if (seeded !== undefined) {
        defaults[input.name] = seeded;
      } else if (typeof input.default === 'string') {
        defaults[input.name] = input.default;
      }
    }
  }
  return defaults;
}

// ---- provider ----

/**
 * Wraps a `form` block's children with form state (values, errors,
 * isSubmitting) and the `submit()` action. On submit:
 *   - required fields are validated → `errors` map set
 *   - if valid → `submitPluginAction` is called
 *   - 2xx → success toast
 *   - 422/issues → issues report rendered + error toast
 *   - other error → error toast
 */
/**
 * The record inside a preload response, or null when there is nothing to seed.
 *
 * THE ENVELOPE IS THE CONTRACT (#981). Core's handlers return
 * `{ data: { … } }` throughout, and the desktop renderer REQUIRES it — its
 * `fetchSource()` throws "malformed response" on a body with no `data` key. Web
 * used to spread the whole parsed body, so against a conventional endpoint it
 * seeded a single field called `data` and left every real field empty.
 *
 * A BARE BODY IS NO LONGER ACCEPTED, deliberately. Sniffing for a `data` key
 * and falling back would leave the contract permanently undecided: an endpoint
 * that legitimately returns a field named `data` becomes ambiguous, and the two
 * renderers would go on disagreeing about what a preload response is — which is
 * the drift this fix exists to end. A declaration whose endpoint returns a bare
 * body now seeds nothing on web, exactly as it already fails on desktop.
 *
 * Seeding nothing is also the SAFE direction. `isLoading` stays true until this
 * resolves and an unbound form is disabled (#957), so a response we cannot read
 * leaves the form unsubmittable rather than blank-and-ready — the state that
 * overwrites a record with empties.
 */
function unwrapEnvelope(body: unknown): Record<string, FormValue> | null {
  if (body === null || typeof body !== 'object') return null;
  const data = (body as { data?: unknown }).data;
  if (data === null || data === undefined || typeof data !== 'object' || Array.isArray(data)) {
    return null;
  }
  return data as Record<string, FormValue>;
}

export function FormProvider({
  block,
  children,
  resolveRef,
  onSubmitSuccess,
}: {
  block: FormBlock;
  children: React.ReactNode;
  /** WC-block-modal-drawer: resolves an input's `defaultFrom` against the master-detail context. */
  resolveRef?: (ref: string) => string | undefined;
  /** WC-block-modal-drawer: called after a successful submit (e.g. to close + refetch an enclosing overlay). */
  onSubmitSuccess?: () => void;
}) {
  const { addToast } = useToast();
  const t = useTranslation('plugin');

  // Resolved at render, not inside the effect below: a STRING dependency is
  // compared by value, so the load effect re-runs only if the text actually
  // changes (a language switch) — never merely because `t` got a new identity
  // when the bundle arrived, which would re-fetch and clobber user edits.
  const loadErrorText = t('form.error.load', 'Failed to load settings');

  const [values, setValues] = React.useState<Record<string, FormValue>>(
    () => collectDefaults(block.children, resolveRef)
  );
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [serverIssues, setServerIssues] = React.useState<ActionIssue[] | null>(null);
  const [isLoading, setIsLoading] = React.useState(block.dataSource !== undefined);
  const [loadError, setLoadError] = React.useState<string | null>(null);
  // Reasons descendants have given for why this form is not ready to be sent,
  // keyed by the input name that raised each. See `holdSubmit` on the context.
  const [holds, setHolds] = React.useState<Record<string, string>>({});

  // Idempotent by construction: re-registering the SAME reason returns the
  // previous object, so a child that calls this from an effect on every render
  // cannot drive a render loop.
  const holdSubmit = React.useCallback((name: string, reason: string | null) => {
    setHolds((prev) => {
      if (reason === null) {
        if (!(name in prev)) return prev;
        const next = { ...prev };
        delete next[name];
        return next;
      }
      if (prev[name] === reason) return prev;
      return { ...prev, [name]: reason };
    });
  }, []);

  // #949: `dataSource.path` carries the same `{token}` syntax a
  // `dataRecord.source` does, and it now resolves by the same rule — NOT AT
  // ALL until every token is bound. Substituting `''` would turn
  // `/things/{record}` into `/things/`, which is very often the COLLECTION
  // endpoint: a request that succeeds and pre-populates the form with the
  // wrong thing. Handing the path over raw, which is what this did, was worse
  // still — the form fetched `/things/%7Brecord%7D` and pre-populated with
  // nothing at all.
  //
  // `null` is what the two of them have in common: no fetch. Read
  // {@link resolveContextPath} for why that is the only honest answer.
  const dataSourcePath =
    block.dataSource === undefined
      ? undefined
      : resolveContextPath(block.dataSource.path, resolveRef);
  const dataSourceMethod = block.dataSource?.method;

  // A form whose source names a record NOTHING HAS BOUND YET stays disabled,
  // and this is the whole point of the issue rather than a detail of it. An
  // enabled, un-prefilled edit form is indistinguishable from a record that
  // genuinely holds no values — and against an update endpoint that replaces
  // rather than merges, submitting it writes blanks over every field the user
  // did not retype, and returns success. Disabled is the state that is true:
  // the stored values have not been loaded, and cannot be until something says
  // which record this is about.
  const isUnbound = block.dataSource !== undefined && dataSourcePath === null;

  React.useEffect(() => {
    if (!dataSourcePath || !dataSourceMethod) return;
    apiClient(dataSourcePath, { method: dataSourceMethod })
      .then((response) => response.json())
      .then((body: unknown) => {
        // THE `{ data: … }` ENVELOPE, which this used to spread whole (#981).
        //
        // Core's own handlers document that envelope throughout — `Response: {
        // data: { id, code, name, … } }` appears across LanguagesApiHandler,
        // UsersApiHandler and the rest — so a plugin endpoint following the
        // platform convention was seeding ONE form field literally named
        // `data`, and every real field stayed empty.
        //
        // That is #957's hazard reached by a second route: an enabled,
        // un-prefilled form. Against an update endpoint that replaces rather
        // than merges, submitting it writes blanks over every field the user
        // did not retype, and reports success.
        //
        // The desktop renderer has always required the envelope — its
        // `fetchSource()` throws "malformed response" without a `data` key —
        // so the two renderers disagreed about what a preload response IS.
        // This is the side that was wrong: one platform silently mis-seeded
        // while the other failed loudly.
        const record = unwrapEnvelope(body);
        if (record !== null) {
          setValues((prev) => ({ ...prev, ...record }));
        }
        setIsLoading(false);
      })
      .catch(() => {
        setLoadError(loadErrorText);
        setIsLoading(false);
      });
  }, [dataSourcePath, dataSourceMethod, loadErrorText]);

  const setValue = React.useCallback(
    (name: string, value: FormValue | undefined) => {
      setValues((prev) => {
        if (value === undefined) {
          if (!(name in prev)) return prev;
          const next = { ...prev };
          delete next[name];
          return next;
        }
        return { ...prev, [name]: value };
      });
      // Clear the field error when the user edits the field.
      setErrors((prev) => {
        if (!(name in prev)) return prev;
        const next = { ...prev };
        delete next[name];
        return next;
      });
    },
    []
  );

  const submit = React.useCallback(() => {
    // Collect required-field errors across all descendant inputs (any depth).
    const newErrors: Record<string, string> = {};

    for (const child of collectFormInputs(block.children)) {
      if (
        (child.type === 'textInput' ||
          child.type === 'textArea' ||
          child.type === 'numberInput' ||
          child.type === 'select' ||
          child.type === 'dateInput' ||
          child.type === 'fileInput' ||
          child.type === 'richTextInput') &&
        child.required === true
      ) {
        const val = values[child.name];
        const filled =
          typeof val === 'string' ? val.trim() !== '' : val !== undefined;
        if (!filled) {
          // `child.label` is plugin data; the sentence around it is ours.
          newErrors[child.name] = t('form.error.required', '{label} is required', {
            label: child.label,
          });
        }
      } else if (child.type === 'bilingualText' && child.required === true) {
        // A required bilingual field is satisfied by at least one language
        // (mirrors the CRUD localized-text rule).
        const val = values[child.name];
        const filled =
          val !== null &&
          typeof val === 'object' &&
          !Array.isArray(val) &&
          !isOuScopeValue(val) &&
          ((val.ar ?? '').trim() !== '' || (val.en ?? '').trim() !== '');
        if (!filled) {
          newErrors[child.name] = t('form.error.required', '{label} is required', {
            label: child.label,
          });
        }
      } else if (child.type === 'fieldArray' && typeof child.min === 'number' && child.min > 0) {
        // WC-532 A2: enforce the minimum row count.
        const val = values[child.name];
        const count = Array.isArray(val) ? val.length : 0;
        if (count < child.min) {
          // NOT translated, deliberately: the message picks "entry"/"entries"
          // from the count, and there is no plural machinery behind t(). A
          // two-arm English branch does not survive a language with more than
          // two plural categories (Arabic has six), and splitting the sentence
          // to key the halves would be worse than leaving it in English.
          newErrors[child.name] = `${child.label} needs at least ${child.min} ${child.min === 1 ? 'entry' : 'entries'}`;
        }
      }
    }

    // Holds are applied LAST and overwrite anything the loop above wrote for the
    // same name, because a hold is the more specific truth. An array still
    // waiting on its rows fails the min-count check too, and "needs at least 1
    // entry" would send the author off to add one — which is precisely the
    // wrong instruction, since the rows they are missing already exist and are
    // on their way.
    //
    // This is the AUTHORITATIVE refusal. The submit button is disabled while a
    // hold stands, but a disabled button is an affordance; this is the check a
    // programmatic `submit()` still has to get past, and the one the destructive
    // case is actually tested against.
    for (const [name, reason] of Object.entries(holds)) {
      newErrors[name] = reason;
    }

    setErrors(newErrors);
    if (Object.keys(newErrors).length > 0) {
      return;
    }

    setIsSubmitting(true);
    setServerIssues(null);

    // Omit sensitive sentinel values — they mean "unchanged, don't overwrite".
    const payload: Record<string, unknown> = {};
    for (const [key, val] of Object.entries(values)) {
      if (val === SENSITIVE_SENTINEL) continue;
      payload[key] = val;
    }

    // WC-block-submit-templating: interpolate {targetId.field}/{selector} context
    // tokens in the endpoint (e.g. an edit form inside a modal PATCHing
    // /api/persons/{edit-person.id} for the opened row). Unresolved → '' (a
    // runtime no-op), same as the SDK contract's no-cross-reference stance.
    //
    // DELIBERATELY NOT the not-until-resolved rule the read path above now
    // follows (#949), and the asymmetry is the point. A read that guesses is
    // silent: nobody asked for it, nobody is watching it, and its answer is
    // presented as fact. A submit is pressed, and every outcome it can have is
    // reported back — success toast, issues report, or error toast. The
    // dangerous truncation is a TRAILING token collapsing onto a live
    // collection route, and a trailing token belongs to the PUT/PATCH edit
    // endpoints, where the collection answers 404 or 405 — visibly, to a user
    // who is waiting for an answer.
    //
    // Refusing the submit instead would have to SAY why, or the Save button
    // becomes a no-op that reports nothing — which is the bug #949 is about,
    // moved rather than fixed. That is its own copy and its own UX call, not a
    // rider on this one.
    const endpoint = block.submit.endpoint.replace(
      /\{([^}]+)\}/g,
      (_match: string, ref: string) => encodeURIComponent(resolveRef?.(ref) ?? '')
    );

    void submitPluginAction(endpoint, block.submit.method, payload).then(
      (result) => {
        setIsSubmitting(false);
        if (result.ok) {
          addToast(t('action.toast.completed', 'Completed successfully'), 'success');
          // WC-block-modal-drawer: close the enclosing overlay + refetch the tree.
          onSubmitSuccess?.();
        } else if (result.issues && result.issues.length > 0) {
          setServerIssues(result.issues);
          addToast(
            t('action.issues.summary', '{count} issue(s) — see the report below', {
              count: result.issues.length,
            }),
            'error'
          );
        } else {
          // `result.error` is the server's own message — never keyed.
          addToast(result.error ?? t('action.toast.requestFailed', 'Request failed'), 'error');
        }
      }
    );
  }, [block, values, holds, addToast, t, onSubmitSuccess, resolveRef]);

  const contextValue: FormBlockContextValue = {
    values,
    setValue,
    errors,
    isSubmitting,
    submit,
    holdSubmit,
    submitHeld: Object.keys(holds).length > 0,
  };

  return (
    <FormBlockContext.Provider value={contextValue}>
      <div className="space-y-3" data-slot="form-block">
        {loadError !== null && (
          <p className="text-sm text-destructive" role="alert">{loadError}</p>
        )}
        {isUnbound && (
          // Said out loud, and deliberately not styled as a failure — nothing
          // has gone wrong, nothing has named a record yet. The same sentence
          // and the same key a `dataRecord` uses for the same state, so a
          // detail pane whose record block and edit form are both waiting on
          // one selection says one thing twice rather than two things once.
          <p className="text-sm text-muted-foreground" data-slot="form-unbound">
            {t('blocks.record.unbound', 'No record selected.')}
          </p>
        )}
        <fieldset disabled={isLoading || isUnbound} className="contents">
          {children}
        </fieldset>
        {serverIssues !== null && serverIssues.length > 0 && (
          <IssuesReport issues={serverIssues} />
        )}
      </div>
    </FormBlockContext.Provider>
  );
}
