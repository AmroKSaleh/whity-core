import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
import * as React from "react"
import { IconCloudUpload, IconDatabase, IconPuzzle, IconShieldCheck } from "@tabler/icons-react"

import { PluginStoreCard, InstalledPluginCard, type PluginItem } from "./plugin-card"

const SAMPLE_PLUGINS: PluginItem[] = [
  {
    id: "p1",
    name: "AI Document Summarizer",
    author: "Whity AI Labs",
    version: "2.4.0",
    latestVersion: "2.5.0",
    description: "Extract key takeaways, executive summaries, and action items from uploaded PDF documents using local or cloud LLM models.",
    longDescription: "AI Document Summarizer provides native document parsing for PDF, DOCX, and TXT files. It integrates directly into Whity storage backends to offer one-click executive summaries, entity extraction, and automated metadata tagging with full multi-tenant isolation.",
    category: "Productivity",
    rating: 4.9,
    reviewCount: 128,
    icon: <IconPuzzle className="size-6 text-primary" />,
    bannerGradient: "from-primary/30 via-slate-800/20 to-muted/80",
    screenshots: [
      "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop&q=60",
      "https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&auto=format&fit=crop&q=60",
      "https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=800&auto=format&fit=crop&q=60",
    ],
    verified: true,
    state: "update-available",
    permissions: ["storage:read-write", "ai-engine:invoke", "documents:parse"],
    versions: [
      { version: "2.4.0", releasedLabel: "Current (Installed)", isCurrent: true },
      { version: "2.3.1", releasedLabel: "2 weeks ago", changelog: "Added batch PDF processing worker support." },
      { version: "2.2.0", releasedLabel: "1 month ago", changelog: "Initial multi-lingual summary support." },
    ],
  },
  {
    id: "p2",
    name: "S3 Cloud Storage Connector",
    author: "CloudSync Inc",
    version: "1.8.2",
    description: "Stream seamless backups, media assets, and export archives directly to Amazon S3, Cloudflare R2, or MinIO storage buckets.",
    category: "Storage",
    rating: 4.8,
    reviewCount: 84,
    icon: <IconCloudUpload className="size-6 text-blue-600 dark:text-blue-400" />,
    bannerGradient: "from-blue-600/30 via-cyan-600/20 to-muted/80",
    verified: true,
    state: "active",
    permissions: ["storage:read-write", "network:outbound-https"],
    versions: [
      { version: "1.8.2", releasedLabel: "Current", isCurrent: true },
      { version: "1.7.5", releasedLabel: "3 weeks ago", changelog: "Added MinIO multipart upload chunking." },
      { version: "1.6.0", releasedLabel: "2 months ago", changelog: "S3 server-side encryption support." },
    ],
  },
  {
    id: "p3",
    name: "Advanced Audit Telemetry",
    author: "Security Suite",
    version: "3.1.0",
    description: "Immutable audit event logging, SIEM webhook forwarding, and automated ISO 27001 compliance reporting.",
    category: "Security",
    rating: 4.95,
    reviewCount: 210,
    icon: <IconShieldCheck className="size-6 text-emerald-600 dark:text-emerald-400" />,
    bannerGradient: "from-emerald-600/30 via-teal-600/20 to-muted/80",
    verified: true,
    state: "inactive",
    permissions: ["audit-log:read-write", "webhooks:dispatch"],
    versions: [
      { version: "3.1.0", releasedLabel: "Current", isCurrent: true },
      { version: "3.0.2", releasedLabel: "1 month ago" },
      { version: "2.9.0", releasedLabel: "3 months ago" },
    ],
  },
  {
    id: "p4",
    name: "Legacy Database Connector v1",
    author: "Core Engine",
    version: "1.0.4",
    description: "Deprecated legacy MySQL 5.7 driver bridge. Restrictive policy enforced.",
    category: "Database",
    rating: 3.5,
    reviewCount: 12,
    icon: <IconDatabase className="size-6 text-red-600 dark:text-red-400" />,
    bannerGradient: "from-red-600/30 via-amber-600/20 to-muted/80",
    verified: false,
    state: "disabled",
    permissions: ["database:raw-connect"],
  },
]

const meta = {
  title: "Primitives/PluginCard",
  component: PluginStoreCard,
  subcomponents: { InstalledPluginCard },
  tags: ["autodocs"],
  parameters: { layout: "padded" },
  args: {
    plugin: SAMPLE_PLUGINS[0],
    onInstall: fn(),
    onConfigure: fn(),
  },
} satisfies Meta<typeof PluginStoreCard>

export default meta
type Story = StoryObj<typeof meta>

/** A single store card, driven entirely by the Controls panel. */
export const StoreCard: Story = {}

export const PluginStoreMarketplace: Story = {
  render: (args) => (
    <div className="space-y-4 max-w-4xl">
      <div className="space-y-1">
        <h3 className="text-base font-bold text-foreground">Real-Store Plugin Marketplace</h3>
        <p className="text-xs text-muted-foreground">
          Features store preview banners, neutral rating stars positioned above title, clean borderless icons, and interactive image gallery inside More Info &gt; Overview modal.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* The first card is the `plugin` arg; the second shows a second
            catalogue entry beside it, as the marketplace grid renders them. */}
        {[args.plugin, SAMPLE_PLUGINS[1]].map((plugin) => (
          <PluginStoreCard {...args} key={plugin.id} plugin={plugin} />
        ))}
      </div>
    </div>
  ),
}

/** `onUpdate` is not one of the store card's args, so it gets its own spy. */
const logUpdate = fn()

export const InstalledPluginsAllStates: Story = {
  args: { plugin: SAMPLE_PLUGINS[0] },
  render: ({ plugin, onConfigure }) => {
    const [plugins, setPlugins] = React.useState<PluginItem[]>(() => [
      plugin,
      ...SAMPLE_PLUGINS.slice(1),
    ])

    const handleToggle = (target: PluginItem, newState: "active" | "inactive") => {
      setPlugins((prev) =>
        prev.map((p) => (p.id === target.id ? { ...p, state: newState } : p))
      )
    }

    const handleRollback = (target: PluginItem, version: string) => {
      setPlugins((prev) =>
        prev.map((p) => (p.id === target.id ? { ...p, version, state: "active" } : p))
      )
    }

    return (
      <div className="space-y-4 max-w-4xl">
        <div className="space-y-1">
          <h3 className="text-base font-bold text-foreground">Installed Plugins Management</h3>
          <p className="text-xs text-muted-foreground">
            Demonstrates Active, Inactive, Disabled by System, and Update Available (Blue info theme) states with version rollback and details modal gallery.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {plugins.map((p) => (
            <InstalledPluginCard
              key={p.id}
              plugin={p}
              onToggleState={handleToggle}
              onUpdate={logUpdate}
              onRollback={handleRollback}
              onConfigure={onConfigure}
            />
          ))}
        </div>
      </div>
    )
  },
}
