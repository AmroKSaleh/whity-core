"use client"

import * as React from "react"

import { cn } from "./utils"
import { Input } from "./input"
import { Badge } from "./badge"

/**
 * The value shape every bilingual field speaks — a plain `{ar?, en?}` pair,
 * matching the LocalizedText convention (WC-532) used by schema-driven CRUD
 * screens and any plugin storing bilingual content.
 */
export interface BilingualValue {
  ar?: string
  en?: string
}

export interface BilingualInputProps {
  /** Base id; the AR/EN inputs get `${id}-ar` / `${id}-en`. */
  id?: string
  value: BilingualValue
  onChange: (value: BilingualValue) => void
  arLabel?: string
  enLabel?: string
  disabled?: boolean
  required?: boolean
  className?: string
}

/**
 * The atom of every bilingual form (WC-532): two synced fields — Arabic
 * (`dir="rtl"`) and English (`dir="ltr"`) — each with a presence indicator,
 * a single `{ar?, en?}` value in/out. Each field keeps its own direction
 * regardless of the host page's direction, since a bilingual field always
 * needs BOTH scripts typed correctly at once.
 */
export function BilingualInput({
  id,
  value,
  onChange,
  arLabel = "Arabic",
  enLabel = "English",
  disabled = false,
  required = false,
  className,
}: BilingualInputProps) {
  const arId = id ? `${id}-ar` : undefined
  const enId = id ? `${id}-en` : undefined

  return (
    <div
      data-slot="bilingual-input"
      className={cn("grid gap-3 sm:grid-cols-2", className)}
    >
      <div className="space-y-1.5">
        <div className="flex items-center justify-between gap-2">
          <label htmlFor={arId} className="text-sm font-medium text-foreground">
            {arLabel}
          </label>
          <PresenceIndicator present={Boolean(value.ar?.trim())} testId="bilingual-presence-ar" />
        </div>
        <Input
          id={arId}
          dir="rtl"
          lang="ar"
          disabled={disabled}
          required={required}
          value={value.ar ?? ""}
          onChange={(event) => onChange({ ...value, ar: event.target.value })}
        />
      </div>
      <div className="space-y-1.5">
        <div className="flex items-center justify-between gap-2">
          <label htmlFor={enId} className="text-sm font-medium text-foreground">
            {enLabel}
          </label>
          <PresenceIndicator present={Boolean(value.en?.trim())} testId="bilingual-presence-en" />
        </div>
        <Input
          id={enId}
          dir="ltr"
          lang="en"
          disabled={disabled}
          required={required}
          value={value.en ?? ""}
          onChange={(event) => onChange({ ...value, en: event.target.value })}
        />
      </div>
    </div>
  )
}

function PresenceIndicator({ present, testId }: { present: boolean; testId: string }) {
  return (
    <Badge
      variant={present ? "secondary" : "outline"}
      className="text-[9px] uppercase"
      data-testid={testId}
    >
      {present ? "Set" : "Empty"}
    </Badge>
  )
}
