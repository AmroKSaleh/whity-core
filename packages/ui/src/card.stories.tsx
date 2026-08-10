import type { Meta, StoryObj } from "@storybook/react-vite"
import {
  IconCheck,
  IconCloudUpload,
  IconCpu,
  IconDownload,
  IconKey,
  IconLock,
  IconPuzzle,
  IconShieldCheck,
  IconSparkles,
  IconStarFilled,
} from "@tabler/icons-react"

import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardAction,
  CardContent,
  CardFooter,
} from "./card"
import { Button } from "./button"
import { Badge } from "./badge"
import { Input } from "./input"

const meta = {
  title: "Primitives/Card",
  component: Card,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: ["default", "flat", "outline", "elevated", "purple", "info", "success", "warning", "destructive"],
    },
    borderStyle: {
      control: "select",
      options: ["solid", "dashed", "dotted", "none"],
    },
    state: {
      control: "select",
      options: ["default", "disabled", "selected", "active"],
    },
    size: {
      control: "select",
      options: ["sm", "default", "lg"],
    },
    disabled: { control: "boolean" },
    interactive: { control: "boolean" },
  },
} satisfies Meta<typeof Card>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <Card className="w-80">
      <CardHeader>
        <CardTitle>Acme Tenant</CardTitle>
        <CardDescription>Sovereign deployment · eu-central</CardDescription>
        <CardAction>
          <Badge variant="secondary">Active</Badge>
        </CardAction>
      </CardHeader>
      <CardContent>
        <p className="text-muted-foreground text-xs">
          42 active users. Last update applied 3 days ago.
        </p>
      </CardContent>
      <CardFooter className="gap-2">
        <Button size="sm">Manage</Button>
        <Button size="sm" variant="outline">View logs</Button>
      </CardFooter>
    </Card>
  ),
}

export const PluginStoreCard: Story = {
  render: () => (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
      <Card interactive variant="elevated" className="w-full">
        <CardHeader>
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center size-10 rounded-xl bg-purple-500/10 text-purple-600 dark:bg-purple-950/50 dark:text-purple-300 border border-purple-500/20">
              <IconPuzzle className="size-5" />
            </div>
            <div>
              <CardTitle className="text-sm font-bold">AI Document Summarizer</CardTitle>
              <CardDescription className="text-xs">by Whity AI Labs · v2.4.0</CardDescription>
            </div>
          </div>
          <CardAction>
            <Badge variant="purple-solid" className="text-[10px]">Verified</Badge>
          </CardAction>
        </CardHeader>
        <CardContent className="space-y-3">
          <p className="text-xs text-muted-foreground leading-relaxed">
            Automatically extract key takeaways, action items, and structural summaries from uploaded PDF and Word documents.
          </p>
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="outline" className="text-[10px]">Productivity</Badge>
            <Badge variant="outline" className="text-[10px]">LLM Powered</Badge>
            <div className="ms-auto flex items-center gap-1 text-xs text-amber-500 font-semibold">
              <IconStarFilled className="size-3.5" />
              <span>4.9 (128)</span>
            </div>
          </div>
        </CardContent>
        <CardFooter className="border-t border-border/40 pt-3 flex items-center justify-between">
          <span className="text-xs font-semibold text-foreground">Free Tier Included</span>
          <Button size="sm" variant="purple-solid">
            <IconDownload data-icon="inline-start" />
            Install Plugin
          </Button>
        </CardFooter>
      </Card>

      <Card interactive variant="default" className="w-full">
        <CardHeader>
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center size-10 rounded-xl bg-blue-500/10 text-blue-600 dark:bg-blue-950/50 dark:text-blue-300 border border-blue-500/20">
              <IconCloudUpload className="size-5" />
            </div>
            <div>
              <CardTitle className="text-sm font-bold">S3 Storage Connector</CardTitle>
              <CardDescription className="text-xs">by CloudSync Inc · v1.8.2</CardDescription>
            </div>
          </div>
          <CardAction>
            <Badge variant="info-solid" className="text-[10px]">Installed</Badge>
          </CardAction>
        </CardHeader>
        <CardContent className="space-y-3">
          <p className="text-xs text-muted-foreground leading-relaxed">
            Stream seamless backups and document attachments directly to your AWS S3 or MinIO bucket storage.
          </p>
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="outline" className="text-[10px]">Storage</Badge>
            <Badge variant="outline" className="text-[10px]">Cloud</Badge>
            <div className="ms-auto flex items-center gap-1 text-xs text-amber-500 font-semibold">
              <IconStarFilled className="size-3.5" />
              <span>4.8 (84)</span>
            </div>
          </div>
        </CardContent>
        <CardFooter className="border-t border-border/40 pt-3 flex items-center justify-between">
          <span className="text-xs text-muted-foreground">Configured</span>
          <Button size="sm" variant="outline">
            Configure
          </Button>
        </CardFooter>
      </Card>
    </div>
  ),
}

