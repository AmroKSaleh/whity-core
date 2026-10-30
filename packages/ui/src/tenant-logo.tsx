import { initials } from "./avatar"
import { cn } from "./utils"

/**
 * The slot where a tenant's own brand appears (docs/design/components/TenantLogo):
 * an uploaded logo in a framed square, or the initials on the tenant's main
 * colour (one letter below 32px, two from 32px). Corners are 29% of the size.
 * `showName={false}` keeps only the mark and moves the name to its label.
 */
function TenantLogo({
  name,
  src,
  size = 24,
  showName = true,
  className,
}: {
  name: string
  src?: string | null
  size?: number
  showName?: boolean
  className?: string
}) {
  const radius = Math.round(size * 0.29)
  return (
    <span
      data-slot="tenant-logo"
      role={showName ? undefined : "img"}
      aria-label={showName ? undefined : name}
      className={cn("inline-flex min-w-0 items-center gap-2 text-sm font-semibold text-foreground", className)}
    >
      {src ? (
        <span
          className="grid shrink-0 place-items-center overflow-hidden bg-card shadow-[inset_0_0_0_1px_var(--border)]"
          style={{ width: size, height: size, borderRadius: radius }}
        >
          <img src={src} alt="" className="size-full object-contain" />
        </span>
      ) : (
        <span
          aria-hidden="true"
          className="grid shrink-0 place-items-center bg-primary leading-none font-semibold text-primary-foreground"
          style={{ width: size, height: size, borderRadius: radius, fontSize: Math.max(11, Math.round(size * 0.42)) }}
        >
          {initials(name).slice(0, size < 32 ? 1 : 2)}
        </span>
      )}
      {showName && <span className="min-w-0 truncate">{name}</span>}
    </span>
  )
}

export { TenantLogo }
