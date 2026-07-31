"use client"

import * as React from "react"
import {
  IconChevronDown,
  IconClock,
  IconInfoCircle,
  IconX,
} from "@tabler/icons-react"

import { cn } from "./utils"
import { Popover, PopoverContent, PopoverTrigger } from "./popover"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "./tooltip"

export interface TimePickerProps {
  /**
   * Selected time string e.g. "09:30 AM" or "14:30".
   */
  value?: string
  /**
   * Default time string.
   */
  defaultValue?: string
  /**
   * Callback fired when time changes or is cleared.
   */
  onChange?: (time: string | null) => void
  /**
   * Field label text displayed statically above the input field.
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
   * Small informational note rendered below the input field.
   */
  helperText?: React.ReactNode
  /**
   * Error text rendered below the input field in destructive red.
   */
  errorText?: React.ReactNode
  /**
   * Placeholder text when no time is selected. Defaults to "Select time...".
   */
  placeholder?: string
  /**
   * Time display format. Defaults to "12h".
   */
  format?: "12h" | "24h"
  /**
   * Minute step intervals e.g. 1, 5, 15, 30. Defaults to 1 for full granularity.
   */
  minuteStep?: number
  /**
   * Disables the time picker control.
   */
  disabled?: boolean
  /**
   * Allows clearing the selected time. Defaults to true.
   */
  clearable?: boolean
  /**
   * Custom container class name.
   */
  className?: string
  id?: string
  name?: string
}

function parseTimeString(val?: string, format: "12h" | "24h" = "12h") {
  if (!val) return { hour: "09", minute: "00", period: "AM" }
  const match12 = val.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/i)
  if (match12) {
    let h = parseInt(match12[1], 10)
    let p = (match12[3] || "AM").toUpperCase()
    if (format === "24h") {
      return {
        hour: String(h).padStart(2, "0"),
        minute: match12[2],
        period: "AM",
      }
    }
    if (h === 0) h = 12
    if (h > 12) {
      h = h - 12
      p = "PM"
    }
    return {
      hour: String(h).padStart(2, "0"),
      minute: match12[2],
      period: p,
    }
  }
  return { hour: "09", minute: "00", period: "AM" }
}

const HOURS_12 = Array.from({ length: 12 }, (_, i) => String(i + 1).padStart(2, "0"))
const HOURS_24 = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, "0"))

