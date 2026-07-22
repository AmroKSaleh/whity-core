"use client"

import * as React from "react"
import { IconX } from "@tabler/icons-react"

import { cn } from "./utils"
import { Badge } from "./badge"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./select"

/** One selectable option — the API-backed collection this input binds to. */
export interface TagOption {
  value: string
  label: string
}

export interface TagInputProps {
  id?: string
  /**
   * The full option collection to pick from (already fetched by the caller —
   * this primitive does no data-fetching of its own, matching every other
   * `@amroksaleh/ui` input).
   */
  options: TagOption[]
  /** Selected option values, in selection order. */
  value: string[]
  onChange: (value: string[]) => void
  placeholder?: string
  disabled?: boolean
  className?: string
}

/**
 * Tag/chips multi-select bound to an API-backed collection (WC-532 #7):
 * selected options render as removable chips; an "add" select (reusing the
 * existing single-value `Select`) lists only the NOT-yet-selected options —
 * picking one adds it and the select resets to its placeholder, ready to add
 * another.
 */
export function TagInput({
  id,
  options,
  value,
  onChange,
  placeholder = "Add…",
  disabled = false,
  className,
}: TagInputProps) {
  const selectedSet = new Set(value)
  const selectedOptions = value
    .map((tagValue) => options.find((option) => option.value === tagValue))
    .filter((option): option is TagOption => option !== undefined)
  const availableOptions = options.filter((option) => !selectedSet.has(option.value))

  const addTag = (tagValue: string) => {
    if (!selectedSet.has(tagValue)) {
      onChange([...value, tagValue])
    }
  }

  const removeTag = (tagValue: string) => {
    onChange(value.filter((existing) => existing !== tagValue))
  }

  return (
    <div data-slot="tag-input" className={cn("space-y-2", className)}>
      <div className="flex flex-wrap gap-1.5" data-testid="tag-input-chips">
        {selectedOptions.length === 0 ? (
          <span className="text-xs text-muted-foreground">No tags selected</span>
        ) : (
          selectedOptions.map((option) => (
            <Badge key={option.value} variant="secondary" className="gap-1 pe-1">
              {option.label}
              {!disabled && (
                <button
                  type="button"
                  aria-label={`Remove ${option.label}`}
                  onClick={() => removeTag(option.value)}
                  className="rounded-full p-0.5 hover:bg-muted-foreground/20"
                >
                  <IconX className="size-2.5" />
                </button>
              )}
            </Badge>
          ))
        )}
      </div>

      {!disabled && availableOptions.length > 0 && (
        // Always controlled to "" — there is never a persisted selection here,
        // only a one-shot pick that immediately becomes a chip and resets.
        <Select value="" onValueChange={addTag}>
          <SelectTrigger id={id} aria-label={placeholder} className="w-full">
            <SelectValue placeholder={placeholder} />
          </SelectTrigger>
          <SelectContent>
            {availableOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </div>
  )
}
