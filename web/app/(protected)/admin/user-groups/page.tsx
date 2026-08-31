'use client';

/**
 * User groups — the named rules that say which people a set contains
 * (#1015, over #999's engine).
 *
 * WHY THIS SCREEN HAD TO EXIST BEFORE THE PICKER WAS WORTH ANYTHING
 * ----------------------------------------------------------------
 * #999 shipped the whole group engine with no surface at all: no screen, no nav
 * entry, no way to make a group except a hand-written API call. So a route
 * composer that can name a group would, on any real install, open onto an empty
 * list — and "you have no groups" is not a thing an author can act on when there
 * is nowhere to make one.
 *
 * A GROUP IS A RULE. THIS SCREEN NEVER PRETENDS OTHERWISE
 * ------------------------------------------------------
 * There is no member list here, no "add person to group" button, and no member
 * count on a row. Those absences are the design, not gaps:
 *
 *  - THE ROW HAS NO COUNT because rendering one would resolve every rule on
 *    every render — forty groups, forty fan-out queries, to decorate a screen on
 *    which nobody asked a membership question. Resolution is live and uncached
 *    on purpose, so the way to keep it affordable is to resolve when somebody
 *    ASKS. Asking is the preview.
 *  - THERE IS NO ROSTER because a screen that renders 1,043 rows has rebuilt the
 *    exact problem groups exist to remove. `/preview` answers with a count and a
 *    bounded sample, it takes no page parameter, and none is coming.
 *  - MEMBERSHIP IS NEVER STORED. There is deliberately no `user_group_members`
 *    table: a saved list is wrong the moment somebody is hired. Every number on
 *    this screen is therefore a snapshot with the word "now" attached to it.
 *
 * WHAT A GROUP MAY BE DEFINED AS COMES FROM THE SERVER
 * ---------------------------------------------------
 * The kind picker is filled from `GET /api/v1/group-rules`, which is NOT the
 * same list a route step chooses from: it is the subset that can answer without
 * a document, so it excludes `group` itself. That is what makes a group of
 * groups impossible rather than merely discouraged, and reading the right list
 * means this client never has to know the rule.
 *
 * PREVIEW BEFORE SAVE, AND IT IS THE TIGHTER PERMISSION
 * ----------------------------------------------------
 * The dialog previews the rule being COMPOSED, through
 * `POST /api/v1/user-groups/preview` — the point of the whole preview contract:
 * an author writing "everyone holding the instructor role" needs to know they
 * wrote what they meant before committing, and a preview that only worked on
 * saved groups would make them save a wrong one to find out. That endpoint is
 * gated on `groups:write` rather than `groups:read`, deliberately, because it
 * resolves an arbitrary caller-composed rule.
 */

