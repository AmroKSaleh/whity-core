import { initials, type ActorKind } from "./avatar"
import { Icon } from "./icon"
import { Mark } from "./mark"
import { cn } from "./utils"

/**
 * Names who did something, on everything an agent did or proposes (stage 7).
 * A person: initials. An agent: the robot face on the tint, a dashed border,
 * its role name and the person it acts "for", because an agent never holds more permission
 * than the person who granted it. The system: the mark. Shape and words carry
 * the difference, never colour alone; a hidden word says which it is. The
 * reference is docs/design/components/ActorChip (`.wy-actor`).
 */
function ActorChip({
  kind = "person",
  name,
  onBehalfOf,
  labels,
  className,
}: {
  kind?: ActorKind
  /** A person's name, or an agent's role name ("Billing assistant"). */
  name: string
  /** For agents: the person it acts for. */
  onBehalfOf?: string
  /** Overrides for every visible and hidden word, e.g. Arabic. */
  labels?: { for?: string; person?: string; agent?: string; system?: string }
  className?: string
}) {
  const L = { for: "for", person: "person", agent: "agent", system: "system", ...labels }
  return (
    <span
      data-slot="actor-chip"
      data-kind={kind}
      className={cn(
        "inline-flex h-6 max-w-full items-center gap-1.5 rounded-full border border-transparent bg-muted pe-2 text-[0.8125rem] font-medium whitespace-nowrap",
        // An agent's chip is dashed (the ActorChip card); people and the system stay borderless.
        kind === "agent" ? "border-dashed border-input ps-[3px]" : "ps-1",
        className
      )}
    >
      {kind === "agent" ? (
        <span
          aria-hidden="true"
          className="grid size-[18px] shrink-0 place-items-center rounded-full bg-primary-tint text-primary-tint-foreground"
        >
          <Icon name="robot" size={12} />
        </span>
      ) : kind === "system" ? (
        <Mark size={16} cut="c16" label="" />
      ) : (
        // 11px is the floor (decision 3.5a); the reference card's 10px initials follow it.
        <span
          aria-hidden="true"
          className="grid size-[18px] shrink-0 place-items-center rounded-full bg-card text-2xs leading-none font-semibold text-foreground"
        >
          {initials(name)}
        </span>
      )}
      <span className="truncate">{name}</span>
      {kind === "agent" && onBehalfOf && (
        <span className="font-normal text-muted-foreground">
          {L.for} {onBehalfOf}
        </span>
      )}
      <span className="sr-only"> ({L[kind]})</span>
    </span>
  )
}

export { ActorChip }
