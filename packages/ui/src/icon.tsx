import { ICONS, iconNames, type IconName } from "./icon-map"
import { cn } from "./utils"

/**
 * One of the 116 curated Tabler outline icons (decision 5.1), by its Whity
 * name. 16px beside 14px text, 20 in touch navigation, 24 in headers and
 * empty states; the stroke is 2.25 at 16px and below and 2 above, so every
 * size reads at the same weight (5.2). Decorative unless `label` is given.
 * Direction icons (chevrons, arrows, undo, send, logout, external, lists)
 * flip in right-to-left layouts; `mirror` overrides the icon's own default.
 */
function Icon({
  name,
  size = 16,
  stroke,
  label,
  mirror,
  className,
}: {
  name: IconName
  size?: number
  stroke?: number
  label?: string
  mirror?: boolean
  className?: string
}) {
  const { icon: Glyph, mirror: mirrorsByDefault } = ICONS[name]
  const flips = mirror ?? mirrorsByDefault
  return (
    <Glyph
      data-slot="icon"
      data-icon={name}
      size={size}
      stroke={stroke ?? (size <= 16 ? 2.25 : 2)}
      className={cn("shrink-0", flips && "rtl:-scale-x-100", className)}
      {...(label ? { role: "img", "aria-label": label } : { "aria-hidden": true })}
    />
  )
}

export { Icon, iconNames, type IconName }
