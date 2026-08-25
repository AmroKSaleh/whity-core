"use client"

import * as React from "react"
import { IconX } from "@tabler/icons-react"

import { cn } from "./utils"
import { Badge } from "./badge"
import { Button } from "./button"
import { Input } from "./input"

/**
 * Naming PEOPLE, one at a time, for the hand-picked case (#1015, over #999's
 * `explicit` rule kind).
 *
 * WHY A ROSTER IS ALLOWED HERE AND NOWHERE ELSE
 * ---------------------------------------------
 * Everything else about document routing argues against enumerating people: a
 * step names a rule, and a stored list of names is wrong the moment somebody is
 * hired. `explicit` is the deliberate exception, and it is an exception with a
 * meaning rather than an escape hatch — "the tender committee is Aisha, Omar and
 * Lena" is a real requirement that no computed rule expresses, and it MEANS
 * those three. A fourth person joining the department must not silently join the
 * committee.
 *
 * So this control is for choosing a handful, and its shape says so: a search box
 * and a bounded result list rather than a scrollable roster of the whole
 * organisation. A picker that renders four thousand rows has rebuilt the problem
 * user groups exist to remove — the thousand nodes standing in for the one that
 * says "instructors" — on the screen where somebody is trying to name three
 * people.
 *
 * NO CEILING IS MIRRORED HERE, ON PURPOSE
 * ---------------------------------------
 * The server refuses an `explicit` rule naming more people than it will accept,
 * and its refusal names the number and says what to do instead. This component
 * does not carry a copy of that number. A mirrored limit is a second copy of a
 * value that only the server actually enforces: wrong on the first install that
 * changes it, and wrong in the direction that blocks legitimate work. The same
 * position the route composer already takes for the per-step recipient ceiling —
 * limits are surfaced, not mirrored.
 *
 * FETCHES NOTHING, TRANSLATES NOTHING. People arrive as a prop and every visible
 * string is a label prop with an English default, like every other
 * `@amroksaleh/ui` input.
 */

/** One person who may be named. */
export interface AudiencePersonOption {
  /** Profile id — what the rule stores, and what survives a rename. */
  id: number
  name: string
  /** An email or unit, shown to tell two people of the same name apart. */
  secondary?: string | null
}

export interface AudiencePeoplePickerProps {
  id?: string
  /** The catalogue to search, already fetched by the caller. */
  people: AudiencePersonOption[]
  /** Chosen profile ids, in the order they were chosen. */
  value: number[]
  onChange: (profileIds: number[]) => void
  /**
   * Why there is nobody to choose from — typically a 403 on the people
   * catalogue. Rendered instead of an empty search box, which would read as "this
   * workspace has no people".
   */
  unavailableReason?: string | null
  /** Why the catalogue may be incomplete, when it loaded but only partly. */
  incompleteReason?: string | null
  /**
   * How many matches to render at once. A bound, not a page: somebody who cannot
   * find their person in this many should type more, not scroll further.
   */
  maxResults?: number
  disabled?: boolean
  className?: string

  // -- labels, English by default ------------------------------------------

  searchPlaceholder?: string
  /** No people in the catalogue at all. */
  emptyLabel?: string
  /** Nothing chosen yet. */
  nothingSelectedLabel?: string
  /** The query matched nobody. */
  noMatchesLabel?: string
  /** More matched than are being shown. */
  moreMatchesLabel?: (shown: number, total: number) => string
  /** Accessible name for a chip's remove button. */
  removeLabel?: (name: string) => string
  /** For a chosen id that is not in the catalogue the caller supplied. */
  unknownPersonLabel?: (profileId: number) => string
}

/**
 * A searchable multi-person picker.
 *
 * Controlled: `value` is the list of chosen profile ids, and an empty list is
 * the state a caller must treat as "not configured yet" — the server refuses an
 * `explicit` rule that names nobody rather than accepting it as "nobody".
 */