const TimePicker = React.forwardRef<HTMLDivElement, TimePickerProps>(
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
      placeholder = "Select time...",
      format = "12h",
      minuteStep = 1,
      disabled,
      clearable = true,
      className,
      id,
      name,
    },
    ref
  ) => {
    const [selectedTime, setSelectedTime] = React.useState<string | null>(() => {
      return value ?? defaultValue ?? null
    })
    const [activeFormat, setActiveFormat] = React.useState<"12h" | "24h">(format)
    const [open, setOpen] = React.useState(false)

    React.useEffect(() => {
      if (value !== undefined) {
        setSelectedTime(value)
      }
    }, [value])

    React.useEffect(() => {
      setActiveFormat(format)
    }, [format])

    const parsed = parseTimeString(selectedTime || undefined, activeFormat)
    const [hour, setHour] = React.useState(parsed.hour)
    const [minute, setMinute] = React.useState(parsed.minute)
    const [period, setPeriod] = React.useState(parsed.period)

    React.useEffect(() => {
      if (selectedTime) {
        const p = parseTimeString(selectedTime, activeFormat)
        setHour(p.hour)
        setMinute(p.minute)
        setPeriod(p.period)
      }
    }, [selectedTime, activeFormat])

    const minutesList = React.useMemo(() => {
      const list: string[] = []
      for (let i = 0; i < 60; i += minuteStep) {
        list.push(String(i).padStart(2, "0"))
      }
      return list
    }, [minuteStep])

    const hoursList = activeFormat === "24h" ? HOURS_24 : HOURS_12

    const updateTime = (newHour: string, newMinute: string, newPeriod: string, fmt = activeFormat) => {
      setHour(newHour)
      setMinute(newMinute)
      setPeriod(newPeriod)
      const formatted = fmt === "24h" ? `${newHour}:${newMinute}` : `${newHour}:${newMinute} ${newPeriod}`
      setSelectedTime(formatted)
      onChange?.(formatted)
    }

    const incrementHour = (h: string, p: string, fmt: "12h" | "24h") => {
      let nextH = h
      let nextP = p
      if (fmt === "12h") {
        const list = HOURS_12
        const currIdx = list.indexOf(h)
        const nextIdx = (currIdx + 1) % list.length
        nextH = list[nextIdx]
        if (h === "12" && nextH === "01") {
          nextP = p === "AM" ? "PM" : "AM"
        }
      } else {
        const list = HOURS_24
        const currIdx = list.indexOf(h)
        const nextIdx = (currIdx + 1) % list.length
        nextH = list[nextIdx]
      }
      return { nextH, nextP }
    }

    const decrementHour = (h: string, p: string, fmt: "12h" | "24h") => {
      let nextH = h
      let nextP = p
      if (fmt === "12h") {
        const list = HOURS_12
        const currIdx = list.indexOf(h)
        const prevIdx = (currIdx - 1 + list.length) % list.length
        nextH = list[prevIdx]
        if (h === "01" && nextH === "12") {
          nextP = p === "AM" ? "PM" : "AM"
        }
      } else {
        const list = HOURS_24
        const currIdx = list.indexOf(h)
        const prevIdx = (currIdx - 1 + list.length) % list.length
        nextH = list[prevIdx]
      }
      return { nextH, nextP }
    }

    const handleHourWheel = (e: React.WheelEvent) => {
      e.preventDefault()
      if (e.deltaY > 0) {
        const { nextH, nextP } = incrementHour(hour, period, activeFormat)
        updateTime(nextH, minute, nextP)
      } else if (e.deltaY < 0) {
        const { nextH, nextP } = decrementHour(hour, period, activeFormat)
        updateTime(nextH, minute, nextP)
      }
    }

    const handleMinuteWheel = (e: React.WheelEvent) => {
      e.preventDefault()
      const currIdx = minutesList.indexOf(minute)
      if (currIdx === -1) return
      if (e.deltaY > 0) {
        if (currIdx === minutesList.length - 1) {
          const nextM = minutesList[0]
          const { nextH, nextP } = incrementHour(hour, period, activeFormat)
          updateTime(nextH, nextM, nextP)
        } else {
          updateTime(hour, minutesList[currIdx + 1], period)
        }
      } else if (e.deltaY < 0) {
        if (currIdx === 0) {
          const nextM = minutesList[minutesList.length - 1]
          const { nextH, nextP } = decrementHour(hour, period, activeFormat)
          updateTime(nextH, nextM, nextP)
        } else {
          updateTime(hour, minutesList[currIdx - 1], period)
        }
      }
    }

    const handleClear = (e: React.MouseEvent) => {
      e.stopPropagation()
      setSelectedTime(null)
      onChange?.(null)
    }

    const handleSetNow = () => {
      const now = new Date()
      let h = now.getHours()
      const m = String(Math.floor(now.getMinutes() / minuteStep) * minuteStep).padStart(2, "0")
      let p = "AM"
      if (activeFormat === "12h") {
        p = h >= 12 ? "PM" : "AM"
        h = h % 12 || 12
      }
      const formattedH = String(h).padStart(2, "0")
      updateTime(formattedH, m, p)
    }

    const renderDrumColumn = (
      colLabel: string,
      items: string[],
      selectedVal: string,
      onSelect: (val: string) => void,
      onWheel: (e: React.WheelEvent) => void
    ) => {
      const currIdx = items.indexOf(selectedVal) >= 0 ? items.indexOf(selectedVal) : 0
      const N = items.length

      const top2Idx = (currIdx - 2 + N) % N
      const top1Idx = (currIdx - 1 + N) % N
      const centerIdx = currIdx
      const bot1Idx = (currIdx + 1) % N
      const bot2Idx = (currIdx + 2) % N

      return (
        <div className="flex flex-col items-center gap-1">
          <span className="text-[0.625rem] font-semibold text-muted-foreground uppercase">{colLabel}</span>
          <div
            onWheel={onWheel}
            className="relative flex flex-col items-center justify-center w-16 select-none rounded-xl border border-border/60 bg-card p-1 shadow-2xs"
          >
            {/* Permanent Center Selection Lens */}
            <div className="pointer-events-none absolute inset-x-1 top-1/2 h-8 -translate-y-1/2 rounded-lg bg-primary text-primary-foreground shadow-2xs" />

            {/* Row 1 (-2) */}
            <button
              type="button"
              onClick={() => onSelect(items[top2Idx])}
              className="flex h-6 w-full items-center justify-center text-[0.6875rem] font-medium text-muted-foreground/40 transition-colors hover:text-foreground"
            >
              {items[top2Idx]}
            </button>

            {/* Row 2 (-1) */}
            <button
              type="button"
              onClick={() => onSelect(items[top1Idx])}
              className="flex h-6 w-full items-center justify-center text-xs font-medium text-muted-foreground/70 transition-colors hover:text-foreground"
            >
              {items[top1Idx]}
            </button>

            {/* Row 3 (CENTERED LENS) */}
            <div className="relative z-10 flex h-8 w-full items-center justify-center text-sm font-bold text-primary-foreground">
              {items[centerIdx]}
            </div>

            {/* Row 4 (+1) */}
            <button
              type="button"
              onClick={() => onSelect(items[bot1Idx])}
              className="flex h-6 w-full items-center justify-center text-xs font-medium text-muted-foreground/70 transition-colors hover:text-foreground"
            >
              {items[bot1Idx]}
            </button>

            {/* Row 5 (+2) */}
            <button
              type="button"
              onClick={() => onSelect(items[bot2Idx])}
              className="flex h-6 w-full items-center justify-center text-[0.6875rem] font-medium text-muted-foreground/40 transition-colors hover:text-foreground"
            >
              {items[bot2Idx]}
            </button>
          </div>
        </div>
      )
    }

    const hasHeader = label || tooltip || required
    const hasFooter = helperText || errorText

    const triggerButton = (
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            id={id}
            disabled={disabled}
            className={cn(
              "relative flex h-7 w-full items-center justify-start rounded-md border border-input bg-card ps-8 pe-8 py-0.5 text-xs font-medium text-foreground transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20",
              !selectedTime && "text-muted-foreground font-normal",
              className
            )}
          >
            <div className="absolute start-2.5 flex items-center justify-center size-3.5 text-muted-foreground pointer-events-none shrink-0 z-10">
              <IconClock className="size-3.5 shrink-0" aria-hidden="true" />
            </div>

            <span className="truncate">{selectedTime || placeholder}</span>

            <div className="absolute end-2.5 flex items-center justify-center gap-1 shrink-0 z-10">
              {clearable && selectedTime && !disabled && (
                <span
                  role="button"
                  tabIndex={-1}
                  onClick={handleClear}
                  aria-label="Clear time"
                  className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                  <IconX className="size-3.5" aria-hidden="true" />
                </span>
              )}
              <IconChevronDown className="size-3.5 text-muted-foreground transition-transform duration-200 group-data-[state=open]:rotate-180" />
            </div>
          </button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-64 p-3 shadow-lg border border-border bg-popover rounded-xl">
          {/* Header & 12H/24H format selector + AM/PM toggle */}
          <div className="flex items-center justify-between pb-2 mb-2 border-b border-border/50">
            <span className="text-xs font-semibold text-foreground">Time</span>
            <div className="flex items-center gap-1.5">
              <div className="flex items-center rounded-md bg-muted p-0.5 text-[0.625rem] font-semibold">
                <button
                  type="button"
                  onClick={() => {
                    setActiveFormat("12h")
                    if (activeFormat === "24h") {
                      let h = parseInt(hour, 10)
                      const p = h >= 12 ? "PM" : "AM"
                      h = h % 12 || 12
                      updateTime(String(h).padStart(2, "0"), minute, p, "12h")
                    }
                  }}
                  className={cn(
                    "px-1.5 py-0.5 rounded transition-colors",
                    activeFormat === "12h" ? "bg-card text-foreground shadow-2xs font-bold" : "text-muted-foreground hover:text-foreground"
                  )}
                >
                  12H
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setActiveFormat("24h")
                    if (activeFormat === "12h") {
                      let h = parseInt(hour, 10)
                      if (period === "PM" && h < 12) h += 12
                      if (period === "AM" && h === 12) h = 0
                      updateTime(String(h).padStart(2, "0"), minute, "AM", "24h")
                    }
                  }}
                  className={cn(
                    "px-1.5 py-0.5 rounded transition-colors",
                    activeFormat === "24h" ? "bg-card text-foreground shadow-2xs font-bold" : "text-muted-foreground hover:text-foreground"
                  )}
                >
                  24H
                </button>
              </div>

              {activeFormat === "12h" && (
                <div className="flex items-center rounded-md bg-muted p-0.5 text-[0.625rem] font-semibold">
                  <button
                    type="button"
                    onClick={() => updateTime(hour, minute, "AM", activeFormat)}
                    className={cn(
                      "px-1.5 py-0.5 rounded transition-colors",
                      period === "AM" ? "bg-card text-foreground shadow-2xs font-bold" : "text-muted-foreground hover:text-foreground"
                    )}
                  >
                    AM
                  </button>
                  <button
                    type="button"
                    onClick={() => updateTime(hour, minute, "PM", activeFormat)}
                    className={cn(
                      "px-1.5 py-0.5 rounded transition-colors",
                      period === "PM" ? "bg-card text-foreground shadow-2xs font-bold" : "text-muted-foreground hover:text-foreground"
                    )}
                  >
                    PM
                  </button>
                </div>
              )}
            </div>
          </div>

          {/* Fixed Drum Wheel Columns: Selected Value ALWAYS Centered */}
          <div className="flex items-center justify-center gap-4 py-1">
            {renderDrumColumn(
              "Hour",
              hoursList,
              hour,
              (h) => {
                // When selecting a new hour in 12H mode, check if we rolled from 12->01 or 01->12
                if (activeFormat === "12h") {
                  let p = period
                  if (hour === "12" && h === "01") p = period === "AM" ? "PM" : "AM"
                  if (hour === "01" && h === "12") p = period === "AM" ? "PM" : "AM"
                  updateTime(h, minute, p)
                } else {
                  updateTime(h, minute, period)
                }
              },
              handleHourWheel
            )}
            <span className="text-sm font-bold text-muted-foreground pt-5">:</span>
            {renderDrumColumn(
              "Minute",
              minutesList,
              minute,
              (m) => updateTime(hour, m, period),
              handleMinuteWheel
            )}
          </div>

          <p className="mt-1 text-center text-[0.625rem] text-muted-foreground">
            Scroll wheel or click numbers to cycle time
          </p>

          {/* Footer Now / Clear */}
          <div className="flex items-center justify-between pt-2 mt-2 border-t border-border/50">
            <button
              type="button"
              onClick={handleSetNow}
              className="text-[0.6875rem] font-semibold text-primary hover:underline"
            >
              Now
            </button>
            {selectedTime && (
              <button
                type="button"
                onClick={() => {
                  setSelectedTime(null)
                  onChange?.(null)
                  setOpen(false)
                }}
                className="text-[0.6875rem] text-muted-foreground hover:text-foreground"
              >
                Clear
              </button>
            )}
          </div>
        </PopoverContent>
      </Popover>
    )

    if (name && selectedTime) {
      return (
        <div ref={ref} className="w-full">
          <input type="hidden" name={name} value={selectedTime} />
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
                            aria-label="More information"
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
                      aria-label="More information"
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

TimePicker.displayName = "TimePicker"

export { TimePicker }
