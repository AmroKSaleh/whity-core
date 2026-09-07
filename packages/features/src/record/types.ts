/**
 * The RECORD-PAGE SHELL contract (#882).
 *
 * A record page is the Whity standard for editing one record: a URL per record,
 * the record's own context around the form, and read-only as a state rather than
 * a greyed-out form. #885 proved the shape by hand on `/admin/roles/[id]`; this
 * module is that shape made reusable, and it was extracted against a SECOND real
 * screen (`/admin/users/[id]`) rather than against the first one alone — a shell
 * designed against one screen is a shell shaped like that screen.
 *
 * WHAT THE SHELL OWNS
 *   - the layout primitives: back link, title, badges, actions, a stat strip, and
 *     a main-plus-side split for the record's own fields versus its related
 *     collections;
 *   - the read-only STATE — it takes two distinct renderings and picks one, so a
 *     screen cannot ship a disabled form as its read-only view;
 *   - which gate refused, and saying so;
 *   - the loading and failure states, so a route wrapper and its screen agree on
 *     what a half-loaded record looks like.
 *
 * WHAT THE SHELL DELIBERATELY DOES NOT OWN
 *   - fetching, routing, capabilities and copy. Like every screen in this
 *     package the shell is presentational and data-source-agnostic, so the same
 *     component mounts under Next, a Tauri shell, or the Vite harness.
 */

import type { ReactNode } from 'react';

import type { DateDisplay } from '../datetime';

/**
 * Property names that state a decision about the CALLER rather than a property
 * of the RECORD.
 *
 * THIS LIST IS THE #895 GUARD. The roles record page derived "is this a global
 * base role" from `manageable` — the server's answer to "may YOU write this?".
 * For a tenant-0 caller `manageable` is true of every role, so the system tenant
 * saw a global base role labelled "Your tenant's role": the inference reads as
 * correct in the common case and is wrong precisely for the one caller who can
 * act on it.
 *
 * The lesson generalises past that one flag. A record page STATES FACTS ABOUT
 * THE RECORD, and those facts come from the server's description of the record —
 * never from an adjacent permission flag, whether that flag arrives from `can()`
 * or rides along inside the record payload as `manageable` did.
 *
 * So the shell splits the payload at that seam and enforces the split in the
 * type system: a fields type carrying any of these names cannot be given a fact
 * projection, so the screen fails to compile with the offending property named.
 * The flags go in {@link RecordAccess} instead, which the projection never
 * receives.
 */
type CallerDecisionKey =
  | 'manageable'
  | 'editable'
  | 'writable'
  | 'deletable'
  | 'canEdit'
  | 'canDelete'
  | 'canManage'
  | 'canWrite'
  | 'allowed'
  | 'permitted'
  | 'readOnly';

/**
 * The compile error a caller-permission flag in a fields type produces.
 *
 * A template-literal message rather than a bare `never`, so the offending
 * property and the reason both appear in the TypeScript error itself — the
 * person who hits it should not have to find this file to learn why.
 */
export interface CallerFlagInRecordFields<K extends string> {
  readonly __recordFieldsError: `#895: '${K}' says what the CALLER may do, not what the record IS. A record page states facts about the record; move this to RecordAccess (see resolveAccess).`;
}

/** The caller-permission flags a fields type carries, if any. */
type CallerFlagsIn<T> = Extract<keyof T, CallerDecisionKey>;

/**
 * A record's FIELDS: what the server says the record IS, checked against
 * {@link CallerDecisionKey}.
 *
 * Resolves to `T` when the type is clean and to {@link CallerFlagInRecordFields}
 * when it is not, so it can be used directly as a self-check on a declaration:
 *
 * ```ts
 * type UserRecordFields = RecordFields<{ email: string; tenantName: string }>;
 * ```
 *
 * The shell applies it for you through {@link RecordFactsFn}; declaring it
 * explicitly just moves the error one file closer to the mistake.
 */
export type RecordFields<T> = CallerFlagsIn<T> extends never
  ? T
  : CallerFlagInRecordFields<CallerFlagsIn<T>>;

