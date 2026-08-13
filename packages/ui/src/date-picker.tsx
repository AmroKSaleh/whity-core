"use client"

import * as React from "react"
import {
  IconCalendar,
  IconChevronDown,
  IconChevronLeft,
  IconChevronRight,
  IconInfoCircle,
  IconX,
} from "@tabler/icons-react"

import { cn } from "./utils"
import { Popover, PopoverContent, PopoverTrigger } from "./popover"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "./tooltip"

export interface DatePickerProps {
  /**
   * The selected Date object or ISO string.
   */
  value?: Date | string
  /**
   * Default Date object or ISO string.
   */
  defaultValue?: Date | string
  /**
   * Callback fired when a date is selected or cleared.
   */
  onChange?: (date: Date | null) => void
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
   * Placeholder text when no date is selected. Defaults to "Select date...".
   */
  placeholder?: string
  /** Accessible name for the clear button. */
  clearLabel?: string
  /** Accessible name for a tooltip trigger. Defaults to "More information". */
  tooltipTriggerLabel?: string
  /** Accessible names for the month navigation buttons. */
  previousMonthLabel?: string
  nextMonthLabel?: string
  /** Footer button text. */
  todayLabel?: string
  clearButtonLabel?: string
  /**
   * Disables the date picker control.
   */
  disabled?: boolean
  /**
   * Allows clearing the selected date. Defaults to true.
   */
  clearable?: boolean
  /**
   * Custom container class name.
   */
  className?: string
  id?: string
  name?: string
}

const MONTH_NAMES = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December"
]

const DAY_NAMES = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"]

function parseDateValue(val?: Date | string): Date | null {
  if (!val) return null
  if (val instanceof Date) return isNaN(val.getTime()) ? null : val
  const parsed = new Date(val)
  return isNaN(parsed.getTime()) ? null : parsed
}

function formatDate(date: Date): string {
  const m = MONTH_NAMES[date.getMonth()].slice(0, 3)
  const d = date.getDate()
  const y = date.getFullYear()
  return `${m} ${d}, ${y}`
}

function isSameDay(d1: Date, d2: Date): boolean {
  return (
    d1.getFullYear() === d2.getFullYear() &&
    d1.getMonth() === d2.getMonth() &&
    d1.getDate() === d2.getDate()
  )
}

