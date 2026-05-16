# shadcn-ui Setup & Components

Whity Core uses **shadcn-ui** with a custom preset for the web dashboard, providing a collection of accessible, unstyled component primitives built on Radix UI.

## What is shadcn-ui?

shadcn-ui is **not a component library you install** — it's a **collection of copy-paste components** built on:
- **Radix UI** — Unstyled, accessible primitives
- **Tailwind CSS** — Utility-first styling
- **React Hook Form** — Form state management

Components are copied into your project source code, giving you full control over styling and behavior.

## Project Setup

Our web application is set up with shadcn-ui at `/web` with a custom preset:

```bash
# The preset includes:
# - RTL (Right-to-Left) language support
# - Pointer event enhancements
# - Custom theme tokens
npx shadcn@latest init --preset b1D0eTWj --template next --rtl --pointer
```

### What Was Configured

- `components.json` — shadcn config with import paths
- `lib/utils.ts` — Tailwind merge utilities
- `app/globals.css` — Design tokens and theme system
- Base components installed: Button, UI utilities

## Adding Components

### Install from Registry

Add any component from the shadcn registry:

```bash
cd web

# Add a single component
npx shadcn-ui@latest add button

# Add multiple components
npx shadcn-ui@latest add input textarea select

# Add with aliases
npx shadcn-ui@latest add dialog --alias "use-dialog"
```

### Available Components

Popular components ready to install:

**Forms & Input**
- `input` — Text, email, password inputs
- `textarea` — Multi-line text
- `checkbox` — Checkbox inputs
- `radio-group` — Radio buttons
- `select` — Dropdown select
- `switch` — Toggle switches
- `slider` — Range slider
- `form` — Complete form system with validation

**Display**
- `button` — Primary, secondary, outline variants
- `badge` — Label badges
- `card` — Card containers
- `table` — Data tables
- `avatar` — User avatars
- `image` — Optimized images

**Navigation**
- `sidebar` — Collapsible navigation
- `breadcrumb` — Breadcrumb navigation
- `navigation-menu` — Dropdown menus
- `tabs` — Tab navigation
- `pagination` — Page navigation

**Feedback**
- `alert` — Alert messages
- `alert-dialog` — Confirmation dialogs
- `dialog` — Modal dialogs
- `toast` — Notification toasts
- `progress` — Progress bars
- `skeleton` — Loading skeletons

**Disclosure**
- `accordion` — Collapsible sections
- `popover` — Floating popovers
- `dropdown-menu` — Context menus
- `sheet` — Side panels
- `tooltip` — Hover tooltips

See the full registry: https://ui.shadcn.com/docs/components/

## Using Components

### Import and Use

Components are imported from `@/components/ui`:

```jsx
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

export default function Dashboard() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Welcome</CardTitle>
      </CardHeader>
      <CardContent>
        <Input placeholder="Enter your name" />
        <Button>Submit</Button>
      </CardContent>
    </Card>
  );
}
```

### Component Props

Each component accepts standard HTML props:

```jsx
// Button variants
<Button>Default</Button>
<Button variant="secondary">Secondary</Button>
<Button variant="outline">Outline</Button>
<Button variant="ghost">Ghost</Button>
<Button variant="destructive">Delete</Button>
<Button disabled>Disabled</Button>

// Sizes
<Button size="sm">Small</Button>
<Button size="default">Default</Button>
<Button size="lg">Large</Button>

// States
<Button onClick={handleClick}>Click me</Button>
<Button loading>Loading...</Button>
<Button aria-label="Close">×</Button>
```

## Customizing Components

### Modifying Style

Components use Tailwind CSS classes. Edit them in `/web/components/ui/`:

```jsx
// components/ui/button.tsx
const buttonVariants = cva(
  "inline-flex items-center justify-center rounded-md text-sm font-medium",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-primary/90",
        secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        // ... more variants
      },
    },
  }
);
```

### Extending Components

Create wrapper components for project-specific behavior:

```jsx
// components/dashboard-button.tsx
import { Button } from "@/components/ui/button";

export function DashboardButton(props) {
  return (
    <Button 
      size="lg" 
      className="w-full" 
      {...props} 
    />
  );
}
```

## Theme Integration

All components automatically use the design tokens from `globals.css`:

```css
/* In globals.css */
:root {
  --primary: oklch(0.205 0 0);
  --primary-foreground: oklch(0.985 0 0);
  --background: oklch(1 0 0);
  /* ... more tokens ... */
}

/* Components use these via Tailwind */
<Button className="bg-primary text-primary-foreground">
```

Change theme tokens and all components update automatically.

## Best Practices

### 1. Copy, Don't Import
shadcn components are copied into your project. Feel free to modify them:

```bash
# This copies the component to your project
npx shadcn-ui@latest add button

# Edit it freely — it's now your code
# components/ui/button.tsx
```

### 2. Use Semantic Variants
Prefer semantic names over generic colors:

```jsx
// ✅ Good
<Button variant="destructive">Delete</Button>

// ❌ Avoid
<Button className="bg-red-500">Delete</Button>
```

### 3. Compose Components
Build complex UIs from simple components:

```jsx
<Card>
  <CardHeader>
    <CardTitle>Settings</CardTitle>
  </CardHeader>
  <CardContent>
    <form>
      <div className="space-y-4">
        <Input placeholder="Email" />
        <Select>
          <SelectItem value="admin">Admin</SelectItem>
        </Select>
        <Button>Save</Button>
      </div>
    </form>
  </CardContent>
</Card>
```

### 4. Accessible by Default
All components follow WAI-ARIA standards. Use semantic HTML:

```jsx
// Components handle accessibility
<Button aria-label="Close menu">×</Button>
<Dialog open={open} onOpenChange={setOpen}>
```

### 5. Dark Mode
Components automatically support dark mode via `.dark` class:

```jsx
// In layout.tsx
export default function RootLayout({ children }) {
  const [isDark, setIsDark] = useState(false);
  
  return (
    <html className={isDark ? "dark" : ""}>
      <body>{children}</body>
    </html>
  );
}
```

## Preset Details

Our custom preset (`b1D0eTWj`) includes:

- ✅ RTL support for international applications
- ✅ Pointer event enhancements for better mobile UX
- ✅ Optimized component defaults
- ✅ Integrated theme token system

To regenerate the preset or use a different one:

```bash
npx shadcn-ui@latest init --preset <preset-id>
```

Browse presets: https://ui.shadcn.com/create

## Common Patterns

### Form with Validation

```jsx
import { useForm } from "react-hook-form";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

export function LoginForm() {
  const { register, handleSubmit } = useForm();
  
  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <Input {...register("email")} type="email" />
      <Input {...register("password")} type="password" />
      <Button type="submit">Login</Button>
    </form>
  );
}
```

### Modal Dialog

```jsx
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";

export function ConfirmDialog({ open, onConfirm, onCancel }) {
  return (
    <Dialog open={open} onOpenChange={onCancel}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Confirm Action</DialogTitle>
        </DialogHeader>
        <div className="flex gap-2 justify-end">
          <Button variant="outline" onClick={onCancel}>Cancel</Button>
          <Button onClick={onConfirm}>Confirm</Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
```

## Resources

- **Component Registry:** https://ui.shadcn.com/docs/components/
- **Preset Creator:** https://ui.shadcn.com/create
- **Installation Guide:** https://ui.shadcn.com/docs/installation/next
- **Accessibility:** https://www.radix-ui.com/docs/primitives/overview/accessibility
- **Tailwind CSS:** https://tailwindcss.com/docs
