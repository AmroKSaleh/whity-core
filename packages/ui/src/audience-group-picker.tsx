"use client"

import { cn } from "./utils"
import { Badge } from "./badge"
import { Button } from "./button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "./select"

/**
 * Naming ONE user group, and seeing who it currently means (#1015, over #999's
 * engine).
 *
 * WHY THIS IS A KIT PRIMITIVE AND NOT A SCREEN COMPONENT
 * -----------------------------------------------------
 * A route step that says "everyone in Instructors" is authored in at least two
 * places — the linear route composer and the node-based route template editor —
 * and both need the identical control for the identical reason. Authored inside
 * one client, the second surface either imports across an app boundary or grows
 * a copy, and the copy is where the two drift. `sidebar.tsx` is the cautionary
 * case in this repo: 427 lines of hand-rolled duplicate that cost nothing until
 * a capability had to be added twice.
 *
 * THE PREVIEW IS A SNAPSHOT, NOT A MEMBERSHIP LIST
 * -----------------------------------------------
 * There is deliberately no `user_group_members` table. A group is a RULE, and it
 * is re-resolved against the organisation every time it is reached — which is
 * the whole reason groups exist: the instructor hired next week is in
 * "Instructors" without anybody editing anything, and the one who left is not.
 *
 * That makes the preview a fact with a timestamp attached, and this component
 * has to say so or it teaches the wrong model. Three things do that work
 * together and none of them is decoration:
 *
 *  1. the heading is phrased in the present tense — "reaches N people right now",
 *     never "has N members";
 *  2. {@link AudienceGroupPickerProps.previewDynamicNote} states the rule
 *     outright, and is rendered with every preview rather than behind a
 *     disclosure, because a caveat nobody opens is a caveat nobody reads;
 *  3. the sample is labelled as a sample whenever it is one, from the server's
 *     own `truncated` flag rather than from `total > members.length` — a client
 *     that re-derived that and got it wrong would present ten people as the
 *     whole group, which is the single misreading this shape exists to prevent.
 *
 * IT FETCHES NOTHING, LIKE EVERY OTHER `@amroksaleh/ui` INPUT
 * ----------------------------------------------------------
 * Groups and the preview arrive as props. That is the kit's standing convention
 * ({@see TagInput}), and here it is also forced: the preview endpoint is
 * `GET /api/v1/user-groups/{id}/preview`, three clients reach it three different
 * ways, and a primitive that knew the URL could be reused by exactly one of
 * them. The consumer owns the request; this owns everything about presenting the
 * answer that is easy to get subtly wrong.
 *
 * NO TRANSLATOR IMPORT. Every user-visible string is a prop with an English
 * default — the kit ships to clients with three different i18n stacks, and one
 * of them has no runtime translator at all.
 */

/** One group a step may name. */
export interface AudienceGroupOption {
  id: number
  name: string
  /** The group's own one-line description, when it has one. */
  description?: string | null
}

/**
 * One person in a preview sample.
 *
 * `displayName` is nullable rather than optional-and-absent because the server
 * sends it that way on purpose: reading a person's NAME needs `users:read`,
 * which reading a group's DEFINITION does not imply, so a caller without it gets
 * the same payload shape with nulls where the names would be. One shape means a
 * client renders an id instead of branching on which flavour it received.
 */
export interface AudienceGroupPreviewMember {
  profileId: number
  displayName?: string | null
}

/** What a group resolves to at the moment it was asked. */
export interface AudienceGroupPreview {
  /** Exact, not an estimate: the server counts what the rule actually answered. */
  total: number
  /** The server's own flag. Never re-derive it — see the file docblock. */
  truncated: boolean
  /** The ceiling that produced the sample, so "that is everybody" is tellable. */
  sampleSize: number
  members: AudienceGroupPreviewMember[]
}

export type AudienceGroupPreviewStatus = "idle" | "loading" | "ready" | "error"

export interface AudienceGroupPickerProps {
  /** Id for the select, so a caller's own `<label htmlFor>` can address it. */
  id?: string
  /** The groups to choose from, already fetched by the caller. */
  groups: AudienceGroupOption[]
  /** The chosen group's id, or null when nothing is chosen yet. */
  value: number | null
  onChange: (groupId: number | null) => void
  /**
   * Why there is no list to show — a 403 on the group catalogue, a failed
   * request. Rendered as an explanation IN PLACE OF the select, never as an
   * empty dropdown: an empty picker reads as "this tenant has no groups", which
   * is a different and wrong statement.
   */
  unavailableReason?: string | null
  /**
   * Why the list, though populated, may not be all of them — a truncated
   * pagination walk. Rendered BESIDE the select, because the author can still
   * work; they just must not conclude a missing group does not exist.
   */
  incompleteReason?: string | null
  /** The chosen group's membership snapshot. */
  preview?: AudienceGroupPreview | null
  previewStatus?: AudienceGroupPreviewStatus
  /** The server's refusal, verbatim. */
  previewError?: string | null
  /** Rendered as a retry control only when supplied. */
  onRetryPreview?: () => void
  disabled?: boolean
  className?: string

  // -- labels, English by default ------------------------------------------