/**
 * The value a stat may show.
 *
 * NOT `boolean`, and not `ReactNode`, on purpose. A permission flag is a
 * boolean, so a type that admits one invites `value: role.manageable`; a fact is
 * something a person reads, so it is rendered text. `null` is the honest
 * "the server has not said yet", which the shell renders as an em dash rather
 * than leaving the screen to invent a placeholder per stat.
 */
export type RecordFactValue = string | number | null;

/** One labelled fact in the stat strip. */
export interface RecordFact {
  /** Stable identifier; also the stat's `data-testid` suffix. */
  key: string;
  label: string;
  value: RecordFactValue;
}

/** The visual weight of a badge — mapped to the UI kit's `Badge` variants. */
export type RecordTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

/** One badge beside the title, stating something the record IS. */
export interface RecordBadge {
  /** Stable identifier; also the badge's `data-testid` suffix. */
  key: string;
  label: string;
  tone?: RecordTone;
  /** Optional hover explanation (e.g. what a scope means). */
  title?: string;
}

/**
 * Everything the page SAYS about the record: its title, its subtitle, the badges
 * beside the title and the stat strip beneath it.
 */
export interface RecordStatement {
  title: string;
  subtitle?: string;
  badges?: RecordBadge[];
  stats?: RecordFact[];
}

/**
 * The translator a fact projection renders its labels through.
 *
 * Structurally identical to the i18n package's `TranslateFn` and deliberately
 * named differently, for the same reason the roles slice uses `RolesTranslate`:
 * the catalogue extractor binds a `TranslateFn`-typed parameter as a translate
 * call and then needs a `useTranslation('domain')` in the file to resolve its
 * domain, which these prop-driven components do not have. Files in this slice
 * declare their keys in file-scoped `@i18n-keys` blocks instead.
 */
export type RecordTranslate = (
  key: string,
  fallback?: string,
  vars?: Record<string, string | number>
) => string;

/**
 * The projection from a record's fields to what the page says about it.
 *
 * A PURE FUNCTION OF THE RECORD AND THE DICTIONARY. Its parameters are the
 * server's description of the record and a translator — and nothing else. A fact
 * builder written where it belongs, at module scope next to the fields type, has
 * no capability check in scope to reach for; the fields type it receives cannot
 * carry one (see {@link RecordFields}); and a translator cannot answer a
 * question about the caller's authority. That exhausts the ways a fact could be
 * inferred from a permission (#895).
 */
export type RecordFactsFn<TFields> = CallerFlagsIn<TFields> extends never
  ? RecordProjection<TFields>
  : CallerFlagInRecordFields<CallerFlagsIn<TFields>>;

/**
 * The projection's raw call shape, without the {@link RecordFields} check.
 *
 * Internal to the shell — it is what {@link RecordFactsFn} resolves to once the
 * fields type is proven clean, and what the shell casts back to in order to call
 * it. Screens should always write `RecordFactsFn`, which is the checked one.
 *
 * `dates` is handed in for exactly the reason `t` is (#1068). A projection is
 * module-scope and pure by design — that is the #895 property that keeps a
 * permission flag out of its reach — so it cannot call a hook, and a date it
 * formatted itself would be a date outside the one sanctioned path. The shell
 * IS a component, so it resolves {@link DateDisplay} once and passes it down,
 * and a projection that shows a timestamp reads `dates.hidden` to drop the
 * whole stat rather than leaving a label with an em dash under it.
 */
export type RecordProjection<TFields> = (
  fields: TFields,
  t: RecordTranslate,
  dates: DateDisplay,
) => RecordStatement;

/**
 * One reason a caller may or may not edit this record, in the order the screen
 * wants them checked.
 *
 * `reason` is USER-FACING copy, already translated, because only the screen
 * knows which of its own sentences fits. The shell renders the first refusal and
 * only the first: two notices saying overlapping things is one notice too many.
 */
export interface RecordGate {
  /** True when this gate permits editing. */
  allowed: boolean;
  /** Why this gate refuses. Rendered only when it is the FIRST gate to refuse. */
  reason: string;
}