export const FeatureInstallCard: Story = {
  render: () => (
    <Card variant="outline" className="w-full max-w-lg border-2">
      <CardHeader>
        <div className="flex items-center gap-3">
          <div className="flex items-center justify-center size-11 rounded-xl bg-emerald-500/10 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-500/20">
            <IconCpu className="size-6" />
          </div>
          <div>
            <CardTitle className="text-base font-bold">Advanced Audit Logging</CardTitle>
            <CardDescription className="text-xs">Immutable security and access audit telemetry</CardDescription>
          </div>
        </div>
        <CardAction>
          <Badge variant="success-solid">Ready to Enable</Badge>
        </CardAction>
      </CardHeader>

      <CardContent className="space-y-3.5">
        <p className="text-xs text-muted-foreground leading-relaxed">
          Enable real-time SIEM forwarding, tamper-evident log hashes, and export compliance reports for ISO 27001 auditing.
        </p>

        <div className="space-y-2 rounded-lg bg-muted/40 p-3 border border-border/50">
          <span className="text-[11px] font-semibold text-foreground uppercase tracking-wider">Features Included:</span>
          <ul className="space-y-1.5 text-xs text-muted-foreground">
            <li className="flex items-center gap-2">
              <IconCheck className="size-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" />
              <span>Unlimited audit event retention</span>
            </li>
            <li className="flex items-center gap-2">
              <IconCheck className="size-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" />
              <span>Real-time webhook alert dispatches</span>
            </li>
            <li className="flex items-center gap-2">
              <IconCheck className="size-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" />
              <span>One-click PDF compliance export</span>
            </li>
          </ul>
        </div>
      </CardContent>

      <CardFooter className="border-t border-border/40 pt-4 flex items-center justify-between">
        <span className="text-xs font-medium text-muted-foreground">No restart required</span>
        <Button size="default" variant="success-solid">
          <IconCheck data-icon="inline-start" />
          Enable Feature Module
        </Button>
      </CardFooter>
    </Card>
  ),
}

export const InteractiveWithInputFieldCard: Story = {
  render: () => (
    <Card variant="default" className="w-full max-w-md">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm font-bold">
          <IconKey className="size-4 text-purple-600 dark:text-purple-400" />
          API Access Token
        </CardTitle>
        <CardDescription>Generate a scoped bearer token for CLI and REST integration.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1.5">
          <label className="text-xs font-semibold text-foreground">Token Label</label>
          <Input placeholder="e.g. CI/CD Deployment Secret" defaultValue="Production Sync Bot" className="h-8 text-xs" />
        </div>
        <div className="space-y-1.5">
          <label className="text-xs font-semibold text-foreground">Generated Key</label>
          <div className="flex items-center gap-2">
            <Input type="password" readOnly value="whity_live_8392104928104810293810" className="h-8 text-xs font-mono" />
            <Button size="sm" variant="outline">Copy</Button>
          </div>
        </div>
      </CardContent>
      <CardFooter className="border-t border-border/40 pt-3 flex items-center justify-between">
        <span className="text-[11px] text-muted-foreground">Expires in 90 days</span>
        <Button size="sm" variant="purple-solid">
          Regenerate Key
        </Button>
      </CardFooter>
    </Card>
  ),
}

export const InformationalCard: Story = {
  render: () => (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-4xl">
      <Card variant="purple" className="w-full">
        <CardHeader>
          <CardTitle className="text-xs uppercase tracking-wider text-purple-800 dark:text-purple-300 font-bold">
            Total Requests
          </CardTitle>
          <CardDescription>Last 30 days telemetry</CardDescription>
          <CardAction>
            <Badge variant="purple-solid">+14.2%</Badge>
          </CardAction>
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold text-foreground font-heading">1.42 M</div>
          <p className="text-[11px] text-muted-foreground mt-1">Average 48.2k requests / day</p>
        </CardContent>
      </Card>

      <Card variant="info" className="w-full">
        <CardHeader>
          <CardTitle className="text-xs uppercase tracking-wider text-blue-800 dark:text-blue-300 font-bold">
            Active Subscriptions
          </CardTitle>
          <CardDescription>Tenant billing telemetry</CardDescription>
          <CardAction>
            <Badge variant="info-solid">Healthy</Badge>
          </CardAction>
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold text-foreground font-heading">328</div>
          <p className="text-[11px] text-muted-foreground mt-1">24 new tenants signed up this week</p>
        </CardContent>
      </Card>

      <Card variant="success" className="w-full">
        <CardHeader>
          <CardTitle className="text-xs uppercase tracking-wider text-emerald-800 dark:text-emerald-300 font-bold">
            System Availability
          </CardTitle>
          <CardDescription>All services operational</CardDescription>
          <CardAction>
            <Badge variant="success-solid">99.99%</Badge>
          </CardAction>
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold text-foreground font-heading">0 Incidents</div>
          <p className="text-[11px] text-muted-foreground mt-1">Zero downtime over 90 days</p>
        </CardContent>
      </Card>
    </div>
  ),
}

