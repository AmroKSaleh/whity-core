import * as React from "react"
import {
  IconCalendarTime,
  IconCloudUpload,
  IconEye,
  IconEyeOff,
  IconInfoCircle,
  IconMinus,
  IconPaperclip,
  IconPlus,
  IconSearch,
  IconX,
} from "@tabler/icons-react"

import { cn } from "./utils"
import { DatePicker } from "./date-picker"
import { TimePicker } from "./time-picker"
import { ColorPicker } from "./color-picker"
import { Slider } from "./slider"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "./tooltip"

export interface InputProps extends React.ComponentProps<"input"> {
  /**
   * Field label text or node displayed statically above the input field.
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
   * For type="password", automatically renders a toggle button to show/hide
   * password text. Defaults to true. Set to false to disable the toggle button.
   */
  showPasswordToggle?: boolean
  /**
   * For type="number", automatically removes default browser spinner arrows and
   * renders custom +/- stepper buttons. Defaults to true. Set to false to disable.
   */
  showNumberStepper?: boolean
  /**
   * For type="file", chooses between a prominent drag-and-drop upload zone ("dropzone", default)
   * or a compact inline file picker ("compact").
   */
  fileVariant?: "dropzone" | "compact"
  /** Accessible name for a tooltip trigger. Defaults to "More information". */
  tooltipTriggerLabel?: string
  /** Accessible name for the password reveal toggle, by state. */
  showPasswordLabel?: string
  hidePasswordLabel?: string
  /** Accessible names for the number stepper buttons. */
  decrementLabel?: string
  incrementLabel?: string
  /**
   * Prompt inside the file dropzone. A NODE, not a string, because the
   * default is one sentence with a link-styled span inside it ("Click to
   * browse or drag and drop files"). Splitting that into two string props
   * would hand a translator two fragments they cannot reorder, which is
   * exactly what a whole-sentence key avoids — so the caller passes the
   * finished node instead.
   */
  dropzonePrompt?: React.ReactNode
  /** Hint under the dropzone prompt, by `multiple`. */
  multipleFilesHint?: string
  singleFileHint?: string
}