export function AudiencePeoplePicker({
  id,
  people,
  value,
  onChange,
  unavailableReason = null,
  incompleteReason = null,
  maxResults = 8,
  disabled = false,
  className,
  searchPlaceholder = "Search people by name",
  emptyLabel = "There is nobody here to name.",
  nothingSelectedLabel = "Nobody chosen yet.",
  noMatchesLabel = "Nobody matches that.",
  moreMatchesLabel = (shown: number, total: number) =>
    `Showing ${shown} of ${total} matches — keep typing to narrow it down.`,
  removeLabel = (name: string) => `Remove ${name}`,
  unknownPersonLabel = (profileId: number) => `Profile #${profileId}`,
}: AudiencePeoplePickerProps) {
  const [query, setQuery] = React.useState("")

  const byId = React.useMemo(() => {
    const map = new Map<number, AudiencePersonOption>()
    for (const person of people) map.set(person.id, person)
    return map
  }, [people])

  const chosen = React.useMemo(() => new Set(value), [value])

  const matches = React.useMemo(() => {
    const needle = query.trim().toLocaleLowerCase()
    // An empty query shows nothing rather than everybody. This is a picker for
    // naming a few, and opening it with the whole organisation already listed
    // invites scrolling through it, which is the reading the `explicit` kind is
    // deliberately not for.
    if (needle === "") return []
    return people.filter((person) => {
      if (chosen.has(person.id)) return false
      const secondary = person.secondary ?? ""
      return (
        person.name.toLocaleLowerCase().includes(needle) ||
        secondary.toLocaleLowerCase().includes(needle)
      )
    })
  }, [people, query, chosen])

  if (unavailableReason !== null && unavailableReason !== "") {
    return (
      <p
        data-slot="audience-people-picker-unavailable"
        className={cn("text-xs text-muted-foreground", className)}
      >
        {unavailableReason}
      </p>
    )
  }

  if (people.length === 0) {
    return (
      <p
        data-slot="audience-people-picker-empty"
        className={cn("text-xs text-muted-foreground", className)}
      >
        {emptyLabel}
      </p>
    )
  }

  const add = (profileId: number): void => {
    if (chosen.has(profileId)) return
    onChange([...value, profileId])
    setQuery("")
  }

  const remove = (profileId: number): void => {
    onChange(value.filter((existing) => existing !== profileId))
  }

  const shown = matches.slice(0, maxResults)

  return (
    <div
      data-slot="audience-people-picker"
      className={cn("space-y-2", className)}
    >
      <div className="flex flex-wrap gap-1.5" data-slot="audience-people-picker-chosen">
        {value.length === 0 ? (
          <span className="text-xs text-muted-foreground">
            {nothingSelectedLabel}
          </span>
        ) : (
          value.map((profileId) => {
            const person = byId.get(profileId)
            const name = person?.name ?? unknownPersonLabel(profileId)
            return (
              <Badge key={profileId} variant="secondary" className="gap-1 pe-1">
                {name}
                {!disabled && (
                  <button
                    type="button"
                    aria-label={removeLabel(name)}
                    onClick={() => remove(profileId)}
                    className="rounded-full p-0.5 outline-none hover:bg-muted-foreground/20 focus-visible:ring-2 focus-visible:ring-ring/30"
                  >
                    <IconX className="size-2.5" />
                  </button>
                )}
              </Badge>
            )
          })
        )}
      </div>

      {!disabled && (
        <>
          <Input
            id={id}
            type="search"
            value={query}
            aria-label={searchPlaceholder}
            placeholder={searchPlaceholder}
            onChange={(event) => setQuery(event.target.value)}
          />

          {query.trim() !== "" && (
            <div data-slot="audience-people-picker-results">
              {matches.length === 0 ? (
                <p className="text-xs text-muted-foreground">{noMatchesLabel}</p>
              ) : (
                <>
                  <ul className="space-y-1">
                    {shown.map((person) => (
                      <li key={person.id}>
                        <Button
                          variant="ghost"
                          size="sm"
                          // `justify-start` is a flex alignment on the writing
                          // axis, so it follows the document direction — the
                          // reason this is not `justify-left`.
                          className="w-full justify-start"
                          onClick={() => add(person.id)}
                        >
                          <span className="truncate">{person.name}</span>
                          {person.secondary !== undefined &&
                            person.secondary !== null &&
                            person.secondary !== "" && (
                              <span className="ms-2 truncate text-xs text-muted-foreground">
                                {person.secondary}
                              </span>
                            )}
                        </Button>
                      </li>
                    ))}
                  </ul>
                  {matches.length > shown.length && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      {moreMatchesLabel(shown.length, matches.length)}
                    </p>
                  )}
                </>
              )}
            </div>
          )}
        </>
      )}

      {incompleteReason !== null && incompleteReason !== "" && (
        <p
          data-slot="audience-people-picker-incomplete"
          className="text-xs text-muted-foreground"
        >
          {incompleteReason}
        </p>
      )}
    </div>
  )
}