const DatePicker = React.forwardRef<HTMLDivElement, DatePickerProps>(
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
      placeholder = "Select date...",
      clearLabel = "Clear date",
      tooltipTriggerLabel = "More information",
      previousMonthLabel = "Previous month",
      nextMonthLabel = "Next month",
      todayLabel = "Today",
      clearButtonLabel = "Clear",
      disabled,
      clearable = true,
      className,
      id,
      name,
    },
    ref
  ) => {
    const [selectedDate, setSelectedDate] = React.useState<Date | null>(() => {
      return parseDateValue(value ?? defaultValue)
    })
    const [open, setOpen] = React.useState(false)

    React.useEffect(() => {
      if (value !== undefined) {
        setSelectedDate(parseDateValue(value))
      }
    }, [value])

    const activeViewDate = selectedDate || new Date()
    const [viewDate, setViewDate] = React.useState<Date>(activeViewDate)

    const handleSelectDay = (day: Date) => {
      setSelectedDate(day)
      onChange?.(day)
      setOpen(false)
    }

    const handleClear = (e: React.MouseEvent) => {
      e.stopPropagation()
      setSelectedDate(null)
      onChange?.(null)
    }

    const today = new Date()

    // Calendar grid math
    const year = viewDate.getFullYear()
    const month = viewDate.getMonth()
    const firstDay = new Date(year, month, 1)
    const startDayOfWeek = firstDay.getDay()
    const daysInMonth = new Date(year, month + 1, 0).getDate()

    const prevMonthLastDay = new Date(year, month, 0).getDate()
    const prevDays: Date[] = Array.from({ length: startDayOfWeek }, (_, i) => {
      const dayNum = prevMonthLastDay - startDayOfWeek + i + 1
      return new Date(year, month - 1, dayNum)
    })

    const currentDays: Date[] = Array.from({ length: daysInMonth }, (_, i) => {
      return new Date(year, month, i + 1)
    })

    const totalGrid = prevDays.length + currentDays.length <= 35 ? 35 : 42
    const nextDaysCount = totalGrid - (prevDays.length + currentDays.length)
    const nextDays: Date[] = Array.from({ length: nextDaysCount }, (_, i) => {
      return new Date(year, month + 1, i + 1)
    })

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
              !selectedDate && "text-muted-foreground font-normal",
              className
            )}
          >
            <div className="absolute start-2.5 flex items-center justify-center size-3.5 text-muted-foreground pointer-events-none shrink-0 z-10">
              <IconCalendar className="size-3.5 shrink-0" aria-hidden="true" />
            </div>

            <span className="truncate">{selectedDate ? formatDate(selectedDate) : placeholder}</span>

            <div className="absolute end-2.5 flex items-center justify-center gap-1 shrink-0 z-10">
              {clearable && selectedDate && !disabled && (
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
          {/* Header Month / Year Nav */}
          <div className="flex items-center justify-between pb-2 mb-2 border-b border-border/50">
            <button
              type="button"
              onClick={() => setViewDate(new Date(year, month - 1, 1))}
              aria-label={previousMonthLabel}
              className="flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
            >
              <IconChevronLeft className="size-4" />
            </button>
            <span className="text-xs font-semibold text-foreground">
              {MONTH_NAMES[month]} {year}
            </span>
            <button
              type="button"
              onClick={() => setViewDate(new Date(year, month + 1, 1))}
              aria-label={nextMonthLabel}
              className="flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
            >
              <IconChevronRight className="size-4" />
            </button>
          </div>

          {/* Day Names Row */}
          <div className="grid grid-cols-7 gap-1 text-center mb-1">
            {DAY_NAMES.map((dayName) => (
              <span key={dayName} className="text-[0.625rem] font-semibold text-muted-foreground uppercase">
                {dayName}
              </span>
            ))}
          </div>

          {/* Calendar Grid */}
          <div className="grid grid-cols-7 gap-1 text-center">
            {prevDays.map((d, idx) => (
              <button
                key={`prev-${idx}`}
                type="button"
                onClick={() => {
                  setViewDate(new Date(d.getFullYear(), d.getMonth(), 1))
                  handleSelectDay(d)
                }}
                className="flex h-7 w-full items-center justify-center rounded-md text-xs text-muted-foreground/35 hover:bg-muted/40 transition-colors"
              >
                {d.getDate()}
              </button>
            ))}

            {currentDays.map((d) => {
              const isSelected = selectedDate ? isSameDay(d, selectedDate) : false
              const isTodayDay = isSameDay(d, today)
              return (
                <button
                  key={d.toISOString()}
                  type="button"
                  onClick={() => handleSelectDay(d)}
                  className={cn(
                    "flex h-7 w-full items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground",
                    isTodayDay && !isSelected && "border border-primary/40 font-bold text-primary",
                    isSelected && "bg-primary text-primary-foreground font-semibold shadow-2xs hover:bg-primary/90"
                  )}
                >
                  {d.getDate()}
                </button>
              )
            })}

            {nextDays.map((d, idx) => (
              <button
                key={`next-${idx}`}
                type="button"
                onClick={() => {
                  setViewDate(new Date(d.getFullYear(), d.getMonth(), 1))
                  handleSelectDay(d)
                }}
                className="flex h-7 w-full items-center justify-center rounded-md text-xs text-muted-foreground/35 hover:bg-muted/40 transition-colors"
              >
                {d.getDate()}
              </button>
            ))}
          </div>

          {/* Footer Today Button */}
          <div className="flex items-center justify-between pt-2 mt-2 border-t border-border/50">
            <button
              type="button"
              onClick={() => {
                const now = new Date()
                setViewDate(now)
                handleSelectDay(now)
              }}
              className="text-[0.6875rem] font-semibold text-primary hover:underline"
            >
              {todayLabel}
            </button>
            {selectedDate && (
              <button
                type="button"
                onClick={() => {
                  setSelectedDate(null)
                  onChange?.(null)
                  setOpen(false)
                }}
                className="text-[0.6875rem] text-muted-foreground hover:text-foreground"
              >
                {clearButtonLabel}
              </button>
            )}
          </div>
        </PopoverContent>
      </Popover>
    )

    if (name && selectedDate) {
      const isoValue = selectedDate.toISOString().split("T")[0]
      return (
        <div ref={ref} className="w-full">
          <input type="hidden" name={name} value={isoValue} />
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

DatePicker.displayName = "DatePicker"

export { DatePicker }
