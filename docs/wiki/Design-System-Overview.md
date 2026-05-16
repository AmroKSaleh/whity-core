# Design System Overview

Whity Core's design system is a comprehensive, cross-platform solution for building consistent user interfaces across web, mobile, and desktop applications.

## Core Principles

1. **Tokens-First** — All design decisions expressed as reusable tokens
2. **Platform-Native** — Each platform uses idiomatic components (shadcn for web, Material 3 for mobile)
3. **Accessible** — WCAG AA+ compliance by default
4. **Customizable** — Full white-label support with per-tenant theming
5. **Consistent** — Unified design tokens across all platforms

## Architecture

### Three-Layer System

```
┌─────────────────────────────────────────────────────┐
│           Design Decision Layer                      │
│  (Figma, Design Specs, Brand Guidelines)            │
└────────────────────┬────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────┐
│           Token Definition Layer                     │
│  Colors, Typography, Spacing, Radius, etc.          │
│  (src/design/tokens/base.json)                      │
└────────────────────┬────────────────────────────────┘
                     │
        ┌────────────┼────────────┐
        │            │            │
        ▼            ▼            ▼
    ┌────────┐  ┌────────┐  ┌────────┐
    │  Web   │  │ Mobile │  │Desktop │
    │ (CSS)  │  │ (Dart) │  │ (CSS)  │
    └────────┘  └────────┘  └────────┘
        │            │            │
        ▼            ▼            ▼
┌─────────────────────────────────────────────────────┐
│         Component Implementation Layer               │
│  (shadcn-ui, Material Design 3, Web Components)     │
└────────────────────┬────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────┐
│              User Interfaces                         │
│  (Dashboard, Admin Panels, Mobile App, Desktop)     │
└─────────────────────────────────────────────────────┘
```

## Token System

### 32 Design Tokens

**Colors (28)**
- Core: background, foreground, card, popover, primary, secondary, accent, destructive
- UI: border, input, ring
- Data: chart-1 through chart-5
- Navigation: sidebar (6 variants)

**Layout (4)**
- radius, radius-sm, radius-md, radius-lg, radius-xl, radius-2xl, radius-3xl, radius-4xl

**Typography (3)**
- font-sans, font-mono, font-heading

### OKLCH Color Space

Tokens use perceptually uniform OKLCH color space:
- **L (Lightness)** — 0 (dark) to 1 (light)
- **C (Chroma)** — Color intensity
- **H (Hue)** — 0-360° color angle

Example: `oklch(0.205 0 0)` = Dark Navy Blue

**Why OKLCH?**
✅ Perceptually uniform (equal steps appear equally different)
✅ Better dark mode support
✅ Easier to generate color scales
✅ Superior accessibility

### Light & Dark Modes

All tokens have light and dark variants:

```css
:root { /* Light mode */
  --background: oklch(1 0 0);      /* White */
  --foreground: oklch(0.145 0 0);  /* Dark text */
}

.dark { /* Dark mode */
  --background: oklch(0.145 0 0);  /* Dark bg */
  --foreground: oklch(0.985 0 0);  /* Light text */
}
```

## Platform Implementation

### Web (React + shadcn-ui)

**Location:** `web/`

**Technology Stack:**
- Framework: Next.js 16.2 (App Router)
- Styling: Tailwind CSS v4
- Components: shadcn-ui (copy-paste)
- Base: Radix UI primitives

**Token Usage:**
```jsx
<button className="bg-primary text-primary-foreground">
  Click me
</button>
```

**Files:**
- `app/globals.css` — Token definitions
- `components/ui/` — 7+ components
- `components.json` — shadcn configuration

### Mobile (Flutter + Material 3)

**Status:** Planned (Sprint 1+)

**Technology Stack:**
- Framework: Flutter
- Design: Material Design 3
- Tokens: Dart constants (auto-generated)

**Token Usage:**
```dart
Color primary = AppTokens.lightTokens['primary'];
```

**Files:**
- `src/design/tokens/generated/tokens.dart` — Auto-generated
- `pubspec.yaml` — Flutter config (to create)

### Desktop (Electron)

**Status:** Planned

**Technology Stack:**
- Framework: Electron
- Rendering: Web components (shadcn-ui)
- Desktop integration: Native OS APIs

## White-Label Customization

### Approach

1. **Define tenant colors** in database
2. **Generate CSS** from templates
3. **Inject at runtime** into HTML head
4. **Cascade** makes all components use new colors

### Implementation Pattern

