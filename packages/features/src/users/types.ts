/**
 * Data contract for the user RECORD page (#882) — the record-page shell's second
 * consumer, and the screen that finally reads what #889/#890 wrote.
 *
 * Same shape as the roles slice: the components here never fetch directly, every
 * data access goes through a caller-injected {@link UsersAdapter}, and the
 * adapter IMPLEMENTATIONS live outside this package (`web/lib/users-adapter.ts`
 * wires it to the cookie-authenticated REST API; a desktop client would wire the
 * same interface to `invoke('remote_request', …)`). This file defines only the
 * shapes.
 *
 * NOTE ON SCOPE. The users LIST and its create/edit/delete/memberships modals
 * still live in `web/app/(protected)/admin/users/` and still work. This slice is
 * additive: it adds the record PAGE, exactly the way #885 added the role record
 * page without removing the role edit modal.
 */

/**
 * One person, as `GET /api/users/{id}` describes them in the caller's tenant.
 *
 * `id` is the canonical `profile_id`. `name` has no column behind it — the
 * server derives it from the email's local part — which is why the record page
 * presents it as read-only rather than as an editable field that silently
 * discards what you type.
 */
export interface UserRecord {
  id: number;
  name: string;
  email: string;
  /** The PRIMARY role held in this tenant. */
  role: string;
  tenantId: number;
  ouId: number | null;
  createdAt: string | null;
  /**
   * The PER-TENANT membership lifecycle: `active` | `invited` | `suspended`.
   *
   * Deliberately kept distinct from {@link UserRecord.accountStatus} rather than
   * folded into one "Status" — they answer different questions, and a page that
   * collapsed them would say "Active" about somebody whose account is switched
   * off globally. Same doctrine as #895 one level down: state what the server
   * said, do not infer one fact from a neighbouring one.
   */
  status: string;
  /** The GLOBAL account switch (`profiles.status`): `active` | `inactive`. */
  accountStatus: string;
}

/** One tenant this person belongs to, and the role they hold there. */
export interface UserMembership {
  id: number;
  tenantId: number;
  tenantName: string;
  roleId: number;
  role: string;
  ouId: number | null;
  isPrimary: boolean;
  status: string;
}

/**
 * One audit entry targeted at this person, from
 * `GET /audit-logs?target_type=user&target_id=…`.
 *
 * Since #889/#890 that filter returns a person's COMPLETE authority history in
 * one query — `user.created`/`updated`/`deleted` plus `user.membership.added`
 * and `user.membership.removed`. `metadata` is what makes a revocation readable
 * after the fact: the membership row is DELETED, so the audit row is the only
 * surviving record of which role was taken away and how long it was held.
 */
export interface UserActivityEntry {
  id: number;
  action: string;
  actorUserId: number | null;
  createdAt: string | null;
  metadata: Record<string, unknown>;
}

/**
 * `audit:read` is a SEPARATE permission from user administration, so
 * `'forbidden'` is an EXPECTED refusal the UI renders as an absent panel — the
 * same sentinel shape the roles slice uses, and the same reason.
 */
export type UserActivityResult = UserActivityEntry[] | 'forbidden';

/** One selectable organisational unit. */
export interface OuOption {
  id: number;
  name: string;
}

/**
 * The OU picker's source, with the honesty flag the users modals established.
 *
 * `/api/ous` is paginated, and a SHORT list of units is indistinguishable from a
 * correct one — acting on it moves a real person into the wrong unit while the
 * operator believes the right one was never created. So a list that could not be
 * completed is reported as incomplete and the picker is withheld rather than
 * offered short.
 */
export interface OuOptionsResult {
  options: OuOption[];
  complete: boolean;
}

/** The fields `PATCH /api/users/{id}` accepts from this screen. */
export interface UserUpdateInput {
  /** The role NAME, which the server resolves to a tenant-visible role id. */
  role: string;
  /** Null clears the OU (root). */
  ouId: number | null;
}

/** The injected data-source adapter the user record screen consumes. */
export interface UsersAdapter {
  /** GET /users/{id} — one person, tenant-scoped (404 when not in this tenant). */
  getUser(id: number): Promise<UserRecord>;
  /** GET /users/{id}/memberships — every tenant + role, primary first. */
  listUserMemberships(id: number): Promise<UserMembership[]>;
  /**
   * GET /audit-logs?target_type=user&target_id={id} — this person's authority
   * history. Resolves `'forbidden'` when the caller lacks `audit:read`.
   */
  getUserActivity(id: number, limit?: number): Promise<UserActivityResult>;
  /** GET /roles — the role NAMES this tenant may assign. */
  listRoleNames(): Promise<string[]>;
  /** GET /ous — every page; see {@link OuOptionsResult}. */
  listOus(): Promise<OuOptionsResult>;
  /** PATCH /users/{id}; a 404 maps to 'not-found' rather than a raw failure. */
  updateUser(id: number, input: UserUpdateInput): Promise<'ok' | 'not-found'>;
  /**
   * POST /users/{id}/password-reset — mails a one-time LINK.
   *
   * Never a password: the link path already invalidates every existing session,
   * already rate-limits and already audits, and it never puts a plaintext
   * password in an administrator's hands.
   */
  sendPasswordResetLink(id: number): Promise<void>;
  /** GET /me/capabilities — the caller's effective permission slugs. */
  getCapabilities(): Promise<string[]>;
}

/**
 * The translation function this slice's components call.
 *
 * Structurally `TranslateFn`, named differently on purpose — see the note on
 * `RolesTranslate`: the catalogue extractor binds a `TranslateFn`-typed
 * parameter as a translate call and then needs a `useTranslation('domain')` in
 * the file, which these prop-driven components do not have. Keys are declared in
 * file-scoped `@i18n-keys` blocks instead.
 */
export type UsersTranslate = (
  key: string,
  fallback?: string,
  vars?: Record<string, string | number>
) => string;

export interface UserRecordScreenProps {
  /** Injected data-source adapter. */
  adapter: UsersAdapter;
  /** The person this page is about — the route's dynamic segment. */
  userId: number;
  /** Resolved, fail-closed capability check. */
  can: (capability: string) => boolean;
  /** Optional translator; defaults to identity (keys render as literals). */
  t?: UsersTranslate;
  /** Optional notifier; web wires ToastProvider. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
  /** Navigate back to the users list (host-supplied, same seam as the roles page). */
  onBack: () => void;
  className?: string;
}