/**
 * What THIS CALLER may do to the record — the other half of the #895 split.
 *
 * Never reaches {@link RecordFactsFn}. It decides which controls exist and which
 * of the two `main` renderings the shell shows; it is never a statement about
 * the record.
 */
export interface RecordAccess {
  editable: boolean;
  /** The first refusing gate's reason, or null when the record is editable. */
  readOnlyReason: string | null;
}

/**
 * The THREE states a region of a record page can be in (#910).
 *
 * The operator's requirement is "some parts have permissions, not always
 * everything is allowed", and the page-level {@link RecordAccess} above models
 * two states for the whole page. Two is one short, and the missing one is not a
 * styling variant: the difference between HIDDEN and READ-ONLY is an
 * authorization decision.
 *
 *  - `hidden`    — the caller may not see this region. It is ABSENT: not
 *                  collapsed, not `display:none`, not an empty card. Its data
 *                  never reached the browser either (see
 *                  {@link RecordSectionVerdicts}).
 *  - `read-only` — the caller may see it and may not change it, and is TOLD SO.
 *                  #951's principle: an unavailable affordance is refused with a
 *                  reason, not silently missing, because "you may not" and "this
 *                  is broken" are otherwise the same pixels.
 *  - `editable`  — the caller may change it.
 */
export type RecordSectionState = 'hidden' | 'read-only' | 'editable';

/**
 * One reason a region is in the state it is in.
 *
 * A DISCRIMINATED UNION rather than a `reason` beside an `effect` string,
 * because the two effects differ in exactly one way that matters: a `hide`
 * refusal has no user-facing copy AT ALL, and must not be able to acquire any.
 * A sentence explaining why a region is missing is a statement about a region
 * the caller was not to know exists — the disclosure the `hidden` state is for
 * avoiding. Making the field structurally unavailable means a screen cannot
 * "helpfully" add one later.
 *
 * `read-only` refusals carry already-translated copy, for the same reason
 * {@link RecordGate} does: only the screen knows which of its own sentences fits.
 */
export type RecordSectionGate =
  | { allowed: boolean; effect: 'hide' }
  | { allowed: boolean; effect: 'read-only'; reason: string };

/**
 * What THIS CALLER may do to ONE REGION of the record — {@link RecordAccess}
 * with the third state.
 *
 * `readOnlyReason` is non-null only in the `read-only` state. `hidden` carries
 * none by construction (above), and `editable` has nothing to explain.
 */
export interface RecordSectionAccess {
  state: RecordSectionState;
  readOnlyReason: string | null;
}

/**
 * Why a region is read-only — the same three-field shape #951/#968 established
 * for a denied crud control, because it is the same idea one level up.
 *
 * That PR's finding was that a control which is merely ABSENT collapses three
 * unrelated causes into one symptom, so a correct screen the viewer has no
 * rights on is pixel-identical to a broken one. A region is a bigger control,
 * and the fix is the same fix: present, inert, and able to say why. The fields
 * carry the same meanings, deliberately, so nobody has to learn a second
 * vocabulary for the same question.
 */
export interface RecordSectionDenial {
  /**
   * The stable machine discriminant, used to key a localized string:
   *   - `permission` the caller does not hold what changing this region needs;
   *   - `record`     the RECORD refuses the write whatever the caller holds
   *                  (a global base role, a lock, a closed period).
   *
   * The two are worth separating because the remedies differ: one is fixed by a
   * grant and the other cannot be fixed by one at all, and an operator told
   * "you lack a permission" about the second goes looking for a grant that
   * would not have helped.
   */
  code: string;
  /**
   * The audience-safe explanation, for whoever is looking at the screen. Names
   * no internal identifier, and doubles as the i18n fallback — a host with no
   * catalogue (the desktop renderer, the Vite harness) shows this verbatim.
   */
  reason: string;
  /**
   * The operator-grade half: the exact permission the write would need.
   * Non-null ONLY for a caller the server decided may read it. The server
   * decides the audience; the client renders what it got, exactly as #968 has
   * it — a client that gated this itself would be holding an opinion about a
   * disclosure rule the server already owns.
   */
  detail: string | null;
}

