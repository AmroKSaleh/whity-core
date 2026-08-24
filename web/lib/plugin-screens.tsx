'use client';

/**
 * App-owned plugin screen registrations (WC-169, #964).
 *
 * This is the single app-level file the plugin UI registry expects (see
 * `lib/plugin-ui-registry.tsx`): it wires plugin feature ids to the bespoke
 * screen components this app provides.
 *
 * IT MUST BE A CLIENT MODULE, AND THE APP SHELL MUST *RENDER* IT (#964).
 * The registry is consulted from `/admin/x/[featureId]`, a `'use client'`
 * page — so the lookup happens in the BROWSER, against the browser's copy of
 * the registry map. This file used to carry no directive and was pulled in by
 * a bare side-effect `import '@/lib/plugin-screens'` from the SERVER component
 * `app/layout.tsx`, which meant every registration below ran in the server's
 * module graph and none of them ran in the browser's. The client-side registry
 * was empty on every render, so no override ever won and the host silently fell
 * through to the generic screen (or, for a `screen: 'custom'` feature, to the
 * "no screen registered" placeholder).
 *
 * The `'use client'` directive above puts these registrations in the client
 * graph, and {@see PluginScreenRegistrations} — rendered by `app/layout.tsx` —
 * is what pulls the module into the shell's client bundle. A component is used
 * rather than another bare import on purpose: a rendered element is a reference
 * the bundler cannot treat as a side-effect-only import to be shaken away, and
 * it is the part a reviewer can see in the layout's JSX.
 *
 * `__tests__/plugin-screen-override.test.tsx` pins both halves — that a
 * registered override actually renders, and that this module stays a client
 * module the shell renders rather than one the server imports.
 *
 * To add a bespoke override, add one import and one `registerPluginScreen(...)`
 * call below — nothing else belongs in this file.
 *
 * WHAT BELONGS HERE. An override ALWAYS wins over whatever the feature
 * descriptor asks for, so registering one for a `screen: 'crud'` /
 * `screen: 'blocks'` feature REPLACES the host-derived screen wholesale —
 * including the capability gating (#199/#951) and the record route (#948) that
 * come with it for free. That is the intended escape hatch when the block DSL
 * genuinely cannot express a screen, and a poor trade otherwise. `demo-catalog`
 * declares `screen: 'custom'` and so has no host-derived screen to lose: the
 * registration below is the ONLY way it can render at all.
 */

import { registerPluginScreen } from '@/lib/plugin-ui-registry';
import { DemoCatalogScreen } from '@/components/demo-catalog/demo-catalog-screen';

registerPluginScreen('demo-catalog', DemoCatalogScreen);

/**
 * Rendered once by `app/layout.tsx` so the registrations above land in the
 * client bundle and run before any feature screen renders. Renders nothing:
 * its whole job is to be a reference from the app shell into this module.
 */
export function PluginScreenRegistrations(): null {
  return null;
}
