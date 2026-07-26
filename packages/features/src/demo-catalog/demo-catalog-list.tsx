"use client"

import * as React from "react"
import { IconPlus } from "@tabler/icons-react"

import { Badge } from "@amroksaleh/ui/badge"
import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent } from "@amroksaleh/ui/card"
import { EmptyState, ErrorState } from "@amroksaleh/ui/empty-state"
import { Skeleton } from "@amroksaleh/ui/skeleton"

import { identityTranslate, type NavTranslate } from "../nav/types"
import type { DemoCatalogAdapter, DemoCatalogItem } from "./types"

export interface DemoCatalogListProps {
  /** Injected data-source adapter (server api-client in web, SQLite on desktop). */
  adapter: DemoCatalogAdapter
  /** Called with an item's id when the caller should navigate to its detail view. */
  onSelect: (id: number) => void
  /** Called when the caller should navigate to the "new item" detail view. */
  onCreate: () => void
  /** Optional translator; falls back to literal strings when omitted. */
  t?: NavTranslate
  className?: string
}

/**
 * Presentational, data-source-agnostic list screen for the DemoCatalog pilot
 * feature. Never fetches directly — all data access goes through the
 * injected `adapter`, and all navigation goes through the injected
 * `onSelect`/`onCreate` callbacks, so this component has zero opinion about
 * routing (Next router, hash router, or otherwise) or where the data
 * actually lives.
 */
export function DemoCatalogList({
  adapter,
  onSelect,
  onCreate,
  t = identityTranslate,
  className,
}: DemoCatalogListProps) {
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
        if (!cancelled) setError(t("demoCatalog.list.error"))
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
          {t("demoCatalog.list.create")}
        </Button>
      </div>

      {error ? (
        <ErrorState
          title={t("demoCatalog.list.errorTitle")}
          description={error}
          action={<Button onClick={load}>{t("demoCatalog.list.retry")}</Button>}
        />
      ) : items === null ? (
        <div className="space-y-2" aria-busy="true">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      ) : items.length === 0 ? (
        <EmptyState
          title={t("demoCatalog.list.emptyTitle")}
          description={t("demoCatalog.list.emptyDescription")}
          action={<Button onClick={onCreate}>{t("demoCatalog.list.create")}</Button>}
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
                      {t(`demoCatalog.status.${item.status}`)}
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