export const BorderStylesAndStates: Story = {
  render: () => (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-4xl">
      {/* Dotted Disabled Card */}
      <Card borderStyle="dotted" disabled className="w-full">
        <CardHeader>
          <CardTitle className="flex items-center gap-1.5">
            <IconLock className="size-4 text-muted-foreground" />
            Disabled Module
          </CardTitle>
          <CardDescription>Dotted border style for disabled features</CardDescription>
          <CardAction>
            <Badge variant="outline">Disabled</Badge>
          </CardAction>
        </CardHeader>
        <CardContent>
          <p className="text-muted-foreground text-xs">
            This workspace module is disabled by tenant policy.
          </p>
        </CardContent>
      </Card>

      {/* Dashed Interactive Card */}
      <Card borderStyle="dashed" interactive className="w-full">
        <CardHeader>
          <CardTitle className="flex items-center gap-1.5">
            <IconSparkles className="size-4 text-purple-600 dark:text-purple-400" />
            Add Custom Integration
          </CardTitle>
          <CardDescription>Dashed border style for drag & drop or action cards</CardDescription>
        </CardHeader>
        <CardContent>
          <p className="text-muted-foreground text-xs">
            Click here to connect a new webhook or external API.
          </p>
        </CardContent>
      </Card>

      {/* Selected Card */}
      <Card state="selected" interactive className="w-full">
        <CardHeader>
          <CardTitle className="flex items-center gap-1.5">
            <IconShieldCheck className="size-4 text-primary" />
            Enterprise Tier
          </CardTitle>
          <CardDescription>Selected state with primary ring focus</CardDescription>
          <CardAction>
            <Badge variant="purple-solid">Selected</Badge>
          </CardAction>
        </CardHeader>
        <CardContent>
          <p className="text-muted-foreground text-xs">
            Full compliance suite & 99.99% uptime SLA enabled.
          </p>
        </CardContent>
      </Card>
    </div>
  ),
}

export const ColorVariants: Story = {
  render: () => (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-4xl">
      <Card variant="purple">
        <CardHeader>
          <CardTitle>Purple Theme</CardTitle>
          <CardDescription>Subtle purple brand accent tint</CardDescription>
        </CardHeader>
        <CardContent>
          <Badge variant="purple-solid">AI Engine Active</Badge>
        </CardContent>
      </Card>

      <Card variant="info">
        <CardHeader>
          <CardTitle>Info Status</CardTitle>
          <CardDescription>Subtle blue informational tint</CardDescription>
        </CardHeader>
        <CardContent>
          <Badge variant="info-solid">Syncing Data</Badge>
        </CardContent>
      </Card>

      <Card variant="success">
        <CardHeader>
          <CardTitle>Success Status</CardTitle>
          <CardDescription>Subtle emerald success tint</CardDescription>
        </CardHeader>
        <CardContent>
          <Badge variant="success-solid">Operational</Badge>
        </CardContent>
      </Card>

      <Card variant="warning">
        <CardHeader>
          <CardTitle>Warning Status</CardTitle>
          <CardDescription>Subtle amber warning tint</CardDescription>
        </CardHeader>
        <CardContent>
          <Badge variant="warning-solid">High Load</Badge>
        </CardContent>
      </Card>

      <Card variant="destructive">
        <CardHeader>
          <CardTitle>Destructive Status</CardTitle>
          <CardDescription>Subtle red error tint</CardDescription>
        </CardHeader>
        <CardContent>
          <Badge variant="destructive-solid">2 Failures</Badge>
        </CardContent>
      </Card>

      <Card variant="elevated" interactive>
        <CardHeader>
          <CardTitle>Elevated Shadow</CardTitle>
          <CardDescription>Floating shadow card variant</CardDescription>
        </CardHeader>
        <CardContent>
          <Badge variant="outline">Elevated</Badge>
        </CardContent>
      </Card>
    </div>
  ),
}
