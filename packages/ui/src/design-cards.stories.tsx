import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { ActorChip } from "./actor-chip"
import { AgentRunStatus } from "./agent-run-status"
import { Avatar } from "./avatar"
import { Badge } from "./badge"
import { Button } from "./button"
import { Checkbox } from "./checkbox"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "./dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuTrigger,
} from "./dropdown-menu"
import { Icon, iconNames } from "./icon"
import { IconButton } from "./icon-button"
import { Popover, PopoverContent, PopoverTrigger } from "./popover"
import { RadioGroup, RadioGroupItem } from "./radio-group"
import { ToggleGroup } from "./toggle-group"
import { Tooltip, TooltipContent, TooltipTrigger } from "./tooltip"

/**
 * One story per design card (docs/design/components/<Name>), with the card's
 * own content, rendered at the card's size from docs/design/cards.html. The
 * screenshot check (scripts/design-cards-compare.mjs) renders these in light
 * and dark and compares them with docs/design/screenshots/<Name>-<theme>.png.
 * `parameters.designCard` names the card and its height; every card is 960 wide.
 */
const meta = {
  title: "Design cards",
  parameters: { layout: "fullscreen" },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const card = (name: string, height: number) => ({ designCard: { name, width: 960, height } })

/** `.wy-stage`: a wrapping row, 12px apart, 16px by 24px of padding. */
function Stage({ className, ...props }: React.ComponentProps<"div">) {
  return <div className={`flex flex-wrap items-center gap-3 px-6 py-4 ${className ?? ""}`} {...props} />
}

/** The card page's own `#root` padding, for cards that put a stage inside it. */
function Root({ children }: { children: React.ReactNode }) {
  return <div className="px-6 py-4">{children}</div>
}

function Cap({ children }: { children: React.ReactNode }) {
  return <p className="m-0 mb-1.5 text-xs text-muted-foreground">{children}</p>
}

export const ButtonCard: Story = {
  name: "Button",
  parameters: card("Button", 126),
  render: () => (
    <Stage>
      <Button icon="send">Send invoice</Button>
      <Button variant="outline">Save draft</Button>
      <Button variant="secondary">Duplicate</Button>
      <Button variant="ghost">Cancel</Button>
      <Button variant="destructive" icon="delete">
        Delete tenant
      </Button>
      <Button variant="link">View history</Button>
      <Button size="sm" loading>
        Saving
      </Button>
      <Button size="lg">Create workspace</Button>
    </Stage>
  ),
}

export const IconButtonCard: Story = {
  name: "IconButton",
  parameters: card("IconButton", 72),
  render: () => (
    <Stage>
      <IconButton icon="edit" label="Edit" />
      <IconButton icon="more" label="More actions" variant="outline" />
      <IconButton icon="delete" label="Delete" variant="ghost" />
      <IconButton icon="close" label="Close" />
    </Stage>
  ),
}

export const TooltipCard: Story = {
  name: "Tooltip",
  parameters: card("Tooltip", 130),
  render: () => (
    <Root>
      <Stage className="gap-5 pt-11">
      <Tooltip open>
        <TooltipTrigger asChild>
          <Button variant="outline" icon="download">
            Download
          </Button>
        </TooltipTrigger>
        <TooltipContent>Download as PDF</TooltipContent>
      </Tooltip>
      <Tooltip open>
        <TooltipTrigger asChild>
          <span className="inline-flex">
            <IconButton icon="search" label="Search" tooltip={false} />
          </span>
        </TooltipTrigger>
        <TooltipContent>
          Search <kbd>⌘K</kbd>
        </TooltipContent>
      </Tooltip>
      <IconButton icon="notification" label="Notifications" />
      <IconButton icon="help" label="Help" />
      <IconButton icon="more" label="More actions" variant="outline" />
      <IconButton icon="edit" label="Edit" size="sm" />
    </Stage>
      </Root>
  ),
}

export const MenuCard: Story = {
  name: "Menu",
  parameters: card("Menu", 430),
  render: () => (
    <Root>
      <Stage className="min-h-[360px] items-start gap-[220px]">
      <div>
        <Cap>Menu</Cap>
        <DropdownMenu open modal={false}>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" iconEnd="chevron-down" aria-label="Invoice actions">
              Actions
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start">
            <DropdownMenuItem>
              <Icon name="edit" /> Edit <DropdownMenuShortcut>E</DropdownMenuShortcut>
            </DropdownMenuItem>
            <DropdownMenuItem>
              <Icon name="copy" /> Duplicate
            </DropdownMenuItem>
            <DropdownMenuItem>
              <Icon name="download" /> Download PDF <DropdownMenuShortcut>⌘D</DropdownMenuShortcut>
            </DropdownMenuItem>
            <DropdownMenuItem>
              <Icon name="send" />
              <span className="grid gap-px">
                Send a reminder
                <span className="text-xs text-muted-foreground">Last sent 3 days ago</span>
              </span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem variant="destructive">
              <Icon name="delete" /> Void invoice
            </DropdownMenuItem>
            <DropdownMenuItem disabled>
              <Icon name="delete" />
              <span className="grid gap-px">
                Delete
                <span className="text-xs text-muted-foreground">Issued invoices can’t be deleted</span>
              </span>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
      <div>
        <Cap>Popover</Cap>
        <Popover open modal={false}>
          <PopoverTrigger asChild>
            <Button variant="outline" icon="filter">
              Filter
            </Button>
          </PopoverTrigger>
          <PopoverContent align="start">
            <div className="font-semibold">Filter invoices</div>
            <div className="font-medium">Payment</div>
            <RadioGroup defaultValue="overdue" aria-label="Payment" className="grid gap-2">
              {[
                ["all", "All"],
                ["unpaid", "Unpaid"],
                ["overdue", "Overdue"],
              ].map(([value, label]) => (
                <label key={value} className="flex items-center gap-2">
                  <RadioGroupItem value={value} /> {label}
                </label>
              ))}
            </RadioGroup>
            <label className="flex items-center gap-2">
              <Checkbox defaultChecked /> Only invoices sent by agents
            </label>
            <div className="flex justify-end gap-2">
              <Button variant="ghost" size="sm">
                Clear
              </Button>
              <Button size="sm">Apply</Button>
            </div>
          </PopoverContent>
        </Popover>
      </div>
    </Stage>
      </Root>
  ),
}

export const ToggleGroupCard: Story = {
  name: "ToggleGroup",
  parameters: card("ToggleGroup", 230),
  render: () => (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] items-start gap-x-8 gap-y-6 px-6 py-4">
      <div className="grid content-start gap-3">
        <Cap>One at a time: the view</Cap>
        <ToggleGroup
          label="View"
          defaultValue="list"
          required
          options={[
            { value: "list", label: "List", icon: "form" },
            { value: "board", label: "Board", icon: "dashboard" },
            { value: "calendar", label: "Calendar", icon: "calendar" },
          ]}
        />
        <Cap>Icons only, with tooltips</Cap>
        <ToggleGroup
          label="Show"
          size="sm"
          defaultValue="list"
          required
          options={[
            { value: "list", label: "List", icon: "form", iconOnly: true },
            { value: "board", label: "Board", icon: "dashboard", iconOnly: true },
            { value: "chart", label: "Chart", icon: "chart", iconOnly: true },
          ]}
        />
      </div>
      <div className="grid content-start gap-3">
        <Cap>Any number: filters</Cap>
        <ToggleGroup
          label="Status"
          type="multiple"
          defaultValue={["unpaid", "overdue"]}
          options={[
            { value: "paid", label: "Paid" },
            { value: "unpaid", label: "Unpaid" },
            { value: "overdue", label: "Overdue" },
            { value: "draft", label: "Draft", disabled: true },
          ]}
        />
        <Cap>بالعربية</Cap>
        <div dir="rtl" lang="ar" className="font-arabic">
          <ToggleGroup
            label="العرض"
            defaultValue="list"
            required
            options={[
              { value: "list", label: "قائمة", icon: "form" },
              { value: "board", label: "لوحة", icon: "dashboard" },
              { value: "calendar", label: "تقويم", icon: "calendar" },
            ]}
          />
        </div>
      </div>
    </div>
  ),
}

