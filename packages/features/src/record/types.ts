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
 */
export type RecordProjection<TFields> = (
  fields: TFields,
  t: RecordTranslate
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

export interface RecordPageShellProps<TFields extends object> {
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
  /** What this caller may do to it — see {@link RecordAccess}. */
  access: RecordAccess;
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
  /** The record's own fields, in both renderings. */
  main: RecordMain;
  /** Related collections, in the side column. */
  side?: ReactNode;
  className?: string;
}

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
  RecordFactsFn<{ name: string; manageable: boolean }> extends CallerFlagInRecordFields<'manageable'>
    ? true
    : false
>;

// Referenced so a `noUnusedLocals` build does not strip the proof it exists for.
export type RecordFieldsGuardProof = [_CleanFieldsStayProjectable, _CallerFlagIsRejected];
