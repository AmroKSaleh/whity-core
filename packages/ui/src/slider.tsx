"use client"

import * as React from "react"
import { Slider as SliderPrimitive } from "radix-ui"
import { IconInfoCircle } from "@tabler/icons-react"

import { cn } from "./utils"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "./tooltip"

export interface SliderProps extends React.ComponentPropsWithoutRef<typeof SliderPrimitive.Root> {
  /**
   * Field label text displayed above the slider.
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
   * Small informational note rendered below the slider.
   */
  helperText?: React.ReactNode
  /**
   * Error text rendered below the slider in destructive red.
   */
  errorText?: React.ReactNode
  /**
   * Displays the current value badge next to the label. Defaults to true.
   */
  showValueBadge?: boolean
  /**
   * Renders direct numeric entry input field(s) alongside the slider track. Defaults to true.
   */
  showInput?: boolean
  /**
   * Optional formatter function for the value badge e.g. (val) => `${val}%`.
   */
  formatValue?: (value: number) => React.ReactNode
}

const Slider = React.forwardRef<
  React.ElementRef<typeof SliderPrimitive.Root>,
  SliderProps
>(
  (
    {
      className,
      label,
      required,
      tooltip,
      helperText,
      errorText,
      showValueBadge = true,
      showInput = true,
      formatValue,
      defaultValue,
      value,
      min = 0,
      max = 100,
      step = 1,
      disabled,
      onValueChange,
      ...props
    },
    ref
  ) => {
    const initialVals = Array.isArray(value)
      ? value
      : Array.isArray(defaultValue)
        ? defaultValue
        : [min]

    const [internalValues, setInternalValues] = React.useState<number[]>(initialVals)
    const [inputStrings, setInputStrings] = React.useState<string[]>(
      initialVals.map((v) => String(v))
    )

    React.useEffect(() => {
      let nextVals = initialVals
      if (Array.isArray(value)) {
        nextVals = value
      } else if (Array.isArray(defaultValue)) {
        nextVals = defaultValue
      }
      setInternalValues(nextVals)
      setInputStrings(nextVals.map((v) => String(v)))
    }, [value, defaultValue])

    const handleValueChange = (vals: number[]) => {
      setInternalValues(vals)
      setInputStrings(vals.map((v) => String(v)))
      onValueChange?.(vals)
    }

    const handleDirectInputChange = (index: number, rawVal: string) => {
      setInputStrings((prev) => {
        const next = [...prev]
        next[index] = rawVal
        return next
      })

      const parsed = parseFloat(rawVal)
      if (isNaN(parsed)) return

      const newVals = [...internalValues]
      if (newVals.length === 1) {
        const clamped = Math.min(Math.max(parsed, min), max)
        newVals[0] = clamped
        setInternalValues(newVals)
        onValueChange?.(newVals)
      } else if (newVals.length === 2) {
        if (index === 0) {
          const clamped = Math.min(Math.max(parsed, min), newVals[1])
          newVals[0] = clamped
        } else {
          const clamped = Math.min(Math.max(parsed, newVals[0]), max)
          newVals[1] = clamped
        }
        setInternalValues(newVals)
        onValueChange?.(newVals)
      }
    }

    const handleInputBlur = (index: number) => {
      let parsed = parseFloat(inputStrings[index])
      if (isNaN(parsed)) {
        parsed = internalValues[index] ?? min
      }
      const newVals = [...internalValues]
      if (newVals.length === 1) {
        const clamped = Math.min(Math.max(parsed, min), max)
        newVals[0] = clamped
      } else if (newVals.length === 2) {
        if (index === 0) {
          const clamped = Math.min(Math.max(parsed, min), newVals[1])
          newVals[0] = clamped
        } else {
          const clamped = Math.min(Math.max(parsed, newVals[0]), max)
          newVals[1] = clamped
        }
      }
      setInternalValues(newVals)
      setInputStrings(newVals.map((v) => String(v)))
      onValueChange?.(newVals)
    }

    const renderDisplayVal = () => {
      if (!internalValues || internalValues.length === 0) return null
      if (internalValues.length === 1) {
        const val = internalValues[0]
        return formatValue ? formatValue(val) : val
      }
      const val1 = formatValue ? formatValue(internalValues[0]) : internalValues[0]
      const val2 = formatValue ? formatValue(internalValues[1]) : internalValues[1]
      return `${val1} – ${val2}`
    }

    const hasHeader = label || tooltip || required || (showValueBadge && (!showInput || !!formatValue))
    const hasFooter = helperText || errorText

    const isRange = internalValues.length > 1

    const sliderPrimitive = (
      <SliderPrimitive.Root
        ref={ref}
        data-slot="slider"
        disabled={disabled}
        defaultValue={defaultValue}
        value={value}
        min={min}
        max={max}
        step={step}
        onValueChange={handleValueChange}
        className={cn(
          "relative flex w-full touch-none select-none items-center py-1.5",
          disabled && "pointer-events-none opacity-50",
          className
        )}
        {...props}
      >
        <SliderPrimitive.Track className="relative h-1.5 w-full grow overflow-hidden rounded-full bg-muted">
          <SliderPrimitive.Range className="absolute h-full bg-primary" />
        </SliderPrimitive.Track>
        <SliderPrimitive.Thumb className="block size-4 rounded-full border-2 border-primary bg-card shadow-2xs transition-colors hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 cursor-grab active:cursor-grabbing" />
        {isRange && (
          <SliderPrimitive.Thumb className="block size-4 rounded-full border-2 border-primary bg-card shadow-2xs transition-colors hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 cursor-grab active:cursor-grabbing" />
        )}
      </SliderPrimitive.Root>
    )

    const renderCombinedSlider = () => {
      if (!showInput) return sliderPrimitive

      if (!isRange) {
        return (
          <div className="flex items-center gap-3 w-full">
            <div className="flex-1 min-w-0">{sliderPrimitive}</div>
            <input
              type="number"
              min={min}
              max={max}
              step={step}
              disabled={disabled}
              value={inputStrings[0] ?? ""}
              onChange={(e) => handleDirectInputChange(0, e.target.value)}
              onBlur={() => handleInputBlur(0)}
              onKeyDown={(e) => e.key === "Enter" && handleInputBlur(0)}
              className="h-7 w-14 shrink-0 rounded-md border border-input bg-card px-1.5 py-0.5 text-center text-xs font-medium text-foreground transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
            />
          </div>
        )
      }

      return (
        <div className="flex items-center gap-2.5 w-full">
          <input
            type="number"
            min={min}
            max={internalValues[1]}
            step={step}
            disabled={disabled}
            value={inputStrings[0] ?? ""}
            onChange={(e) => handleDirectInputChange(0, e.target.value)}
            onBlur={() => handleInputBlur(0)}
            onKeyDown={(e) => e.key === "Enter" && handleInputBlur(0)}
            className="h-7 w-14 shrink-0 rounded-md border border-input bg-card px-1.5 py-0.5 text-center text-xs font-medium text-foreground transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
          />
          <div className="flex-1 min-w-0">{sliderPrimitive}</div>
          <input
            type="number"
            min={internalValues[0]}
            max={max}
            step={step}
            disabled={disabled}
            value={inputStrings[1] ?? ""}
            onChange={(e) => handleDirectInputChange(1, e.target.value)}
            onBlur={() => handleInputBlur(1)}
            onKeyDown={(e) => e.key === "Enter" && handleInputBlur(1)}
            className="h-7 w-14 shrink-0 rounded-md border border-input bg-card px-1.5 py-0.5 text-center text-xs font-medium text-foreground transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
          />
        </div>
      )
    }

    if (!hasHeader && !hasFooter) {
      return renderCombinedSlider()
    }

    return (
      <div className="flex flex-col gap-1.5 w-full text-start">
        {hasHeader && (
          <div className="flex items-center justify-between gap-2 text-xs font-semibold text-foreground">
            <div className="flex items-center gap-1">
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
            {showValueBadge && (!showInput || !!formatValue) && (
              <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.6875rem] font-semibold text-muted-foreground">
                {renderDisplayVal()}
              </span>
            )}
          </div>
        )}
        {renderCombinedSlider()}
        {errorText ? (
          <p className="text-[0.6875rem] font-medium text-destructive">{errorText}</p>
        ) : (
          helperText && <p className="text-[0.6875rem] text-muted-foreground">{helperText}</p>
        )}
      </div>
    )
  }
)

Slider.displayName = "Slider"

export { Slider }
