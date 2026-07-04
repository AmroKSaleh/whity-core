import type { Meta, StoryObj } from "@storybook/react-vite"

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

const meta = {
  title: "Primitives/Card",
  component: Card,
  tags: ["autodocs"],
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
