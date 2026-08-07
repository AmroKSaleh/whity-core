"use client"

import * as React from "react"

import { cn } from "./utils"
import { Input } from "./input"
import { Badge } from "./badge"

/**
 * The value shape every bilingual field speaks — a plain `{ar?, en?, [key: string]: string | undefined}` pair/map,
 * matching the LocalizedText convention (WC-532) used by schema-driven CRUD
 * screens and any plugin storing bilingual/multilingual content.
 */
export interface BilingualValue {
  ar?: string
  en?: string
  [key: string]: string | undefined
}

export interface LanguageConfig {
  /** Language ISO code (e.g. "ar", "en", "fr", "de", "es") */
  code: string
  /** Human-readable language name (e.g. "Arabic", "English", "French") */
  label: string
  /** Text direction: "rtl" or "ltr" (defaults to "rtl" for ar/fa/ur/he, "ltr" otherwise) */
  dir?: "rtl" | "ltr"
  /** Short badge text (defaults to uppercase code e.g. "AR") */
  badge?: string
}

export interface BilingualInputProps {
  /** Overall field group title/label (e.g. "Job Title" or "Organization Name"). */
  label?: string
  /** Subtitle or description explaining the field. */
  description?: string
  /** Base id; the primary/secondary inputs get `${id}-${primaryLang.code}` / `${id}-${secondaryLang.code}`. */
  id?: string
  value: BilingualValue
  onChange: (value: BilingualValue) => void
  /** Configuration for primary language input (defaults to Arabic `AR`). */
  primaryLang?: LanguageConfig
  /** Configuration for secondary language input (defaults to English `EN`). */
  secondaryLang?: LanguageConfig
  /** Backward-compatibility prop for Arabic label text. */
  arLabel?: string
  /** Backward-compatibility prop for English label text. */
  enLabel?: string
  disabled?: boolean
  required?: boolean
  className?: string
}

/**
 * The atom of every bilingual form (WC-532): two synced fields —
 * configurable for any language pair (e.g. AR/EN, FR/DE, ES/EN) —
 * with an overall field title, explicit language badges (`AR`, `EN`),
 * presence indicators, and a single `{ar?, en?}` value in/out.
 */
export function BilingualInput({
  label,
  description,
  id,
  value,
  onChange,
  primaryLang,
  secondaryLang,
  arLabel,
  enLabel,
  disabled = false,
  required = false,
  className,
}: BilingualInputProps) {
  const pCode = primaryLang?.code ?? "ar"
  const sCode = secondaryLang?.code ?? "en"

  const pLang: LanguageConfig = {
    code: pCode,
    label: arLabel ?? primaryLang?.label ?? "Arabic",
    dir: primaryLang?.dir ?? (pCode === "ar" || pCode === "fa" || pCode === "ur" || pCode === "he" ? "rtl" : "ltr"),
    badge: primaryLang?.badge ?? pCode.toUpperCase(),
  }

  const sLang: LanguageConfig = {
    code: sCode,
    label: enLabel ?? secondaryLang?.label ?? "English",
    dir: secondaryLang?.dir ?? (sCode === "ar" || sCode === "fa" || sCode === "ur" || sCode === "he" ? "rtl" : "ltr"),
    badge: secondaryLang?.badge ?? sCode.toUpperCase(),
  }

  const arId = id ? (id.endsWith("-ar") ? id : `${id}-ar`) : undefined
  const enId = id ? (id.endsWith("-en") ? id : `${id}-en`) : undefined

  const pId = pCode === "ar" ? arId : id ? `${id}-${pCode}` : undefined
  const sId = sCode === "en" ? enId : id ? `${id}-${sCode}` : undefined

  const pVal = value[pLang.code] ?? (pLang.code === "ar" ? value.ar : undefined) ?? ""
  const sVal = value[sLang.code] ?? (sLang.code === "en" ? value.en : undefined) ?? ""

  return (
    <div data-slot="bilingual-input" className={cn("space-y-2", className)}>
      {/* Field Title & Description */}
      {label && (
        <div className="flex flex-col gap-0.5">
          <label className="text-xs font-semibold text-foreground">
            {label}
            {required && <span className="ms-0.5 text-destructive">*</span>}
          </label>
          {description && <p className="text-[0.6875rem] text-muted-foreground">{description}</p>}
        </div>
      )}

      {/* Grid of Language Inputs */}
      <div className="grid gap-3 sm:grid-cols-2">
        {/* Primary Language Input */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-1.5 min-w-0">
              <Badge variant="outline" className="font-mono font-bold text-[9px] uppercase px-1.5 py-0 bg-muted/60 text-foreground shrink-0">
                {pLang.badge}
              </Badge>
              <label htmlFor={pId} className="text-xs font-medium text-muted-foreground truncate">
                {pLang.label}
              </label>
            </div>
            <PresenceIndicator present={Boolean(pVal.trim())} testId="bilingual-presence-ar" />
          </div>
          <Input
            id={pId}
            dir={pLang.dir}
            lang={pLang.code}
            disabled={disabled}
            required={required}
            value={pVal}
            onChange={(event) => {
              const nextVal = { ...value, [pLang.code]: event.target.value }
              if (pLang.code === "ar") nextVal.ar = event.target.value
              onChange(nextVal)
            }}
          />
        </div>

        {/* Secondary Language Input */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-1.5 min-w-0">
              <Badge variant="outline" className="font-mono font-bold text-[9px] uppercase px-1.5 py-0 bg-muted/60 text-foreground shrink-0">
                {sLang.badge}
              </Badge>
              <label htmlFor={sId} className="text-xs font-medium text-muted-foreground truncate">
                {sLang.label}
              </label>
            </div>
            <PresenceIndicator present={Boolean(sVal.trim())} testId="bilingual-presence-en" />
          </div>
          <Input
            id={sId}
            dir={sLang.dir}
            lang={sLang.code}
            disabled={disabled}
            required={required}
            value={sVal}
            onChange={(event) => {
              const nextVal = { ...value, [sLang.code]: event.target.value }
              if (sLang.code === "en") nextVal.en = event.target.value
              onChange(nextVal)
            }}
          />
        </div>
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