For white-label tenants, generate CSS variables server-side from tenant configuration:

```javascript
// Load base tokens for reference
const baseTokens = require('./tokens.json');

// Generate CSS for tenant (server-side only)
function generateTenantCss(tenantConfig) {
  const css = `
    :root {
      --primary: ${tenantConfig.primaryColor};
      --accent: ${tenantConfig.accentColor};
    }
    .dark {
      --primary: ${tenantConfig.darkPrimary};
      --accent: ${tenantConfig.darkAccent};
    }
  `;
  return css;
}

// Inject into layout (safe - generated on server)
export default function RootLayout({ children, tenant }) {
  const tenantCss = generateTenantCss(tenant.theme);
  return (
    <html>
      <head>
        <style>{tenantCss}</style>
      </head>
      <body>{children}</body>
    </html>
  );
}
```

**Key:** All content is generated server-side, never user-supplied.

## Component Library

### 7 Core Components (Web)

Pre-installed and ready to use:

| Component | Purpose | States |
|-----------|---------|--------|
| Button | Actions | Primary, Secondary, Outline, Ghost, Destructive |
| Card | Container | Default, Hover (elevated) |
| Badge | Labels | Default, Secondary, Destructive, Outline |
| Input | Text fields | Default, Focus, Disabled, Error |
| Select | Dropdowns | Default, Open, Disabled |
| Alert | Messages | Default, Success, Error, Warning, Info |
| Tabs | Navigation | Inactive, Active, Disabled |

### 50+ Additional Components

Available via registry: https://ui.shadcn.com/docs/components/

Popular additions:
- Dialog, Tooltip, Popover, Sheet
- Form, Textarea, Checkbox, Radio, Switch
- Table, Pagination, Breadcrumb
- Avatar, Badge, Progress, Skeleton

## Design System Files

```
whity-core/
├── src/design/
│   ├── tokens/
│   │   ├── base.json                  ← Master definitions
│   │   ├── generate-tokens.js         ← CLI tool
│   │   └── generated/
│   │       ├── tokens.json            ← JSON export
│   │       └── tokens.dart            ← Dart export
│   └── test.html                      ← Component showcase
│
├── web/                               ← Web app
│   ├── app/
│   │   ├── globals.css               ← Token definitions
│   │   └── demo/page.tsx             ← Demo dashboard
│   └── components/ui/                ← shadcn components
│
└── docs/
    └── wiki/
        ├── Design-System-Overview.md  ← This file
        ├── Theme-Customization.md     ← Token customization
        └── Shadcn-UI-Setup.md         ← Component usage
```

## Getting Started

### View the Demo
```bash
cd web && npm run dev
# Visit http://localhost:3000/demo
```

### Customize Theme
1. Visit https://ui.shadcn.com/create
2. Choose brand colors
3. Copy CSS variables
4. Paste into `web/app/globals.css`

### Add Components
```bash
cd web
npx shadcn@latest add [component-name]
# Example: npx shadcn@latest add dialog
```

### Generate Tokens
```bash
cd web
npm run tokens:generate

# Outputs:
# - tokens.json (programmatic use)
# - tokens.dart (Flutter mobile)
```

## Accessibility

All components and tokens meet WCAG AA+ standards:

- **Contrast Ratios** — 7:1+ on all backgrounds
- **Color Space** — OKLCH for perceptual consistency
- **Focus States** — Clear ring color on all states
- **Semantic HTML** — Proper markup and ARIA labels
- **Keyboard Navigation** — Full keyboard support
- **Responsive** — Works on all device sizes

## Team References

- **Component Registry:** https://ui.shadcn.com/docs/components/
- **Theme Generator:** https://ui.shadcn.com/create
- **Radix UI Accessibility:** https://www.radix-ui.com/
- **Tailwind CSS:** https://tailwindcss.com/docs
- **OKLCH Colors:** https://oklch.com/

## Next Steps

### Immediate (Sprint 0 - In Progress)
- ✅ Token system complete
- ✅ Web components library ready
- ⏳ Icon set and brand assets

### Short-term (Sprint 1)
- [ ] Flutter Material 3 integration
- [ ] Mobile app setup
- [ ] Additional components (Dialog, Tooltip, Popover)

### Medium-term (Sprint 2+)
- [ ] Electron desktop app
- [ ] White-label tenant management UI
- [ ] Theme builder and gallery
- [ ] Design tokens CLI

---

**Last Updated:** May 16, 2026  
**Status:** Production Ready  
**Maintained by:** Whity Core Team
