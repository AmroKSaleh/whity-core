"use client"

import * as React from "react"
import {
  IconCheck,
  IconChevronDown,
  IconCopy,
  IconInfoCircle,
  IconPalette,
  IconX,
} from "@tabler/icons-react"

import { cn } from "./utils"
import { Popover, PopoverContent, PopoverTrigger } from "./popover"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "./tooltip"

export interface ColorPickerProps {
  /**
   * Selected color hex string e.g. "#4f46e5".
   */
  value?: string
  /**
   * Default color hex string.
   */
  defaultValue?: string
  /**
   * Callback fired when color changes or is cleared.
   */
  onChange?: (color: string | null) => void
  /**
   * Field label text displayed statically above the color picker.
   */
  label?: React.ReactNode
  /**
   * Displays a required asterisk (*) next to the label.
   */
  required?: boolean
  /**
   * Tooltip message displayed in an interactive info icon next to the label.
   */
  tooltip?: React.ReactNode
  /**
   * Small informational note rendered below the color picker.
   */
  helperText?: React.ReactNode
  /**
   * Error text rendered below the color picker in destructive red.
   */
  errorText?: React.ReactNode
  /**
   * Placeholder text when no color is selected. Defaults to "Select color...".
   */
  placeholder?: string
  /** Accessible name for the clear button. */
  clearLabel?: string
  /** Accessible name for a tooltip trigger. Defaults to "More information". */
  tooltipTriggerLabel?: string
  /** Tooltip on the copy-hex button. */
  copyHexLabel?: string
  /** Copy button text, before and after copying. */
  copyLabel?: string
  copiedLabel?: string
  /** Heading above the preset swatches. */
  presetsLabel?: string
  /** Text of the "no colour" swatch. Defaults to "Clear selection". */
  clearSelectionLabel?: string
  /**
   * Presets color swatches. Defaults to a modern curated color palette.
   */
  presets?: string[]
  /**
   * Disables the color picker control.
   */
  disabled?: boolean
  /**
   * Allows clearing the selected color. Defaults to true.
   */
  clearable?: boolean
  /**
   * Custom container class name.
   */
  className?: string
  id?: string
  name?: string
}

const DEFAULT_PRESETS = [
  "#000000", // Black
  "#64748b", // Slate
  "#ef4444", // Red
  "#f97316", // Orange
  "#f59e0b", // Amber
  "#10b981", // Emerald
  "#06b6d4", // Cyan
  "#3b82f6", // Blue
  "#6366f1", // Indigo
  "#8b5cf6", // Violet
  "#ec4899", // Pink
  "#ffffff", // White
]