/**
 * The SERVER'S verdict for one region, as it arrives on the wire.
 *
 * Note what this type cannot express: `hidden`. There is no wire value for it,
 * on purpose. A server states that a region is hidden by OMITTING its key from
 * {@link RecordSectionVerdicts} and omitting the region's data from the payload
 * — so a response can never carry a line telling a caller about a region they
 * may not see. "Absent" is the representation, not a flag meaning absent.
 *
 * A viewer must never be shipped the LABELS of things they may not see, which
 * is what a `{state: 'hidden'}` entry would be: authorization's clothes on a
 * disclosure bug.
 */
export interface RecordSectionVerdict {
  state: 'read-only' | 'editable';
  /** Null when `editable` — there is nothing to explain about a permitted write. */
  denial: RecordSectionDenial | null;
}

/**
 * The server's verdicts for a record's regions, keyed by region.
 *
 * A KEY THAT IS NOT HERE IS HIDDEN. That is the whole contract, and it is what
 * makes the absent-not-suppressed rule checkable: the same server decision that
 * leaves a key out of this map is the one that leaves the region's data out of
 * the record payload, so a hidden region has nothing to render FROM even if a
 * screen tried.
 *
 * A payload with NO verdicts at all resolves every region to hidden — fail
 * closed, matching the way `can()` answers while capabilities are in flight. A
 * screen asking for server-resolved regions and getting no answer has not been
 * told it may show them.
 */
export type RecordSectionVerdicts = Readonly<Record<string, RecordSectionVerdict>>;

/**
 * One region of a record page, and what this caller may do to it.
 *
 * `editor` and `readOnly` are BOTH required, for the reason {@link RecordMain}
 * gives at page scope: a read-only rendering is a different rendering, not the
 * editable one wearing `disabled`, and demanding both makes the greyed-out form
 * impossible to ship by omission. A `hidden` region renders neither.
 *
 * `key` is the region's stable identifier AND the key the server keys its
 * verdict by. One string, named once — a screen whose region key and server
 * verdict key can drift is a screen with a gate that quietly stops gating.
 */
export interface RecordSectionSpec {
  key: string;
  title: string;
  description?: string;
  /** The resolved three-state access — see {@link RecordSectionAccess}. */
  access: RecordSectionAccess;
  /** Shown when `editable`. Typically a fragment of a `<form>`. */
  editor: ReactNode;
  /** Shown when `read-only`. Typically a `<dl>`. */
  readOnly: ReactNode;
}

/**
 * A supplementary resource a record page hangs off the side — who holds this
 * role, which tenants this person is in, what has happened to it.
 *
 * `'forbidden'` is a first-class state, not an error. A record page is assembled
 * from several endpoints with DIFFERENT permission gates (`audit:read` is not
 * `users:read`), and a caller who lacks one of them should see the panel ABSENT,
 * not a red box explaining a capability their operator deliberately withheld.
 */
export type RecordResource<T> =
  | { status: 'loading' }
  | { status: 'forbidden' }
  | {
      status: 'error';
      /** The screen's own translated copy — what a side panel shows. */
      message: string;
      /**
       * The underlying failure's message, when it carried one. Shown where it
       * genuinely helps ("Role not found" on the record's own error page) and
       * withheld where it does not (a side panel, which would otherwise print
       * raw backend text next to its title).
       */
      detail: string | null;
    }
  | { status: 'ready'; value: T };

/** The back affordance every record page carries: a label and where it goes. */
export interface RecordBack {
  label: string;
  onBack: () => void;
}

/**
 * The record's own fields, in the TWO renderings a record page needs.
 *
 * Both are required, and that is the point. "Read-only" on this platform is not
 * a form with `disabled` on every input — it is a different rendering (a
 * description list) fed from a different source (the record's own values rather
 * than the whole catalogue of what it could be). Asking for both makes the
 * greyed-out form impossible to ship by omission.
 */
export interface RecordMain {
  /** Shown when {@link RecordAccess.editable}. Typically a `<form>`. */
  editor: ReactNode;
  /** Shown otherwise. Typically a `<dl>`. */
  readOnly: ReactNode;
}

