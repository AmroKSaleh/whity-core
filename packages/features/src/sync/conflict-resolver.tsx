import * as React from "react"

import { Button } from "@amroksaleh/ui/button"
import { Input } from "@amroksaleh/ui/input"
import { RadioGroup, RadioGroupItem } from "@amroksaleh/ui/radio-group"

import { identityTranslate, type NavTranslate } from "../nav/types"
import type { Conflict, FieldChoice, FieldConflict, Resolution } from "./types"

export interface ConflictResolverProps {
  conflict: Conflict
  onResolve: (resolution: Resolution) => void
  onCancel?: () => void
  /** Initial per-field pick. Defaults to "theirs" (server wins unless overridden). */
  defaultChoice?: "mine" | "theirs"
  t?: NavTranslate
  className?: string
}

type Pick = "mine" | "theirs" | "custom"

/**
 * Field-level conflict resolver (WC-desktop-sync): for each diverging field the
 * user picks mine / theirs / a custom merged value, with a live merged preview.
 * Presentational — resolution is handed back via `onResolve` for the injected
 * sync controller to apply.
 *
 * Arabic-safety: chrome uses logical styling, but the user CONTENT (mine/theirs/
 * merged values, unknown language) is wrapped in `dir="auto"` so each value
 * self-detects its direction rather than inheriting the chrome's.
 */
export function ConflictResolver({
  conflict,
  onResolve,
  onCancel,
  defaultChoice = "theirs",
  t = identityTranslate,
  className,
}: ConflictResolverProps) {
  const [choices, setChoices] = React.useState<Record<string, FieldChoice>>(() => {
    const initial: Record<string, FieldChoice> = {}
    for (const field of conflict.fields) {
      initial[field.field] = { pick: defaultChoice }
    }
    return initial
  })

  const setPick = (field: string, pick: Pick) => {
    setChoices((prev) => {
      if (pick === "custom") {
        const existing = prev[field]
        const value = "value" in existing ? existing.value : ""
        return { ...prev, [field]: { pick: "custom", value } }
      }
      return { ...prev, [field]: { pick } }
    })
  }

  const setCustomValue = (field: string, value: string) => {
    setChoices((prev) => ({ ...prev, [field]: { pick: "custom", value } }))
  }

  const mergedValue = (field: FieldConflict): unknown => {
    const choice = choices[field.field]
    if (choice.pick === "custom") return choice.value
    return choice.pick === "mine" ? field.mine : field.theirs
  }

  const canResolve = conflict.fields.every((field) => {
    const choice = choices[field.field]
    if (choice.pick !== "custom") return true
    return typeof choice.value === "string" ? choice.value.trim() !== "" : choice.value != null
  })

  const label = (field: FieldConflict) =>
    field.labelKey ? t(field.labelKey) : (field.label ?? field.field)

  return (
    <div data-slot="conflict-resolver" className={className}>
      <h3 data-slot="conflict-resolver-title" className="mb-3 text-sm font-semibold text-foreground">
        {conflict.title ?? t("sync.conflict.title")}
      </h3>

      <div className="grid gap-4">
        {conflict.fields.map((field) => {
          const choice = choices[field.field]
          return (
            <div key={field.field} data-slot="conflict-field" className="grid gap-1.5">
              <div className="text-xs font-medium text-muted-foreground">{label(field)}</div>
              <RadioGroup value={choice.pick} onValueChange={(v) => setPick(field.field, v as Pick)}>
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="mine" aria-label={`${label(field)} ${t("sync.conflict.mine")}`} />
                  <span>{t("sync.conflict.mine")}:</span>
                  <span dir="auto" className="text-muted-foreground">{String(field.mine)}</span>
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="theirs" aria-label={`${label(field)} ${t("sync.conflict.theirs")}`} />
                  <span>{t("sync.conflict.theirs")}:</span>
                  <span dir="auto" className="text-muted-foreground">{String(field.theirs)}</span>
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="custom" aria-label={`${label(field)} ${t("sync.conflict.custom")}`} />
                  <span>{t("sync.conflict.custom")}</span>
                </label>
              </RadioGroup>
              {choice.pick === "custom" ? (
                <Input
                  dir="auto"
                  aria-label={`${label(field)} ${t("sync.conflict.custom")}`}
                  value={typeof choice.value === "string" ? choice.value : ""}
                  onChange={(e) => setCustomValue(field.field, e.target.value)}
                />
              ) : null}
              <div data-slot="conflict-merged-preview" className="text-xs text-muted-foreground">
                {t("sync.conflict.merged")}: <span dir="auto">{String(mergedValue(field))}</span>
              </div>
            </div>
          )
        })}
      </div>

      <div className="mt-4 flex justify-end gap-2">
        {onCancel ? (
          <Button variant="outline" onClick={onCancel}>
            {t("sync.conflict.cancel")}
          </Button>
        ) : null}
        <Button
          disabled={!canResolve}
          onClick={() => onResolve({ conflictId: conflict.id, fields: choices })}
        >
          {t("sync.conflict.resolve")}
        </Button>
      </div>
    </div>
  )
}
