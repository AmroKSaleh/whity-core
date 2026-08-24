/**
 * Per-app override slot for plugin screens (WC-169).
 *
 * The dynamic feature host (`/admin/x/[featureId]`) renders plugin features in
 * this order of precedence:
 *
 *   1. A component registered here for the feature id — ALWAYS wins. This is
 *      the documented bespoke-override path for `screen: "crud"` features and
 *      the ONLY way to render `screen: "custom"` features.
 *   2. The generic schema-driven CRUD renderer (crud features with a resource).
 *   3. A neutral placeholder asking the app to register a component.
 *
 * How an app registers an override — one import + one call in a single
 * app-level file (e.g. `web/lib/plugin-screens.tsx`, created by the app):
 *
 * ```tsx
 * // web/lib/plugin-screens.tsx
 * 'use client';
 *
 * import { registerPluginScreen } from '@/lib/plugin-ui-registry';
 * import { DemoCatalogScreen } from '@/components/demo-catalog/demo-catalog-screen';
 *
 * registerPluginScreen('demo-catalog', DemoCatalogScreen);
 *
 * export function PluginScreenRegistrations(): null {
 *   return null;
 * }
 * ```
 *
 * ...and the app shell RENDERS it (`app/layout.tsx`:
 * `<PluginScreenRegistrations />`), so the registrations run in the browser
 * before any feature screen renders.
 *
 * BOTH HALVES ARE LOAD-BEARING (#964). This registry is a plain module-level
 * Map, and a module imported on both sides of the server/client split has TWO
 * instances — one per graph. The lookup below happens in `'use client'` pages,
 * i.e. in the browser, so a registration that runs only on the server writes to
 * the copy nobody reads. That is precisely what a bare, directive-less
 * `import '@/lib/plugin-screens'` from the server component `app/layout.tsx`
 * did: every registration ran, none of them counted, and the host quietly
 * served the generic screen instead. The failure mode is a page that WORKS,
 * which is why it went unnoticed for two months — so the registration module
 * must carry `'use client'` and the shell must render it rather than import it
 * for side effects. `web/__tests__/plugin-screen-override.test.tsx` holds that
 * line.
 */

import type { ComponentType } from 'react';
import type { PluginFeature } from '@/lib/plugin-features';

/** A screen component rendered for a plugin feature. */
export type PluginScreenComponent = ComponentType<{ feature: PluginFeature }>;

const registry = new Map<string, PluginScreenComponent>();

/**
 * Register a bespoke screen for a feature id. Re-registering the same id
 * replaces the previous component (last registration wins).
 */
export function registerPluginScreen(
  id: string,
  component: PluginScreenComponent
): void {
  registry.set(id, component);
}

/** Look up the registered override for a feature id, if any. */
export function resolvePluginScreen(
  id: string
): PluginScreenComponent | undefined {
  return registry.get(id);
}

/**
 * Remove a registration (used by tests and hot-module teardown).
 *
 * @returns true when a component was actually removed.
 */
export function unregisterPluginScreen(id: string): boolean {
  return registry.delete(id);
}