/**
 * The props every record page passes, whichever body shape it uses.
 *
 * The body itself — one page-level `main` or a list of independently gated
 * `sections` — is the discriminated half, {@link RecordPageBody}.
 */
export interface RecordPageShellBaseProps<TFields extends object> {
  /**
   * Prefix for every `data-testid` the shell emits (`user-record` yields
   * `user-record`, `user-record-stat-tenant`, `user-record-readonly-notice`, …).
   */
  testId: string;
  /** The record as the SERVER describes it. */
  fields: TFields;
  /** What the page says about it — see {@link RecordFactsFn}. */
  facts: RecordFactsFn<TFields>;
  /**
   * The screen's translator, forwarded to `facts`. Optional: omitted, keys
   * render as their own literals, which is what a host with no i18n runtime
   * (the Vite harness) already gets everywhere else in this package.
   */
  t?: RecordTranslate;
  back: RecordBack;
  /** Icon shown beside the title. */
  icon?: ReactNode;
  /**
   * Header controls (save, discard, …). Rendered ONLY when the record is
   * editable: an action bar above a read-only page promises an edit that the
   * page has already explained is not available.
   */
  actions?: ReactNode;
  /**
   * Alerts between the header and the stat strip — the blast radius of an edit,
   * a deprecation, a warning about this particular record. Stated BEFORE the
   * edit rather than after it.
   */
  notices?: ReactNode;
  /** Related collections, in the side column. */
  side?: ReactNode;
  className?: string;
}

/**
 * The two ways a record page can express its body, as an EITHER/OR.
 *
 *  - `{access, main}` — the page-level binary #897 shipped. One gate for the
 *    whole record, two renderings, the shell picks. Still correct for a record
 *    whose fields are governed as one thing, and every screen written before
 *    #910 keeps compiling unchanged.
 *  - `{sections}` — regions, each with its own three-state access, each
 *    resolved by the SERVER. No page-level `access`: with regions there is no
 *    page-level answer to give that would not be a second opinion about the same
 *    question. Whether the header's actions render is DERIVED (at least one
 *    section is editable) rather than stated, so the action bar and the regions
 *    beneath it cannot disagree.
 *
 * A union rather than two optional props, so "neither" and "both" — the states
 * whose meaning nobody could name — do not typecheck.
 */
export type RecordPageBody =
  | {
      /** What this caller may do to the record — see {@link RecordAccess}. */
      access: RecordAccess;
      /** The record's own fields, in both renderings. */
      main: RecordMain;
      sections?: never;
    }
  | {
      /** The record's regions, in the order they should read. */
      sections: readonly RecordSectionSpec[];
      access?: never;
      main?: never;
    };

export type RecordPageShellProps<TFields extends object> = RecordPageShellBaseProps<TFields> &
  RecordPageBody;

/**
 * COMPILE-TIME REGRESSION TEST for the #895 guard.
 *
 * A guard that silently stops guarding is worse than none, and this one is a
 * conditional type — the kind of thing a later refactor loosens without noticing
 * because nothing at runtime changes. These two aliases fail to compile if it
 * ever does: the first proves a clean fields type still yields a callable
 * projection, the second proves a fields type carrying `manageable` yields the
 * error type instead. Types only, so they cost nothing at runtime, and they live
 * in the file they protect rather than in a test that could be deleted
 * separately from it.
 */
type Assert<T extends true> = T;

type _CleanFieldsStayProjectable = Assert<
  RecordFactsFn<{ name: string; global: boolean }> extends RecordProjection<{
    name: string;
    global: boolean;
  }>
    ? true
    : false
>;

type _CallerFlagIsRejected = Assert<
  RecordFactsFn<{
    name: string;
    manageable: boolean;
  }> extends CallerFlagInRecordFields<'manageable'>
    ? true
    : false
>;

// Referenced so a `noUnusedLocals` build does not strip the proof it exists for.
export type RecordFieldsGuardProof = [_CleanFieldsStayProjectable, _CallerFlagIsRejected];
