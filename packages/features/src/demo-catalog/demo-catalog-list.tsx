"use client"

import * as React from "react"
import { IconPlus } from "@tabler/icons-react"

import { Badge } from "@amroksaleh/ui/badge"
import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent } from "@amroksaleh/ui/card"
import { EmptyState, ErrorState } from "@amroksaleh/ui/empty-state"
import { Skeleton } from "@amroksaleh/ui/skeleton"

import { useTranslation } from "../i18n"
import type { DemoCatalogAdapter, DemoCatalogItem } from "./types"

/**
 * English for the status badge, supplied as the `t()` FALLBACK.
 *
 * The badge's key is computed (`demoCatalog.status.${item.status}`), and a
 * computed key with no fallback resolves to itself — so before this existed the
 * badge rendered `demoCatalog.status.active` wherever no bundle was loaded,
 * even though every other string on the screen had been given English. The
 * `@i18n-keys` block on the component declares these keys to the EXTRACTOR,
 * which is a build-time concern and does nothing at runtime; this map is the
 * runtime half.
 *
 * Keyed by the status union rather than `string`, so adding a third status is a
 * type error here as well as a drift-guard failure there. Keep the text in step
 * with that block — they are the same two sentences, read by a translator and
 * by a reader with no translations respectively.
 */
const STATUS_FALLBACK: Record<DemoCatalogItem["status"], string> = {
  active: "Active",
  archived: "Archived",
}

export interface DemoCatalogListProps {
  /** Injected data-source adapter (server api-client in web, SQLite on desktop). */
  adapter: DemoCatalogAdapter
  /** Called with an item's id when the caller should navigate to its detail view. */
  onSelect: (id: number) => void
  /** Called when the caller should navigate to the "new item" detail view. */
  onCreate: () => void
  className?: string
}

/**
 * Presentational, data-source-agnostic list screen for the DemoCatalog pilot
 * feature. Never fetches directly — all data access goes through the
 * injected `adapter`, and all navigation goes through the injected
 * `onSelect`/`onCreate` callbacks, so this component has zero opinion about
 * routing (Next router, hash router, or otherwise) or where the data
 * actually lives.
 *
 * IT TRANSLATES ITSELF, and the `t` prop is gone — that is the fix for #984.
 *
 * The prop was optional and defaulted to `identityTranslate`, and NO host ever
 * passed one: web's screen mounted this without it, and so does
 * packages/spa-harness. So every string rendered as its own key, and an
 * administrator opening the screen read `demoCatalog.list.create`.
 *
 * The prop is removed rather than merely re-defaulted, because an injected
 * translator is what kept the vocabulary out of every catalogue. The extractor
 * builds the catalogue by finding `const <name> = useTranslation(<domain>)` and
 * reading the calls to THAT name; a `t` arriving as a prop binds no domain, so
 * `demoCatalog.*` was in no catalogue at all and the drift guard had nothing to
 * compare — both halves of the defect invisible at once. Keeping the prop as an
 * override would have left the alias (`const t = override ?? translate`)
 * unrecognised and the keys just as unextractable.
 *
 * It degrades correctly where there is no provider: `useTranslation` returns
 * `fallback ?? key`, so the harness — which wraps no `LanguageProvider` —
 * renders the English below rather than keys. Every call passes that English,
 * which the previous `NavTranslate` type made impossible: it is
 * `(key: string) => string`, with nowhere to put a fallback.
 *
 * The status labels below are the exception the scanner cannot read: they reach
 * `t()` through a template literal (`demoCatalog.status.${item.status}`), so
 * they are DECLARED here and the extractor takes them from this block rather
 * than pretending the scan saw them. `DemoCatalogItem["status"]` is the closed
 * set `'active' | 'archived'`, so the list is exhaustive today, and adding a
 * third status without a line here fails the drift guard rather than shipping a
 * badge that reads `demoCatalog.status.retired`.
 *
 * @i18n-keys plugin
 *   demoCatalog.status.active = Active
 *   demoCatalog.status.archived = Archived
 */
export function DemoCatalogList({
  adapter,
  onSelect,
  onCreate,
  className,
}: DemoCatalogListProps) {
  const t = useTranslation("plugin")
  const [items, setItems] = React.useState<DemoCatalogItem[] | null>(null)
  const [error, setError] = React.useState<string | null>(null)

  const load = React.useCallback(() => {
    let cancelled = false
    setError(null)
    adapter
      .list()
      .then((data) => {
        if (!cancelled) setItems(data)
      })
      .catch(() => {
        if (!cancelled) setError(t("demoCatalog.list.error", "Could not load the catalog."))
      })
    return () => {
      cancelled = true
    }
  }, [adapter, t])

  React.useEffect(() => load(), [load])

  return (
    <div className={className}>
      <div className="mb-4 flex justify-end">
        <Button onClick={onCreate}>
          <IconPlus data-icon="inline-start" />
          {t("demoCatalog.list.create", "New item")}
        </Button>
      </div>

      {error ? (
        <ErrorState
          title={t("demoCatalog.list.errorTitle", "Something went wrong")}
          description={error}
          action={<Button onClick={load}>{t("demoCatalog.list.retry", "Try again")}</Button>}
        />
      ) : items === null ? (
        <div className="space-y-2" aria-busy="true">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      ) : items.length === 0 ? (
        <EmptyState
          title={t("demoCatalog.list.emptyTitle", "No items yet")}
          description={t("demoCatalog.list.emptyDescription", "Create the first item to see it listed here.")}
          action={<Button onClick={onCreate}>{t("demoCatalog.list.create", "New item")}</Button>}
        />
      ) : (
        <ul className="space-y-2">
          {items.map((item) => (
            <li key={item.id}>
              <Card>
                <CardContent>
                  <button
                    type="button"
                    onClick={() => onSelect(item.id)}
                    className="flex w-full items-center justify-between gap-3 text-start"
                  >
                    <div className="min-w-0">
                      <div className="truncate text-sm font-medium text-foreground">
                        {item.name}
                      </div>
                      {item.description ? (
                        <div className="truncate text-xs text-muted-foreground">
                          {item.description}
                        </div>
                      ) : null}
                    </div>
                    <Badge variant={item.status === "active" ? "default" : "outline"}>
                      {t(`demoCatalog.status.${item.status}`, STATUS_FALLBACK[item.status])}
                    </Badge>
                  </button>
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
