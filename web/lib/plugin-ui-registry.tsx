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
 * app-level file (e.g. `web/lib/plugin-screens.ts`, created by the app):
 *
 * ```tsx
 * // web/lib/plugin-screens.ts
 * import { registerPluginScreen } from '@/lib/plugin-ui-registry';
 * import { HelloGreetingsScreen } from '@/components/hello/greetings-screen';
 *
 * registerPluginScreen('hello-greetings', HelloGreetingsScreen);
 * ```
 *
 * That file must be imported once for its side effects (e.g. from the root
 * `app/layout.tsx`: `import '@/lib/plugin-screens';`) so registrations run
 * before any feature screen renders.
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