import { useCallback, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { GROUPS_WRITE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import {
  DataTable,
  dataTableQueryString,
  useDataTableQuery,
  type DataTableColumn,
} from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import {
  AudiencePeoplePicker,
  type AudiencePersonOption,
} from '@amroksaleh/ui/audience-people-picker';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import {
  EXPLICIT_KIND,
  configuredProfileIds,
  configuredRoleId,
  isRoleConfiguredKind,
} from '@/components/documents/routing-wire';

/** A group as `GET /api/v1/user-groups` renders it. Definition only — no members. */
interface UserGroup {
  id: number;
  name: string;
  description: string | null;
  rule_kind: string;
  rule_config: Record<string, unknown>;
}

/** One entry from `GET /api/v1/group-rules`. */
interface GroupRule {
  kind: string;
  label: string;
  source: string;
}

interface RoleOption {
  id: number;
  name: string;
}

/** What a composed rule resolves to right now. */
interface DraftPreview {
  total: number;
  truncated: boolean;
  sample: { profile_id: number; display_name: string | null }[];
}

type ApiClient = ReturnType<typeof useAuth>['apiClient'];
type AddToast = ReturnType<typeof useToast>['addToast'];

export default function UserGroupsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canWrite = hasPermission(GROUPS_WRITE);

  /**
   * Page, sort and search belong to the SERVER (#1102).
   *
   * This screen used to walk every page of `/user-groups` with `fetchAllPages`
   * and hand the whole set to the table, which then sorted, filtered and paged
   * it in the browser. That was honest about completeness — a partial walk threw
   * rather than rendering a short list as whole — but it paid for it with one
   * request per hundred groups on every visit, and the "complete" it was
   * defending only ever mattered because the sorting and searching were local.
   * With the server doing all three, one request answers the question actually
   * on screen, and the row count in the footer is the server's own.
   *
   * `ruleLabel` → `rule` is the one rename: the column renders a LOCALISED label
   * resolved from `/api/v1/group-rules`, while the endpoint can only order by
   * the `rule_kind` slug behind it — which still groups every row that renders
   * the same label together. `name` needs no entry, and `description` is not
   * sortable at all because `UserGroupRepository::listSpec()` offers no key for
   * it; it IS searchable, which is why the search box finds a group by its
   * description even though the header will not sort by it.
   */
  const query = useDataTableQuery({ sortKeys: { ruleLabel: 'rule' } });
  const queryString = dataTableQueryString(query.request);

  const groups = useFetch(async () => {
    const response = await apiClient(`/api/v1/user-groups?${queryString}`);
    if (!response.ok) {
      throw new Error(
        t('userGroups.error.load', 'The user groups could not be loaded.')
      );
    }
    const body = (await response.json()) as {
      data: UserGroup[];
      pagination?: { total: number; totalPages: number };
    };
    const items = body.data ?? [];
    return {
      items,
      total: body.pagination?.total ?? items.length,
      totalPages: body.pagination?.totalPages ?? 1,
    };
  }, [apiClient, queryString]);

  /** What a group's definition may name. NOT the route-step list — see the docblock. */
  const rules = useFetch(async () => {
    const response = await apiClient('/api/v1/group-rules');
    if (!response.ok) {
      throw new Error(t('userGroups.error.rules', 'The rule kinds could not be loaded.'));
    }
    const body = (await response.json()) as { data: GroupRule[] };
    return body.data;
  }, [apiClient]);

  const roles = useFetch(async () => {
    const all = await fetchAllPages<RoleOption>(apiClient, '/api/v1/roles');
    return { items: all.items, complete: all.complete };
  }, [apiClient]);

  const people = useFetch(async () => {
    const all = await fetchAllPages<{ id: number; name: string; email?: string | null }>(
      apiClient,
      '/api/v1/users'
    );
    return {
      items: all.items.map((person) => ({
        id: person.id,
        name: person.name !== '' ? person.name : String(person.id),
        secondary: person.email ?? null,
      })),
      complete: all.complete,
    };
  }, [apiClient]);

  const [editing, setEditing] = useState<UserGroup | 'new' | null>(null);
  const [deleting, setDeleting] = useState<UserGroup | null>(null);

  const ruleLabel = useCallback(
    (kind: string): string => {
      const rule = (rules.data ?? []).find((entry) => entry.kind === kind);
      // The kind itself when nothing describes it — a group defined by a plugin
      // that has since been uninstalled is a real state, and its row must still
      // render rather than showing a blank where the rule was.
      return rule?.label ?? kind;
    },
    [rules.data]
  );

  const rows = (groups.data?.items ?? []).map((group) => ({
    ...group,
    ruleLabel: ruleLabel(group.rule_kind),
  }));
  type Row = (typeof rows)[number];

  const columns: DataTableColumn<Row>[] = [
    {
      // The per-column filter box is gone, and its absence is the same fix as
      // the rest of this change: a column filter is applied by the table to the
      // rows it holds, which is now ONE page, so it would hide matches on the
      // page and leave every other match where it was. The search box above the
      // table asks the server, across name and description both.
      accessorKey: 'name',
      header: t('userGroups.table.name', 'Name'),
      enableSorting: true,
      cell: (group) => <span className="font-medium">{group.name}</span>,
    },
    {
      accessorKey: 'ruleLabel',
      header: t('userGroups.table.rule', 'Defined as'),
      enableSorting: true,
    },
    {
      accessorKey: 'description',
      header: t('userGroups.table.description', 'Description'),
      cell: (group) => (
        <span className="text-muted-foreground">{group.description ?? '—'}</span>
      ),
    },
  ];

  const rowActions = (group: Row) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('userGroups.rowActions.label', 'Actions for {name}', { name: group.name })}
        >
          <IconMenu2 size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => setEditing(group)}>
          {t('userGroups.rowActions.edit', 'Edit')}
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => setDeleting(group)}
          className="text-destructive focus:text-destructive"
        >
          {t('userGroups.rowActions.delete', 'Delete')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('userGroups.title', 'User Groups')}
        description={t(
          'userGroups.description',
          'A group is a named RULE — "everyone holding the instructor role" — not a saved list of people. Who it contains is worked out afresh every time it is used, so a group keeps up with the organisation on its own.'
        )}
        action={
          canWrite ? (
            <Button onClick={() => setEditing('new')} className="gap-2">
              <IconPlus size={18} />
              {t('userGroups.createButton', 'Define a group')}
            </Button>
          ) : undefined
        }
      />

      {groups.error !== null ? (
        <p className="text-sm text-destructive">{groups.error}</p>
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          getRowId={(group) => String(group.id)}
          rowActions={canWrite ? rowActions : undefined}
          // The skeleton is for the FIRST load only. DataTable's loading branch
          // replaces the whole table, search box included, so showing it on
          // every request would unmount the search input mid-word and take the
          // caret with it. `data` is null until the first response lands and
          // non-null forever after.
          isLoading={groups.loading && groups.data === null}
          globalFilterPlaceholder={t('userGroups.searchPlaceholder', 'Search user groups…')}
          sorting={query.sorting}
          search={query.search}
          pagination={query.pagination({
            total: groups.data?.total ?? 0,
            totalPages: groups.data?.totalPages ?? 1,
          })}
        />
      )}

      {editing !== null && (
        <UserGroupDialog
          group={editing === 'new' ? null : editing}
          rules={rules.data ?? []}
          roles={roles.data?.items ?? []}
          rolesComplete={roles.data?.complete ?? false}
          people={people.data?.items ?? []}
          peopleComplete={people.data?.complete ?? false}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            groups.refetch();
          }}
        />
      )}

      {deleting !== null && (
        <DeleteUserGroupDialog
          group={deleting}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            groups.refetch();
          }}
        />
      )}
    </div>
  );
}