function formatBytes(bytes: number): string {
  if (!bytes || bytes <= 0) return "0 B"
  const k = 1024
  const sizes = ["B", "KB", "MB", "GB"]
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`
}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
  (
    {
      className,
      type,
      label,
      required,
      tooltip,
      helperText,
      errorText,
      showPasswordToggle = true,
      showNumberStepper = true,
      fileVariant = "dropzone",
      tooltipTriggerLabel = "More information",
      showPasswordLabel = "Show password",
      hidePasswordLabel = "Hide password",
      decrementLabel = "Decrease value",
      incrementLabel = "Increase value",
      dropzonePrompt,
      multipleFilesHint = "Upload multiple files",
      singleFileHint = "Upload single file",
      disabled,
      id,
      ...props
    },
    ref
  ) => {
    const internalRef = React.useRef<HTMLInputElement>(null)
    React.useImperativeHandle(ref, () => internalRef.current!)

    // Generate a unique ID for the input if not provided and label exists.
    // This ensures proper label association for accessibility.
    // If id is provided, always use it. Otherwise, generate one only if label exists.
    const inputId = React.useMemo(() => {
      if (id) return id
      if (label) {
        return `input-${Math.random().toString(36).substr(2, 9)}`
      }
      return undefined
    }, [id, label])

    const [showPassword, setShowPassword] = React.useState(false)
    const [selectedFiles, setSelectedFiles] = React.useState<File[]>([])
    const [isDragging, setIsDragging] = React.useState(false)

    const handleStep = (direction: 1 | -1) => {
      const input = internalRef.current
      if (!input || disabled) return
      try {
        if (direction === 1) {
          input.stepUp()
        } else {
          input.stepDown()
        }
      } catch {
        const current = Number(input.value) || 0
        const step = Number(props.step) || 1
        input.value = String(current + direction * step)
      }
      const nativeSetter = Object.getOwnPropertyDescriptor(
        window.HTMLInputElement.prototype,
        "value"
      )?.set
      nativeSetter?.call(input, input.value)
      input.dispatchEvent(new Event("input", { bubbles: true }))
      input.dispatchEvent(new Event("change", { bubbles: true }))
    }

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
      if (e.target.files) {
        const filesArr = Array.from(e.target.files)
        setSelectedFiles(filesArr)
      }
      props.onChange?.(e)
    }

    const handleDropFiles = (droppedFiles: File[]) => {
      if (disabled || droppedFiles.length === 0) return
      let nextFiles = droppedFiles
      if (!props.multiple) {
        nextFiles = [droppedFiles[0]]
      } else {
        const existingKeys = new Set(selectedFiles.map((f) => `${f.name}-${f.size}`))
        const added = droppedFiles.filter((f) => !existingKeys.has(`${f.name}-${f.size}`))
        nextFiles = [...selectedFiles, ...added]
      }
      setSelectedFiles(nextFiles)

      const input = internalRef.current
      if (input) {
        const dt = new DataTransfer()
        nextFiles.forEach((f) => dt.items.add(f))
        const nativeSetter = Object.getOwnPropertyDescriptor(
          window.HTMLInputElement.prototype,
          "files"
        )?.set
        nativeSetter?.call(input, dt.files)
        input.dispatchEvent(new Event("input", { bubbles: true }))
        input.dispatchEvent(new Event("change", { bubbles: true }))
      }
    }

    const handleRemoveFile = (indexToRemove: number) => {
      const nextFiles = selectedFiles.filter((_, i) => i !== indexToRemove)
      setSelectedFiles(nextFiles)

      const input = internalRef.current
      if (input) {
        const dt = new DataTransfer()
        nextFiles.forEach((file) => dt.items.add(file))
        const nativeSetter = Object.getOwnPropertyDescriptor(
          window.HTMLInputElement.prototype,
          "files"
        )?.set
        nativeSetter?.call(input, dt.files)
        input.dispatchEvent(new Event("input", { bubbles: true }))
        input.dispatchEvent(new Event("change", { bubbles: true }))
      }
    }

    const isPassword = type === "password" && showPasswordToggle
    const isNumber = type === "number" && showNumberStepper
    const isFile = type === "file"
    const isDate = type === "date"
    const isTime = type === "time"
    const isColor = type === "color"
    const isDateTime = type === "datetime-local"
    const isSearch = type === "search"
    const isSlider = type === "range" || type === "slider"

    const renderInputControl = () => {
      if (isSlider) {
        return (
          <Slider
            disabled={disabled}
            className={className}
            min={props.min !== undefined ? Number(props.min) : 0}
            max={props.max !== undefined ? Number(props.max) : 100}
            step={props.step !== undefined ? Number(props.step) : 1}
            defaultValue={props.defaultValue !== undefined ? [Number(props.defaultValue)] : [0]}
            value={props.value !== undefined ? [Number(props.value)] : undefined}
            onValueChange={(vals) => {
              const val = String(vals[0])
              if (internalRef.current) {
                internalRef.current.value = val
                const nativeSetter = Object.getOwnPropertyDescriptor(
                  window.HTMLInputElement.prototype,
                  "value"
                )?.set
                nativeSetter?.call(internalRef.current, val)
                internalRef.current.dispatchEvent(new Event("input", { bubbles: true }))
                internalRef.current.dispatchEvent(new Event("change", { bubbles: true }))
              }
            }}
          />
        )
      }

      if (isColor) {
        return (
          <ColorPicker
            disabled={disabled}
            className={className}
            placeholder={props.placeholder}
            defaultValue={props.defaultValue as string}
            value={props.value as string}
            onChange={(c) => {
              if (internalRef.current) {
                const val = c || ""
                internalRef.current.value = val
                const nativeSetter = Object.getOwnPropertyDescriptor(
                  window.HTMLInputElement.prototype,
                  "value"
                )?.set
                nativeSetter?.call(internalRef.current, val)
                internalRef.current.dispatchEvent(new Event("input", { bubbles: true }))
                internalRef.current.dispatchEvent(new Event("change", { bubbles: true }))
              }
            }}
          />
        )
      }

      if (isDate) {
        return (
          <>
            {/*
              THE ELEMENT THE EVENT COMES FROM.

              The picker below reports a choice by writing to `internalRef` and
              dispatching a native event, which is how React's `onChange`
              receives it. Every other branch of this component renders the
              native input further down — these picker branches return early and
              never reached it, so `internalRef.current` was null, the whole
              dispatch body was skipped, and a chosen date never left the picker.
              The caller saw an empty value and a "required" error on a field
              they had just filled in.

              Uncontrolled on purpose: the dispatch sets `.value` through the
              native setter, and a React-controlled value would be reasserted
              over it on the next render.

              `type="date"` with the `hidden` ATTRIBUTE, not `type="hidden"`:
              React only tracks value changes for text-like inputs, and
              `hidden` is not one of them — the event would be dispatched onto
              an element React never listens to, and the same silent nothing
              would happen for a different reason.
            */}
            <input
              ref={internalRef}
              type="date"
              hidden
              name={props.name}
              onChange={props.onChange}
            />
          <DatePicker
            disabled={disabled}
            className={className}
            placeholder={props.placeholder}
            defaultValue={props.defaultValue as string}
            value={props.value as string}
            onChange={(d) => {
              if (internalRef.current) {
                const val = d ? d.toISOString().split("T")[0] : ""
                internalRef.current.value = val
                const nativeSetter = Object.getOwnPropertyDescriptor(
                  window.HTMLInputElement.prototype,
                  "value"
                )?.set
                nativeSetter?.call(internalRef.current, val)
                internalRef.current.dispatchEvent(new Event("input", { bubbles: true }))
                internalRef.current.dispatchEvent(new Event("change", { bubbles: true }))
              }
            }}
          />
          </>
        )
      }

      if (isTime) {
        return (
          <TimePicker
            disabled={disabled}
            className={className}
            placeholder={props.placeholder}
            defaultValue={props.defaultValue as string}
            value={props.value as string}
            onChange={(t) => {
              if (internalRef.current) {
                const val = t || ""
                internalRef.current.value = val
                const nativeSetter = Object.getOwnPropertyDescriptor(
                  window.HTMLInputElement.prototype,
                  "value"
                )?.set
                nativeSetter?.call(internalRef.current, val)
                internalRef.current.dispatchEvent(new Event("input", { bubbles: true }))
                internalRef.current.dispatchEvent(new Event("change", { bubbles: true }))
              }
            }}
          />
        )
      }

      if (isDateTime) {
        return (
          <div className="relative flex items-center w-full">
            <input
              ref={internalRef}
              id={inputId}
              type={type}
              data-slot="input"
              disabled={disabled}
              aria-invalid={!!errorText}
              aria-describedby={[
                errorText && inputId ? `${inputId}-error` : undefined,
                helperText && inputId ? `${inputId}-helper` : undefined,
              ]
                .filter(Boolean)
                .join(" ") || undefined}
              className={cn(
                "h-7 w-full min-w-0 rounded-md border border-input bg-card ps-2.5 pe-8 py-0.5 text-xs font-medium transition-colors outline-none placeholder:text-muted-foreground placeholder:text-xs focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20 [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-0",
                className
              )}
              {...props}
            />
            <button
              type="button"
              tabIndex={-1}
              disabled={disabled}
              onClick={() => {
                const input = internalRef.current
                if (!input) return
                if ("showPicker" in input && typeof (input as any).showPicker === "function") {
                  ;(input as any).showPicker()
                } else {
                  input.focus()
                }
              }}
              className="absolute end-2.5 flex items-center justify-center size-3.5 text-muted-foreground hover:text-foreground focus-visible:outline-none rounded transition-colors disabled:pointer-events-none disabled:opacity-50 z-10"
            >
              <IconCalendarTime className="size-3.5 shrink-0" aria-hidden="true" />
            </button>
          </div>
        )
      }

      if (isSearch) {
        return (
          <div className="relative flex items-center w-full">
            <div className="absolute start-2.5 flex items-center justify-center size-3.5 text-muted-foreground pointer-events-none shrink-0 z-10">
              <IconSearch className="size-3.5 shrink-0" aria-hidden="true" />
            </div>
            <input
              ref={internalRef}
              id={inputId}
              type="search"
              data-slot="input"
              disabled={disabled}
              aria-invalid={!!errorText}
              aria-describedby={[
                errorText && inputId ? `${inputId}-error` : undefined,
                helperText && inputId ? `${inputId}-helper` : undefined,
              ]
                .filter(Boolean)
                .join(" ") || undefined}
              className={cn(
                "h-7 w-full min-w-0 rounded-md border border-input bg-card ps-8 pe-2.5 py-0.5 text-xs font-medium transition-colors outline-none placeholder:text-muted-foreground placeholder:text-xs focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20 [&::-webkit-search-cancel-button]:cursor-pointer",
                className
              )}
              {...props}
            />
          </div>
        )
      }

      if (isPassword) {
        const inputType = showPassword ? "text" : "password"
        return (
          <div className="relative flex items-center w-full">
            <input
              ref={internalRef}
              id={inputId}
              type={inputType}
              data-slot="input"
              disabled={disabled}
              aria-invalid={!!errorText}
              aria-describedby={[
                errorText && inputId ? `${inputId}-error` : undefined,
                helperText && inputId ? `${inputId}-helper` : undefined,
              ]
                .filter(Boolean)
                .join(" ") || undefined}
              className={cn(
                "h-7 w-full min-w-0 rounded-md border border-input bg-card ps-2.5 pe-8 py-0.5 text-xs font-medium transition-colors outline-none placeholder:text-muted-foreground placeholder:text-xs focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20",
                className
              )}
              {...props}
            />
            <button
              type="button"
              disabled={disabled}
              onClick={() => setShowPassword((prev) => !prev)}
              aria-label={showPassword ? hidePasswordLabel : showPasswordLabel}
              title={showPassword ? hidePasswordLabel : showPasswordLabel}
              className="absolute end-2.5 flex items-center justify-center size-3.5 text-muted-foreground hover:text-foreground focus-visible:outline-none rounded transition-colors disabled:pointer-events-none disabled:opacity-50 z-10"
            >
              {showPassword ? (
                <IconEyeOff className="size-3.5 shrink-0" aria-hidden="true" />
              ) : (
                <IconEye className="size-3.5 shrink-0" aria-hidden="true" />
              )}
            </button>
          </div>
        )
      }

      if (isNumber) {
        return (
          <div className="relative flex items-center w-full">
            <button
              type="button"
              tabIndex={-1}
              disabled={disabled}
              onClick={() => handleStep(-1)}
              aria-label={decrementLabel}
              title={decrementLabel}
              className="absolute start-2.5 flex items-center justify-center size-3.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 z-10"
            >
              <IconMinus className="size-3.5 shrink-0" aria-hidden="true" />
            </button>
            <input
              ref={internalRef}
              id={inputId}
              type="number"
              data-slot="input"
              disabled={disabled}
              aria-invalid={!!errorText}
              aria-describedby={[
                errorText && inputId ? `${inputId}-error` : undefined,
                helperText && inputId ? `${inputId}-helper` : undefined,
              ]
                .filter(Boolean)
                .join(" ") || undefined}
              className={cn(
                "h-7 w-full min-w-0 rounded-md border border-input bg-card px-8 py-0.5 text-center text-xs font-medium transition-colors outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none placeholder:text-muted-foreground placeholder:text-xs focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20",
                className
              )}
              {...props}
            />
            <button
              type="button"
              tabIndex={-1}
              disabled={disabled}
              onClick={() => handleStep(1)}
              aria-label={incrementLabel}
              title={incrementLabel}
              className="absolute end-2.5 flex items-center justify-center size-3.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 z-10"
            >
              <IconPlus className="size-3.5 shrink-0" aria-hidden="true" />
            </button>
          </div>
        )
      }

      if (isFile && fileVariant === "dropzone") {
        return (
          <div className="flex flex-col gap-2.5 w-full">
            <div
              onDragOver={(e) => {
                e.preventDefault()
                e.stopPropagation()
                if (!disabled) setIsDragging(true)
              }}
              onDragLeave={(e) => {
                e.preventDefault()
                e.stopPropagation()
                setIsDragging(false)
              }}
              onDrop={(e) => {
                e.preventDefault()
                e.stopPropagation()
                setIsDragging(false)
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                  handleDropFiles(Array.from(e.dataTransfer.files))
                }
              }}
              onClick={() => internalRef.current?.click()}
              className={cn(
                "group flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-input bg-card/60 p-5 text-center transition-all cursor-pointer hover:border-primary/60 hover:bg-muted/30 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30",
                isDragging && "border-primary bg-primary/10 ring-2 ring-primary/20",
                disabled && "pointer-events-none opacity-50",
                className
              )}
            >
              <input
                ref={internalRef}
                id={inputId}
                type="file"
                data-slot="input"
                disabled={disabled}
                onChange={handleFileChange}
                aria-invalid={!!errorText}
                aria-describedby={[
                  errorText && inputId ? `${inputId}-error` : undefined,
                  helperText && inputId ? `${inputId}-helper` : undefined,
                ]
                  .filter(Boolean)
                  .join(" ") || undefined}
                className="sr-only"
                {...props}
              />
              <div className="flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary transition-transform group-hover:scale-105 mb-2">
                <IconCloudUpload className="size-5 shrink-0" aria-hidden="true" />
              </div>
              <p className="text-xs font-semibold text-foreground">
                {dropzonePrompt ?? (
                  <>
                    <span className="text-primary hover:underline">Click to browse</span> or drag
                    and drop files
                  </>
                )}
              </p>
              <p className="mt-0.5 text-[0.6875rem] text-muted-foreground">
                {props.multiple ? multipleFilesHint : singleFileHint}
              </p>
            </div>

            {selectedFiles.length > 0 && (
              <div className="flex flex-col gap-1.5 w-full">
                {selectedFiles.map((file, idx) => (
                  <div
                    key={`${file.name}-${idx}`}
                    className="flex items-center justify-between gap-3 rounded-lg border border-border/70 bg-card p-2 shadow-2xs transition-colors"
                  >
                    <div className="flex items-center gap-2.5 min-w-0">
                      <div className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                        <IconPaperclip className="size-4" aria-hidden="true" />
                      </div>
                      <div className="flex flex-col min-w-0 text-start">
                        <span className="truncate text-xs font-semibold text-foreground" title={file.name}>
                          {file.name}
                        </span>
                        <span className="text-[0.625rem] text-muted-foreground font-mono">
                          {formatBytes(file.size)}
                        </span>
                      </div>
                    </div>
                    <button
                      type="button"
                      disabled={disabled}
                      onClick={(e) => {
                        e.stopPropagation()
                        handleRemoveFile(idx)
                      }}
                      aria-label={`Remove ${file.name}`}
                      className="flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    >
                      <IconX className="size-3.5" aria-hidden="true" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        )
      }

      if (isFile && fileVariant === "compact") {
        return (
          <div
            className={cn(
              "flex min-h-7 w-full flex-wrap items-center gap-1.5 rounded-md border border-input bg-card p-1 text-xs font-medium transition-colors focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30",
              disabled && "pointer-events-none opacity-50",
              className
            )}
          >
            <input
              ref={internalRef}
              id={inputId}
              type="file"
              data-slot="input"
              disabled={disabled}
              onChange={handleFileChange}
              aria-invalid={!!errorText}
              aria-describedby={[
                errorText && inputId ? `${inputId}-error` : undefined,
                helperText && inputId ? `${inputId}-helper` : undefined,
              ]
                .filter(Boolean)
                .join(" ") || undefined}
              className="sr-only"
              {...props}
            />
            <button
              type="button"
              disabled={disabled}
              onClick={() => internalRef.current?.click()}
              className="flex h-6 items-center justify-center rounded border border-input/40 bg-muted px-2.5 text-xs font-semibold text-foreground transition-colors hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
            >
              {props.multiple ? "Choose files" : "Choose file"}
            </button>
            {selectedFiles.length === 0 ? (
              <span className="text-xs text-muted-foreground ps-1">
                No {props.multiple ? "files" : "file"} chosen
              </span>
            ) : (
              <div className="flex flex-wrap items-center gap-1">
                {selectedFiles.map((file, idx) => (
                  <span
                    key={`${file.name}-${idx}`}
                    className="inline-flex max-w-[200px] items-center gap-1 rounded-full border border-border/60 bg-muted/80 px-2 py-0.5 text-xs font-medium text-foreground"
                  >
                    <IconPaperclip className="size-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span className="truncate" title={file.name}>
                      {file.name}
                    </span>
                    <button
                      type="button"
                      tabIndex={-1}
                      disabled={disabled}
                      onClick={(e) => {
                        e.stopPropagation()
                        handleRemoveFile(idx)
                      }}
                      aria-label={`Remove ${file.name}`}
                      className="ms-0.5 flex size-3.5 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted-foreground/20 hover:text-foreground"
                    >
                      <IconX className="size-3 shrink-0" aria-hidden="true" />
                    </button>
                  </span>
                ))}
              </div>
            )}
          </div>
        )
      }

      return (
        <input
          ref={ref}
          id={inputId}
          type={type}
          data-slot="input"
          disabled={disabled}
          aria-invalid={!!errorText}
          aria-describedby={[
            errorText && inputId ? `${inputId}-error` : undefined,
            helperText && inputId ? `${inputId}-helper` : undefined,
          ]
            .filter(Boolean)
            .join(" ") || undefined}
          className={cn(
            "h-7 w-full min-w-0 rounded-md border border-input bg-card px-2.5 py-0.5 text-xs font-medium transition-colors outline-none file:inline-flex file:h-full file:items-center file:border-0 file:border-e file:border-input/40 file:bg-muted file:px-2.5 file:text-xs file:font-semibold file:text-foreground hover:file:bg-muted/80 file:transition-colors file:cursor-pointer file:me-2 placeholder:text-muted-foreground placeholder:text-xs focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20",
            className
          )}
          {...props}
        />
      )
    }

    const hasHeader = label || tooltip || required
    const hasFooter = helperText || errorText

    if (!hasHeader && !hasFooter) {
      return renderInputControl()
    }

    return (
      <div className="flex flex-col gap-1 w-full text-start">
        {hasHeader && (
          <div className="flex items-center gap-1 text-xs font-semibold text-foreground">
            {label && inputId ? (
              <label htmlFor={inputId} className="cursor-pointer">
                {label}
              </label>
            ) : (
              label && <span>{label}</span>
            )}
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
        {renderInputControl()}
        {errorText ? (
          <p id={inputId ? `${inputId}-error` : undefined} className="text-[0.6875rem] font-medium text-destructive">{errorText}</p>
        ) : (
          helperText && <p id={inputId ? `${inputId}-helper` : undefined} className="text-[0.6875rem] text-muted-foreground">{helperText}</p>
        )}
      </div>
    )
  }
)

Input.displayName = "Input"

export { Input }