function normalizeHex(hex?: string | null): string {
  if (!hex) return "#3b82f6"
  let clean = hex.trim()
  if (!clean.startsWith("#")) clean = `#${clean}`
  if (/^#[0-9A-F]{6}$/i.test(clean)) return clean
  if (/^#[0-9A-F]{3}$/i.test(clean)) {
    return `#${clean[1]}${clean[1]}${clean[2]}${clean[2]}${clean[3]}${clean[3]}`
  }
  return "#3b82f6"
}

const ColorPicker = React.forwardRef<HTMLDivElement, ColorPickerProps>(
  (
    {
      value,
      defaultValue,
      onChange,
      label,
      required,
      tooltip,
      helperText,
      errorText,
      placeholder = "Select color...",
      clearLabel = "Clear color",
      tooltipTriggerLabel = "More information",
      copyHexLabel = "Copy HEX code",
      copyLabel = "Copy",
      copiedLabel = "Copied",
      presetsLabel = "Presets",
      clearSelectionLabel = "Clear selection",
      presets = DEFAULT_PRESETS,
      disabled,
      clearable = true,
      className,
      id,
      name,
    },
    ref
  ) => {
    const [selectedColor, setSelectedColor] = React.useState<string | null>(() => {
      return value ?? defaultValue ?? null
    })
    const [open, setOpen] = React.useState(false)
    const [copied, setCopied] = React.useState(false)

    const hexInputRef = React.useRef<HTMLInputElement>(null)

    React.useEffect(() => {
      if (value !== undefined) {
        setSelectedColor(value)
      }
    }, [value])

    React.useEffect(() => {
      if (open) {
        const timer = setTimeout(() => {
          if (hexInputRef.current) {
            hexInputRef.current.focus()
            hexInputRef.current.select()
          }
        }, 50)
        return () => clearTimeout(timer)
      }
    }, [open])

    const handleColorChange = (newColor: string) => {
      const normalized = normalizeHex(newColor)
      setSelectedColor(normalized)
      onChange?.(normalized)
    }

    const handleClear = (e: React.MouseEvent) => {
      e.stopPropagation()
      setSelectedColor(null)
      onChange?.(null)
    }

    const handleCopy = () => {
      if (!selectedColor) return
      navigator.clipboard.writeText(selectedColor)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }

    const hasHeader = label || tooltip || required
    const hasFooter = helperText || errorText

    const activeHex = selectedColor ? normalizeHex(selectedColor) : "#3b82f6"

    const triggerButton = (
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            id={id}
            disabled={disabled}
            className={cn(
              "relative flex h-7 w-full items-center justify-start rounded-md border border-input bg-card ps-8 pe-8 py-0.5 text-xs font-medium text-foreground transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20",
              !selectedColor && "text-muted-foreground font-normal",
              className
            )}
          >
            {/* Color Swatch Preview at start-2.5 */}
            <div className="absolute start-2.5 flex items-center justify-center size-3.5 shrink-0 z-10">
              {selectedColor ? (
                <span
                  className="size-3.5 rounded-full border border-border/80 shadow-2xs transition-transform"
                  style={{ backgroundColor: activeHex }}
                />
              ) : (
                <IconPalette className="size-3.5 text-muted-foreground shrink-0" aria-hidden="true" />
              )}
            </div>

            <span className="truncate uppercase font-mono tracking-wider">{selectedColor || placeholder}</span>

            {/* Trailing Chevron / Clear at end-2.5 */}
            <div className="absolute end-2.5 flex items-center justify-center gap-1 shrink-0 z-10">
              {clearable && selectedColor && !disabled && (
                <span
                  role="button"
                  tabIndex={-1}
                  onClick={handleClear}
                  aria-label={clearLabel}
                  className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                  <IconX className="size-3.5" aria-hidden="true" />
                </span>
              )}
              <IconChevronDown className="size-3.5 text-muted-foreground transition-transform duration-200 group-data-[state=open]:rotate-180" />
            </div>
          </button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-64 p-3 shadow-lg border border-border bg-popover text-popover-foreground rounded-xl">
          {/* Header */}
          <div className="flex items-center justify-between pb-2 mb-2 border-b border-border/50">
            <div className="flex items-center gap-2">
              <span
                className="size-4 rounded-full border border-border/80 shadow-2xs"
                style={{ backgroundColor: activeHex }}
              />
              <span className="text-xs font-semibold text-foreground uppercase font-mono">
                {selectedColor ? activeHex : "Select Color"}
              </span>
            </div>
            {selectedColor && (
              <button
                type="button"
                onClick={handleCopy}
                title={copyHexLabel}
                className="flex items-center gap-1 text-[0.6875rem] font-medium text-muted-foreground transition-colors hover:text-foreground"
              >
                {copied ? (
                  <>
                    <IconCheck className="size-3 text-emerald-500" />
                    <span className="text-emerald-500 font-semibold">{copiedLabel}</span>
                  </>
                ) : (
                  <>
                    <IconCopy className="size-3" />
                    <span>{copyLabel}</span>
                  </>
                )}
              </button>
            )}
          </div>

          {/* Color Spectrum Picker & Hex Input */}
          <div className="flex items-center gap-2 mb-3">
            <input
              type="color"
              value={activeHex}
              onChange={(e) => handleColorChange(e.target.value)}
              className="size-8 rounded-lg border border-border/80 bg-card p-0.5 cursor-pointer shrink-0"
            />
            <div className="flex-1 min-w-0">
              <input
                ref={hexInputRef}
                type="text"
                value={selectedColor || ""}
                placeholder="#000000"
                onChange={(e) => handleColorChange(e.target.value)}
                onFocus={(e) => e.target.select()}
                className="h-8 w-full rounded-md border border-input bg-card px-2 text-xs font-mono font-medium uppercase text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
              />
            </div>
          </div>

          {/* Swatch Presets Grid */}
          <div className="flex flex-col gap-1.5">
            <span className="text-[0.625rem] font-semibold text-muted-foreground uppercase">{presetsLabel}</span>
            <div className="grid grid-cols-6 gap-1.5">
              {presets.map((presetHex) => {
                const isSelected = selectedColor?.toLowerCase() === presetHex.toLowerCase()
                return (
                  <button
                    key={presetHex}
                    type="button"
                    onClick={() => handleColorChange(presetHex)}
                    className={cn(
                      "relative flex size-7 items-center justify-center rounded-lg border border-border/60 transition-transform hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      isSelected && "ring-2 ring-primary ring-offset-1"
                    )}
                    style={{ backgroundColor: presetHex }}
                    title={presetHex}
                  >
                    {isSelected && (
                      <IconCheck
                        className={cn(
                          "size-3.5 stroke-[3]",
                          presetHex.toLowerCase() === "#ffffff" ? "text-black" : "text-white"
                        )}
                      />
                    )}
                  </button>
                )
              })}
            </div>
          </div>

          {/* Footer Clear */}
          {selectedColor && (
            <div className="flex items-center justify-end pt-2 mt-3 border-t border-border/50">
              <button
                type="button"
                onClick={() => {
                  setSelectedColor(null)
                  onChange?.(null)
                  setOpen(false)
                }}
                className="text-[0.6875rem] text-muted-foreground hover:text-foreground transition-colors"
              >
                {clearSelectionLabel}
              </button>
            </div>
          )}
        </PopoverContent>
      </Popover>
    )

    if (name && selectedColor) {
      return (
        <div ref={ref} className="w-full">
          <input type="hidden" name={name} value={selectedColor} />
          {!hasHeader && !hasFooter ? (
            triggerButton
          ) : (
            <div className="flex flex-col gap-1 w-full text-start">
              {hasHeader && (
                <div className="flex items-center gap-1 text-xs font-semibold text-foreground">
                  {label && <span>{label}</span>}
                  {required && <span className="text-destructive font-bold ms-0.5">*</span>}
                  {tooltip && (
                    <TooltipProvider>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <button
                            type="button"
                            aria-label={tooltipTriggerLabel}
                            className="inline-flex items-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring rounded p-0.5"
                          >
                            <IconInfoCircle className="size-3.5 shrink-0" aria-hidden="true" />
                          </button>
                        </TooltipTrigger>
                        <TooltipContent>{tooltip}</TooltipContent>
                      </Tooltip>
                    </TooltipProvider>
                  )}
                </div>
              )}
              {triggerButton}
              {errorText ? (
                <p className="text-[0.6875rem] font-medium text-destructive">{errorText}</p>
              ) : (
                helperText && <p className="text-[0.6875rem] text-muted-foreground">{helperText}</p>
              )}
            </div>
          )}
        </div>
      )
    }

    if (!hasHeader && !hasFooter) {
      return <div ref={ref} className="w-full">{triggerButton}</div>
    }

    return (
      <div ref={ref} className="flex flex-col gap-1 w-full text-start">
        {hasHeader && (
          <div className="flex items-center gap-1 text-xs font-semibold text-foreground">
            {label && <span>{label}</span>}
            {required && <span className="text-destructive font-bold ms-0.5">*</span>}
            {tooltip && (
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      aria-label={tooltipTriggerLabel}
                      className="inline-flex items-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring rounded p-0.5"
                    >
                      <IconInfoCircle className="size-3.5 shrink-0" aria-hidden="true" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent>{tooltip}</TooltipContent>
                </Tooltip>
              </TooltipProvider>
            )}
          </div>
        )}
        {triggerButton}
        {errorText ? (
          <p className="text-[0.6875rem] font-medium text-destructive">{errorText}</p>
        ) : (
          helperText && <p className="text-[0.6875rem] text-muted-foreground">{helperText}</p>
        )}
      </div>
    )
  }
)

ColorPicker.displayName = "ColorPicker"

export { ColorPicker }