export const DialogCard: Story = {
  name: "Dialog",
  parameters: card("Dialog", 384),
  render: () => (
    <Dialog open>
      <DialogContent showCloseButton>
        <DialogHeader>
          <DialogTitle>Revoke this credential?</DialogTitle>
          <DialogDescription>
            Billing assistant will stop working at once. Anything it already did stays in the activity log.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="ghost">Cancel</Button>
          <Button variant="destructive">Revoke credential</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
}

export const AvatarCard: Story = {
  name: "Avatar",
  parameters: card("Avatar", 140),
  render: () => (
    <Root>
      <Stage className="items-end gap-7">
      <div className="grid justify-items-center gap-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <Avatar name="Rana Haddad" size={40} status="online" />
          <Avatar name="Omar Saleh" size={32} />
          <Avatar name="Lina" size={24} />
        </div>
        <Cap>People: initials</Cap>
      </div>
      <div className="grid justify-items-center gap-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <Avatar name="Billing assistant" kind="agent" size={40} />
          <Avatar name="HR assistant" kind="agent" size={32} />
          <Avatar name="Reports assistant" kind="agent" size={24} />
        </div>
        <Cap>Agents: the robot face, tinted</Cap>
      </div>
      <div className="grid justify-items-center gap-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <Avatar name="Whity" kind="system" size={40} />
          <Avatar name="Whity" kind="system" size={32} />
        </div>
        <Cap>The system: the mark</Cap>
      </div>
      <div className="grid gap-1.5">
        <ActorChip name="Whity" kind="system" />
        <Cap>Actor chip, system</Cap>
      </div>
    </Stage>
      </Root>
  ),
}

export const ActorChipCard: Story = {
  name: "ActorChip",
  parameters: card("ActorChip", 64),
  render: () => (
    <Stage>
      <ActorChip kind="person" name="Rana Haddad" />
      <ActorChip kind="agent" name="Billing assistant" onBehalfOf="Rana" />
      <ActorChip kind="agent" name="Front desk" />
    </Stage>
  ),
}

export const BadgeCard: Story = {
  name: "Badge",
  parameters: card("Badge", 64),
  render: () => (
    <Stage>
      <Badge>[plan name]</Badge>
      <Badge variant="secondary">Draft</Badge>
      <Badge variant="outline">12 seats</Badge>
      <Badge variant="success" icon="check">
        Active
      </Badge>
      <Badge variant="info">Syncing</Badge>
      <Badge variant="warning">Trial ends in 3 days</Badge>
      <Badge variant="destructive">Failed</Badge>
    </Stage>
  ),
}

export const AgentRunStatusCard: Story = {
  name: "AgentRunStatus",
  parameters: card("AgentRunStatus", 520),
  render: () => (
    <div className="grid gap-5 px-6 py-4">
      <div className="grid grid-cols-2 items-start gap-4">
        <AgentRunStatus
          status="running"
          agent="Billing assistant"
          onBehalfOf="Rana"
          title="Reading 48 open invoices"
          progress={{ done: 31, total: 48 }}
          time="Started 1 min ago"
          onStop={() => {}}
        />
        <AgentRunStatus
          status="waiting"
          agent="Billing assistant"
          onBehalfOf="Rana"
          title="Send payment reminders to 12 customers"
          detail="Waiting since 14:02 · uses invoice:remind"
          time="Asked 3 min ago"
          onReview={() => {}}
        />
        <AgentRunStatus
          status="done"
          agent="Front desk"
          onBehalfOf="Omar"
          title="Booked 3 deliveries for tomorrow"
          detail="Example Clinic, Example Hotel and Example School"
          time="Finished 14:20 · took 40 s"
        />
        <AgentRunStatus
          status="failed"
          agent="HR assistant"
          onBehalfOf="Lina"
          title="Couldn’t prepare October payslips"
          next="Two employees have no bank account. Add them in People, then try again."
          time="Stopped 13:55"
          onRetry={() => {}}
        />
      </div>
      <div className="grid content-start gap-3">
        <Cap>Compact, in the top bar</Cap>
        <div className="flex flex-wrap items-center gap-2">
          <AgentRunStatus compact status="running" agent="Billing assistant" title="Reading 48 invoices" />
          <AgentRunStatus compact status="done" agent="Front desk" title="Booked 3 deliveries" />
          <AgentRunStatus compact status="failed" agent="HR assistant" title="Payslips stopped" />
        </div>
      </div>
    </div>
  ),
}

export const IconCard: Story = {
  name: "Icon",
  parameters: card("Icon", 940),
  render: () => (
    <div className="grid grid-cols-[repeat(auto-fill,minmax(96px,1fr))] gap-1 px-5 py-3">
      {iconNames.map((name) => (
        <div key={name} className="grid justify-items-center gap-1.5 rounded-[8px] px-1 py-2.5">
          <Icon name={name} size={20} />
          <span className="font-mono text-xs text-muted-foreground">{name}</span>
        </div>
      ))}
    </div>
  ),
}
