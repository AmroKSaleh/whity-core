# Theme Customization

Whity Core uses a comprehensive CSS custom properties (variables) system for theming, powered by OKLCH color space for perceptually uniform colors across light and dark modes.

## Token Architecture

```
src/design/tokens/
├── base.json                    ← Master definitions
├── generate-tokens.js           ← Generation script
└── generated/
    ├── tokens.json             ← For programmatic use
    └── tokens.dart             ← For Flutter mobile

web/app/
└── globals.css                  ← Web tokens (source of truth)
```

## Token Structure

### Core UI Colors

| Token | Purpose |
|-------|---------|
| `background` / `foreground` | Page backgrounds and text |
| `card` / `card-foreground` | Card component surfaces |
| `popover` / `popover-foreground` | Dropdown/tooltip surfaces |
| `primary` / `primary-foreground` | Primary actions and buttons |
| `secondary` / `secondary-foreground` | Secondary interactive elements |
| `muted` / `muted-foreground` | Disabled/inactive states |
| `accent` / `accent-foreground` | Emphasis and highlights |
| `destructive` | Dangerous actions (delete, confirm) |

### UI Element Tokens

- `border` — Component borders
- `input` — Input field backgrounds and borders
- `ring` — Focus ring color for keyboard navigation

### Data Visualization

- `chart-1` through `chart-5` — Chart and graph colors for consistency

### Navigation (Sidebar)

- `sidebar`, `sidebar-foreground` — Navigation background and text
- `sidebar-primary`, `sidebar-primary-foreground` — Active sidebar items
- `sidebar-accent`, `sidebar-accent-foreground` — Sidebar emphasis
- `sidebar-border`, `sidebar-ring` — Sidebar borders and focus

### Spacing

- `radius` — Base border radius value (10px = 0.625rem)
- Derived values: `radius-sm`, `radius-md`, `radius-lg`, `radius-xl`, `radius-2xl`, `radius-3xl`, `radius-4xl`

## Color Spaces

**OKLCH** is used for all colors because it's perceptually uniform:
- **L** (Lightness) — 0 (dark) to 1 (light) — roughly perception
- **C** (Chroma) — Color saturation intensity
- **H** (Hue) — Color angle (0-360°)

Example: `oklch(0.578 0.245 27.325)` = A red-orange color

## Light and Dark Modes

### Light Mode (`:root`)
Default colors with high lightness for white backgrounds and dark text.

### Dark Mode (`.dark`)
Inverted with dark backgrounds and light text, with adjusted OKLCH values for readability.

## Customizing Your Theme

### Quick Start with Token Generator

The fastest way to create custom tokens:

1. **Visit:** https://ui.shadcn.com/create
2. **Choose your brand color** in the color picker
3. **Preview** light and dark modes
4. **Copy** the generated CSS
5. **Paste** into `web/app/globals.css`

### Manual Customization

Edit `web/app/globals.css`:

```css
:root {
  --primary: oklch(0.205 0 0);           /* Change primary color */
  --accent: oklch(0.97 0 0);             /* Change accent */
  --destructive: oklch(0.577 0.245 27.325); /* Change destructive */
  --radius: 0.625rem;                    /* Change border radius */
}

.dark {
  --primary: oklch(0.922 0 0);           /* Dark mode primary */
  --background: oklch(0.145 0 0);        /* Dark background */
  /* ... rest of dark tokens ... */
}
```

### Using Tokens in Components

With Tailwind CSS, tokens are automatically available as classes:

```jsx
// Using color tokens
<button className="bg-primary text-primary-foreground">
  Save Changes
</button>

// Using semantic tokens
<div className="bg-card text-card-foreground border border-border">
  Card Content
</div>

// Using state tokens
<input className="border border-input focus:ring-2 focus:ring-ring" />

// Using radius tokens
<div className="rounded-lg">Rounded corner</div>
```

## White-Label Customization

For multi-tenant applications, tokens can be customized per tenant by generating CSS variables server-side:

### Approach

1. **Store tenant preferences** in database
2. **Generate CSS** from preferences on server
3. **Inject into page** as `<style>` tag in HTML head

### Example Flow

```jsx
// In layout.tsx
export default function RootLayout({ children, tenant }) {
  // Generate CSS variables from tenant config
  const tenantCss = generateCssForTenant(tenant);
  
  // Inject as style tag (safe because generated server-side)
  return (
    <html>
      <head>
        <style>{tenantCss}</style>
      </head>
      <body>{children}</body>
    </html>
  );
}

// Helper function
function generateCssForTenant(tenant) {
  const { primary, accent, radius } = tenant.theme;
  return `
    :root {
      --primary: ${primary};
      --accent: ${accent};
      --radius: ${radius};
    }
  `;
}
```

The CSS cascade ensures all components automatically use tenant-specific tokens.

## Font Customization

Fonts are defined in `web/app/layout.tsx` and configured in Tailwind:

```css
@theme inline {
  --font-sans: var(--font-sans);      /* Body text, UI */
  --font-mono: var(--font-geist-mono); /* Code blocks */
  --font-heading: var(--font-sans);   /* Headings */
}
```

To change fonts:
1. Install font package: `npm install next/font`
2. Import in `app/layout.tsx`
3. Update `--font-*` variables
4. Update tailwind config

## Accessibility

All color tokens are designed for WCAG AA+ contrast:
- Foreground colors on background colors maintain 7:1+ contrast
- Focus states (`ring` color) clearly visible on all backgrounds
- High Contrast theme variant available for accessibility needs

## Token Generation

Tokens are automatically generated for multiple platforms:

### Generate All Tokens
```bash
cd web
npm run tokens:generate
```

### Generate Specific Formats
```bash
# JSON for programmatic access
npm run tokens:generate:json

# Dart for Flutter mobile
npm run tokens:generate:dart
```

### Generated Output

**tokens.json** — For programmatic use:
```json
{
  "light": { "primary": "oklch(...)", ... },
  "dark": { "primary": "oklch(...)", ... },
  "fonts": { "font-sans": "...", ... }
}
```

**tokens.dart** — For Flutter:
```dart
class AppTokens {
  static const lightTokens = <String, Color>{
    'primary': Color(0xFF3B82F6),
    // ...
  };
  static const darkTokens = <String, Color>{...};
}
```

### For White-Label Tenants

1. Load `tokens.json` programmatically
2. Customize colors per tenant
3. Generate CSS variables dynamically
4. Inject at runtime

```javascript
const baseTokens = require('./tokens.json');
const tenantTokens = { ...baseTokens, ...customization };
const css = generateCss(tenantTokens);
// Inject into page
```

## Resources

- **shadcn Token Generator:** https://ui.shadcn.com/create
- **OKLCH Colors:** https://oklch.com/ (interactive color picker)
- **Color Contrast Checker:** https://www.tpgi.com/color-contrast-checker/
- **Tailwind CSS Variables:** https://tailwindcss.com/docs/adding-custom-styles#using-css-variables
- **Token Generation:** See `src/design/tokens/generate-tokens.js` in repository