  /** Placeholder on the trigger before a group is chosen. */
  placeholder?: string
  /** Shown when the caller could read the catalogue and it is genuinely empty. */
  emptyLabel?: string
  /** While the preview request is in flight. */
  previewLoadingLabel?: string
  /**
   * The count sentence. A function, not a string: the number sits INSIDE the
   * phrase, languages put it in different places, and the caller is the only
   * party that knows how to format a number for its locale.
   */
  previewCountLabel?: (total: number) => string
  /** When the rule resolves to nobody at all — a fact, not an error. */
  previewEmptyLabel?: string
  /** Introduces the sample when it is only a sample. */
  previewSampleLabel?: (shown: number, total: number) => string
  /** Introduces the sample when the sample IS everybody. */
  previewAllLabel?: string
  /** The "a group is a rule, not a list" sentence. Always rendered. */
  previewDynamicNote?: string
  /** For a sample row whose name the reader may not see. */
  unnamedMemberLabel?: (profileId: number) => string
  previewRetryLabel?: string
}

/**
 * A group chooser with the membership snapshot attached.
 *
 * Controlled. `value` is the group id; `null` means nothing chosen, which is the
 * state a caller must treat as "this step is not configured yet".
 */
export function AudienceGroupPicker({
  id,
  groups,
  value,
  onChange,
  unavailableReason = null,
  incompleteReason = null,
  preview = null,
  previewStatus = "idle",
  previewError = null,
  onRetryPreview,
  disabled = false,
  className,
  placeholder = "Choose a user group",
  emptyLabel = "No user groups have been defined in this workspace yet.",
  previewLoadingLabel = "Working out who this group reaches…",
  previewCountLabel = (total: number) =>
    total === 1
      ? "Reaches 1 person right now."
      : `Reaches ${total} people right now.`,
  previewEmptyLabel =
    "This group resolves to nobody right now. A step naming it would reach no one.",
  previewSampleLabel = (shown: number, total: number) =>
    `Showing ${shown} of the ${total} — a sample, not the whole set:`,
  previewAllLabel = "That is everybody:",
  previewDynamicNote = "A group is a rule, not a saved list of people. Who it reaches is worked out again every time the document moves, so this is what it means right now — not a set that has been fixed in place.",
  unnamedMemberLabel = (profileId: number) => `Profile #${profileId}`,
  previewRetryLabel = "Try again",
}: AudienceGroupPickerProps) {
  // An explanation instead of an empty dropdown (#756). The caller's sentence is
  // rendered verbatim: it is the only party that knows WHY, and the server's own
  // 403 names the permission slug that is missing.
  if (unavailableReason !== null && unavailableReason !== "") {
    return (
      <p
        data-slot="audience-group-picker-unavailable"
        className={cn("text-xs text-muted-foreground", className)}
      >
        {unavailableReason}
      </p>
    )
  }

  if (groups.length === 0) {
    return (
      <p
        data-slot="audience-group-picker-empty"
        className={cn("text-xs text-muted-foreground", className)}
      >
        {emptyLabel}
      </p>
    )
  }

  const selected = groups.find((group) => group.id === value) ?? null

  return (
    <div
      data-slot="audience-group-picker"
      className={cn("space-y-2", className)}
    >
      <Select
        value={value === null ? undefined : String(value)}
        disabled={disabled}
        onValueChange={(next) => {
          const parsed = Number(next)
          onChange(Number.isInteger(parsed) && parsed > 0 ? parsed : null)
        }}
      >
        <SelectTrigger id={id} className="w-full">
          <SelectValue placeholder={placeholder} />
        </SelectTrigger>
        <SelectContent>
          {groups.map((group) => (
            <SelectItem key={group.id} value={String(group.id)}>
              {group.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {incompleteReason !== null && incompleteReason !== "" && (
        <p
          data-slot="audience-group-picker-incomplete"
          className="text-xs text-muted-foreground"
        >
          {incompleteReason}
        </p>
      )}

      {selected?.description !== undefined &&
        selected.description !== null &&
        selected.description !== "" && (
          <p
            data-slot="audience-group-picker-description"
            className="text-xs text-muted-foreground"
          >
            {selected.description}
          </p>
        )}

      {selected !== null && (
        <div
          data-slot="audience-group-picker-preview"
          // `text-start`, not `text-left`: the kit ships into Arabic UIs and a
          // physical direction would pin this block to the wrong edge there.
          className="rounded-md border border-border bg-muted/40 p-2 text-start"
          // Announced as it changes: choosing a group is the act, and the count
          // arriving afterwards is the answer to it.
          aria-live="polite"
        >
          {previewStatus === "loading" && (
            <p className="text-xs text-muted-foreground">
              {previewLoadingLabel}
            </p>
          )}

          {previewStatus === "error" && (
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-xs text-destructive">{previewError}</p>
              {onRetryPreview !== undefined && (
                <Button variant="outline" size="sm" onClick={onRetryPreview}>
                  {previewRetryLabel}
                </Button>
              )}
            </div>
          )}

          {previewStatus === "ready" && preview !== null && (
            <div className="space-y-1">
              <p className="text-xs font-medium text-foreground">
                {preview.total === 0
                  ? previewEmptyLabel
                  : previewCountLabel(preview.total)}
              </p>

              {preview.total > 0 && (
                <>
                  <p className="text-xs text-muted-foreground">
                    {preview.truncated
                      ? previewSampleLabel(preview.members.length, preview.total)
                      : previewAllLabel}
                  </p>
                  <ul className="flex flex-wrap gap-1">
                    {preview.members.map((member) => (
                      <li key={member.profileId}>
                        <Badge variant="secondary">
                          {member.displayName !== undefined &&
                          member.displayName !== null &&
                          member.displayName !== ""
                            ? member.displayName
                            : unnamedMemberLabel(member.profileId)}
                        </Badge>
                      </li>
                    ))}
                  </ul>
                </>
              )}

              <p
                data-slot="audience-group-picker-dynamic-note"
                className="text-xs text-muted-foreground"
              >
                {previewDynamicNote}
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
