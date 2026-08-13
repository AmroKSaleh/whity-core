"use client"

import * as React from "react"
import { IconCheck } from "@tabler/icons-react"

import { cn } from "./utils"
import { Card } from "./card"

export interface ColorShade {
  shade: number // 50, 100, 200, ..., 900
  name: string // e.g. "Whity Blue 100"
  hex: string // e.g. "#dbeafe"
  isMain?: boolean
  textColor: string // "#000000" or "#ffffff" for contrast
}

export interface ColorCardProps extends React.ComponentProps<"div"> {
  colorName?: string // e.g. "Whity Blue"
  mainHex?: string // e.g. "#2563eb" or pastel "#93c5fd"
  mainShade?: number // Which shade number is the main brand color (tagged as MAIN)
  onColorSelect?: (shade: ColorShade) => void
  /** Accessible name for the MAIN-shade marker. Defaults to "Main Brand Color". */
  mainShadeLabel?: string
  /** Short badge on the main shade. Defaults to "MAIN". */
  mainBadgeLabel?: string
  /** Confirmation flash after copying a swatch. Defaults to "COPIED". */
  copiedLabel?: string
  /**
   * Tooltip for a swatch. A function, not a string: the shade name and hex
   * sit inside the phrase and languages order them differently.
   */
  copyHint?: (name: string, hex: string) => string
}

// Default Whity Brand Design System Color Palette Presets (Including Neutral Greys)
export const WHITY_BRAND_PRESETS = [
  { name: "Whity Blue", hex: "#2563eb", mainShade: 600 },
  { name: "Whity Greys", hex: "#64748b", mainShade: 500 },
  { name: "Whity Pastel Blue", hex: "#93c5fd", mainShade: 300 },
  { name: "Whity Emerald", hex: "#10b981", mainShade: 500 },
  { name: "Whity Amber", hex: "#f59e0b", mainShade: 500 },
  { name: "Whity Rose", hex: "#f43f5e", mainShade: 500 },
  { name: "Whity Slate", hex: "#475569", mainShade: 600 },
  { name: "Whity Dark Violet", hex: "#6d28d9", mainShade: 700 },
]

function hexToRgb(hex: string): { r: number; g: number; b: number } {
  let c = hex.replace("#", "")
  if (c.length === 3) {
    c = c.split("").map((char) => char + char).join("")
  }
  const num = parseInt(c, 16) || 0
  return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 }
}

function rgbToHex(r: number, g: number, b: number): string {
  const toHex = (n: number) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, "0")
  return `#${toHex(r)}${toHex(g)}${toHex(b)}`
}

function rgbToHsl(r: number, g: number, b: number): { h: number; s: number; l: number } {
  r /= 255
  g /= 255
  b /= 255
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  let h = 0
  let s = 0
  const l = (max + min) / 2

  if (max !== min) {
    const d = max - min
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
    switch (max) {
      case r:
        h = (g - b) / d + (g < b ? 6 : 0)
        break
      case g:
        h = (b - r) / d + 2
        break
      case b:
        h = (r - g) / d + 4
        break
    }
    h /= 6
  }
  return { h: Math.round(h * 360), s: Math.round(s * 100), l: Math.round(l * 100) }
}

function hslToRgb(h: number, s: number, l: number): { r: number; g: number; b: number } {
  h /= 360
  s /= 100
  l /= 100
  let r: number, g: number, b: number

  if (s === 0) {
    r = g = b = l
  } else {
    const hue2rgb = (p: number, q: number, t: number) => {
      if (t < 0) t += 1
      if (t > 1) t -= 1
      if (t < 1 / 6) return p + (q - p) * 6 * t
      if (t < 1 / 2) return q
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6
      return p
    }
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s
    const p = 2 * l - q
    r = hue2rgb(p, q, h + 1 / 3)
    g = hue2rgb(p, q, h)
    b = hue2rgb(p, q, h - 1 / 3)
  }
  return { r: Math.round(r * 255), g: Math.round(g * 255), b: Math.round(b * 255) }
}

/**
 * Automatically computes 50-900 shade scale (10 even steps) from a main color.
 * Black (#000000) and White (#ffffff) are excluded to maintain pure color scales.
 */
