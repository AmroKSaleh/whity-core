import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
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

type Values = { name: string; email: string }

interface TenantFormProps {
  /** Seeds the fields — the shape a real screen would hydrate from the API. */
  defaultValues?: Values
  /** Label of the submit button. */
  submitLabel?: string
  /** Fired with the validated values once the form submits cleanly. */
  onSubmit?: (values: Values) => void
}

/**
 * A representative form assembled from this module's primitives. `Form` is
 * react-hook-form's `FormProvider`, whose props are a live `useForm()` return
 * value — it can only be produced by a hook at render time, never by static
 * story args — so the stories document the composition through this wrapper
 * instead of the provider itself.
 */
function TenantForm({
  defaultValues = { name: "", email: "" },
  submitLabel = "Create tenant",
  onSubmit = () => {},
}: TenantFormProps) {
  const form = useForm<Values>({ defaultValues, mode: "onTouched" })

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex w-80 flex-col gap-4">
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
        <Button type="submit">{submitLabel}</Button>
      </form>
    </Form>
  )
}

const meta = {
  title: "Primitives/Form",
  component: TenantForm,
  tags: ["autodocs"],
  parameters: {
    layout: "padded",
    docs: {
      description: {
        component:
          "`Form`, `FormField`, `FormItem`, `FormLabel`, `FormControl`, `FormDescription` and `FormMessage` are the react-hook-form bindings shipped by this package. The stories exercise them through a representative tenant-creation form.",
      },
    },
  },
  args: {
    onSubmit: fn(),
  },
} satisfies Meta<typeof TenantForm>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

/** Prefilled the way an edit screen hydrates the form from an existing record. */
export const Prefilled: Story = {
  args: {
    defaultValues: { name: "Acme Inc.", email: "admin@acme.com" },
    submitLabel: "Save changes",
  },
}
