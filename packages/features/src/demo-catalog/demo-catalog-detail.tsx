"use client"

import * as React from "react"

import { Button } from "@amroksaleh/ui/button"
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@amroksaleh/ui/card"
import { ErrorState } from "@amroksaleh/ui/empty-state"
import { Input } from "@amroksaleh/ui/input"
import { Skeleton } from "@amroksaleh/ui/skeleton"
import { Textarea } from "@amroksaleh/ui/textarea"

import { useTranslation } from "../i18n"
import type { DemoCatalogAdapter, DemoCatalogItem, DemoCatalogItemInput } from "./types"

export interface DemoCatalogDetailProps {
  /** Injected data-source adapter (server api-client in web, SQLite on desktop). */
  adapter: DemoCatalogAdapter
  /** The item id to load and edit, or `null` to render the "new item" form. */
  itemId: number | null
  /** Called after a successful save with the saved item. */
  onSaved: (item: DemoCatalogItem) => void
  /** Called when the caller should navigate back to the list. */
  onCancel: () => void
  className?: string
}

type FormState = { name: string; description: string; status: "active" | "archived" }

const emptyForm: FormState = { name: "", description: "", status: "active" }

function toFormState(item: DemoCatalogItem): FormState {
  return { name: item.name, description: item.description ?? "", status: item.status }
}

/**
 * Presentational, data-source-agnostic detail/edit screen for the
 * DemoCatalog pilot feature. Serves both "edit an existing item" (`itemId`
 * set) and "create a new item" (`itemId === null`) — never fetches or saves
 * directly, all data access goes through the injected `adapter`.
 *
 * Translates itself rather than waiting to be handed a translator — see the
 * fuller note on {@link DemoCatalogList}. No host ever passed `t`, so every
 * string here rendered as its own key, and with the translator arriving as a
 * prop there was no `useTranslation(domain)` in the file for the extractor to
 * bind these keys to, so none of them reached a catalogue (#984).
 */
export function DemoCatalogDetail({
  adapter,
  itemId,
  onSaved,
  onCancel,
  className,
}: DemoCatalogDetailProps) {
  const t = useTranslation("plugin")
  const isNew = itemId === null

  const [form, setForm] = React.useState<FormState | null>(isNew ? emptyForm : null)
  const [loadError, setLoadError] = React.useState<string | null>(null)
  const [saveError, setSaveError] = React.useState<string | null>(null)
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    if (isNew) {
      setForm(emptyForm)
      return
    }
    let cancelled = false
    setForm(null)
    setLoadError(null)
    adapter
      .get(itemId)
      .then((item) => {
        if (cancelled) return
        if (item === null) {
          setLoadError(t("demoCatalog.detail.notFound", "That item no longer exists."))
          return
        }
        setForm(toFormState(item))
      })
      .catch(() => {
        if (!cancelled) setLoadError(t("demoCatalog.detail.loadError", "Could not load this item."))
      })
    return () => {
      cancelled = true
    }
  }, [adapter, itemId, isNew, t])

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    if (form === null) return

    const input: DemoCatalogItemInput = {
      ...(itemId !== null ? { id: itemId } : {}),
      name: form.name,
      description: form.description === "" ? null : form.description,
      status: form.status,
    }

    setSaving(true)
    setSaveError(null)
    adapter
      .save(input)
      .then((saved) => {
        onSaved(saved)
      })
      .catch(() => {
        setSaveError(t("demoCatalog.detail.saveError", "Could not save your changes."))
      })
      .finally(() => {
        setSaving(false)
      })
  }

  if (loadError) {
    return (
      <div className={className}>
        <ErrorState
          title={t("demoCatalog.detail.errorTitle", "Something went wrong")}
          description={loadError}
          action={<Button onClick={onCancel}>{t("demoCatalog.detail.back", "Back to the list")}</Button>}
        />
      </div>
    )
  }

  if (form === null) {
    return (
      <div className={className}>
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  return (
    <form className={className} onSubmit={handleSubmit}>
      <Card>
        <CardHeader>
          <CardTitle>
            {isNew ? t("demoCatalog.detail.createTitle", "New item") : t("demoCatalog.detail.editTitle", "Edit item")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="demo-catalog-name" className="text-xs font-medium text-foreground">
              {t("demoCatalog.detail.nameLabel", "Name")}
            </label>
            <Input
              id="demo-catalog-name"
              value={form.name}
              required
              maxLength={255}
              onChange={(event) => setForm({ ...form, name: event.target.value })}
            />
          </div>

          <div className="space-y-1.5">
            <label
              htmlFor="demo-catalog-description"
              className="text-xs font-medium text-foreground"
            >
              {t("demoCatalog.detail.descriptionLabel", "Description")}
            </label>
            <Textarea
              id="demo-catalog-description"
              value={form.description}
              rows={4}
              maxLength={2000}
              onChange={(event) => setForm({ ...form, description: event.target.value })}
            />
          </div>

          <div className="space-y-1.5">
            <label htmlFor="demo-catalog-status" className="text-xs font-medium text-foreground">
              {t("demoCatalog.detail.statusLabel", "Status")}
            </label>
            <select
              id="demo-catalog-status"
              value={form.status}
              onChange={(event) =>
                setForm({ ...form, status: event.target.value as FormState["status"] })
              }
              className="h-8 w-full rounded-md border border-input bg-transparent px-2 text-xs/relaxed"
            >
              <option value="active">{t("demoCatalog.status.active", "Active")}</option>
              <option value="archived">{t("demoCatalog.status.archived", "Archived")}</option>
            </select>
          </div>

          {saveError ? (
            <p role="alert" className="text-xs text-destructive">
              {saveError}
            </p>
          ) : null}
        </CardContent>
        <CardFooter className="justify-end gap-2 border-t">
          <Button type="button" variant="ghost" onClick={onCancel}>
            {t("demoCatalog.detail.cancel", "Cancel")}
          </Button>
          <Button type="submit" disabled={saving}>
            {saving ? t("demoCatalog.detail.saving", "Saving…") : t("demoCatalog.detail.save", "Save")}
          </Button>
        </CardFooter>
      </Card>
    </form>
  )
}