export function generateShadeScale(
  name: string,
  mainHex: string,
  targetMainShade?: number
): ColorShade[] {
  const steps = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]
  const rgb = hexToRgb(mainHex)
  const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b)

  let mainShade = targetMainShade
  if (!mainShade) {
    const estimatedShade = Math.round((100 - hsl.l) * 10)
    mainShade = steps.reduce((prev, curr) =>
      Math.abs(curr - estimatedShade) < Math.abs(prev - estimatedShade) ? curr : prev
    )
  }

  const mainIndex = steps.indexOf(mainShade) !== -1 ? steps.indexOf(mainShade) : 5

  return steps.map((shade, idx) => {
    let hex: string

    if (idx === mainIndex) {
      hex = mainHex.toLowerCase()
    } else if (idx < mainIndex) {
      const factor = (mainIndex - idx) / (mainIndex + 1)
      const targetL = hsl.l + (96 - hsl.l) * factor
      const targetS = Math.max(5, hsl.s - factor * 15)
      const resRgb = hslToRgb(hsl.h, targetS, targetL)
      hex = rgbToHex(resRgb.r, resRgb.g, resRgb.b)
    } else {
      const factor = (idx - mainIndex) / (steps.length - mainIndex)
      const targetL = hsl.l - (hsl.l - 12) * factor
      const targetS = Math.min(100, hsl.s + factor * 10)
      const resRgb = hslToRgb(hsl.h, targetS, targetL)
      hex = rgbToHex(resRgb.r, resRgb.g, resRgb.b)
    }

    const resRgb = hexToRgb(hex)
    const luminance = (0.299 * resRgb.r + 0.587 * resRgb.g + 0.114 * resRgb.b) / 255
    const textColor = luminance > 0.55 ? "#0f172a" : "#ffffff"

    return {
      shade,
      name: `${name} ${shade}`,
      hex: hex.toLowerCase(),
      isMain: idx === mainIndex,
      textColor,
    }
  })
}

/**
 * Ultra-Minimal Color Card with generous top/bottom padding on tokens (`py-3.5 h-20`),
 * 10 even shade tokens (50 to 900), and top-edge cutout MAIN badge.
 */
export function ColorCard({
  className,
  colorName = "Whity Blue",
  mainHex = "#2563eb",
  mainShade = 600,
  onColorSelect,
  mainShadeLabel = "Main Brand Color",
  mainBadgeLabel = "MAIN",
  copiedLabel = "COPIED",
  copyHint = (name: string, hex: string) => `Click to copy ${name} (${hex})`,
  ...props
}: ColorCardProps) {
  const [copiedHex, setCopiedHex] = React.useState<string | null>(null)

  const shades = React.useMemo(() => {
    return generateShadeScale(colorName, mainHex, mainShade)
  }, [colorName, mainHex, mainShade])

  const handleSwatchClick = (shade: ColorShade) => {
    navigator.clipboard.writeText(shade.hex)
    setCopiedHex(shade.hex)
    onColorSelect?.(shade)
    setTimeout(() => setCopiedHex(null), 1500)
  }

  return (
    <Card
      variant="elevated"
      className={cn("w-full max-w-2xl overflow-visible p-3.5 pt-4 space-y-3.5 transition-all", className)}
      {...props}
    >
      {/* Clean Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span
            className="size-3.5 rounded-full border border-black/10 shrink-0 shadow-2xs"
            style={{ backgroundColor: mainHex }}
          />
          <span className="font-heading font-bold text-sm text-foreground tracking-tight">{colorName}</span>
        </div>
        <span className="font-mono text-xs text-muted-foreground uppercase font-semibold">{mainHex}</span>
      </div>

      {/* 10 Even Shade Tokens Grid with Generous Top/Bottom Padding (py-3.5 h-20) */}
      <div className="grid grid-cols-5 sm:grid-cols-10 gap-1.5 w-full pt-1.5">
        {shades.map((shade) => {
          const isCopied = copiedHex === shade.hex

          return (
            <button
              key={shade.shade}
              type="button"
              onClick={() => handleSwatchClick(shade)}
              className={cn(
                "group relative flex flex-col justify-between py-3.5 px-1.5 rounded-md h-20 transition-all duration-150 cursor-pointer w-full hover:scale-105 hover:z-30 focus:outline-none border border-black/10 dark:border-white/10 hover:border-foreground/30",
                shade.isMain && "z-20"
              )}
              style={{ backgroundColor: shade.hex, color: shade.textColor }}
              title={copyHint(shade.name, shade.hex)}
            >
              {/* MAIN Badge Resting on Top Edge with Card Background Cutout Border */}
              {shade.isMain && (
                <span
                  aria-label={mainShadeLabel}
                  className="absolute -top-2.5 left-1/2 -translate-x-1/2 z-30 text-[7.5px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded-full border-2 border-card shadow-2xs whitespace-nowrap leading-none select-none"
                  style={{
                    backgroundColor: shade.hex,
                    color: shade.textColor,
                  }}
                >
                  {mainBadgeLabel}
                </span>
              )}

              <div className="flex items-center justify-between w-full">
                <span className="text-[10px] font-bold font-mono tracking-tight">{shade.shade}</span>
              </div>

              {isCopied ? (
                <div className="flex items-center justify-center gap-0.5 text-[8px] font-bold text-emerald-400 bg-slate-950 px-1 py-0.5 rounded-xs animate-pulse ring-1 ring-emerald-500/50">
                  <IconCheck className="size-2.5 shrink-0" />
                  <span>{copiedLabel}</span>
                </div>
              ) : (
                <span className="text-[8.5px] font-mono uppercase opacity-90 font-semibold truncate">
                  {shade.hex}
                </span>
              )}
            </button>
          )
        })}
      </div>
    </Card>
  )
}
