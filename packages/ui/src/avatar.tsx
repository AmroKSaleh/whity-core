import { Icon } from "./icon"
import { Mark } from "./mark"
import { cn } from "./utils"

export type ActorKind = "person" | "agent" | "system"

/** Up to two initials, from the first letters of the first two words. */
function initials(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word.charAt(0))
      .join("")
      .toUpperCase() || "?"
  )
}

/**
 * Who did something, at a glance: people get their initials on grey (or a
 * photo), agents the robot face on the tint, and the system the Whity mark.
 * Agents never get a human face or a photo (stage 8). An optional dot shows
 * presence. The reference is docs/design/components/Avatar (`.wy-avatar`).
 */
function Avatar({
  name,
  kind = "person",
  src,
  size = 32,
  status,
  labels,
  className,
}: {
  name: string
  kind?: ActorKind
  /** A photo, for people only. */
  src?: string
  size?: number
  status?: "online" | "busy" | "offline"
  /** Overrides for the accessible name suffixes, e.g. Arabic. */
  labels?: { agent?: string }
  className?: string
}) {
  const L = { agent: "agent", ...labels }
  return (
    <span
      data-slot="avatar"
      data-kind={kind}
      role="img"
      aria-label={kind === "agent" ? `${name} (${L.agent})` : name}
      style={{ width: size, height: size, fontSize: Math.max(11, Math.round(size * 0.36)) }}
      className={cn(
        "relative inline-grid shrink-0 place-items-center rounded-full leading-none font-semibold",
        kind === "agent" && "bg-primary-tint text-primary-tint-foreground",
        kind === "system" && "bg-card shadow-[inset_0_0_0_1px_var(--border)]",
        kind === "person" && "bg-muted text-foreground",
        className
      )}
    >
      {kind === "agent" ? (
        <Icon name="robot" size={Math.round(size * 0.56)} stroke={2} />
      ) : kind === "system" ? (
        <Mark size={Math.round(size * 0.62)} label="" />
      ) : src ? (
        <img src={src} alt="" className="size-full rounded-full object-cover" />
      ) : (
        initials(name)
      )}
      {status && (
        <span
          aria-hidden="true"
          className={cn(
            "absolute -end-px -bottom-px box-border size-[32%] min-h-2 min-w-2 rounded-full border-2 border-card",
            status === "online" ? "bg-success" : status === "busy" ? "bg-warning" : "bg-muted-foreground"
          )}
        />
      )}
    </span>
  )
}

export { Avatar, initials }
