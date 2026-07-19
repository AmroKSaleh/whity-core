# EmptyState / ErrorState — usage notes (for the gallery/showcase task)

Import: `import { EmptyState, ErrorState } from '@amroksaleh/ui/empty-state'` (or the root barrel).

`ErrorState` is `EmptyState` pinned to `variant="error"` — identical props minus `variant`.

## Props

| Prop | Type | Required | Notes |
|---|---|---|---|
| `variant` | `'empty' \| 'error'` | no (default `'empty'`) | Not settable on `ErrorState` (always `'error'`). Drives tone + default icon + ARIA role. |
| `icon` | `React.ReactNode` | no | Slot override. Falls back to `IconInbox` (empty) / `IconAlertTriangle` (error) from `@tabler/icons-react`. Web-only slot — see platform note below. |
| `title` | `string` | **yes** | |
| `description` | `string` | no | |
| `action` | `React.ReactNode` | no | Typically a `<Button>` (e.g. "Retry" / "Create X"). |
| ...rest | `React.ComponentProps<'div'>` | no | Spread onto the root element (e.g. `className`). |

## Accessibility

- `EmptyState` renders `role="status"` (polite announcement — informational).
- `ErrorState` renders `role="alert"` (assertive announcement — interrupts, matching real error semantics).
- Verified via a real axe-core scan in `web/__tests__/empty-state.a11y.test.tsx` (0 violations across default, error, and with-action renders).

## Platform note (Flutter-forward design)

`variant`, `title`, and `description` are plain string/enum props — directly mirrorable by a future Flutter/Dart component. `icon` and `action` are React-node slots and are **web-only**; a Flutter implementation should supply its own platform-appropriate default icon/action per `variant` rather than trying to accept an arbitrary widget through the same prop name.

## Gallery / Storybook

Story file: `packages/ui/src/empty-state.stories.tsx` — `Default`, `WithAction`, `Error` stories under `Primitives/EmptyState`.
