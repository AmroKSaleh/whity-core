import { cn } from "./utils"

/**
 * The Whity mark: a blank sheet offset up-right on the platform (stage 1).
 * Pixel-fitted cuts below 24px (decision 1.7a): 16px is side 11, offset 4,
 * edge 2, radius 3; 20px is side 14, offset 5, edge 2, radius 3.5; the master
 * is used from 24px up. Each cut is [canvas, side, offset, edge, radius, pad].
 */
const CUTS = {
  main: [24, 16, 6, 2, 4, 1],
  c20: [20, 14, 5, 2, 3.5, 0.5],
  c16: [16, 11, 4, 2, 3, 0.5],
} as const

type Cut = keyof typeof CUTS

function cutFor(size: number): Cut {
  return size >= 24 ? "main" : size >= 20 ? "c20" : "c16"
}

function Mark({
  size = 32,
  cut,
  tone = "auto",
  label = "Whity",
  className,
}: {
  size?: number
  cut?: Cut
  /** `auto` is the colour mark; `onbrand` sits on Whity Blue; `mono` takes the text colour. */
  tone?: "auto" | "onbrand" | "mono"
  /** The accessible name; pass "" when the mark is decorative. */
  label?: string
  className?: string
}) {
  const [canvas, side, offset, edge, radius, pad] = CUTS[cut ?? cutFor(size)]
  const plane = tone === "onbrand" ? "fill-mark-sheet" : tone === "mono" ? "fill-current" : "fill-brand"
  const window = tone === "onbrand" ? "fill-brand" : tone === "mono" ? "fill-background" : "fill-mark-sheet"
  return (
    <svg
      data-slot="mark"
      viewBox={`0 0 ${canvas} ${canvas}`}
      width={size}
      height={size}
      className={cn("shrink-0", className)}
      {...(label ? { role: "img", "aria-label": label } : { "aria-hidden": true })}
    >
      <rect className={plane} x={pad} y={pad + offset} width={side} height={side} rx={radius} />
      <rect className={plane} x={pad + offset} y={pad} width={side} height={side} rx={radius} />
      <rect
        className={window}
        x={pad + offset + edge}
        y={pad + edge}
        width={side - 2 * edge}
        height={side - 2 * edge}
        rx={radius - edge}
      />
    </svg>
  )
}

export { Mark }