/**
 * Define or redefine a group.
 *
 * ONE dialog for both, unlike the tag-groups screen's create-only one, because a
 * group has no record page to send anybody to and nothing about editing needs
 * more room than creating does — the same three fields either way.
 *
 * A redefinition takes effect IMMEDIATELY for everything naming the group,
 * including routes already in flight. That is the intended reading — the group
 * means what it now says — and the dialog says so out loud when editing, because
 * it is the one consequence somebody could not guess from the form.
 */
function UserGroupDialog({
  group,
  rules,
  roles,
  rolesComplete,
  people,
  peopleComplete,
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  group: UserGroup | null;
  rules: GroupRule[];
  roles: RoleOption[];
  rolesComplete: boolean;
  people: AudiencePersonOption[];
  peopleComplete: boolean;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const [name, setName] = useState(group?.name ?? '');
  const [description, setDescription] = useState(group?.description ?? '');
  const [kind, setKind] = useState(group?.rule_kind ?? (rules[0]?.kind ?? ''));
  const [config, setConfig] = useState<Record<string, unknown>>(group?.rule_config ?? {});
  const [submitting, setSubmitting] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [preview, setPreview] = useState<DraftPreview | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  const selectedRoleId = configuredRoleId(config);
  const selectedProfileIds = configuredProfileIds(config);

  /** Whether the rule is filled in enough to be worth sending anywhere. */
  const configured =
    (isRoleConfiguredKind(kind) && selectedRoleId !== null) ||
    (kind === EXPLICIT_KIND && selectedProfileIds.length > 0) ||
    // A kind this client does not author — a plugin's. Its own validator is the
    // authority, and refusing to send would block a rule the server accepts.
    (!isRoleConfiguredKind(kind) && kind !== EXPLICIT_KIND && kind !== '');

  const runPreview = async (): Promise<void> => {
    setPreviewing(true);
    setPreviewError(null);
    try {
      const response = await apiClient('/api/v1/user-groups/preview', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rule_kind: kind, rule_config: config }),
      });
      const body = (await response.json().catch(() => null)) as
        | { error?: string; data?: DraftPreview }
        | null;
      const data = body?.data;
      // `== null`, not `=== undefined`: an explicit null payload is not a
      // preview either, and reading a count off it would take the dialog down.
      if (!response.ok || data == null) {
        setPreview(null);
        // Verbatim — a plugin's own validator wrote some of these.
        setPreviewError(
          body?.error ?? t('userGroups.preview.error', 'This rule could not be resolved.')
        );
        return;
      }
      setPreview(data);
    } catch {
      setPreview(null);
      setPreviewError(t('userGroups.preview.networkError', 'This rule could not be resolved.'));
    } finally {
      setPreviewing(false);
    }
  };

  const submit = async (): Promise<void> => {
    setSubmitting(true);
    setRefusal(null);
    try {
      const response = await apiClient(
        group === null ? '/api/v1/user-groups' : `/api/v1/user-groups/${group.id}`,
        {
          method: group === null ? 'POST' : 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: name.trim(),
            description: description.trim() === '' ? null : description.trim(),
            rule_kind: kind,
            rule_config: config,
          }),
        }
      );
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      if (!response.ok) {
        // Verbatim: the server's 409 names the group that already has the name,
        // and its 422 quotes whichever resolver refused the definition.
        setRefusal(body?.error ?? t('userGroups.form.error', 'The group could not be saved.'));
        return;
      }
      addToast(
        group === null
          ? t('userGroups.form.created', 'Group defined.')
          : t('userGroups.form.updated', 'Group redefined.'),
        'success'
      );
      onSaved();
    } catch {
      setRefusal(t('userGroups.form.networkError', 'The group could not be saved.'));
    } finally {
      setSubmitting(false);
    }
  };

  const blockedReason =
    name.trim() === ''
      ? t('userGroups.form.blocked.name', 'A group needs a name.')
      : kind === ''
        ? t('userGroups.form.blocked.kind', 'A group needs a rule that says who is in it.')
        : !configured
          ? t(
              'userGroups.form.blocked.config',
              'The rule still needs its setting — without it the group would name nobody.'
            )
          : null;

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {group === null
              ? t('userGroups.form.createTitle', 'Define a user group')
              : t('userGroups.form.editTitle', 'Redefine “{name}”', { name: group.name })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'userGroups.form.description',
              'Give the rule a name people will recognise. The rule itself is what decides who is in the group, and it is applied afresh every time the group is used.'
            )}
          </DialogDescription>
        </DialogHeader>

        {group !== null && (
          <Alert>
            <AlertDescription>
              {t(
                'userGroups.form.liveWarning',
                'This group is used by name. Changing its rule changes who it reaches everywhere it is named, including circulations already under way — what each step actually reached stays recorded in the document’s trail.'
              )}
            </AlertDescription>
          </Alert>
        )}

        <div className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="user-group-name" className="text-sm font-medium">
              {t('userGroups.form.name.label', 'Name')}
            </label>
            <Input
              id="user-group-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              maxLength={160}
              placeholder={t('userGroups.form.name.placeholder', 'e.g. Instructors')}
              autoComplete="off"
            />
          </div>

          <div className="space-y-1.5">
            <label htmlFor="user-group-description" className="text-sm font-medium">
              {t('userGroups.form.description.label', 'Description (optional)')}
            </label>
            <Input
              id="user-group-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              maxLength={1000}
              placeholder={t(
                'userGroups.form.description.placeholder',
                'What this group is for, in a sentence.'
              )}
              autoComplete="off"
            />
          </div>

          <div className="space-y-1.5">
            <label htmlFor="user-group-kind" className="text-sm font-medium">
              {t('userGroups.form.kind.label', 'Who is in it')}
            </label>
            {rules.length === 0 ? (
              <p className="text-xs text-muted-foreground">
                {t(
                  'userGroups.form.kind.none',
                  'This installation has no rule kinds a group can be defined as.'
                )}
              </p>
            ) : (
              <Select
                value={kind === '' ? undefined : kind}
                onValueChange={(next) => {
                  // The config belonged to the OLD kind and means nothing to the
                  // new one — the server refuses the pair for the same reason.
                  setKind(next);
                  setConfig({});
                  setPreview(null);
                  setPreviewError(null);
                }}
              >
                <SelectTrigger id="user-group-kind">
                  <SelectValue
                    placeholder={t('userGroups.form.kind.placeholder', 'Choose a rule')}
                  />
                </SelectTrigger>
                <SelectContent>
                  {rules.map((rule) => (
                    <SelectItem key={rule.kind} value={rule.kind}>
                      {rule.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>

          {isRoleConfiguredKind(kind) && (
            <div className="space-y-1.5">
              <label htmlFor="user-group-role" className="text-sm font-medium">
                {t('userGroups.form.role.label', 'Role')}
              </label>
              {roles.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  {t('userGroups.form.role.none', 'No roles are available to name.')}
                </p>
              ) : (
                <>
                  <Select
                    value={selectedRoleId === null ? undefined : String(selectedRoleId)}
                    onValueChange={(value) => {
                      setConfig({ role_id: Number(value) });
                      setPreview(null);
                      setPreviewError(null);
                    }}
                  >
                    <SelectTrigger id="user-group-role">
                      <SelectValue
                        placeholder={t('userGroups.form.role.placeholder', 'Choose a role')}
                      />
                    </SelectTrigger>
                    <SelectContent>
                      {roles.map((role) => (
                        <SelectItem key={role.id} value={String(role.id)}>
                          {role.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {!rolesComplete && (
                    <p className="text-xs text-muted-foreground">
                      {t(
                        'userGroups.form.role.partial',
                        'Only some roles could be loaded, so this list may be incomplete.'
                      )}
                    </p>
                  )}
                </>
              )}
            </div>
          )}

          {kind === EXPLICIT_KIND && (
            <div className="space-y-1.5">
              <label htmlFor="user-group-people" className="text-sm font-medium">
                {t('userGroups.form.people.label', 'People')}
              </label>
              <p className="text-xs text-muted-foreground">
                {t(
                  'userGroups.form.people.help',
                  'This group means exactly these people. It will not pick up somebody who joins later — which is the point when a committee is a committee, and the wrong tool when it is a job title.'
                )}
              </p>
              <AudiencePeoplePicker
                id="user-group-people"
                people={people}
                value={selectedProfileIds}
                onChange={(profileIds) => {
                  setConfig(profileIds.length === 0 ? {} : { profile_ids: profileIds });
                  setPreview(null);
                  setPreviewError(null);
                }}
                incompleteReason={
                  peopleComplete
                    ? null
                    : t(
                        'userGroups.form.people.partial',
                        'Only some people could be loaded, so this list may be incomplete.'
                      )
                }
                searchPlaceholder={t('userGroups.form.people.search', 'Search people by name')}
                emptyLabel={t('userGroups.form.people.none', 'There is nobody here to name.')}
                nothingSelectedLabel={t(
                  'userGroups.form.people.nothingSelected',
                  'Nobody chosen yet.'
                )}
                noMatchesLabel={t('userGroups.form.people.noMatches', 'Nobody matches that.')}
                moreMatchesLabel={(shown, total) =>
                  t(
                    'userGroups.form.people.moreMatches',
                    'Showing {shown} of {total} matches — keep typing to narrow it down.',
                    { shown, total }
                  )
                }
                removeLabel={(personName) =>
                  t('userGroups.form.people.remove', 'Remove {name}', { name: personName })
                }
                unknownPersonLabel={(profileId) =>
                  t('userGroups.form.people.unknown', 'Profile #{id}', { id: profileId })
                }
              />
            </div>
          )}

          {/*
            The preview is the point of the whole contract: know what the rule
            means BEFORE saving it, not by saving a wrong one and finding out.
          */}
          <div className="space-y-2 rounded-md border border-border p-2">
            <div className="flex flex-wrap items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!configured || previewing}
                onClick={() => void runPreview()}
              >
                {previewing
                  ? t('userGroups.preview.working', 'Working it out…')
                  : t('userGroups.preview.run', 'Who is in this right now?')}
              </Button>
              {!configured && (
                <span className="text-xs text-muted-foreground">
                  {t(
                    'userGroups.preview.needsRule',
                    'Finish the rule and this will say who it reaches.'
                  )}
                </span>
              )}
            </div>

            {previewError !== null && (
              <p className="text-xs text-destructive">{previewError}</p>
            )}

            {preview !== null && (
              <div className="space-y-1" data-slot="user-group-preview">
                <p className="text-xs font-medium">
                  {preview.total === 0
                    ? t(
                        'userGroups.preview.nobody',
                        'This rule resolves to nobody right now. A group defined like this would reach no one.'
                      )
                    : t('userGroups.preview.count', 'Reaches {count} people right now.', {
                        count: preview.total,
                      })}
                </p>
                {preview.total > 0 && (
                  <>
                    <p className="text-xs text-muted-foreground">
                      {preview.truncated
                        ? t(
                            'userGroups.preview.sample',
                            'Showing {shown} of the {total} — a sample, not the whole set:',
                            { shown: preview.sample.length, total: preview.total }
                          )
                        : t('userGroups.preview.all', 'That is everybody:')}
                    </p>
                    <ul className="flex flex-wrap gap-1">
                      {preview.sample.map((member) => (
                        <li key={member.profile_id}>
                          <Badge variant="secondary">
                            {member.display_name ??
                              t('userGroups.preview.unnamed', 'Profile #{id}', {
                                id: member.profile_id,
                              })}
                          </Badge>
                        </li>
                      ))}
                    </ul>
                  </>
                )}
                <p className="text-xs text-muted-foreground">
                  {t(
                    'userGroups.preview.dynamic',
                    'A group is a rule, not a saved list of people. Who it reaches is worked out again every time it is used, so this is what it means right now — not a set that has been fixed in place.'
                  )}
                </p>
              </div>
            )}
          </div>

          {refusal !== null && (
            <Alert variant="destructive" data-slot="user-group-refusal">
              <AlertDescription>{refusal}</AlertDescription>
            </Alert>
          )}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('userGroups.form.cancel', 'Cancel')}
          </Button>
          {/* Disabled with its reason, never hidden (#951). */}
          <div className="flex flex-col items-end">
            <span className="inline-flex" title={blockedReason ?? undefined}>
              <Button
                type="button"
                disabled={submitting || blockedReason !== null}
                aria-disabled={submitting || blockedReason !== null}
                onClick={blockedReason !== null ? undefined : () => void submit()}
                data-slot="user-group-save"
              >
                {submitting
                  ? t('userGroups.form.saving', 'Saving…')
                  : t('userGroups.form.save', 'Save')}
              </Button>
              {blockedReason !== null && (
                <span className="sr-only" role="note">
                  {blockedReason}
                </span>
              )}
            </span>
            {blockedReason !== null && (
              <p className="mt-1 max-w-xs text-xs text-muted-foreground">{blockedReason}</p>
            )}
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Delete a group.
 *
 * The confirmation names the real consequence, which is NOT "the group's members
 * lose access" — a group has no members and grants nothing. It is that a route
 * step naming this group will fail by name when it is reached, possibly weeks
 * later. Deletion is deliberately not blocked by references: failing loudly beats
 * both alternatives, since silently resolving to nobody would drop a whole class
 * of people from a distribution and still report success.
 */
function DeleteUserGroupDialog({
  group,
  apiClient,
  addToast,
  onClose,
  onDeleted,
}: {
  group: UserGroup;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const t = useTranslation('admin');
  const [submitting, setSubmitting] = useState(false);

  const confirm = async (): Promise<void> => {
    setSubmitting(true);
    try {
      const response = await apiClient(`/api/v1/user-groups/${group.id}`, { method: 'DELETE' });
      if (!response.ok) {
        throw new Error('Delete failed');
      }
      addToast(t('userGroups.delete.success', 'Group deleted.'), 'success');
      onDeleted();
    } catch {
      addToast(t('userGroups.delete.error', 'The group could not be deleted.'), 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t('userGroups.delete.title', 'Delete “{name}”?', { name: group.name })}
          </DialogTitle>
          <DialogDescription>
            {t(
              'userGroups.delete.description',
              'Nobody loses anything — a group holds no permissions and no people. But any route step that names this group will stop and say so when it is reached, which may be long after today.'
            )}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('userGroups.delete.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={() => void confirm()}
            disabled={submitting}
          >
            {submitting
              ? t('userGroups.delete.pending', 'Deleting…')
              : t('userGroups.delete.confirm', 'Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
