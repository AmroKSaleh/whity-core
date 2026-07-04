# Component Library Specification

This page is the canonical specification for every UI component that actually ships in
`web/components/ui/`. For each component it documents the **purpose**, **variants**, **every
applicable interaction state**, the **design tokens** that drive those states, **accessibility**
behavior, and **usage examples grounded in the real component API**.

- **Stack:** shadcn-ui (copy-in components) + Radix UI primitives + Tailwind CSS v4.
- **Icons:** `@tabler/icons-react` (see [Design-System-Grid › Icon set](Design-System-Grid.md)).
- **Tokens:** referenced by **role/name** from `src/design/tokens/base.json` and the
  CSS variables in `web/app/globals.css`. Do not hard-code colors — use token-backed
  Tailwind utilities (`bg-primary`, `text-muted-foreground`, `ring-ring`, …).
- **Theming:** all tokens have light + dark variants; the `accent` token is the
  **white-label-overridable brand color** and must not be hard-coded.

> [!NOTE]
> Components are **copied into the repo**, so the source in `web/components/ui/` is the
> source of truth. Where this spec and the code disagree, the code wins — open a follow-up
> to reconcile. Known divergences are listed under [Follow-ups](#follow-ups).

## Token vocabulary used below

State styling is expressed through these token roles (each has a light + dark value):

| Token role | Drives |
|------------|--------|
| `background` / `foreground` | Page surface and default text |
| `card` / `card-foreground` | Card and alert surfaces |
| `popover` / `popover-foreground` | Floating surfaces (dialog, dropdown, select content) |
| `primary` / `primary-foreground` | Primary actions, default badge |
| `secondary` / `secondary-foreground` | Secondary actions/badges |
| `muted` / `muted-foreground` | Hover fills, hints, placeholders, disabled-ish text |
| `accent` / `accent-foreground` | Highlight/active menu+select item, emphasis (**brand, white-label-overridable**) |
| `destructive` | Dangerous actions, error/invalid styling |
| `border` | Component borders/dividers |
| `input` | Field background/border |
| `ring` | Focus ring color (keyboard focus) |
| `radius` (+ `radius-sm…4xl`) | Corner rounding |
| `font-sans` / `font-heading` / `font-mono` | Typography |

> The token agent is adding an explicit **accent** brand role and a **type scale** to
> `base.json`. This page references those by role/name; it does not redefine them.

## Interaction-state model

Most interactive components express states through a consistent set of Tailwind/Radix hooks:

| State | How it is expressed in code | Token(s) |
|-------|-----------------------------|----------|
| Default | Base variant classes | per variant |
| Hover | `hover:` | usually a `/80`–`/50` opacity shift of the variant color or `muted` |
| Focus (keyboard) | `focus-visible:border-ring` + `focus-visible:ring-ring/30` (or `/50`) | `ring` |
| Active (pressed) | `active:translate-y-px` (Button) | — (motion only) |
| Disabled | `disabled:opacity-50` + `disabled:pointer-events-none` | dims current tokens |
| Invalid / error | `aria-invalid:border-destructive` + `aria-invalid:ring-destructive/20` | `destructive` |
| Open / expanded | `aria-expanded:` / `data-open:` / `data-[state=open]` | `muted` / `accent` |
| Selected / active item | `data-active:` / `focus:bg-accent` | `accent` / `background` |

Components opt into the subset that applies to them; each section below lists exactly which.

---

## Button

**Source:** `web/components/ui/button.tsx` · **Slot:** `data-slot="button"`

**Purpose:** Trigger an action or navigation. Supports `asChild` (renders as a child element,
e.g. an `<a>` or a Radix trigger) and auto-sizes inline icons.

### Variants (`variant`)

| Variant | Purpose | Default tokens |
|---------|---------|----------------|
| `default` | Primary action | `bg-primary` / `text-primary-foreground` |
| `outline` | Secondary, bordered | `border-border`, transparent fill |
| `secondary` | Lower-emphasis solid | `bg-secondary` / `text-secondary-foreground` |
| `ghost` | Minimal, toolbar/menu actions | transparent → `muted` on hover |
| `destructive` | Dangerous action | `bg-destructive/10` / `text-destructive` (tinted, not solid) |
| `link` | Inline text link | `text-primary` + underline on hover |

### Sizes (`size`)

`xs`, `sm`, `default` (h-7), `lg`, plus icon-only `icon-xs`, `icon-sm`, `icon`, `icon-lg`.
Icon glyphs auto-scale per size (e.g. `size-3.5` at default). All heights are multiples that
sit on the 8px grid rhythm (see [Design-System-Grid](Design-System-Grid.md)).

### Interaction states

| State | Behavior | Token |
|-------|----------|-------|
| Default | Variant base | per variant |
| Hover | Opacity shift (`hover:bg-primary/80`, `hover:bg-muted`, …) | `primary` / `secondary` / `muted` / `destructive` |
| Focus (keyboard) | `focus-visible:border-ring` + `ring-2 ring-ring/30` (destructive uses `ring-destructive/20`) | `ring` / `destructive` |
| Active (pressed) | `active:translate-y-px` (suppressed when it opens a popup) | motion only |
| Disabled | `disabled:opacity-50`, `disabled:pointer-events-none` | dims tokens |
| Invalid | `aria-invalid:border-destructive` + `aria-invalid:ring-destructive/20` | `destructive` |
| Expanded (popup trigger) | `aria-expanded:bg-muted` (outline/ghost/secondary) | `muted` / `secondary` |
| Loading | Pass `loading` to show a spinning `IconLoader2`, auto-`disabled`, and `aria-busy`. (Label-swap convention, e.g. `Saving…`, is still fine for submit buttons.) | motion + `aria-busy` |

### Accessibility
- Renders a native `<button>` (or, via `asChild`, the child element) — keyboard-activatable by default.
- Icon-only buttons **must** carry an accessible name via `aria-label` or an `sr-only` span
  (the Dialog close button does the latter).
- Focus ring uses the `ring` token and is visible on keyboard focus (`focus-visible`).

### Usage

```tsx
import { Button } from "@/components/ui/button";
import { IconPlus } from "@tabler/icons-react";

<Button onClick={openCreate} className="gap-2">
  <IconPlus size={18} /> Create User
</Button>

<Button variant="outline" onClick={onCancel}>Cancel</Button>
<Button variant="destructive" onClick={onDelete}>Delete</Button>

// Built-in loading prop (spinner + disabled + aria-busy):
<Button type="submit" loading={isSubmitting}>
  {isSubmitting ? "Saving…" : "Save Changes"}
</Button>

// Icon-only requires an accessible name:
<Button variant="ghost" size="icon-sm" aria-label="Open menu">
  <IconMenu2 size={16} />
</Button>
```
Real example: `web/app/(protected)/admin/users/edit-modal.tsx` (submit/disabled),
`web/app/(protected)/admin/users/page.tsx` (icon trigger).

---

## Badge

**Source:** `web/components/ui/badge.tsx` · **Slot:** `data-slot="badge"`

**Purpose:** Compact status/label/count chip. Pill-shaped (`rounded-full`), `h-5`. Supports
`asChild` so a badge can be a link (`[a]:hover:` styles only apply when rendered as a link).

### Variants
`default` (`bg-primary`), `secondary`, `destructive` (tinted `bg-destructive/10`), `outline`
(bordered, `bg-input/20`), `ghost`, `link`.

### Interaction states

| State | Behavior | Token |
|-------|----------|-------|
| Default | Variant base (static label) | per variant |
| Hover | Only when `asChild` renders a link (`[a]:hover:bg-primary/80`, …) | `primary` / `secondary` / `destructive` / `muted` |
| Focus | `focus-visible:border-ring` + `ring-[3px] ring-ring/50` | `ring` |
| Invalid | `aria-invalid:border-destructive` + ring | `destructive` |

### Accessibility
- A badge is decorative/informational text by default — no role.
- If it conveys status that isn't otherwise in the DOM, pair it with text or an `aria-label`.
- Do not use a non-link badge as a click target; use `asChild` with a real `<a>`/`<button>`.

```tsx
import { Badge } from "@/components/ui/badge";
<Badge>Active</Badge>
<Badge variant="secondary">Draft</Badge>
<Badge variant="destructive">Suspended</Badge>
<Badge variant="outline">v1.0</Badge>
```

---

## Card

**Source:** `web/components/ui/card.tsx` · **Slot:** `data-slot="card"`

**Purpose:** Surface/container that groups related content. Composed of `Card`, `CardHeader`,
`CardTitle`, `CardDescription`, `CardAction`, `CardContent`, `CardFooter`.

**Variants:** `size` = `default` | `sm` (tighter gap/padding). Edge images auto-round.
**Props:** `interactive` (opt into the hover/elevated treatment for clickable cards).

### States

| State | Behavior | Token |
|-------|----------|-------|
| Default | `bg-card` / `text-card-foreground`, `ring-1 ring-foreground/10` (hairline border) | `card`, `foreground` |
| Hover/elevated | Opt-in via `interactive`: deepens the ring (`hover:ring-foreground/20`), adds `hover:shadow-md`, and shows a `focus-within` ring for keyboard users | `foreground`, `ring` |

> Pass `interactive` only on cards that are actually clickable (e.g. a card wrapping a link or
> button). Non-interactive cards keep the flat default — don't add hover affordances to static
> surfaces.

### Accessibility
- Card is a generic container (`<div>`). Use real heading elements inside `CardTitle` when it
  is a document section, or give an interactive card a proper role/link.
- `CardTitle` and `DialogTitle` share `font-heading text-sm font-medium` styling.

```tsx
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from "@/components/ui/card";
<Card>
  <CardHeader>
    <CardTitle>Account</CardTitle>
    <CardDescription>Manage your account settings.</CardDescription>
  </CardHeader>
  <CardContent>{/* … */}</CardContent>
  <CardFooter className="border-t">{/* … */}</CardFooter>
</Card>
```

---

## Input

**Source:** `web/components/ui/input.tsx` · **Slot:** `data-slot="input"`

**Purpose:** Single-line text/number/email/password/file field. `h-7`, `bg-input/20`, token border.

### States

| State | Behavior | Token |
|-------|----------|-------|
| Default | `border-input`, `bg-input/20` (dark `bg-input/30`) | `input` |
| Placeholder | `placeholder:text-muted-foreground` | `muted-foreground` |
| Focus | `focus-visible:border-ring` + `ring-2 ring-ring/30` | `ring` |
| Disabled | `disabled:opacity-50`, `disabled:cursor-not-allowed`, `disabled:pointer-events-none` | dims tokens |
| Invalid / error | `aria-invalid:border-destructive` + `ring-destructive/20` (dark `/40`) | `destructive` |

The error state is driven by `aria-invalid`, which `FormControl` sets automatically when the
field has a validation error (see [Form](#form-react-hook-form--zod)).

### Accessibility
- Always pair with a label: `FormLabel` (RHF context) or a native `<label htmlFor>` for
  read-only/standalone fields (see the read-only fields in `users/edit-modal.tsx`).
- `aria-invalid` + `aria-describedby` (wired by `FormControl`) connect the field to its error
  message for screen readers.

```tsx
import { Input } from "@/components/ui/input";
<Input type="email" placeholder="john@example.com" {...field} />
<Input id="edit-user-email" type="email" value={user.email} disabled />
```

---

## Textarea

**Source:** `web/components/ui/textarea.tsx`

**Purpose:** Multi-line text input. `min-h-[80px]`.

### States
Default · placeholder · focus (`focus-visible:border-ring` + `ring-2 ring-ring/30`) ·
disabled (`opacity-50`, `cursor-not-allowed`) · invalid (`aria-invalid:border-destructive` +
`ring-destructive/20`). Token-aligned with `Input` — uses the `input` / `ring` / `border` /
`destructive` tokens (no raw palette).

```tsx
import { Textarea } from "@/components/ui/textarea";
<Textarea placeholder="Describe the role…" {...field} />
```

---

## Select

**Source:** `web/components/ui/select.tsx` (Radix `Select`) · icons from `@tabler/icons-react`

**Purpose:** Single-value dropdown. Parts: `Select`, `SelectTrigger`, `SelectValue`,
`SelectContent`, `SelectGroup`, `SelectLabel`, `SelectItem`, `SelectSeparator`, scroll buttons.

**Trigger sizes:** `default` (h-7) | `sm` (h-6). Caret = `IconSelector`.

### Trigger states

| State | Behavior | Token |
|-------|----------|-------|
| Default | `border-input`, `bg-input/20` | `input` |
| Placeholder | `data-placeholder:text-muted-foreground` | `muted-foreground` |
| Hover (dark) | `dark:hover:bg-input/50` | `input` |
| Focus | `focus-visible:border-ring` + `ring-2 ring-ring/30` | `ring` |
| Disabled | `disabled:opacity-50`, `disabled:cursor-not-allowed` | dims tokens |
| Invalid | `aria-invalid:border-destructive` + ring | `destructive` |
| Open | Radix sets `data-[state=open]`; content animates in | — |

### Item (`SelectItem`) states

| State | Behavior | Token |
|-------|----------|-------|
| Default | text on `popover` surface | `popover-foreground` |
| Highlighted / focused | `focus:bg-accent` + `focus:text-accent-foreground` | `accent` |
| Selected | `IconCheck` indicator shown | — |
| Disabled | `data-disabled:opacity-50`, `pointer-events-none` | dims tokens |

`SelectContent` is a portalled `popover` surface (`bg-popover`, `ring-1 ring-foreground/10`,
`shadow-md`) with open/close zoom+fade animations.

### Accessibility
- Full Radix listbox semantics, keyboard nav (arrows/Home/End/type-ahead), and focus
  management out of the box.
- Inside a form, wrap the trigger in `FormControl` so `aria-invalid`/`aria-describedby`
  apply (see `users/create-modal.tsx`).

```tsx
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
<Select onValueChange={field.onChange} value={field.value}>
  <SelectTrigger><SelectValue placeholder="Select a role" /></SelectTrigger>
  <SelectContent>
    <SelectItem value="admin">Admin</SelectItem>
    <SelectItem value="user">User</SelectItem>
    <SelectItem value="moderator">Moderator</SelectItem>
  </SelectContent>
</Select>
```

---

## Dropdown Menu

**Source:** `web/components/ui/dropdown-menu.tsx` (Radix `DropdownMenu`)

**Purpose:** Action/context menu (row actions, overflow menus). Rich part set incl.
`DropdownMenuItem` (with `variant="destructive"`), `CheckboxItem`, `RadioItem`, `Label`,
`Separator`, `Shortcut`, and `Sub`/`SubTrigger`/`SubContent`.

### Item states

| State | Behavior | Token |
|-------|----------|-------|
| Default | text on `popover` surface | `popover-foreground` |
| Highlighted / focused | `focus:bg-accent` + `focus:text-accent-foreground` | `accent` |
| Destructive item | `data-[variant=destructive]:text-destructive`, focus `bg-destructive/10` (dark `/20`) | `destructive` |
| Disabled | `data-disabled:opacity-50`, `pointer-events-none` | dims tokens |
| Sub open | `data-open:bg-accent` on the sub-trigger | `accent` |

`DropdownMenuContent`/`SubContent` are portalled `popover` surfaces with the same
`ring-1 ring-foreground/10 shadow-md` treatment and open/close animations as Select.

### Accessibility
- Radix menu semantics + full keyboard support (arrows, Enter, Esc, type-ahead) and focus
  trapping; `SubTrigger` chevron flips under RTL (`rtl:rotate-180`).

> [!NOTE]
> The Delete item in `users/page.tsx` uses `variant="destructive"` (the `destructive` token),
> not raw palette. Prefer `variant="destructive"` for any dangerous menu action.

```tsx
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
<DropdownMenu>
  <DropdownMenuTrigger asChild>
    <Button variant="ghost" size="icon-sm" aria-label="Row actions"><IconMenu2 size={16} /></Button>
  </DropdownMenuTrigger>
  <DropdownMenuContent align="end">
    <DropdownMenuItem onClick={onEdit}>Edit</DropdownMenuItem>
    <DropdownMenuItem variant="destructive" onClick={onDelete}>Delete</DropdownMenuItem>
  </DropdownMenuContent>
</DropdownMenu>
```

---

## Tabs

**Source:** `web/components/ui/tabs.tsx` (Radix `Tabs`)

**Purpose:** Switch between sibling panels. Parts: `Tabs`, `TabsList`, `TabsTrigger`,
`TabsContent`. Supports `orientation` (`horizontal` default | `vertical`).

**List variants:** `default` (filled `bg-muted` track) | `line` (underline style; active
trigger shows a `bg-foreground` underline via `::after`).

### Trigger states

| State | Behavior | Token |
|-------|----------|-------|
| Inactive | `text-foreground/60` (dark `text-muted-foreground`) | `foreground` / `muted-foreground` |
| Hover | `hover:text-foreground` | `foreground` |
| Active | `data-active:bg-background` + `data-active:text-foreground` (line variant: underline) | `background` / `foreground` |
| Focus | `focus-visible:border-ring` + `ring-[3px] ring-ring/50` + `outline-ring` | `ring` |
| Disabled | `disabled:opacity-50`, `pointer-events-none` | dims tokens |

### Accessibility
- Radix tablist semantics, roving tabindex, arrow-key navigation, and panel association
  handled automatically. Each `TabsContent` is `outline-none` and focusable as a region.

```tsx
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
<Tabs defaultValue="profile">
  <TabsList>
    <TabsTrigger value="profile">Profile</TabsTrigger>
    <TabsTrigger value="security">Security</TabsTrigger>
  </TabsList>
  <TabsContent value="profile">…</TabsContent>
  <TabsContent value="security">…</TabsContent>
</Tabs>
```

---

## Dialog (Modal)

**Source:** `web/components/ui/dialog.tsx` (Radix `Dialog`)

**Purpose:** Modal overlay for focused tasks (create/edit/delete forms, confirmations). Parts:
`Dialog`, `DialogTrigger`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogDescription`,
`DialogFooter`, `DialogClose`, `DialogOverlay`, `DialogPortal`.

`DialogContent` props: `showCloseButton` (default `true`) renders a ghost `IconX` close button
with an `sr-only` "Close" label. `DialogFooter` has an optional `showCloseButton` for an
outline "Close" button.

### States

| State | Behavior | Token |
|-------|----------|-------|
| Closed | Not rendered | — |
| Open | Overlay `bg-black/80` (+ `backdrop-blur-xs` where supported); content `bg-popover`, `ring-1 ring-foreground/10`, zoom+fade in | `popover` |
| Closing | Reverse zoom+fade (`data-closed:animate-out`) | — |
| Close button | Ghost `Button` `size="icon-sm"` with `IconX` + `sr-only` label | `muted` on hover |

### Accessibility
- Radix Dialog provides focus trap, focus restore on close, `Esc` to close, scroll lock, and
  `aria-modal`. Pair `DialogTitle` + `DialogDescription` — they are wired as the dialog's
  accessible name/description.
- Layout is RTL-aware (`start-1/2`, `rtl:translate-x-1/2`).

```tsx
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
<Dialog open={isOpen} onOpenChange={onOpenChange}>
  <DialogContent>
    <DialogHeader>
      <DialogTitle>Edit User</DialogTitle>
      <DialogDescription>Update user information.</DialogDescription>
    </DialogHeader>
    {/* form … */}
    <DialogFooter>
      <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
      <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving…" : "Save"}</Button>
    </DialogFooter>
  </DialogContent>
</Dialog>
```
Real examples: every modal under `web/app/(protected)/admin/**/{create,edit,delete}-modal.tsx`.

---

## Alert

**Source:** `web/components/ui/alert.tsx` · **Slot:** `data-slot="alert"`, `role="alert"`

**Purpose:** Inline, persistent contextual message within page flow (not a transient toast).
Parts: `Alert`, `AlertTitle`, `AlertDescription`, `AlertAction`. Auto-lays out a leading icon
in a two-column grid.

### Variants

| Variant | Purpose | Tokens |
|---------|---------|--------|
| `default` | Informational | `bg-card` / `text-card-foreground`; description `text-muted-foreground` |
| `destructive` | Error / danger | `bg-card` + `text-destructive`; description `text-destructive/90` |

### States
Alert is static (no hover/focus of its own). Links inside it underline and shift to
`text-foreground` on hover. `AlertAction` (e.g. a dismiss button) is positioned top-end.

> [!NOTE]
> `base.json` defines semantic `success` / `warning` / `error` / `info` colors, but `Alert`
> only ships `default` + `destructive` variants, and those semantic tokens are not yet wired
> into `globals.css`. Success/warning/info alerts are a gap — see [Follow-ups](#follow-ups).

### Accessibility
- `role="alert"` announces content to assistive tech when it appears. Provide an `AlertTitle`
  for a clear summary; lead with a Tabler icon for quick visual scanning.

```tsx
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import { IconAlertCircle } from "@tabler/icons-react";
<Alert variant="destructive">
  <IconAlertCircle />
  <AlertTitle>Could not save changes</AlertTitle>
  <AlertDescription>The server rejected the request. Try again.</AlertDescription>
</Alert>
```

---

## Toast (notifications)

**Source:** `web/components/ui/toast-container.tsx` + `web/lib/toast-context.tsx`

**Purpose:** Transient, auto-dismissing feedback after an action (save/create/delete success
or failure). This is a **custom toast system**, not the shadcn/Radix Toast primitive.

**API:** `useToast()` exposes `addToast(message, type, duration?)`, `removeToast(id)`. Types:
`success` | `error` | `warning` | `info`. Default duration `3000ms`. `ToastContainer` renders
bottom-end, fixed, with a slide-in animation and a per-toast `IconX` dismiss button. Icons:
`IconCheck` (success), `IconAlertCircle` (error), `IconAlertTriangle` (warning), `IconInfoCircle`
(info).

### States
Enter (slide-in) · visible · dismiss (manual `IconX` or auto after `duration`).

Each type maps to its semantic token pair: `success` → `bg-success`/`text-success-foreground`,
`error` → `bg-error`/`text-error-foreground`, `warning` → `bg-warning`/`text-warning-foreground`,
`info` → `bg-info`/`text-info-foreground`. No raw palette.

### Accessibility
- The container is a labeled `role="region"` ("Notifications"); each toast is `role="status"`
  with `aria-live` (`assertive` for `error`/`warning`, `polite` for `success`/`info`) and
  `aria-atomic`, so toasts are announced. The dismiss `<button>` has an `aria-label`
  ("Dismiss notification").

```tsx
import { useToast } from "@/lib/toast-context";
const { addToast } = useToast();
addToast("User updated successfully", "success");
addToast(message, "error");
```
Real usage: the `onSubmit`/`catch` blocks in every admin modal and list page.

---

## Form (React Hook Form + Zod)

**Source:** `web/components/ui/form.tsx` · uses `react-hook-form` + `@hookform/resolvers/zod`

**Purpose:** Accessible field scaffolding that binds `react-hook-form` state to inputs and
wires labels, descriptions, and error messages. Parts: `Form` (= `FormProvider`), `FormField`
(= `Controller` + context), `FormItem`, `FormLabel`, `FormControl`, `FormDescription`,
`FormMessage`, and the `useFormField()` hook.

### States

| State | Behavior | Token |
|-------|----------|-------|
| Default | Field renders normally | per field |
| Error | `FormLabel` turns `text-destructive`; `FormControl` sets `aria-invalid` (so the input shows its invalid ring); `FormMessage` renders the message in `text-destructive` | `destructive` |
| Description | `FormDescription` in `text-muted-foreground` | `muted-foreground` |

`useFormField()` derives stable ids (`*-form-item`, `*-form-item-description`,
`*-form-item-message`) and reads the field's RHF error to drive the above.

### Accessibility (this is the component's main job)
- `FormControl` sets `aria-invalid={!!error}` and `aria-describedby` pointing at the
  description and (when present) the error message — so screen readers announce both.
- `FormMessage` returns `null` when there is no error (no empty live nodes).
- `FormLabel`'s `htmlFor` matches the control id.

```tsx
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";

const schema = z.object({ email: z.string().email("Invalid email address") });
const form = useForm({ resolver: zodResolver(schema), defaultValues: { email: "" } });

<Form {...form}>
  <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
    <FormField control={form.control} name="email" render={({ field }) => (
      <FormItem>
        <FormLabel>Email</FormLabel>
        <FormControl><Input type="email" {...field} /></FormControl>
        <FormMessage />
      </FormItem>
    )} />
  </form>
</Form>
```
See [UI-Patterns › Form validation](UI-Patterns.md#form-validation) for the full pattern, and
`web/app/(protected)/admin/users/create-modal.tsx` for a real multi-field form.

---

## Component / state coverage matrix

| Component | Default | Hover | Focus | Active | Disabled | Loading | Error/Invalid | Selected/Active |
|-----------|:------:|:----:|:----:|:-----:|:-------:|:------:|:-------------:|:--------------:|
| Button | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (`loading`) | ✅ | ✅ (expanded) |
| Badge | ✅ | ✅ link | ✅ | — | — | — | ✅ | — |
| Card | ✅ | ✅ (`interactive`) | — | — | — | — | — | — |
| Input | ✅ | — | ✅ | — | ✅ | — | ✅ | — |
| Textarea | ✅ | — | ✅ | — | ✅ | — | ✅ | — |
| Select | ✅ | ✅ (dark) | ✅ | — | ✅ | — | ✅ | ✅ |
| Dropdown Menu | ✅ | ✅ | ✅ | — | ✅ | — | — | ✅ (sub-open) |
| Tabs | ✅ | ✅ | ✅ | — | ✅ | — | — | ✅ (active) |
| Dialog | ✅ | — | trap | — | — | — | — | — |
| Alert | ✅ | link only | — | — | — | — | ✅ (destructive) | — |
| Toast | ✅ | — | — | — | — | — | ✅ (error type) | — |
| Form | ✅ | — | — | — | ✅ (via field) | — | ✅ | — |

✅ built-in · ⚠️ convention/opt-in only · ❌ missing · — not applicable

---

## Follow-ups

Resolved in WC-125 (design-system adherence, a11y, and missing states):

1. ✅ **Button `loading` state** — added a `loading` prop (spinner + `disabled` + `aria-busy`).
2. ✅ **Card hover/elevated** — added the `interactive` opt-in (ring/shadow/focus-within), token-driven.
3. ✅ **Textarea token-aligned** — migrated to `input`/`ring`/`border` tokens and added
   `aria-invalid` error styling, matching `Input`.
4. ✅ **Toast token-aligned + accessible** — semantic `success`/`error`/`warning`/`info` tokens,
   `role="region"`/`role="status"` + `aria-live`, and a labeled dismiss button.
6. ✅ **Destructive list action** — `users/page.tsx` Delete now uses
   `<DropdownMenuItem variant="destructive">`.
7. ✅ **DataTable token-aligned** — loading (skeleton)/empty (icon+title)/table chrome migrated to
   tokens (see [UI-Patterns](UI-Patterns.md)).

Still open:

5. **Semantic colors — Alert variants.** `globals.css` now surfaces `success`/`warning`/`error`/
   `info` (and Toast consumes them), but `Alert` still ships only `default` + `destructive`. Add
   matching success/warning/info `Alert` variants.

## Component playgrounds (Storybook)

Two **decoupled** Storybook galleries let you build and tune components without
booting the backend, auth, or the full app. Both run on **Storybook 10** and render
through the same Tailwind v4 + design-token pipeline as production, so output is
pixel-accurate. A toolbar **light/dark** switch toggles the token set.

| Gallery | Location | Framework | Run |
|---------|----------|-----------|-----|
| **UI primitives** (`@amroksaleh/ui`) | `packages/ui/.storybook` | `@storybook/react-vite` | `npm run storybook -w @amroksaleh/ui` → `:6006` |
| **App components** (`web`) | `web/.storybook` | `@storybook/nextjs-vite` | `npm run storybook -w web` → `:7007` |

- The **primitives** gallery imports the package's own `src/globals.css` and covers every
  exported component with a variant/state matrix.
- The **app-components** gallery wraps stories in the real provider stack
  (Auth/Toast/Branding/Navigation) and mocks `/api/*` with **MSW**
  (`msw-storybook-addon`; worker at `web/public/mockServiceWorker.js`), so data-driven
  screens (CRUD/action/blocks, 2FA, branding) render offline. Shared fixtures + request
  handlers live in `web/.storybook/mocks.ts`.

> [!NOTE]
> The shared primitives now live in the **`@amroksaleh/ui`** workspace package
> (`packages/ui/src/`), consumed by `web` via npm workspaces (root `package.json`
> `workspaces: ["web", "packages/*"]`). A single `npm install` at the repo root installs
> both. The per-component **Source:** paths above (`web/components/ui/…`) predate this
> extraction and are being reconciled separately — the package source is authoritative.

## Related documentation

- [Design-System-Overview](Design-System-Overview.md) — architecture & principles
- [Theme-Customization](Theme-Customization.md) — tokens & white-label theming
- [Shadcn-UI-Setup](Shadcn-UI-Setup.md) — install/usage conventions
- [UI-Patterns](UI-Patterns.md) — loading / error / empty / validation patterns
- [Design-System-Grid](Design-System-Grid.md) — 8px grid, icon set, brand spacing
