import type { Meta, StoryObj } from "@storybook/react-vite"
import { useForm } from "react-hook-form"

import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
} from "./form"
import { Input } from "./input"
import { Button } from "./button"

const meta = {
  title: "Primitives/Form",
  component: Form,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof Form>

export default meta
type Story = StoryObj<typeof meta>

type Values = { name: string; email: string }

export const Default: Story = {
  render: () => {
    // eslint-disable-next-line react-hooks/rules-of-hooks
    const form = useForm<Values>({
      defaultValues: { name: "", email: "" },
      mode: "onTouched",
    })

    return (
      <Form {...form}>
        <form
          onSubmit={form.handleSubmit(() => {})}
          className="flex w-80 flex-col gap-4"
        >
          <FormField
            control={form.control}
            name="name"
            rules={{ required: "Name is required" }}
            render={({ field }) => (
              <FormItem>
                <FormLabel>Tenant name</FormLabel>
                <FormControl>
                  <Input placeholder="Acme" {...field} />
                </FormControl>
                <FormDescription>Shown across the dashboard.</FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="email"
            rules={{
              required: "Email is required",
              pattern: { value: /^[^@]+@[^@]+$/, message: "Enter a valid email" },
            }}
            render={({ field }) => (
              <FormItem>
                <FormLabel>Admin email</FormLabel>
                <FormControl>
                  <Input type="email" placeholder="admin@acme.com" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <Button type="submit">Create tenant</Button>
        </form>
      </Form>
    )
  },
}
